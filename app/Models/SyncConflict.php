<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncConflict extends Model
{
    protected $fillable = [
        'uuid',
        'model_type',
        'record_uuid',
        'device_token_id',
        'user_id',
        'reason',
        'server_version',
        'client_base_version',
        'client_payload',
        'server_state',
        'error_message',
        'resolved_at',
        'resolved_by',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'client_payload' => 'array',
            'server_state'   => 'array',
            'resolved_at'    => 'datetime',
        ];
    }

    public function deviceToken(): BelongsTo
    {
        return $this->belongsTo(DeviceToken::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
