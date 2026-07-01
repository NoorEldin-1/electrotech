<?php

declare(strict_types=1);

namespace App\Filament\Resources\QualitySheetResource\Pages;

use App\Filament\Resources\QualitySheetResource;
use App\Models\QualitySheet;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewQualitySheet extends ViewRecord
{
    protected static string $resource = QualitySheetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Editing is only possible before the sheet is approved; once
            // approved the sheet is final and viewed read-only from here.
            Actions\EditAction::make()
                ->visible(fn (QualitySheet $record) => ! $record->isApproved()),

            Actions\ActionGroup::make([
                Actions\Action::make('print_ar')
                    ->label(__('resources.quality_sheets.actions.print_ar'))
                    ->icon('heroicon-o-printer')
                    ->url(fn (QualitySheet $record): string => route('quality_sheets.pdf', ['qualitySheet' => $record, 'lang' => 'ar']))
                    ->openUrlInNewTab(),
                Actions\Action::make('print_en')
                    ->label(__('resources.quality_sheets.actions.print_en'))
                    ->icon('heroicon-o-printer')
                    ->url(fn (QualitySheet $record): string => route('quality_sheets.pdf', ['qualitySheet' => $record, 'lang' => 'en']))
                    ->openUrlInNewTab(),
            ])
                ->label(__('resources.quality_sheets.actions.print'))
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->button()
                ->visible(fn (QualitySheet $record): bool => auth()->user()?->can('print', $record) ?? false),
        ];
    }
}
