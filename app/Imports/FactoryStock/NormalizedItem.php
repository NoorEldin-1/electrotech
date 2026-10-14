<?php

declare(strict_types=1);

namespace App\Imports\FactoryStock;

use App\Enums\ItemEdge;
use App\Enums\ItemMaterial;
use App\Enums\ItemOrigin;
use App\Enums\ItemTreatment;
use App\Enums\ItemType;
use App\Enums\ProductLine;
use App\Enums\UnitOfMeasure;

/**
 * One factory-sheet row, cleaned up and ready to become an Item.
 */
final class NormalizedItem
{
    /** @var list<string> human-readable notes about what was corrected */
    public array $warnings = [];

    public function __construct(
        public readonly int $row,
        public string $sku,
        public readonly ?string $partNumber,
        public readonly string $name,
        public readonly string $category,
        public readonly ItemType $type,
        public readonly UnitOfMeasure $unit,
        public readonly float $quantity,
        public readonly ?ProductLine $productLine,
        public readonly ?ItemMaterial $material,
        public readonly ?float $thicknessMm,
        public readonly ?float $widthMm,
        public readonly ?string $sizeCode,
        public readonly ?float $lengthM,
        public readonly ?ItemEdge $edge,
        public readonly ?ItemTreatment $treatment,
        public readonly ?ItemOrigin $origin,
        public readonly ?string $brand,
        public readonly ?string $usage,
        public readonly ?string $ampereRating,
        public readonly ?int $poles,
        public readonly ?string $size,
        public readonly ?string $color,
        public readonly ?string $description,
        public readonly ?string $imageEntry,
    ) {}

    /**
     * Item attributes (everything but the SKU, stock and photo).
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'part_number' => $this->partNumber,
            'type' => $this->type,
            'unit' => $this->unit,
            'product_line' => $this->productLine,
            'material' => $this->material,
            'thickness_mm' => $this->thicknessMm,
            'width_mm' => $this->widthMm,
            'size_code' => $this->sizeCode,
            'length_m' => $this->lengthM,
            'edge' => $this->edge,
            'treatment' => $this->treatment,
            'origin' => $this->origin,
            'brand' => $this->brand,
            'usage' => $this->usage,
            'ampere_rating' => $this->ampereRating,
            'poles' => $this->poles,
            'size' => $this->size,
            'color' => $this->color,
            'description' => $this->description,
        ];
    }
}
