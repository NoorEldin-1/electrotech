<?php

declare(strict_types=1);

namespace App\Imports\FactoryStock;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the first worksheet of an .xlsx straight from its XML, including the
 * photos Excel stores *inside* cells ("Place in cell" / richData).
 *
 * Off-the-shelf readers (openspout, PhpSpreadsheet, openpyxl) see such a cell
 * as `#VALUE!`; the picture is only reachable through the chain
 *   cell vm="N" → metadata.xml valueMetadata → futureMetadata rvb
 *   → rdrichvalue.xml (first value = relationship index)
 *   → richValueRel.xml r:id → richValueRel.xml.rels Target (xl/media/…).
 *
 * Formula cells yield their cached value (what Excel last calculated).
 */
final class WorkbookReader
{
    private ZipArchive $zip;

    /** @var list<string> */
    private array $sharedStrings = [];

    /** @var array<int, string> vm index (1-based) => zip entry of the image */
    private array $imagesByValueMetadata = [];

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive();

        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Cannot open workbook: {$path}");
        }

        $this->sharedStrings = $this->readSharedStrings();
        $this->imagesByValueMetadata = $this->readInCellImages();
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    /**
     * Every non-empty row of the first sheet, in order.
     *
     * @return list<array{row: int, cells: array<string, string|float|null>, formulas: array<string, string>, image: ?string}>
     *   `cells` is keyed by column letter; `image` is the zip entry of the
     *   row's in-cell photo, if any.
     */
    public function rows(): array
    {
        $sheet = $this->xml('xl/worksheets/sheet1.xml');
        $rows = [];

        foreach ($sheet->sheetData->row as $rowNode) {
            $rowNumber = (int) $rowNode['r'];
            $cells = [];
            $formulas = [];
            $image = null;

            foreach ($rowNode->c as $cell) {
                $column = preg_replace('/\d+/', '', (string) $cell['r']);

                if (isset($cell['vm'])) {
                    $image ??= $this->imagesByValueMetadata[(int) $cell['vm']] ?? null;

                    continue;
                }

                if (isset($cell->f)) {
                    // A shared formula is spelled out only on its first cell.
                    $formulas[$column] = (string) $cell->f !== '' ? (string) $cell->f : '(shared)';
                }

                $value = $this->cellValue($cell);

                if ($value !== null && $value !== '') {
                    $cells[$column] = $value;
                }
            }

            if ($cells !== [] || $image !== null) {
                $rows[] = ['row' => $rowNumber, 'cells' => $cells, 'formulas' => $formulas, 'image' => $image];
            }
        }

        return $rows;
    }

    /**
     * Raw bytes of an image entry returned by rows().
     */
    public function imageBytes(string $entry): string
    {
        $bytes = $this->zip->getFromName($entry);

        if ($bytes === false) {
            throw new RuntimeException("Missing image in workbook: {$entry}");
        }

        return $bytes;
    }

    private function cellValue(SimpleXMLElement $cell): string|float|null
    {
        $type = (string) $cell['t'];

        return match ($type) {
            's' => $this->sharedStrings[(int) $cell->v] ?? null,
            'inlineStr' => $this->text($cell->is),
            'str' => isset($cell->v) ? (string) $cell->v : null,
            'e' => null,
            'b' => isset($cell->v) ? (float) $cell->v : null,
            default => isset($cell->v) && (string) $cell->v !== '' ? (float) (string) $cell->v : null,
        };
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(): array
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $strings = [];

        foreach ($this->xml('xl/sharedStrings.xml')->si as $si) {
            $strings[] = $this->text($si);
        }

        return $strings;
    }

    /**
     * Plain text of a string item, joining rich-text runs.
     */
    private function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }

        if (isset($node->t)) {
            return (string) $node->t;
        }

        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /**
     * @return array<int, string>
     */
    private function readInCellImages(): array
    {
        $required = [
            'xl/metadata.xml',
            'xl/richData/rdrichvalue.xml',
            'xl/richData/richValueRel.xml',
            'xl/richData/_rels/richValueRel.xml.rels',
        ];

        foreach ($required as $entry) {
            if ($this->zip->locateName($entry) === false) {
                return [];
            }
        }

        $metadata = $this->zip->getFromName('xl/metadata.xml');

        // futureMetadata "XLRICHVALUE": bk index => rich value index.
        preg_match_all('/<xlrd:rvb i="(\d+)"\s*\/>/', $metadata, $rvb);
        $richValueByFuture = array_map('intval', $rvb[1]);

        // valueMetadata: bk index (vm - 1) => futureMetadata bk index.
        $valueMetadata = substr($metadata, (int) strpos($metadata, '<valueMetadata'));
        preg_match_all('/<bk><rc t="\d+" v="(\d+)"\s*\/><\/bk>/', $valueMetadata, $rc);

        $richValues = [];
        foreach ($this->xml('xl/richData/rdrichvalue.xml')->rv as $rv) {
            $richValues[] = (int) $rv->v[0];
        }

        $relIds = [];
        $rels = $this->xml('xl/richData/richValueRel.xml');
        foreach ($rels->children() as $rel) {
            $relIds[] = (string) $rel->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        }

        $targets = [];
        foreach ($this->xml('xl/richData/_rels/richValueRel.xml.rels')->Relationship as $relationship) {
            $targets[(string) $relationship['Id']] = 'xl/'.ltrim(str_replace('../', '', (string) $relationship['Target']), '/');
        }

        $images = [];
        foreach ($rc[1] as $index => $futureIndex) {
            $richValue = $richValueByFuture[(int) $futureIndex] ?? null;
            $relIndex = $richValue !== null ? ($richValues[$richValue] ?? null) : null;
            $relId = $relIndex !== null ? ($relIds[$relIndex] ?? null) : null;

            if ($relId !== null && isset($targets[$relId])) {
                $images[$index + 1] = $targets[$relId];
            }
        }

        return $images;
    }

    private function xml(string $entry): SimpleXMLElement
    {
        $content = $this->zip->getFromName($entry);

        if ($content === false) {
            throw new RuntimeException("Workbook part not found: {$entry}");
        }

        $xml = simplexml_load_string($content);

        if ($xml === false) {
            throw new RuntimeException("Workbook part is not valid XML: {$entry}");
        }

        return $xml;
    }
}
