<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\RendersFinancialStatement;
use App\Services\BalanceSheetService;
use Filament\Pages\Page;

/**
 * قائمة المركز المالى — ماليات.pptx سلايدات 4، 5، 6.
 *
 * A position statement, so the figure that matters is the "as of" date — the
 * `to` end of the period filter (31 ديسمبر …). `from` still matters, because
 * أرباح الفترة inside equity is the income statement's net profit for that
 * same window.
 */
class BalanceSheet extends Page
{
    use RendersFinancialStatement;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static string $view = 'filament.pages.balance-sheet';

    protected static ?int $navigationSort = 66;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.balance_sheet.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.balance_sheet.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('balance_sheet.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatement(): array
    {
        $this->freshBalances();

        return app(BalanceSheetService::class)->build($this->toDate(), $this->fromDate());
    }

    public function getPrintUrl(): string
    {
        return route('finance.statements.pdf', array_filter([
            'statement' => 'balance_sheet',
            'from' => $this->from,
            'to' => $this->to,
        ]));
    }
}
