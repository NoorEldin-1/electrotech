<?php

declare(strict_types=1);

namespace App\Imports\FactoryStock;

use App\Models\Item;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes an import result as an Arabic review workbook:
 * every item with its final code, category, unit, opening balance and the
 * corrections made, plus the skipped rows and the legacy items removed.
 */
final class ReviewReport
{
    private const ACTIONS = [
        'created' => 'جديد',
        'updated' => 'تحديث',
        'deleted' => 'حذف نهائي (غير مستخدم)',
        'retired' => 'إخفاء (مستخدم في مستندات قديمة)',
    ];

    private const STOCK = [
        'opening' => 'رصيد افتتاحي',
        'kept' => 'لم يُضف (له حركات سابقة)',
        'none' => '—',
    ];

    public function write(ImportResult $result, string $path): void
    {
        // The report is for the warehouse team: Arabic labels whatever the
        // CLI locale is.
        $locale = app()->getLocale();
        app()->setLocale('ar');

        try {
            $this->writeWorkbook($result, $path);
        } finally {
            app()->setLocale($locale);
        }
    }

    private function writeWorkbook(ImportResult $result, string $path): void
    {
        $writer = new Writer();
        $writer->openToFile($path);
        $header = (new Style())->setFontBold();

        $this->sheet($writer, 'الأصناف', true);
        $writer->addRow(Row::fromValues([
            'صف الشيت', 'الكود في النظام', 'الكود الأصلي', 'الاسم', 'المواصفات', 'الفئة', 'النوع',
            'الوحدة', 'الرصيد', 'الرصيد الافتتاحي', 'الإجراء', 'صورة', 'ملاحظات التصحيح',
        ], $header));

        foreach ($result->rows as $entry) {
            /** @var NormalizedItem $item */
            $item = $entry['item'];
            $spec = (new Item($item->attributes()))->spec_summary;

            $writer->addRow(Row::fromValues([
                $item->row,
                $item->sku,
                (string) $item->partNumber,
                $item->name,
                $spec,
                $item->category,
                $item->type->getLabel(),
                $item->unit->getLabel(),
                $item->quantity,
                self::STOCK[$entry['stock']],
                self::ACTIONS[$entry['action']],
                $entry['image'] ? 'نعم' : 'لا',
                implode(' ؛ ', $item->warnings),
            ]));
        }

        $this->sheet($writer, 'صفوف لم تُستورد');
        $writer->addRow(Row::fromValues(['صف الشيت', 'السبب'], $header));
        foreach ($result->skipped as $skip) {
            $writer->addRow(Row::fromValues([$skip['row'], $skip['reason']]));
        }

        $this->sheet($writer, 'الأصناف القديمة');
        $writer->addRow(Row::fromValues(['المعرف', 'الكود', 'الاسم', 'الرصيد', 'الإجراء'], $header));
        foreach ($result->purged as $legacy) {
            $writer->addRow(Row::fromValues([
                $legacy['id'], $legacy['sku'], $legacy['name'], $legacy['on_hand'], self::ACTIONS[$legacy['action']],
            ]));
        }

        $this->sheet($writer, 'ملخص');
        foreach ($result->summary() as $key => $value) {
            $writer->addRow(Row::fromValues([$key, $value]));
        }

        $writer->close();
    }

    private function sheet(Writer $writer, string $name, bool $first = false): void
    {
        $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
    }
}
