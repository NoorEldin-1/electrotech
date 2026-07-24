<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Account;
use App\Services\JournalDaybookService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * اليومية التحليلية — the analytical daybook (قائمة المواد.pptx سلايد 2).
 * Posted entries of a period laid out one per row, with a debit/credit column
 * pair for each selected account and the entry's own totals at the end.
 */
class JournalDaybook extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static string $view = 'filament.pages.journal-daybook';

    protected static ?int $navigationSort = 59;

    /** Period start — defaults to the first day of the current month. */
    public ?string $from = null;

    /** Period end — defaults to the last day of the current month. */
    public ?string $to = null;

    /** Accounts rendered as columns; empty = the period's busiest accounts. */
    public array $accountIds = [];

    public ?string $currency = 'EGP';

    public function mount(): void
    {
        $this->from ??= now()->startOfMonth()->toDateString();
        $this->to ??= now()->endOfMonth()->toDateString();
    }

    /**
     * Drop every hand-picked column and fall back to the period's busiest
     * accounts.
     */
    public function clearAccounts(): void
    {
        $this->accountIds = [];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.journal_daybook.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.journal_daybook.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('journal_daybook.view');
    }

    /**
     * The daybook for the current filters.
     *
     * @return array<string, mixed>
     */
    public function getDaybook(): array
    {
        return app(JournalDaybookService::class)->build(
            $this->from ? Carbon::parse($this->from) : null,
            $this->to ? Carbon::parse($this->to) : null,
            $this->accountIds,
            $this->currency ?: null,
        );
    }

    /**
     * Currencies actually in use, so the filter never offers an empty book.
     *
     * @return Collection<int, string>
     */
    public function getCurrencies(): Collection
    {
        return Account::query()
            ->select('currency')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency');
    }

    /**
     * Printable version of exactly what is on screen.
     */
    public function getPrintUrl(): string
    {
        return route('finance.daybook.pdf', array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'currency' => $this->currency,
            'accounts' => implode(',', $this->accountIds),
        ]));
    }
}
