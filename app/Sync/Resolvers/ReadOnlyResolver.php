<?php

declare(strict_types=1);

namespace App\Sync\Resolvers;

use App\Models\DeviceToken;

/**
 * Used for models that operators must not write from offline: Project,
 * Item, Inventory, Bom, BomItem. A push attempt on such a model is a
 * client bug or a misconfigured device; we reject it loudly so the
 * conflict log surfaces it for investigation.
 */
final class ReadOnlyResolver implements Resolver
{
    public function __construct(public readonly string $modelKey)
    {
    }

    public function resolve(array $operation, DeviceToken $token): ResolverResult
    {
        return ResolverResult::rejected(
            'illegal_transition',
            "Model '{$this->modelKey}' is read-only over the sync API. Pushes for this type are not accepted."
        );
    }
}
