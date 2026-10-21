<?php

// ABOUTME: Plain values read from a WooCommerce product, so export rules can run without WooCommerce loaded.
// ABOUTME: A variable product carries its variations; a variation carries its attribute labels.

declare(strict_types=1);

namespace BeyondWeb\ExportInventory;

final class ProductSnapshot
{
    public const MANAGED = 'yes';
    public const UNMANAGED = 'no';
    public const PARENT_MANAGED = 'parent';

    /**
     * @param string                $price           Current selling price as stored by WooCommerce ('12.5'); '' when not set.
     * @param list<string>          $attributeLabels Display values of a variation's attributes; '' for "Any…".
     * @param list<ProductSnapshot> $variations      All variations of a variable product.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $name,
        public readonly string $sku,
        public readonly string $price,
        public readonly string $stockStatus,
        public readonly string $manageStock,
        public readonly ?int $stockQuantity,
        public readonly bool $enabled = true,
        public readonly array $attributeLabels = [],
        public readonly array $variations = [],
    ) {
    }
}
