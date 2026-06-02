<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstallationResource\Pages;

use App\Filament\Resources\InstallationResource;
use Filament\Resources\Pages\EditRecord;

class EditInstallation extends EditRecord
{
    protected static string $resource = InstallationResource::class;
}
