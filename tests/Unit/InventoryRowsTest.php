<?php

// ABOUTME: Tests for InventoryRows, the export rules that turn product snapshots into CSV rows.
// ABOUTME: Covers stock status filtering, variations, parent-managed stock, naming, prices and the stock total.

declare(strict_types=1);

namespace BeyondWeb\ExportInventory\Tests\Unit;

use BeyondWeb\ExportInventory\InventoryRows;
use BeyondWeb\ExportInventory\ProductSnapshot;
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
}
