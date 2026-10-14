<?php

declare(strict_types=1);

namespace App\Imports\FactoryStock;

use App\Enums\ItemEdge;
use App\Enums\ItemMaterial;
use App\Enums\ItemOrigin;
use App\Enums\ItemTreatment;
use App\Enums\ItemType;
use App\Enums\ProductLine;
use App\Enums\UnitOfMeasure;

/**
 * Turns the factory-warehouse sheet («مخزن المصنع») into clean catalog items.
 *
 * Sheet columns: A م · B البيان · C التصنيف · D الصورة · E الطراز · F السمك ·
 * G العرض · H Mat. · I الطول · J Edge · K المعالجة · L الإستخدام · M Origin ·
 * N Source · O الكود · P الامبير · Q الأقطاب · R مقاس · S العدد · T ملاحظات.
 *
 * The sheet has no unit, category or price columns and its codes repeat or
 * are missing, so this class fills the gaps with explicit, documented rules.
 * Every correction is recorded as a warning on the item for the review report.
 */
final class RowNormalizer
{
    /** The «office samples» block is display stock, not warehouse stock. */
    private const SAMPLES_MARKER = 'عينات';

    /** Above this, a "length in metres" is really a total or a millimetre value. */
    private const MAX_PIECE_LENGTH_M = 50;

    private const COLORS = [
        'ازرق غامق' => 'أزرق غامق',
        'اسود' => 'أسود', 'أسود' => 'أسود',
        'احمر' => 'أحمر', 'أحمر' => 'أحمر',
        'اصفر' => 'أصفر', 'أصفر' => 'أصفر',
        'ازرق' => 'أزرق', 'أزرق' => 'أزرق',
        'ابيض' => 'أبيض', 'أبيض' => 'أبيض',
        'رصاصي' => 'رصاصي',
    ];

    /** @var list<array{row: int, reason: string}> */
    private array $skipped = [];

    /**
     * @param  list<array{row: int, cells: array<string, string|float|null>, formulas: array<string, string>, image: ?string}>  $rows
     * @return array{items: list<NormalizedItem>, skipped: list<array{row: int, reason: string}>}
     */
    public function normalize(array $rows): array
    {
        $this->skipped = [];
        $items = [];
        $inSamples = false;
        $samplesSeen = 0;

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $first = $this->text($cells['A'] ?? null);
            $name = $this->text($cells['B'] ?? null);

            // The samples block has its own header row right under its title,
            // so only a header *after* sample rows ends the block.
            if ($this->isHeader($cells)) {
                if ($samplesSeen > 0) {
                    $inSamples = false;
                }

                continue;
            }

            if ($first !== null && str_starts_with($first, self::SAMPLES_MARKER) && $name === null) {
                $inSamples = true;
                $this->skipped[] = ['row' => $row['row'], 'reason' => 'عنوان قسم عينات المكتب'];

                continue;
            }

            if ($name === null) {
                continue;
            }

            if ($inSamples) {
                $samplesSeen++;
                $this->skipped[]= ['row' => $row['row'], 'reason' => "عينة داخل المكتب — ليست مخزونًا ({$name})"];

                continue;
            }

            $items[] = $this->item($row);
        }

        $this->assignSkus($items);

