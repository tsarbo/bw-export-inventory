<?php

// ABOUTME: Tests for InventoryRows, the export rules that turn product snapshots into CSV rows.
// ABOUTME: Covers stock status filtering, variations, parent-managed stock, naming, prices and the stock total.

declare(strict_types=1);

namespace BeyondWeb\ExportInventory\Tests\Unit;

use BeyondWeb\ExportInventory\InventoryRows;
use BeyondWeb\ExportInventory\ProductSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InventoryRowsTest extends TestCase
{
    public function test_simple_product_with_managed_stock_exports_its_quantity(): void
    {
        $rows = self::rows()->forProduct(self::simple(quantity: 5));

        self::assertSame([[10, '', 'simple', 'Mug', 'MUG-1', '12,50', 'yes', 5]], $rows);
    }

    public function test_simple_product_without_managed_stock_leaves_quantity_blank(): void
    {
        $rows = self::rows()->forProduct(
            self::simple(manageStock: ProductSnapshot::UNMANAGED, quantity: 7),
        );

        self::assertSame([[10, '', 'simple', 'Mug', 'MUG-1', '12,50', 'no', '']], $rows);
    }

    public function test_simple_product_not_in_stock_is_skipped(): void
    {
        $rows = self::rows();

        self::assertSame([], $rows->forProduct(self::simple(stockStatus: 'outofstock')));
        self::assertSame([], $rows->forProduct(self::simple(stockStatus: 'onbackorder')));
    }

    public function test_other_product_types_produce_no_rows(): void
    {
        $grouped = new ProductSnapshot(
            id: 30,
            type: 'grouped',
            name: 'Set',
            sku: 'SET',
            price: '30',
            stockStatus: 'instock',
            manageStock: ProductSnapshot::MANAGED,
            stockQuantity: 4,
        );

        self::assertSame([], self::rows()->forProduct($grouped));
    }

    public function test_price_uses_the_given_decimal_separator_and_decimals(): void
    {
        $rows = (new InventoryRows('.', 3))->forProduct(self::simple(price: '1234.5'));

        self::assertSame('1234.500', $rows[0][5]);
    }

    public function test_missing_price_stays_blank(): void
    {
        $rows = self::rows()->forProduct(self::simple(price: ''));

        self::assertSame('', $rows[0][5]);
    }

    public function test_zero_price_is_exported_as_zero_not_blank(): void
    {
        $rows = self::rows()->forProduct(self::simple(price: '0'));

        self::assertSame('0,00', $rows[0][5]);
    }

    public function test_price_is_rounded_to_the_shop_decimals(): void
    {
        $rows = self::rows()->forProduct(self::simple(price: '12.345'));

        self::assertSame('12,35', $rows[0][5]);
    }

    public function test_variable_product_exports_only_enabled_in_stock_variations(): void
    {
        $product = self::variable([
            self::variation(21),
            self::variation(22, stockStatus: 'outofstock'),
            self::variation(23, stockStatus: 'onbackorder'),
            self::variation(24, enabled: false),
        ]);

        $rows = self::rows()->forProduct($product);

        self::assertSame([[21, 20, 'variation', 'T-Shirt - Yellow - XL', 'TS-21', '9,90', 'yes', 3]], $rows);
    }

    public function test_variation_name_omits_attributes_set_to_any(): void
    {
        $product = self::variable([self::variation(21, labels: ['', 'XL'])]);

        $rows = self::rows()->forProduct($product);

        self::assertSame('T-Shirt - XL', $rows[0][3]);
    }

    public function test_variation_without_stock_management_leaves_quantity_blank(): void
    {
        $product = self::variable([self::variation(21, manageStock: ProductSnapshot::UNMANAGED, quantity: 9)]);

        $rows = self::rows()->forProduct($product);

        self::assertSame([[21, 20, 'variation', 'T-Shirt - Yellow - XL', 'TS-21', '9,90', 'no', '']], $rows);
    }

    public function test_variable_product_without_qualifying_variations_writes_nothing(): void
    {
        $product = self::variable([
            self::variation(21, stockStatus: 'outofstock'),
            self::variation(22, enabled: false),
        ]);

        self::assertSame([], self::rows()->forProduct($product));
    }

    public function test_parent_managed_variations_get_one_parent_row_above_them(): void
    {
        $product = self::variable(
            [
                self::variation(21, manageStock: ProductSnapshot::PARENT_MANAGED, quantity: 7),
                self::variation(22, manageStock: ProductSnapshot::PARENT_MANAGED, quantity: 7),
                self::variation(23, quantity: 4),
            ],
            manageStock: ProductSnapshot::MANAGED,
            quantity: 50,
        );

        $rows = self::rows()->forProduct($product);

        self::assertSame([
            [20, '', 'variable', 'T-Shirt', 'TS', '', 'yes', 50],
            [21, 20, 'variation', 'T-Shirt - Yellow - XL', 'TS-21', '9,90', 'parent', ''],
            [22, 20, 'variation', 'T-Shirt - Yellow - XL', 'TS-22', '9,90', 'parent', ''],
            [23, 20, 'variation', 'T-Shirt - Yellow - XL', 'TS-23', '9,90', 'yes', 4],
        ], $rows);
    }

    public function test_parent_row_is_omitted_when_no_exported_variation_uses_parent_stock(): void
    {
        $product = self::variable(
            [
                self::variation(21, stockStatus: 'outofstock', manageStock: ProductSnapshot::PARENT_MANAGED),
                self::variation(22, quantity: 4),
            ],
            manageStock: ProductSnapshot::MANAGED,
            quantity: 50,
        );

        $rows = self::rows()->forProduct($product);

        self::assertSame([[22, 20, 'variation', 'T-Shirt - Yellow - XL', 'TS-22', '9,90', 'yes', 4]], $rows);
    }

    public function test_total_row_is_zero_before_any_product(): void
    {
        $rows = self::rows();

        self::assertSame(['', '', '', 'TOTAL (stock-managed rows)', '', '', '', 0], $rows->totalRow());
        self::assertSame(0, $rows->stockTotal());
    }

    public function test_total_sums_exported_quantities_and_counts_shared_stock_once(): void
    {
        $rows = self::rows();
        $rows->forProduct(self::simple(quantity: 5));
        $rows->forProduct(self::simple(manageStock: ProductSnapshot::UNMANAGED, quantity: 9));
        $rows->forProduct(self::simple(stockStatus: 'outofstock', quantity: 100));
        $rows->forProduct(self::variable(
            [
                self::variation(21, manageStock: ProductSnapshot::PARENT_MANAGED, quantity: 50),
                self::variation(22, manageStock: ProductSnapshot::PARENT_MANAGED, quantity: 50),
                self::variation(23, quantity: 4),
            ],
            manageStock: ProductSnapshot::MANAGED,
            quantity: 50,
        ));

        self::assertSame(59, $rows->stockTotal());
        self::assertSame(['', '', '', 'TOTAL (stock-managed rows)', '', '', '', 59], $rows->totalRow());
    }

    public function test_managed_stock_without_quantity_exports_blank_and_adds_nothing(): void
    {
        $rows = self::rows();

        self::assertSame(
            [[10, '', 'simple', 'Mug', 'MUG-1', '12,50', 'yes', '']],
            $rows->forProduct(self::simple(quantity: null)),
        );
        self::assertSame(0, $rows->stockTotal());
    }

    #[DataProvider('formulaPrefixes')]
    public function test_name_and_sku_starting_with_a_formula_character_get_a_quote_prefix(string $prefix): void
    {
        $product = new ProductSnapshot(
            id: 10,
            type: 'simple',
            name: "{$prefix}Mug",
            sku: "{$prefix}MUG-1",
            price: '12.5',
            stockStatus: 'instock',
            manageStock: ProductSnapshot::MANAGED,
            stockQuantity: 5,
        );

        $rows = self::rows()->forProduct($product);

        self::assertSame("'{$prefix}Mug", $rows[0][3]);
        self::assertSame("'{$prefix}MUG-1", $rows[0][4]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formulaPrefixes(): array
    {
        return [
            'equals' => ['='],
            'plus' => ['+'],
            'minus' => ['-'],
            'at' => ['@'],
            'tab' => ["\t"],
            'carriage return' => ["\r"],
        ];
    }

    public function test_formula_characters_inside_a_value_are_left_alone(): void
    {
        $product = new ProductSnapshot(
            id: 10,
            type: 'simple',
            name: 'A=B + C',
            sku: 'X-1@2',
            price: '12.5',
            stockStatus: 'instock',
            manageStock: ProductSnapshot::MANAGED,
            stockQuantity: 5,
        );

        $rows = self::rows()->forProduct($product);

        self::assertSame('A=B + C', $rows[0][3]);
        self::assertSame('X-1@2', $rows[0][4]);
    }

    public function test_variation_sku_starting_with_a_formula_character_gets_a_quote_prefix(): void
    {
        $variation = new ProductSnapshot(
            id: 21,
            type: 'variation',
            name: 'T-Shirt - variation 21',
            sku: '-21',
            price: '9.9',
            stockStatus: 'instock',
            manageStock: ProductSnapshot::MANAGED,
            stockQuantity: 3,
            attributeLabels: ['Yellow'],
        );

        $rows = self::rows()->forProduct(self::variable([$variation]));

        self::assertSame("'-21", $rows[0][4]);
    }

    public function test_variation_name_from_a_formula_like_parent_name_gets_a_quote_prefix(): void
    {
        $product = new ProductSnapshot(
            id: 20,
            type: 'variable',
            name: '=T-Shirt',
            sku: 'TS',
            price: '15',
            stockStatus: 'instock',
            manageStock: ProductSnapshot::UNMANAGED,
            stockQuantity: null,
            variations: [self::variation(21)],
        );

        $rows = self::rows()->forProduct($product);

        self::assertSame("'=T-Shirt - Yellow - XL", $rows[0][3]);
    }

    public function test_non_latin_name_and_sku_are_left_alone(): void
    {
        $product = new ProductSnapshot(
            id: 10,
            type: 'simple',
            name: 'Κούπα',
            sku: 'ΚΟΥ-1',
            price: '12.5',
            stockStatus: 'instock',
            manageStock: ProductSnapshot::MANAGED,
            stockQuantity: 5,
        );

        $rows = self::rows()->forProduct($product);

        self::assertSame('Κούπα', $rows[0][3]);
        self::assertSame('ΚΟΥ-1', $rows[0][4]);
    }

    private static function rows(): InventoryRows
    {
        return new InventoryRows(',', 2);
    }

    private static function simple(
        string $stockStatus = 'instock',
        string $manageStock = ProductSnapshot::MANAGED,
        ?int $quantity = 5,
        string $price = '12.5',
    ): ProductSnapshot {
        return new ProductSnapshot(
            id: 10,
            type: 'simple',
            name: 'Mug',
            sku: 'MUG-1',
            price: $price,
            stockStatus: $stockStatus,
            manageStock: $manageStock,
            stockQuantity: $quantity,
        );
    }

    /**
     * @param list<ProductSnapshot> $variations
     */
    private static function variable(
        array $variations,
        string $manageStock = ProductSnapshot::UNMANAGED,
        ?int $quantity = null,
    ): ProductSnapshot {
        return new ProductSnapshot(
            id: 20,
            type: 'variable',
            name: 'T-Shirt',
            sku: 'TS',
            price: '15',
            stockStatus: 'instock',
            manageStock: $manageStock,
            stockQuantity: $quantity,
            variations: $variations,
        );
    }

    /**
     * @param list<string> $labels
     */
    private static function variation(
        int $id,
        array $labels = ['Yellow', 'XL'],
        string $stockStatus = 'instock',
        string $manageStock = ProductSnapshot::MANAGED,
        ?int $quantity = 3,
        bool $enabled = true,
    ): ProductSnapshot {
        return new ProductSnapshot(
            id: $id,
            type: 'variation',
            name: "T-Shirt - variation {$id}",
            sku: "TS-{$id}",
            price: '9.9',
            stockStatus: $stockStatus,
            manageStock: $manageStock,
            stockQuantity: $quantity,
            enabled: $enabled,
            attributeLabels: $labels,
        );
    }
}
