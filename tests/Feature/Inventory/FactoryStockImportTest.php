<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ItemType;
use App\Enums\TransactionType;
use App\Enums\UnitOfMeasure;
use App\Enums\WarehouseType;
use App\Imports\FactoryStock\FactoryStockImporter;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * The factory-warehouse sheet import: reading in-cell photos, normalising the
 * sheet's gaps (codes, units, lengths, negative counts, office samples),
 * booking opening balances once, and retiring the legacy items it replaces.
 */
class FactoryStockImportTest extends TestCase
{
    use RefreshDatabase;

    private string $workbook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('public');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->workbook = $this->buildWorkbook();
    }

    protected function tearDown(): void
    {
        @unlink($this->workbook);
        parent::tearDown();
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $result = $this->importer()->run($this->workbook, dryRun: true);

        $this->assertSame(5, $result->summary()['items']);
        $this->assertSame(0, Item::count());
        $this->assertSame(0, ItemCategory::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_it_imports_items_with_codes_units_categories_and_specs(): void
    {
        $this->importer()->run($this->workbook);

        $this->assertSame(5, Item::count());

        $bar = Item::firstWhere('sku', 'CU-1');
        $this->assertSame('شريط', $bar->name);
        $this->assertSame('شرائط نحاس', $bar->category->name);
        $this->assertSame(UnitOfMeasure::Piece, $bar->unit);
        $this->assertEquals(100, $bar->width_mm);
        $this->assertEquals(3, $bar->length_m);

        // A repeated code gets a suffix; the sheet's code stays searchable.
        $repeat = Item::firstWhere('sku', 'CU-1-2');
        $this->assertSame('CU-1', $repeat->part_number);
        $this->assertSame('أسود', $repeat->color);

        // A missing code gets a stable code derived from the sheet row.
        $this->assertTrue(Item::where('sku', 'FS-0004')->exists());

        // A formula in the length column is a total: metre-counted, no length.
        $sleeve = Item::firstWhere('name', 'ريكم سميك');
        $this->assertSame(UnitOfMeasure::Meter, $sleeve->unit);
        $this->assertNull($sleeve->length_m);

        // «بضاعة جاهزة» is a finished good.
        $this->assertSame(ItemType::FinishedGood, Item::firstWhere('sku', 'X-1')->type);

        // Office samples are not imported.
        $this->assertFalse(Item::where('name', 'عينة مخرج')->exists());
    }

    public function test_opening_balances_land_in_the_home_warehouse_and_skip_negative_counts(): void
    {
        $this->importer()->run($this->workbook);

        $this->assertStock('CU-1', WarehouseType::RawMaterials, 10);
        $this->assertStock('X-1', WarehouseType::FinishedGoods, 2);

        $noCode = Item::firstWhere('sku', 'FS-0004');
        $this->assertSame(0, $noCode->inventoryTransactions()->count());

        $opening = InventoryTransaction::firstWhere('item_id', Item::firstWhere('sku', 'CU-1')->id);
        $this->assertSame(TransactionType::In, $opening->type);
        $this->assertSame(FactoryStockImporter::OPENING_NOTE, $opening->notes);
    }

    public function test_running_the_import_again_never_doubles_the_stock(): void
    {
        $this->importer()->run($this->workbook);
        $second = $this->importer()->run($this->workbook);

        $this->assertSame(5, Item::count());
        $this->assertSame(0, $second->summary()['opening_balances']);
        $this->assertSame(5, $second->summary()['updated']);
        $this->assertStock('CU-1', WarehouseType::RawMaterials, 10);
    }

    public function test_a_shared_photo_is_stored_once_and_resized(): void
    {
        $this->importer()->run($this->workbook);

        $files = Storage::disk('public')->allFiles('items');
        $this->assertCount(1, $files);

        $bar = Item::firstWhere('sku', 'CU-1');
        $this->assertSame($files[0], $bar->image_path);
        $this->assertSame($files[0], Item::firstWhere('sku', 'X-1')->image_path);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($files[0]));
        $this->assertSame(1000, max($width, $height));
    }

    public function test_purging_removes_unused_legacy_items_and_retires_used_ones(): void
    {
        $unused = Item::factory()->create(['sku' => 'DEMO-UNUSED']);
        $used = Item::factory()->create(['sku' => 'DEMO-USED', 'type' => ItemType::RawMaterial]);
        app(InventoryService::class)->addStock($used, 7);

        $this->importer()->run($this->workbook, purgeLegacy: true);

        $this->assertNull(Item::withTrashed()->find($unused->id));

        $retired = Item::withTrashed()->find($used->id);
        $this->assertTrue($retired->trashed());
        $this->assertSame(0, Inventory::where('item_id', $used->id)->count());
        // Its history stays so old documents still resolve the item.
        $this->assertSame(1, InventoryTransaction::where('item_id', $used->id)->count());

        $this->assertSame(5, Item::count());
    }

    public function test_purging_frees_a_code_held_by_a_retired_item(): void
    {
        $old = Item::factory()->create(['sku' => 'CU-1']);
        $old->delete();

        $this->importer()->run($this->workbook, purgeLegacy: true);

        $this->assertSame("CU-1-OLD-{$old->id}", Item::withTrashed()->find($old->id)->sku);
        $this->assertFalse(Item::firstWhere('sku', 'CU-1')->is($old));
    }

    public function test_the_command_writes_a_review_report(): void
    {
        $report = tempnam(sys_get_temp_dir(), 'review').'.xlsx';

        $this->artisan('items:import-factory-stock', [
            'file' => $this->workbook,
            '--dry-run' => true,
            '--report' => $report,
        ])->assertSuccessful();

        $this->assertFileExists($report);
        $this->assertSame(0, Item::count());
        @unlink($report);
    }

    private function importer(): FactoryStockImporter
    {
        return app(FactoryStockImporter::class);
    }

    private function assertStock(string $sku, WarehouseType $warehouse, float $quantity): void
    {
        $item = Item::firstWhere('sku', $sku);

        $this->assertEquals($quantity, (float) Inventory::query()
            ->where('item_id', $item->id)
            ->where('warehouse_type', $warehouse->value)
            ->value('on_hand_quantity'));
    }

    /**
     * A minimal .xlsx in the sheet's layout, with one photo placed in two
     * cells the way Excel stores in-cell pictures (richData).
     */
    private function buildWorkbook(): string
    {
        $strings = [];
        $s = function (string $text) use (&$strings): string {
            $index = array_search($text, $strings, true);
            if ($index === false) {
                $strings[] = $text;
                $index = count($strings) - 1;
            }

            return '<v>'.$index.'</v>';
        };

        $cell = fn (string $ref, string|float|int $value): string => is_string($value)
            ? "<c r=\"{$ref}\" t=\"s\">{$s($value)}</c>"
            : "<c r=\"{$ref}\"><v>{$value}</v></c>";
        $image = fn (string $ref, int $vm): string => "<c r=\"{$ref}\" t=\"e\" vm=\"{$vm}\"><v>#VALUE!</v></c>";
        $header = fn (int $r): string => "<row r=\"{$r}\">{$cell("A{$r}", 'م')}{$cell("B{$r}", 'البيـــان')}</row>";

        $rows = [
            $header(1),
            '<row r="2">'.$cell('A2', 1).$cell('B2', 'شريط').$cell('C2', 'خامات').$image('D2', 1).$cell('E2', 'PT')
                .$cell('F2', 5).$cell('G2', 100).$cell('H2', 'Cu').$cell('I2', 3).$cell('O2', 'CU-1').$cell('S2', 10).'</row>',
            '<row r="3">'.$cell('A3', 2).$cell('B3', 'شريط').$cell('H3', 'Cu').$cell('O3', 'CU-1').$cell('S3', 5).$cell('T3', 'لون اسود').'</row>',
            '<row r="4">'.$cell('A4', 3).$cell('B4', 'جوان').$cell('S4', -3).'</row>',
            '<row r="5">'.$cell('A5', 1).$cell('B5', 'ريكم سميك').$cell('G5', 60)
                .'<c r="I5"><f>S5</f><v>20</v></c>'.$cell('O5', 'RK-60').$cell('S5', 20).'</row>',
            '<row r="6">'.$cell('A6', 'عينات داخل المكتب').'</row>',
            $header(7),
            '<row r="8">'.$cell('A8', 1).$cell('B8', 'عينة مخرج').$cell('S8', 1).'</row>',
            $header(9),
            '<row r="10">'.$cell('A10', 1).$cell('B10', 'مخرج').$cell('C10', 'بضاعة جاهزة').$image('D10', 2)
                .$cell('O10', 'X-1').$cell('S10', 2).'</row>',
        ];

        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .implode('', $rows).'</sheetData></worksheet>';

        $shared = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(fn ($t) => '<si><t>'.htmlspecialchars($t).'</t></si>', $strings)).'</sst>';

        // Two cells (vm 1 and 2) → two rich values → the same relationship.
        $metadata = '<?xml version="1.0" encoding="UTF-8"?><metadata xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:xlrd="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata">'
            .'<futureMetadata name="XLRICHVALUE" count="2">'
            .'<bk><extLst><ext uri="{x}"><xlrd:rvb i="0"/></ext></extLst></bk>'
            .'<bk><extLst><ext uri="{x}"><xlrd:rvb i="1"/></ext></extLst></bk></futureMetadata>'
            .'<valueMetadata count="2"><bk><rc t="1" v="0"/></bk><bk><rc t="1" v="1"/></bk></valueMetadata></metadata>';
        $richValues = '<?xml version="1.0" encoding="UTF-8"?><rvData xmlns="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata" count="2">'
            .'<rv s="0"><v>0</v><v>5</v></rv><rv s="0"><v>0</v><v>5</v></rv></rvData>';
        $richRel = '<?xml version="1.0" encoding="UTF-8"?><richValueRels xmlns="http://schemas.microsoft.com/office/spreadsheetml/2022/richvaluerel" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><rel r:id="rId1"/></richValueRels>';
        $richRelRels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/></Relationships>';

        $png = imagecreatetruecolor(2000, 1500);
        ob_start();
        imagepng($png);
        $pngBytes = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'factory').'.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', $shared);
        $zip->addFromString('xl/metadata.xml', $metadata);
        $zip->addFromString('xl/richData/rdrichvalue.xml', $richValues);
        $zip->addFromString('xl/richData/richValueRel.xml', $richRel);
        $zip->addFromString('xl/richData/_rels/richValueRel.xml.rels', $richRelRels);
        $zip->addFromString('xl/media/image1.png', $pngBytes);
        $zip->close();

        return $path;
    }
}
