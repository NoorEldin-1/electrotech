<?php

declare(strict_types=1);

namespace App\Filament\Resources\IssueVoucherResource\Pages;

use App\Filament\Resources\IssueVoucherResource;
use App\Models\IssueVoucher;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIssueVoucher extends EditRecord
{
    protected static string $resource = IssueVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The very same configured action the list row uses (over-issue
            // gate, excess report, approval reason) — declared once in the
            // resource so the two entry points cannot enforce different rules.
            //
            // The redirect is conditional on the post having actually gone
            // through: a voucher stopped at the excess gate must stay open on
            // its lines so the user can correct them.
            IssueVoucherResource::headerPostAction()
                ->after(fn (IssueVoucher $record) => $record->fresh()?->isPosted()
                    ? $this->redirect(static::getResource()::getUrl('index'))
                    : null),
            Actions\DeleteAction::make()
                ->visible(fn (IssueVoucher $record) => ! $record->isPosted()),
        ];
    }

    /**
     * Back to the list after saving — the platform-wide rule (E2E report
     * §5.3), matching what the Create page does, so "what happens after
     * save" no longer differs from module to module.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
