<?php

// ABOUTME: Reads a WooCommerce product (and, for variable products, its variations) into a ProductSnapshot.
// ABOUTME: The only place that talks to WooCommerce product objects.

declare(strict_types=1);

namespace BeyondWeb\ExportInventory;

use WC_Product;
use WC_Product_Variation;
use WP_Term;

final class ProductReader
{
    public function read(WC_Product $product): ProductSnapshot
    {
        return new ProductSnapshot(
            id: $product->get_id(),
            type: $product->get_type(),
            name: $product->get_name(),
            sku: $product->get_sku('edit'),
            price: (string) $product->get_price(),
            stockStatus: $product->get_stock_status(),
            manageStock: $product->get_manage_stock() ? ProductSnapshot::MANAGED : ProductSnapshot::UNMANAGED,
            stockQuantity: $product->get_stock_quantity(),
            variations: $product->is_type('variable') ? $this->readVariations($product) : [],
        );
    }

    /**
     * get_children() on a variable product includes disabled (private) variations.
     *
     * @return list<ProductSnapshot>
     */
    private function readVariations(WC_Product $product): array
    {
        $variations = [];
        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product($variationId);
            if ($variation instanceof WC_Product_Variation) {
                $variations[] = $this->readVariation($variation);
            }
        }

        return $variations;
    }

    private function readVariation(WC_Product_Variation $variation): ProductSnapshot
    {
        return new ProductSnapshot(
            id: $variation->get_id(),
            type: 'variation',
            name: $variation->get_name(),
            sku: $variation->get_sku('edit'),
            price: (string) $variation->get_price(),
            stockStatus: $variation->get_stock_status(),
            manageStock: $this->variationManageStock($variation),
            stockQuantity: $variation->get_stock_quantity('edit'),
            enabled: $variation->get_status() === 'publish',
            attributeLabels: $this->attributeLabels($variation),
        );
    }

    /**
     * In 'view' context WooCommerce returns 'parent' when the variation inherits its parent's stock.
     */
    private function variationManageStock(WC_Product_Variation $variation): string
    {
        $manageStock = $variation->get_manage_stock();
        if ($manageStock === 'parent') {
            return ProductSnapshot::PARENT_MANAGED;
        }

        return $manageStock ? ProductSnapshot::MANAGED : ProductSnapshot::UNMANAGED;
    }

    /**
     * Taxonomy attributes store a term slug and are shown by term name; custom attributes store the
     * display value itself. An empty value means "Any…" and is passed on as ''.
     *
     * @return list<string>
     */
    private function attributeLabels(WC_Product_Variation $variation): array
    {
        $labels = [];
        foreach ($variation->get_attributes() as $attribute => $value) {
            $term = $value !== '' && taxonomy_exists($attribute) ? get_term_by('slug', $value, $attribute) : false;
            $labels[] = $term instanceof WP_Term ? $term->name : (string) $value;
        }

        return $labels;
    }
}
