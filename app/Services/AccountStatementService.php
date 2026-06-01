<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AccountStatementService
{
    /**
     * Build a party (Supplier/Customer) statement: every ledger entry ordered
     * by date with a running balance. Computed in PHP so it stays portable
     * across MySQL and SQLite.
     *
     * @return Collection<int, AccountEntry> each entry carries a
     *         `running_balance` attribute
     */
    public function for(Model $party): Collection
    {
        $entries = AccountEntry::query()
            ->where('party_type', $party->getMorphClass())
            ->where('party_id', $party->getKey())
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $running = 0.0;

        return $entries->map(function (AccountEntry $entry) use (&$running) {
            $running += (float) $entry->amount;
            $entry->setAttribute('running_balance', round($running, 2));

            return $entry;
        });
    }

    /**
     * The closing balance of a party's statement.
     */
    public function balanceFor(Model $party): float
    {
        return (float) AccountEntry::query()
            ->where('party_type', $party->getMorphClass())
            ->where('party_id', $party->getKey())
            ->sum('amount');
    }
}
