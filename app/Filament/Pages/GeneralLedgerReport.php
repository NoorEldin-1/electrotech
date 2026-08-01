<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\DefaultsToPeriodWithLedgerData;
use App\Models\Account;
use App\Services\GeneralLedgerService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * كشف حساب / دفتر الأستاذ — a single account's ledger for a period
 * (قائمة المواد.pptx سلايد 3): "حساب الخزينة — شهر يونيو" with an opening
 * balance, every posted movement carrying its entry serial, document number
 * and description, and a running balance recomputed line by line.
 */
class GeneralLedgerReport extends Page
{
    use DefaultsToPeriodWithLedgerData;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.general-ledger-report';

    protected static ?int $navigationSort = 62;

    /** The account whose ledger is shown; defaults to the first active one. */
    public ?int $accountId = null;

    public ?string $from = null;

    public ?string $to = null;

    public function mount(): void
    {
        [$from, $to] = $this->defaultLedgerPeriod();

        $this->from ??= $from;
        $this->to ??= $to;
        $this->accountId ??= $this->getAccounts()->first()?->id;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.general_ledger_report.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.general_ledger_report.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('general_ledger.view');
    }

    /**
     * @return Collection<int, Account>
     */
    public function getAccounts(): Collection
    {
        return Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->orderBy('name')
            ->get();
    }

    public function getAccount(): ?Account
    {
        return $this->accountId ? Account::find($this->accountId) : null;
    }

    /**
     * The ledger for the selected account and period: opening balance, rows
     * and period totals. Returns null when no account is selected.
     *
     * @return array{account: Account, opening: float, rows: Collection<int, array<string, mixed>>, totals: array{debit: float, credit: float}, closing: float}|null
     */
    public function getLedger(): ?array
    {
        $account = $this->getAccount();

        if (! $account) {
            return null;
        }

        $from = $this->from ? Carbon::parse($this->from) : null;
        $to = $this->to ? Carbon::parse($this->to) : null;

        $service = app(GeneralLedgerService::class);

        return [
            'account' => $account,
            'opening' => $service->openingBalance($account, $from),
            'rows' => $service->for($account, $from, $to),
            'totals' => $service->totals($account, $from, $to),
            'closing' => $service->closingBalance($account, $to),
        ];
    }

    public function getPrintUrl(): string
    {
        return route('finance.general_ledger.pdf', array_filter([
            'account' => $this->accountId,
            'from' => $this->from,
            'to' => $this->to,
        ]));
    }
}
