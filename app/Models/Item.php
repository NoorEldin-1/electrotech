<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ItemEdge;
use App\Enums\ItemMaterial;
use App\Enums\ItemOrigin;
use App\Enums\ItemTreatment;
use App\Enums\ItemType;
use App\Enums\ProductLine;
use App\Enums\UnitOfMeasure;
use App\Enums\WarehouseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Item extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'sku',
        'type',
        'is_scrap',
        'scrap_source_item_id',
        'unit',
        'unit_cost',
        'description',
        'minimum_stock',
        'item_category_id',
        'product_line',
        'part_number',
        'material',
        'thickness_mm',
        'width_mm',
        'size_code',
        'length_m',
        'edge',
        'treatment',
        'origin',
        'brand',
        'usage',
        'ampere_rating',
        'poles',
        'size',
        'color',
        'image_path',
    ];

    /**
     * Columns a free-text item search matches against (pickers, the items
     * table, the API). Many catalog items share a generic name («شريط»), so
     * the codes and the size columns are what actually tell them apart.
     */
    public const SEARCH_COLUMNS = ['sku', 'part_number', 'name', 'size', 'size_code', 'brand', 'description'];

    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'is_scrap' => 'boolean',
            'unit' => UnitOfMeasure::class,
            'unit_cost' => 'decimal:2',
            'minimum_stock' => 'decimal:4',
            'product_line' => ProductLine::class,
            'material' => ItemMaterial::class,
            'thickness_mm' => 'decimal:3',
            'width_mm' => 'decimal:2',
            'length_m' => 'decimal:3',
            'edge' => ItemEdge::class,
            'treatment' => ItemTreatment::class,
            'origin' => ItemOrigin::class,
            'poles' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'part_number', 'type', 'item_category_id', 'unit_cost', 'minimum_stock'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /**
     * A short spec line built from whichever specifications the item has,
     * e.g. «Cu · 100×5.75 مم · 3م · مقصدر» or «B100 · 1600A». Empty for
     * items without specifications.
     */
    protected function specSummary(): Attribute
    {
        return Attribute::get(function (): string {
            $number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
            $mm = __('resources.items.units.mm');

            $dimensions = match (true) {
                filled($this->width_mm) && filled($this->thickness_mm) => $number($this->width_mm) . '×' . $number($this->thickness_mm) . ' ' . $mm,
                filled($this->thickness_mm) => $number($this->thickness_mm) . ' ' . $mm,
                filled($this->width_mm) => $number($this->width_mm) . ' ' . $mm,
                default => $this->size,
            };

            $parts = [
                $this->material ? ucfirst($this->material->value) : null,
                $this->size_code,
                $dimensions,
                (float) $this->length_m > 0 ? $number($this->length_m) . __('resources.items.units.m') : null,
                $this->treatment && $this->treatment !== ItemTreatment::None ? $this->treatment->getLabel() : null,
                filled($this->ampere_rating) ? $this->ampere_rating . 'A' : null,
                $this->poles ? $this->poles . 'P' : null,
                $this->color,
            ];

            return implode(' · ', array_filter($parts, 'filled'));
        });
    }

    /**
     * The label every item picker, table and document shows: code + name +
     * spec line, so items sharing a generic name stay distinguishable.
     */
    protected function displayLabel(): Attribute
    {
        return Attribute::get(function (): string {
            $label = "{$this->sku} — {$this->name}";

            return filled($this->spec_summary) ? "{$label} ({$this->spec_summary})" : $label;
        });
    }

    /**
     * Public URL of the item photo, or null when it has none.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => filled($this->image_path)
            ? Storage::disk('public')->url($this->image_path)
            : null);
    }

    /**
     * Free-text search: every whitespace-separated word must match at least
     * one of SEARCH_COLUMNS, so «شريط 100» finds the 100 mm busbars.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $words = preg_split('/\s+/u', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';

            $query->where(function (Builder $inner) use ($like): void {
                foreach (self::SEARCH_COLUMNS as $column) {
                    $inner->orWhere($inner->qualifyColumn($column), 'like', $like);
                }
            });
        }

        return $query;
    }

    /**
     * The item's stock row in its HOME warehouse. The home row is always the
     * first created for an item (stock lands there before it can be moved
     * elsewhere), so `oldestOfMany` resolves it deterministically and stays
     * eager-loadable for the catalog screens.
     */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class)->oldestOfMany();
    }

    /**
     * All per-warehouse balances for this item.
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function bomItems(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }

    /**
     * Standard BOMs for which this (finished-good) item is the output — its
     * تركيبة المنتج القياسية (سلايد 6). Empty for raw materials.
     */
    public function standardBoms(): HasMany
    {
        return $this->hasMany(Bom::class, 'output_item_id');
    }

    /**
     * The current standard recipe to apply when this product is manufactured:
     * the latest APPROVED standard BOM (highest version). Null if none defined.
     */
    public function latestApprovedStandardBom(): ?Bom
    {
        return $this->standardBoms()
            ->where('status', \App\Enums\BomStatus::Approved)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The scrap variant of this raw item (إذن ارتداد routes returned scrap
     * here under a different code). Null until the first return is posted.
     */
    public function scrapVariant(): HasOne
    {
        return $this->hasOne(Item::class, 'scrap_source_item_id');
    }

    /**
     * The original item this scrap variant was derived from (inverse of
     * scrapVariant); null for normal items.
     */
    public function scrapSource(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'scrap_source_item_id');
    }

    /**
     * Exclude scrap variant items from a query (e.g. pickers that should only
     * offer original raw materials to return).
     */
    public function scopeNotScrap(Builder $query): Builder
    {
        return $query->where('is_scrap', false);
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * The default warehouse where this item's stock lives, derived from its type.
     */
    public function homeWarehouse(): WarehouseType
    {
        return WarehouseType::homeFor($this->type);
    }

    /**
     * On-hand quantity in a specific warehouse (0 if no row yet).
     */
    public function quantityIn(WarehouseType $warehouse): float
    {
        return (float) ($this->inventories
            ->firstWhere('warehouse_type', $warehouse->value)?->on_hand_quantity ?? 0);
    }

    /**
     * Stock value (on_hand × unit_cost) in a specific warehouse. Backs the
     * item card's رصيد وقيمة for the item and its scrap variant.
     */
    public function valueIn(WarehouseType $warehouse): float
    {
        return $this->quantityIn($warehouse) * (float) $this->unit_cost;
    }

    /**
     * Available (on_hand - on_hold) quantity in a specific warehouse.
     */
    public function availableIn(WarehouseType $warehouse): float
    {
        $row = $this->inventories->firstWhere('warehouse_type', $warehouse->value);

        if (! $row) {
            return 0;
        }

        return (float) $row->on_hand_quantity - (float) $row->on_hold_quantity;
    }

    /**
     * Items that currently have available stock (on_hand − on_hold > 0) in the
     * given warehouse. Backs the manual stock-reservation picker (حجز مخزون):
     * you can only reserve an item that has free quantity to hold, so items at
     * zero available are excluded from the list entirely.
     */
    public function scopeWithAvailableStockIn(Builder $query, WarehouseType $warehouse): Builder
    {
        return $query->whereHas('inventories', fn (Builder $inventory) => $inventory
            ->where('warehouse_type', $warehouse->value)
            ->whereRaw('on_hand_quantity - on_hold_quantity > 0'));
    }

    /**
     * Total available quantity across every warehouse (on_hand - on_hold).
     */
    public function getAvailableQuantityAttribute(): float
    {
        return (float) $this->inventories->sum(
            fn (Inventory $row) => (float) $row->on_hand_quantity - (float) $row->on_hold_quantity
        );
    }

    /**
     * Check if total available stock is below minimum threshold.
     */
    public function isBelowMinimumStock(): bool
    {
        return $this->available_quantity < (float) $this->minimum_stock;
    }
}
