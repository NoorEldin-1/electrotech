<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory;
    use HasRoles;
    use LogsActivity;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Determines if the user can access the Filament admin panel.
     * All authenticated users with any role can access; panel-level
     * restrictions are handled via Spatie permissions and Filament policies.
     *
     * Reads from the already-loaded `roles` relation when available, falls
     * back to Spatie's Redis-cached role registrar. Avoids the per-request
     * `SELECT EXISTS (...)` query that `roles()->exists()` would issue.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->isNotEmpty();
        }

        // getRoleNames() reads from Spatie's permission cache (Redis-backed
        // per config/permission.php), which is populated on first hit and
        // invalidated by RoleObserver/PermissionObserver.
        return $this->getRoleNames()->isNotEmpty();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
