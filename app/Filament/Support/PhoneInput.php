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
     * The same input plus a uniqueness check against `$model`'s phone column
     * (E2E report §8: several customers and suppliers shared one number, so
     * one party's history was split across two files).
     *
     * Deliberately NOT Filament's `->unique()`: that compares the raw typed
     * string, while what we store is the normalised one (see normalize()). A
     * number typed in Arabic-Indic numerals, or with a stray leading space,
     * would therefore never match its own stored duplicate — the exact class
     * of user this input exists for. Normalising first makes the check mean
     * what it says.
     *
     * Soft-deleted rows are excluded by the model's global scope, so an
     * archived party never holds its number hostage.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    public static function unique(string $model, string $name = 'phone'): TextInput
    {
        return self::make($name)->rule(
            static fn (mixed $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($model, $record): void {
                if (blank($value)) {
                    return;
                }

                $query = $model::query()->where('phone', self::normalize((string) $value));

                // Only skip the record being edited — inside an inline
                // "create customer" form on another resource, $record is that
                // other resource's model and must not be matched by id.
                if ($record instanceof $model && $record->exists) {
                    $query->whereKeyNot($record->getKey());
                }

                if ($query->exists()) {
                    $fail(__('validation.unique', ['attribute' => __('validation.attributes.phone')]));
                }
            }
        );
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
