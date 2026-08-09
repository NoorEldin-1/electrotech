<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\RendersFinancialStatement;
use App\Services\IncomeStatementService;
use Filament\Pages\Page;

/**
 * قائمة الدخل — ماليات.pptx سلايد 3, rendered as the client's own table with
 * its two numeric columns (جزئى / كلى) and its three subtotals: مجمل الربح،
 * اجمالى الايرادات، صافى الربح.
 */
class IncomeStatement extends Page
{
    use RendersFinancialStatement;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string $view = 'filament.pages.income-statement';

    protected static ?int $navigationSort = 65;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.income_statement.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.income_statement.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('income_statement.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatement(): array
    {
        $this->freshBalances();

        return app(IncomeStatementService::class)->build($this->fromDate(), $this->toDate());
    }

    public function getPrintUrl(): string
    {
        return route('finance.statements.pdf', array_filter([
            'statement' => 'income',
            'from' => $this->from,
            'to' => $this->to,
        ]));
    }
}
