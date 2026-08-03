<?php
declare(strict_types=1);
namespace App\Filament\Resources\WorkOrderResource\Pages;
use App\Filament\Resources\WorkOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
class EditWorkOrder extends EditRecord
{
    protected static string $resource = WorkOrderResource::class;
    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * Same as on create: planned quantity, primary product and the estimate are
     * re-derived from the saved product and material lines (see
     * WorkOrder::syncDerivedPlan).
     */
    protected function afterSave(): void
    {
        $this->record->syncDerivedPlan();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
