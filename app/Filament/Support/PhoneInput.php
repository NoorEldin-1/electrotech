<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Filament\Forms\Components\TextInput;

/**
 * Phone input that tolerates how Egyptian users actually type a number.
 *
 * Filament's ->tel() silently attaches a strict, ASCII-only regex rule
 * (/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/). That regex rejects
 * Arabic-Indic numerals (٠-٩) and Persian numerals (۰-۹) — the digits a phone
 * typed on an Arabic keyboard produces — and also a stray leading space, which
 * is what surfaced the raw "validation.regex" error on the supplier form
 * (المشتريات feedback). The failure was intermittent: it only bit users typing
 * in Arabic numerals, never a tester typing ASCII.
 *
 * This input keeps the `tel` keyboard hint but drops the brittle format regex.
 * Instead it normalises Arabic/Persian numerals to ASCII and trims on dehydrate
 * so the stored value is clean, and validates leniently — a filled value must
 * carry at least a handful of digits (checked against the normalised value, so
 * Arabic numerals count).
 *
 * Reuse everywhere a phone is entered (suppliers, customers, PO/project contact)
 * so the behaviour stays identical across the app.
 */
final class PhoneInput
{
    /** Minimum number of digits a filled phone must contain. */
    private const MIN_DIGITS = 6;

    public static function make(string $name = 'phone'): TextInput
    {
        return TextInput::make($name)
            ->type('tel')
            ->maxLength(50)
            ->dehydrateStateUsing(static fn (?string $state): ?string => self::normalize($state))
            ->rule(static function (): Closure {
                return static function (string $attribute, mixed $value, Closure $fail): void {
                    if (blank($value)) {
                        return;
                    }

                    $digits = preg_replace('/\D/', '', (string) self::normalize((string) $value));

                    if (mb_strlen((string) $digits) < self::MIN_DIGITS) {
                        $fail(__('validation.phone'));
                    }
                };
            });
    }

    /**
     * Convert Arabic-Indic (٠-٩) and Persian (۰-۹) numerals to ASCII digits and
     * trim surrounding whitespace. A null value passes through untouched.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return trim($value);
    }
}
