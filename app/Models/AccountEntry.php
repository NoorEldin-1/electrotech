<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single posting in a party (Supplier/Customer) ledger. Append-only:
 * vouchers create these on post/activation; they are never edited.
 */
class AccountEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'party_type',
        'party_id',
        'entry_date',
        'direction',
        'amount',
        'reference_type',
        'reference_id',
        'operation_name',
        'project_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'direction' => AccountDirection::class,
            'amount' => 'decimal:2',
        ];
    }

    public function party(): MorphTo
    {
        return $this->morphTo('party');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Optional cost-center tag: the operation this party posting belongs to.
     * Supersedes the legacy free-text `operation_name`.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
