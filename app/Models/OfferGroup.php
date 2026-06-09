<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConductorType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One table within an offer (Slide 8: "Bi-Metal Offer", "Copper Offer", …).
 * An offer can carry several so a single quotation prices more than one
 * conductor / section side by side. subtotal is the sum of its line items.
 */
class OfferGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_offer_id',
        'label',
        'conductor_type',
        'subtotal',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'conductor_type' => ConductorType::class,
            'subtotal' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ProjectOffer::class, 'project_offer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('sort_order');
    }
}
