<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\InventoryValuationService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * تقييم المخزون — the inventory valuation report (Final_Modifications سلايد 2).
 *
 * Total inventory value as the ledger holds it (accounts 1310 / 1320 / 1330 at
 * an "as of" date), set against the stock-card valuation of the matching
 * warehouses, with the difference spelled out. Read-only.
 */
class InventoryValuation extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static string $view = 'filament.pages.inventory-valuation';

    protected static ?int $navigationSort = 68;

    /** Bound to the "as of date" filter in the view. */
    public ?string $asOf = null;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.inventory_valuation.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.inventory_valuation.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('reports.inventory_valuation');
    }

    /**
     * The valuation for the current "as of" filter (blank = today's position).
     *
     * @return array<string, mixed>
     */
    public function getValuation(): array
    {
        return app(InventoryValuationService::class)->build($this->asOfDate());
    }

    private function asOfDate(): ?Carbon
    {
        if ($this->asOf === null || $this->asOf === '') {
            return null;
        }

        try {
            return Carbon::parse($this->asOf)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
