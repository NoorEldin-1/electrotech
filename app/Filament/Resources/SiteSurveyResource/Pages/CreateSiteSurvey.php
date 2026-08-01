<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteSurveyResource\Pages;

use App\Filament\Resources\SiteSurveyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSiteSurvey extends CreateRecord
{
    protected static string $resource = SiteSurveyResource::class;

    /**
     * Back to the list after saving — the platform-wide rule (E2E report
     * §5.3): Filament's default lands a create on the record's Edit page,
     * which made "what happens after save" differ from module to module.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
