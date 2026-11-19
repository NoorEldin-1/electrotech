<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ProvisionStaffUsers;
use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Filament\Pages\ProjectsTimeline;
use App\Filament\Resources\ActiveProjectResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Widgets\StatsOverview;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The staff sheet (2026-10-20): every person sees only the screens written
 * against their name. Each account is checked against EVERY resource and page
 * in the panel — a screen that appears for someone without being on their
 * row fails this test, as does one on their row that does not open.
 */
class StaffAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const WAREHOUSE = [
        'AdditionVoucherResource', 'DeliveryVoucherResource', 'DepreciationVoucherResource',
        'InventoryTransactionResource', 'IssueVoucherResource', 'ReturnVoucherResource',
    ];

    private const SALES = [
        'ProjectResource', 'TenderProjectResource', 'InHandProjectResource', 'ActiveProjectResource',
        'LostProjectResource', 'CustomerResource', 'CustomerInteractionResource',
    ];

    /** @return array<string, array<int, string>> */
    private static function expectedScreens(): array
    {
        return [
            'asmaa.mohamed@electrotech.com' => ['ProjectsTimeline', 'ActiveProjectResource'],
            'yassin.mohamed@electrotech.com' => [
                'PurchaseOrderResource', 'SupplierResource', 'ItemResource', 'ItemCategoryResource',
                'StockReservationResource', 'ActiveProjectResource',
            ],
            'raghda@electrotech.com' => self::SALES,
            'mariam.ibrahim@electrotech.com' => self::SALES,
            'afaf.fawzy@electrotech.com' => [...self::SALES, 'ProjectsTimeline'],
            'sameh.magdy@electrotech.com' => [
                ...self::WAREHOUSE, 'AccountResource', 'JournalDaybook', 'GeneralLedgerReport', 'TrialBalance',
            ],
            'mohamed.dabour@electrotech.com' => [
                ...self::WAREHOUSE, 'ItemResource', 'ItemCategoryResource', 'PurchaseOrderResource',
            ],
            'mohamed.abdelrahim@electrotech.com' => ['QualitySheetResource'],
            'ziad@electrotech.com' => ['ProductionEntryResource', 'ActiveProjectResource'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('staff:provision')->assertSuccessful();
    }

    private function staff(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /**
     * Several page requests in one test: a Livewire request leaves its own
     * redirector bound in the container, which the next plain request would
     * then hand to the session middleware. Each request starts clean.
     */
    private function page(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetInstance('redirect');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('redirect');

        return $this->get($url);
    }

    /** @return array<int, string> */
    private function accessibleScreens(): array
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $screens = [];

        foreach ($panel->getResources() as $resource) {
            if ($resource::canViewAny()) {
                $screens[] = class_basename($resource);
            }
        }

        foreach ($panel->getPages() as $page) {
            if (class_basename($page) !== 'Dashboard' && $page::canAccess()) {
                $screens[] = class_basename($page);
            }
        }

        sort($screens);

        return $screens;
    }

    public function test_the_command_creates_all_nine_accounts_with_their_roles(): void
    {
        foreach (ProvisionStaffUsers::STAFF as $email => $staff) {
            $user = User::query()->where('email', $email)->first();
            $this->assertNotNull($user, $email);
            $this->assertSame($staff['name'], $user->name);
            $this->assertEqualsCanonicalizing($staff['roles'], $user->getRoleNames()->all(), $email);
        }
    }

    public function test_every_account_sees_exactly_the_screens_on_its_row(): void
    {
        foreach (self::expectedScreens() as $email => $expected) {
            $this->staff($email);
            sort($expected);

            $this->assertSame($expected, $this->accessibleScreens(), "Screens for {$email}");
        }
    }

    /** @return array<string, array{0: string}> */
    public static function staffEmails(): array
    {
        return collect(self::expectedScreens())->keys()->mapWithKeys(fn (string $e) => [$e => [$e]])->all();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staffEmails')]
    public function test_every_account_opens_its_screens_and_is_refused_elsewhere(string $email): void
    {
        $expected = self::expectedScreens()[$email];
        $this->staff($email);
        $this->page('/admin')->assertOk();

        $panel = Filament::getPanel('admin');
        $all = collect($panel->getResources())->mapWithKeys(fn ($r) => [class_basename($r) => $r::getUrl('index', panel: 'admin')])
            ->merge(collect($panel->getPages())->reject(fn ($p) => class_basename($p) === 'Dashboard')
                ->mapWithKeys(fn ($p) => [class_basename($p) => $p::getUrl(panel: 'admin')]));

        foreach ($all as $screen => $url) {
            $status = $this->page($url)->status();
            in_array($screen, $expected, true)
                ? $this->assertSame(200, $status, "{$email} should open {$screen}")
                : $this->assertSame(403, $status, "{$email} should be refused {$screen}");
        }
    }

    public function test_sales_officers_cannot_give_the_manager_approval_but_the_sales_head_can(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InHand]);

        foreach (['raghda@electrotech.com', 'mariam.ibrahim@electrotech.com'] as $email) {
            $this->assertFalse($this->staff($email)->can('managerApprove', $project), $email);
            $this->assertTrue(auth()->user()->can('update', $project), $email);
        }

        $this->assertTrue($this->staff('afaf.fawzy@electrotech.com')->can('managerApprove', $project));
    }

    /** @return array<string, array{0: string}> */
    public static function viewOnlyStaff(): array
    {
        return ['ziad' => ['ziad@electrotech.com'], 'yassin' => ['yassin.mohamed@electrotech.com']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('viewOnlyStaff')]
    public function test_view_only_staff_open_an_active_operation_read_only_and_nothing_else(string $email): void
    {
        $active = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        $tender = Project::factory()->create(['status' => ProjectStatus::Tender]);

        $user = $this->staff($email);

        $this->assertSame(ProjectResource::getUrl('view', ['record' => $active]), ProjectResource::recordUrl($active));
        $this->page(ProjectResource::getUrl('view', ['record' => $active]))->assertOk();
        $this->page(ProjectResource::getUrl('edit', ['record' => $active]))->assertForbidden();
        $this->page(ProjectResource::getUrl('view', ['record' => $tender]))->assertForbidden();

        Livewire::test(ViewProject::class, ['record' => $active->getRouteKey()])
            ->assertActionHidden('edit')
            ->assertActionHidden('addAttachments');

        Livewire::test(ActiveProjectResource\Pages\ListActiveProjects::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$tender])
            ->assertTableActionHidden('hold', $active)
            ->assertTableActionHidden('complete', $active);

        $this->assertFalse($user->can('update', $active));
    }

    public function test_the_timeline_coordinator_adds_attachments_without_editing_the_project(): void
    {
        Storage::fake('public');
        Storage::fake('restricted');
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress, 'name' => 'Original name']);
        $user = $this->staff('asmaa.mohamed@electrotech.com');

        $this->assertFalse($user->can('update', $project));
        $this->page(ProjectResource::getUrl('edit', ['record' => $project]))->assertForbidden();
        $this->assertSame(ProjectResource::getUrl('view', ['record' => $project]), ProjectResource::recordUrl($project));

        Livewire::test(ProjectsTimeline::class)
            ->assertCanSeeTableRecords([$project])
            ->assertTableActionVisible('open', $project);

        $key = 'attachments_'.AttachmentCategory::PreliminaryDrawing->value;

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->assertActionHidden('edit')
            ->assertActionVisible('addAttachments')
            ->mountAction('addAttachments')
            ->setActionData([$key => [UploadedFile::fake()->create('drawing.pdf', 50)]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $attachment = $project->attachments()->sole();
        $this->assertSame(AttachmentCategory::PreliminaryDrawing->value, $attachment->category instanceof AttachmentCategory ? $attachment->category->value : $attachment->category);
        $this->assertSame($user->id, $attachment->uploaded_by);
        // The preliminary drawing is a restricted category: private disk only.
        Storage::disk('restricted')->assertExists($attachment->file_path);
        Storage::disk('public')->assertMissing($attachment->file_path);
        $this->assertSame('Original name', $project->fresh()->name);
    }

    public function test_adding_attachments_never_removes_the_papers_already_filed(): void
    {
        Storage::fake('public');
        Storage::fake('restricted');
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        $existing = \App\Models\Attachment::factory()->create([
            'project_id' => $project->id,
            'category' => AttachmentCategory::PreliminaryDrawing->value,
            'file_path' => 'attachments/'.$project->id.'/preliminary_drawing/old.pdf',
        ]);
        Storage::disk('restricted')->put($existing->file_path, 'x');
        $this->staff('asmaa.mohamed@electrotech.com');

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->mountAction('addAttachments')
            ->setActionData(['attachments_'.AttachmentCategory::PreliminaryDrawing->value => []])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertModelExists($existing);
        Storage::disk('restricted')->assertExists($existing->file_path);
    }

    public function test_a_role_changed_by_the_admin_takes_effect_at_once(): void
    {
        $user = $this->staff('ziad@electrotech.com');
        $role = \Spatie\Permission\Models\Role::findByName('Production_Viewer');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(\App\Filament\Resources\WorkOrderResource::canViewAny());

        $role->givePermissionTo('work_orders.view');
        $this->assertTrue($user->fresh()->can('work_orders.view'));
        $this->actingAs($user->fresh());
        $this->assertTrue(\App\Filament\Resources\WorkOrderResource::canViewAny());

        $role->revokePermissionTo('production_entries.view');
        $this->actingAs($user->fresh());
        $this->assertFalse(\App\Filament\Resources\ProductionEntryResource::canViewAny());
        $this->page(\App\Filament\Resources\ProductionEntryResource::getUrl('index', panel: 'admin'))->assertForbidden();
    }

    public function test_the_dashboard_shows_each_account_only_its_own_cards(): void
    {
        $cards = [
            'ziad@electrotech.com' => [__('Active Projects'), __('Active Manufacturing Orders')],
            'mohamed.abdelrahim@electrotech.com' => [__('Active Manufacturing Orders')],
            'sameh.magdy@electrotech.com' => [__('Pending Deliveries'), __('Low Stock Items')],
            'raghda@electrotech.com' => [__('Active Projects')],
        ];
        $all = [__('Active Projects'), __('Pending Deliveries'), __('Pending Purchase Orders'), __('Active Manufacturing Orders'), __('Low Stock Items')];

        foreach ($cards as $email => $visible) {
            $this->staff($email);
            $widget = Livewire::test(StatsOverview::class);

            foreach ($all as $card) {
                in_array($card, $visible, true) ? $widget->assertSee($card) : $widget->assertDontSee($card);
            }
        }
    }

    public function test_rerunning_the_command_never_touches_existing_accounts(): void
    {
        $user = User::query()->where('email', 'raghda@electrotech.com')->firstOrFail();
        $user->forceFill(['password' => Hash::make('changed-by-her'), 'name' => 'Raghda A.'])->save();
        $user->syncRoles(['Sales_Head']);

        $this->artisan('staff:provision')->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check('changed-by-her', $user->password));
        $this->assertSame('Raghda A.', $user->name);
        $this->assertSame(['Sales_Head'], $user->getRoleNames()->all());
        $this->assertSame(9, User::query()->where('email', 'like', '%@electrotech.com')->count());
    }

    public function test_the_role_seeder_on_deploy_keeps_roles_an_admin_has_changed(): void
    {
        $role = \Spatie\Permission\Models\Role::findByName('Sales_Officer');
        $role->givePermissionTo('projects.delete');

        $this->seed(\Database\Seeders\RoleAndPermissionSeeder::class);

        $this->assertTrue($role->fresh()->hasPermissionTo('projects.delete'));
    }
}
