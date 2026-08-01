<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected array $tempPermissions = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $permissions = [];

        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'permissions_')) {
                if (is_array($value)) {
                    $permissions = array_merge($permissions, $value);
                }
                unset($data[$key]);
            }
        }

        $this->tempPermissions = $permissions;

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->record->name !== 'Admin') {
            $this->record->syncPermissions($this->tempPermissions);
        }
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
