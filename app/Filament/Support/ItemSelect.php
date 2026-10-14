<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Item;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * One configuration for every item picker in the app.
 *
 * The factory-warehouse catalog has dozens of items sharing a generic name
 * («شريط» × 80), so a picker that lists `name` alone is unusable. Each option
 * shows `Item::display_label` (code — name (specs)), and typing runs the
 * multi-word `Item::search` scope across codes, name and sizes, so
 * «شريط 100» narrows to the 100 mm busbars.
 */
final class ItemSelect
{
    private const RESULTS_LIMIT = 50;

    /**
     * @param  string  $relationship  the BelongsTo relationship on the form's model
     * @param  (Closure(Builder): Builder)|null  $modifyQuery  extra constraints (type, is_scrap, …)
     */
    public static function configure(Select $select, string $relationship = 'item', ?Closure $modifyQuery = null): Select
    {
        return $select
            ->relationship($relationship, 'name', $modifyQuery)
            ->getOptionLabelFromRecordUsing(fn (Item $record): string => $record->display_label)
            ->searchable()
            ->getSearchResultsUsing(fn (?string $search): array => static::search($search, $modifyQuery))
            ->preload()
            ->optionsLimit(self::RESULTS_LIMIT);
    }

    /**
     * Options for a picker that builds its own option list (no relationship),
     * keyed by item id and labelled with the display label.
     *
     * @return array<int, string>
     */
    public static function options(Builder $query): array
    {
        return $query->orderBy('sku')->get()
            ->mapWithKeys(fn (Item $item): array => [$item->getKey() => $item->display_label])
            ->all();
    }

    /**
     * @param  (Closure(Builder): Builder)|null  $modifyQuery
     * @return array<int, string>
     */
    public static function search(?string $search, ?Closure $modifyQuery = null): array
    {
        $query = Item::query()->search($search);

        if ($modifyQuery) {
            $query = $modifyQuery($query) ?? $query;
        }

        return static::options($query->limit(self::RESULTS_LIMIT));
    }
}
