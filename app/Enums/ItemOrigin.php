<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Where the item comes from (Origin): مستورد / محلي. */
enum ItemOrigin: string implements HasLabel
{
    case Imported = 'imported';
    case Local = 'local';

    public function getLabel(): string
    {
        return __('resources.enums.item_origin.' . $this->value);
    }
}
