<?php
declare(strict_types=1);
namespace App\Filament\Resources\BomResource\Pages;
use App\Filament\Resources\BomResource;
use Filament\Resources\Pages\CreateRecord;
class CreateBom extends CreateRecord
{
    protected static string $resource = BomResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