        return ['items' => $items, 'skipped' => $this->skipped];
    }

    /**
     * @param  array{row: int, cells: array<string, string|float|null>, formulas: array<string, string>, image: ?string}  $row
     */
    private function item(array $row): NormalizedItem
    {
        $c = $row['cells'];
        $warnings = [];
        $notes = [];

        $name = $this->text($c['B']);
        $type = $this->text($c['C'] ?? null) === 'بضاعة جاهزة' ? ItemType::FinishedGood : ItemType::RawMaterial;
        $productLine = ProductLine::tryFrom(strtolower((string) $this->text($c['E'] ?? null)));
        $material = ItemMaterial::tryFrom(strtolower((string) $this->text($c['H'] ?? null)));
        $origin = match ($this->text($c['M'] ?? null)) {
            'مستورد' => ItemOrigin::Imported,
            'محلي' => ItemOrigin::Local,
            default => null,
        };
        $brand = $this->text($c['N'] ?? null);

        [$widthMm, $sizeCode] = $this->widthOrSize($c['G'] ?? null);

        $treatmentText = $this->text($c['K'] ?? null);
        $treatment = match ($treatmentText) {
            'بدون' => ItemTreatment::None,
            'مدهون' => ItemTreatment::Painted,
            'منحس' => ItemTreatment::CopperCoated,
            'مقصدر' => ItemTreatment::Tinned,
            default => null,
        };
        if ($treatmentText !== null && $treatment === null) {
            $notes[] = "المعالجة في الشيت: {$treatmentText}";
            $warnings[] = "قيمة «{$treatmentText}» في عمود المعالجة ليست معالجة — نُقلت للوصف";
        }

        $unit = $this->unitFor($name);
        $lengthM = $this->length($row, $name, $warnings, $notes);

        $quantity = $c['S'] ?? null;
        if (! is_float($quantity)) {
            $warnings[] = 'العدد فارغ — رصيد افتتاحي 0';
            $quantity = 0.0;
        } elseif ($quantity < 0) {
            $warnings[] = "رصيد سالب ({$this->number($quantity)}) — رصيد افتتاحي 0";
            $quantity = 0.0;
        }

        $note = $this->text($c['T'] ?? null);
        if ($note !== null && ! $this->repeatsSizeCode($note, $sizeCode)) {
            array_unshift($notes, $note);
        }

        $code = $this->text($c['O'] ?? null);

        $item = new NormalizedItem(
            row: $row['row'],
            sku: (string) $code,
            partNumber: $code,
            name: $name,
            category: $this->categoryFor($name, $type, $productLine, $material, $brand),
            type: $type,
            unit: $unit,
            quantity: round($quantity, 4),
            productLine: $productLine,
            material: $material,
            thicknessMm: is_float($c['F'] ?? null) ? $c['F'] : null,
            widthMm: $widthMm,
            sizeCode: $sizeCode,
            lengthM: $lengthM,
            edge: match ($this->text($c['J'] ?? null)) {
                'R' => ItemEdge::Rounded,
                'F' => ItemEdge::Flat,
                default => null,
            },
            treatment: $treatment,
            origin: $origin,
            brand: $brand,
            usage: $this->text($c['L'] ?? null),
            ampereRating: $this->text($c['P'] ?? null),
            poles: is_float($c['Q'] ?? null) ? (int) $c['Q'] : null,
            size: $this->text($c['R'] ?? null),
            color: $this->colorIn($note),
            description: $notes === [] ? null : implode(' — ', $notes),
            imageEntry: $row['image'],
        );
        $item->warnings = $warnings;

        return $item;
    }

    /**
     * Codes must be unique in the system. Missing codes get a stable code
     * derived from the sheet row; a repeated code keeps its first row and the
     * repeats get "-2", "-3"… The sheet's own code is always kept as
     * part_number, so searching by it still finds every row.
     *
     * @param  list<NormalizedItem>  $items
     */
    private function assignSkus(array $items): void
    {
        $seen = [];

        foreach ($items as $item) {
            if ($item->partNumber === null) {
                $item->sku = sprintf('FS-%04d', $item->row);
                $item->warnings[] = "لا يوجد كود — أُعطي الكود {$item->sku}";

                continue;
            }

            $key = mb_strtolower($item->partNumber);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            if ($seen[$key] > 1) {
                $item->sku = "{$item->partNumber}-{$seen[$key]}";
                $item->warnings[] = "الكود «{$item->partNumber}» مكرر — أُعطي الكود {$item->sku}";
            }
        }
    }

    /**
     * Ordered rules reproducing the sheet's sections (the sheet itself has no
     * category column). First match wins.
     */
    private function categoryFor(string $name, ItemType $type, ?ProductLine $line, ?ItemMaterial $material, ?string $brand): string
    {
        $has = static function (string ...$needles) use ($name): bool {
            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    return true;
                }
            }

            return false;
        };

        return match (true) {
            $type === ItemType::FinishedGood && $has('مونوبلوك') => 'مونوبلوك مستورد',
            $type === ItemType::FinishedGood => 'بضاعة جاهزة DKC',
            $line === ProductLine::DT => 'مكونات DT',
            str_starts_with($name, 'شريط') && $material === ItemMaterial::Copper => 'شرائط نحاس',
            str_starts_with($name, 'شريط') => 'شرائط ألومنيوم',
            $brand === 'Zucchini' => 'عوازل وخامات مستوردة',
            str_starts_with($name, 'جسم') || str_starts_with($name, 'قطاع') => 'قطاعات وأجسام ألومنيوم',
            $has('كوع') => 'أكواع وزوايا وأغطية أكواع',
            $has('جوان') => 'جوانات',
            $has('غطاء وصله', 'غطاء وصلة', 'وحدة نهاية') => 'أغطية وصلات ووحدات نهاية',
            $has('تكسيحة', 'ماسك نحاس') => 'تكسيحات أرضي ومواسك نحاس',
            $has('بوابة', 'بلاكة', 'ريشة', 'مجموعة نحاس', 'فردة شريحة') => 'بوابات وبلاكات وريش المخارج',
            $has('علبة مخرج', 'لوحة عدادات') => 'علب مخرج ولوحات عدادات',
            $has('برشام', 'مسمار تجميع') => 'برشام ومسامير',
            $has('مونوبلوك', 'وردة سوستة') => 'مستلزمات مونوبلوك',
            str_starts_with($name, 'عازل') => 'عوازل بارة',
            $has('فلكسيبل', 'فلكسبل', 'لوح نحاس', 'سلك شعر') => 'شرائح فلكسيبل وألواح نحاس',
            $has('كابل', 'CCA') => 'كابلات وشرائح CCA',
            $has('لحام') => 'سلك لحام',
            $has('ريكم') => 'ريكم',
            $has('استانلس') => 'ألواح استانلس',
            default => 'متنوعات',
        };
    }

    /**
     * The sheet has no unit column; the unit follows from how the item is
     * named and counted.
     */
    private function unitFor(string $name): UnitOfMeasure
    {
        return match (true) {
            str_starts_with($name, 'متر') => UnitOfMeasure::Meter,
            str_starts_with($name, 'ريكم') => UnitOfMeasure::Meter,
            str_starts_with($name, 'كابل') => UnitOfMeasure::Meter,
            str_starts_with($name, 'لفة') => UnitOfMeasure::Roll,
            str_starts_with($name, 'لوح') => UnitOfMeasure::Sheet,
            str_contains($name, 'سلك لحام') => UnitOfMeasure::Kilogram,
            default => UnitOfMeasure::Piece,
        };
    }

    /**
     * Per-piece length in metres. A formula in the length column (=S315,
     * =1.2*S347) holds the row's *total* length, not a piece length; sticks
     * («عود ريكم … 1.2 م») carry their piece length in the name instead.
     *
     * @param  array{row: int, cells: array<string, string|float|null>, formulas: array<string, string>, image: ?string}  $row
     * @param  list<string>  $warnings
     * @param  list<string>  $notes
     */
    private function length(array $row, string $name, array &$warnings, array &$notes): ?float
    {
        $value = $row['cells']['I'] ?? null;

        if (isset($row['formulas']['I'])) {
            return preg_match('/(\d+(?:\.\d+)?)\s*م$/u', $name, $m) ? (float) $m[1] : null;
        }

        if (is_string($value)) {
            $notes[] = "الطول: {$value} م";

            return null;
        }

        if ($value === null || $value <= 0) {
            return null;
        }

        // Metre-counted rows («متر طولى …») repeat the quantity here.
        if ($value === ($row['cells']['S'] ?? null)) {
            return null;
        }

        if ($value > self::MAX_PIECE_LENGTH_M) {
            $warnings[] = "الطول {$this->number($value)} غير منطقي كمتر للقطعة — لم يُسجل";

            return null;
        }

        return $value;
    }

    /**
     * Column G holds either a width in mm (100) or a busway size code (B60, 2B200, All).
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function widthOrSize(string|float|null $value): array
    {
        if (is_float($value)) {
            return [$value, null];
        }

        $text = $this->text($value);

        return $text === null ? [null, null] : [null, $text];
    }

    /**
     * Many notes just restate the busway size («b 100», «25» next to B25).
     */
    private function repeatsSizeCode(string $note, ?string $sizeCode): bool
    {
        if ($sizeCode === null) {
            return false;
        }

        $note = mb_strtolower(str_replace(' ', '', $note));
        $code = mb_strtolower($sizeCode);

        return $note === $code || 'b'.$note === $code;
    }

    private function colorIn(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        foreach (self::COLORS as $needle => $color) {
            if (str_contains($note, $needle)) {
                return $color;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|float|null>  $cells
     */
    private function isHeader(array $cells): bool
    {
        return ($this->text($cells['A'] ?? null) === 'م')
            || str_starts_with((string) $this->text($cells['B'] ?? null), 'البي');
    }

    /**
     * Trimmed text with collapsed whitespace; numbers without a trailing ".0".
     */
    private function text(string|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_float($value) ? $this->number($value) : preg_replace('/\s+/u', ' ', trim($value));

        return $text === '' ? null : $text;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
