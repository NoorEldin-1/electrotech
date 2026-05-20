<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A registered offline-capable client device.
 *
 * One user can have multiple devices (e.g. an operator using both their
 * factory-floor tablet and a backup phone). Each device has its own token,
 * its own outbox, and its own pull cursor.
 *
 * Token storage discipline:
 *   - The raw token is generated once at enrollment, returned to the
 *     client, and never persisted.
 *   - Only the SHA-256 hash sits in the DB. Comparing on hash means a DB
 *     leak does not yield usable tokens.
 *   - Revocation is a soft action (revoked_at) rather than a delete, so
 *     forensic queries against sync_operation_log still join correctly.
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'device_name',
        'token_hash',
        'last_used_at',
        'last_used_ip',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at'   => 'datetime',
        ];
    }

    /**
     * Hide the token hash from any accidental serialization. The raw
     * token is never stored, so this is defence-in-depth, not a fix.
     */
    protected $hidden = ['token_hash'];

    /**
     * Mint a new device token. Returns [DeviceToken, string $rawToken].
     * The raw token is the ONLY copy the caller will ever see.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, string $deviceId, ?string $deviceName = null): array
    {
        // 40 bytes -> 80 hex chars. Plenty of entropy; same order of
        // magnitude as Sanctum's default. We prepend a short prefix to
        // make accidental commits to source control catchable by simple
        // regex scanners.
        $raw = 'etk_' . Str::random(48);

        $token = static::create([
            'user_id'     => $user->id,
            'device_id'   => $deviceId,
            'device_name' => $deviceName,
            'token_hash'  => hash('sha256', $raw),
        ]);

        return [$token, $raw];
    }

    /**
     * Look up an active token by the raw bearer string. Returns null on
     * miss or revoked tokens — never throws — so the auth middleware can
     * decide how to respond.
     */
    public static function findByRawToken(string $raw): ?self
    {
        if ($raw === '' || ! str_starts_with($raw, 'etk_')) {
            return null;
        }

        return static::query()
            ->where('token_hash', hash('sha256', $raw))
            ->whereNull('revoked_at')
            ->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(SyncConflict::class);
    }

    public function operationLog(): HasMany
    {
        return $this->hasMany(SyncOperationLog::class);
    }

    public function touchUsage(?string $ip = null): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
        ])->saveQuietly();
    }
}
