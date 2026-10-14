<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalog group of items (فئة أصناف), e.g. «شرائط نحاس» or «جوانات».
 */
class ItemCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
