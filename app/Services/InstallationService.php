<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InstallationStatus;
use App\Models\Installation;
use DomainException;

/**
 * مرحلة التركيب — drives the installation stage (سلايد 2): pending → in_progress
 * → completed. Installation expenses are loaded onto the cost center via GL
 * (account 5020 tagged to the operation), not here.
 */
class InstallationService
{
    public function start(Installation $installation): void
    {
        $this->assertStatus($installation, [InstallationStatus::Pending]);

        $installation->update([
            'status' => InstallationStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    public function complete(Installation $installation): void
    {
        $this->assertStatus($installation, [InstallationStatus::InProgress]);

        $installation->update([
            'status' => InstallationStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, InstallationStatus>  $allowed
     */
    private function assertStatus(Installation $installation, array $allowed): void
    {
        if (! in_array($installation->status, $allowed, true)) {
            throw new DomainException(__('errors.operations.illegal_transition', [
                'current' => $installation->status->value,
                'allowed' => implode(', ', array_map(fn (InstallationStatus $s) => $s->value, $allowed)),
            ]));
        }
    }
}
