<?php

declare(strict_types=1);

namespace App\Http\Api;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Serializes a backed enum into the `{value, label, color}` shape the API
 * contract promises (API_Development_Plan.md §3.10).
 *
 * The client branches on `value` and renders `label`. `color` is the Filament
 * semantic colour name the panel already uses for that state — exposing it
 * means a status badge looks the same on mobile as on the web without anyone
 * maintaining a second colour table.
 *
 * The enums in app/Enums already implement Filament's HasLabel/HasColor for
 * the panel; nothing new had to be added to them for the API.
 */
final class EnumPresenter
{
    /**
     * @return array{value: string|int, label: string, color: string|null}|null
     */
    public static function present(?BackedEnum $enum): ?array
    {
        if ($enum === null) {
            return null;
        }

        return [
            'value' => $enum->value,
            'label' => $enum instanceof HasLabel
                ? (string) $enum->getLabel()
                : (string) $enum->value,
            'color' => $enum instanceof HasColor
                ? self::normalizeColor($enum->getColor())
                : null,
        ];
    }

    /**
     * Every case of an enum class, for the `/meta/enums` catalog that lets a
     * Flutter form build its dropdown from the server instead of hard-coding
     * options that drift when a new case is added.
     *
     * @param  class-string<BackedEnum>  $enumClass
     * @return list<array{value: string|int, label: string, color: string|null}>
     */
    public static function cases(string $enumClass): array
    {
        return array_map(
            static fn (BackedEnum $case): array => self::present($case),
            $enumClass::cases(),
        );
    }

    /**
     * Filament's getColor() may return a string ('success'), an array (a custom
     * OKLCH/HEX shade map), or null. Only the semantic string is portable to a
     * mobile client, so anything else degrades to null rather than shipping a
     * shade map the app cannot use.
     */
    private static function normalizeColor(string|array|null $color): ?string
    {
        return is_string($color) ? $color : null;
    }
}
