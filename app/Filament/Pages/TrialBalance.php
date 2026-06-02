<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\TrialBalanceService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ميزان المراجعة — Trial Balance report page (سلايد 4). Read-only aggregate of
 * all active accounts, grouped by currency, with an optional "as of" date.
 */
class TrialBalance extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static string $view = 'filament.pages.trial-balance';

    protected static ?int $navigationSort = 63;

    /** Bound to the "as of date" filter in the view. */
    public ?string $asOf = null;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.trial_balance.navigation_label');
    }

    public function getTitle(): string
    {
        return __('resources.trial_balance.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('trial_balance.view');
    }

    /**
     * Trial balance grouped by currency for the current "as of" filter.
     */
    public function getGroups(): Collection
    {
        $asOf = $this->asOf ? Carbon::parse($this->asOf) : null;

        return app(TrialBalanceService::class)->grouped($asOf);
    }
}
