<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\RendersFinancialStatement;
use App\Services\OperatingStatementService;
use Filament\Pages\Page;

/**
 * قائمة التشغيل — ماليات.pptx سلايد 2. The first of the four statements that
 * follow the trial balance: everything that makes up تكلفة المبيعات, each row
 * an account from the chart ("لاحظ كله حسابات مسجلة بشجرة الحسابات").
 *
 * Its single total is what قائمة الدخل subtracts from net sales.
 */
class OperatingStatement extends Page
{
    use RendersFinancialStatement;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.pages.operating-statement';

    protected static ?int $navigationSort = 64;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.operating_statement.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.operating_statement.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('operating_statement.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatement(): array
    {
        $this->freshBalances();

        return app(OperatingStatementService::class)->build($this->fromDate(), $this->toDate());
    }

    public function getPrintUrl(): string
    {
        return route('finance.statements.pdf', array_filter([
            'statement' => 'operating',
            'from' => $this->from,
            'to' => $this->to,
        ]));
    }
}
