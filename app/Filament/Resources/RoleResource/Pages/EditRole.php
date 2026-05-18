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
}
