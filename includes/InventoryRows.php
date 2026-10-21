<?php

// ABOUTME: Turns product snapshots into the CSV rows of the inventory export and keeps the stock total.
// ABOUTME: Pure PHP: holds the rules for stock status, variations, parent-managed stock, naming and prices.

declare(strict_types=1);

namespace BeyondWeb\ExportInventory;

final class InventoryRows
{
    public const HEADER = ['id', 'parent_id', 'type', 'name', 'sku', 'price', 'manage_stock', 'stock_quantity'];

    private const IN_STOCK = 'instock';

    private int $stockTotal = 0;

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

    public function stockTotal(): int
    {
        return $this->stockTotal;
    }

    /**
     * @return list<int|string>
     */
    public function totalRow(): array
    {
        return ['', '', '', 'TOTAL (stock-managed rows)', '', '', '', $this->stockTotal];
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
                id: $product->id,
                parentId: '',
                type: 'simple',
                name: $product->name,
                sku: $product->sku,
                price: $product->price,
                manageStock: $product->manageStock,
                quantity: $product->stockQuantity,
            ),
        ];
    }

    /**
     * Variations that share their parent's stock get a blank quantity; the shared quantity is written
     * once, on a parent row placed directly above them, so the stock total never counts it twice.
     * The parent row has no price: a variable product has no single price of its own.
     *
     * @return list<list<int|string>>
     */
    private function variableRows(ProductSnapshot $product): array
    {
        $variations = $this->exportedVariations($product);

        $rows = [];
        if ($this->usesParentStock($variations)) {
            $rows[] = $this->row(
                id: $product->id,
                parentId: '',
                type: 'variable',
                name: $product->name,
                sku: $product->sku,
                price: '',
                manageStock: $product->manageStock,
                quantity: $product->stockQuantity,
            );
        }

        foreach ($variations as $variation) {
            $rows[] = $this->row(
                id: $variation->id,
                parentId: $product->id,
                type: 'variation',
                name: $this->variationName($product->name, $variation->attributeLabels),
                sku: $variation->sku,
                price: $variation->price,
                manageStock: $variation->manageStock,
                quantity: $variation->stockQuantity,
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
     * @param list<ProductSnapshot> $variations
     */
    private function usesParentStock(array $variations): bool
    {
        foreach ($variations as $variation) {
            if ($variation->manageStock === ProductSnapshot::PARENT_MANAGED) {
                return true;
            }
        }

        return false;
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
        $this->stockTotal += $exportedQuantity ?? 0;

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
