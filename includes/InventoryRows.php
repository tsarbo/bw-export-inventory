<?php

// ABOUTME: Turns product snapshots into the CSV rows of the inventory export and keeps the stock total.
// ABOUTME: Pure PHP: holds the rules for stock status, variations, parent-managed stock, naming and prices.

declare(strict_types=1);

namespace BeyondWeb\ExportInventory;

final class InventoryRows
{
    public const HEADER = ['id', 'parent_id', 'type', 'name', 'sku', 'price', 'manage_stock', 'stock_quantity'];

    private const IN_STOCK = 'instock';

    /**
     * @param string $decimalSeparator Shop's price decimal separator, so Excel in the shop's locale reads prices as numbers.
     * @param int    $decimals         Shop's number of price decimals.
     */
    public function __construct(
        private readonly string $decimalSeparator,
        private readonly int $decimals,
    ) {
    }

    /**
     * @return list<list<int|string>>
     */
    public function forProduct(ProductSnapshot $product): array
    {
        return match ($product->type) {
            'simple' => $this->simpleRows($product),
            'variable' => $this->variableRows($product),
            default => [],
        };
    }

    /**
     * @return list<list<int|string>>
     */
    private function simpleRows(ProductSnapshot $product): array
    {
        if ($product->stockStatus !== self::IN_STOCK) {
            return [];
        }

        return [
            $this->row(
                $product->id,
                '',
                'simple',
                $product->name,
                $product->sku,
                $product->price,
                $product->manageStock,
                $product->stockQuantity,
            ),
        ];
    }

    /**
     * @return list<list<int|string>>
     */
    private function variableRows(ProductSnapshot $product): array
    {
        $rows = [];
        foreach ($this->exportedVariations($product) as $variation) {
            $rows[] = $this->row(
                $variation->id,
                $product->id,
                'variation',
                $this->variationName($product->name, $variation->attributeLabels),
                $variation->sku,
                $variation->price,
                $variation->manageStock,
                $variation->stockQuantity,
            );
        }

        return $rows;
    }

    /**
     * @return list<ProductSnapshot>
     */
    private function exportedVariations(ProductSnapshot $product): array
    {
        return array_values(array_filter(
            $product->variations,
            static fn (ProductSnapshot $variation): bool => $variation->enabled
                && $variation->stockStatus === self::IN_STOCK,
        ));
    }

    /**
     * @param list<string> $attributeLabels
     */
    private function variationName(string $parentName, array $attributeLabels): string
    {
        $labels = array_filter($attributeLabels, static fn (string $label): bool => $label !== '');

        return implode(' - ', [$parentName, ...$labels]);
    }

    /**
     * Quantity is only exported for rows that track their own stock; every other row gets a blank.
     *
     * @return list<int|string>
     */
    private function row(
        int $id,
        int|string $parentId,
        string $type,
        string $name,
        string $sku,
        string $price,
        string $manageStock,
        ?int $quantity,
    ): array {
        $exportedQuantity = $manageStock === ProductSnapshot::MANAGED ? $quantity : null;

        return [$id, $parentId, $type, $name, $sku, $this->formatPrice($price), $manageStock, $exportedQuantity ?? ''];
    }

    private function formatPrice(string $price): string
    {
        if ($price === '') {
            return '';
        }

        return number_format((float) $price, $this->decimals, $this->decimalSeparator, '');
    }
}
