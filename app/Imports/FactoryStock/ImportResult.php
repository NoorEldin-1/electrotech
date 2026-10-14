<?php

declare(strict_types=1);

namespace App\Imports\FactoryStock;

/**
 * What an import did (or, in a dry run, would do).
 */
final class ImportResult
{
    /** @var list<array{item: NormalizedItem, action: string, stock: string, image: bool}> */
    public array $rows = [];

    /** @var list<array{id: int, sku: string, name: string, on_hand: float, action: string}> */
    public array $purged = [];

    /**
     * @param  list<array{row: int, reason: string}>  $skipped
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly array $skipped,
    ) {}

    /**
     * @return array<string, int|float>
     */
    public function summary(): array
    {
        $rows = collect($this->rows);
        $purged = collect($this->purged);

        return [
            'items' => $rows->count(),
            'created' => $rows->where('action', 'created')->count(),
            'updated' => $rows->where('action', 'updated')->count(),
            'opening_balances' => $rows->where('stock', 'opening')->count(),
            'opening_quantity' => round($rows->where('stock', 'opening')->sum(fn (array $r) => $r['item']->quantity), 4),
            'with_image' => $rows->where('image', true)->count(),
            'with_warnings' => $rows->filter(fn (array $r) => $r['item']->warnings !== [])->count(),
            'skipped_rows' => count($this->skipped),
            'legacy_deleted' => $purged->where('action', 'deleted')->count(),
            'legacy_retired' => $purged->where('action', 'retired')->count(),
        ];
    }
}
