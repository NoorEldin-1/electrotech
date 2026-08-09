<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\RendersFinancialStatement;
use App\Services\CashFlowStatementService;
use Filament\Pages\Page;

/**
 * قائمة التدفقات النقدية — ماليات.pptx سلايدات 8 و 9. Starts from net profit
 * taken out of قائمة الدخل, adjusts it, and ends by checking the derived
 * closing cash against the cash the ledger actually holds ("ويتم مطابقته مع
 * الواقع").
 */
class CashFlowStatement extends Page
{
    use RendersFinancialStatement;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string $view = 'filament.pages.cash-flow-statement';

    protected static ?int $navigationSort = 67;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.cash_flow_statement.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.cash_flow_statement.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('cash_flow_statement.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatement(): array
    {
        $this->freshBalances();

        return app(CashFlowStatementService::class)->build($this->fromDate(), $this->toDate());
    }

    public function getPrintUrl(): string
    {
        return route('finance.statements.pdf', array_filter([
            'statement' => 'cash_flow',
            'from' => $this->from,
            'to' => $this->to,
        ]));
    }
}
