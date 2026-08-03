<?php
declare(strict_types=1);
namespace App\Filament\Resources\WorkOrderResource\Pages;
use App\Filament\Resources\WorkOrderResource;
use Filament\Resources\Pages\CreateRecord;
class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    /**
     * The order-level plan is DERIVED from the finished-product lines, and the
     * lines are saved after the order itself (they are a relationship), so the
     * derivation has to run once they exist. Doing it server-side rather than
     * trusting the disabled form field means a tampered payload cannot leave
     * the order with a planned quantity its products do not add up to.
     */
    protected function afterCreate(): void
    {
        $this->record->syncDerivedPlan();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
