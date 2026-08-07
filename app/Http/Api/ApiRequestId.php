<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Support\Str;

/**
 * Holder for the current request's correlation id.
 *
 * Lives in a static rather than on the request object because the exception
 * renderer needs it in contexts where the request is not conveniently in
 * scope, and because ApiResponse is called from static context everywhere.
 * Reset per request by the ApiRequestId middleware; in the queue/CLI it simply
 * generates one on demand.
 */
final class ApiRequestId
{
    private static ?string $id = null;

    public static function set(string $id): void
    {
        self::$id = $id;
    }

    public static function current(): string
    {
        return self::$id ??= (string) Str::uuid();
    }

    /**
     * Used by tests to guarantee isolation between cases.
     */
    public static function forget(): void
    {
        self::$id = null;
    }
}
