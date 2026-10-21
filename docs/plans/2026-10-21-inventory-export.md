# Inventory Export Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** A WordPress plugin exposing `wp inventory-export`, which writes in-stock WooCommerce simple products and variations (with their current price) to a CSV file with a stock total on the last line.

**Architecture:** `ProductReader` turns a `WC_Product` into a plain `ProductSnapshot` value object. `InventoryRows` (pure PHP, no WordPress calls) holds every export rule and turns snapshots into CSV rows while keeping the stock total. `ExportInventoryCommand` validates flags, pages through product IDs, streams rows to disk and prints a summary. Design: `docs/plans/2026-10-21-inventory-export-design.md`.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, WooCommerce 8.0+, WP-CLI, PHPUnit 10.5 (dev only, via Composer).

---

## Conventions (read before starting)

- Every PHP file starts with `<?php`, a blank line, two `// ABOUTME:` lines, a blank line, `declare(strict_types=1);`.
  Exception: the plugin bootstrap file puts the plugin header docblock between ABOUTME and `declare` (see Task 7).
- PSR-12, single quotes, trailing commas in multiline arrays/arguments, `self::assertSame` in tests,
  snake_case test method names (`test_…`) — matches `../beyondweb-marketplace-feeds`.
- Namespace `BeyondWeb\ExportInventory`, classes under `includes/` (PSR-4 for tests; runtime uses `require_once`).
- Every commit message ends with the attribution trailer lines given in the session.
- Run all commands from the repository root: `/Users/gtsar/Documents/_PLUGINS/bw-export-inventory`.

---

### Task 0: Branch

**Step 1:** Create the work branch.

```bash
git checkout -b feature/inventory-export
```

Expected: `Switched to a new branch 'feature/inventory-export'`

---

### Task 1: Dev tooling (Composer + PHPUnit)

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `.gitignore`

**Step 1: Create `composer.json`**

```json
{
    "name": "beyondweb/bw-export-inventory",
    "description": "WP-CLI command that exports in-stock WooCommerce products and variations to CSV with a stock total",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": "^8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": { "BeyondWeb\\ExportInventory\\": "includes/" }
    },
    "autoload-dev": {
        "psr-4": { "BeyondWeb\\ExportInventory\\Tests\\": "tests/" }
    },
    "scripts": {
        "test": "phpunit"
    },
    "config": {
        "sort-packages": true
    }
}
```

**Step 2: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit bootstrap="vendor/autoload.php"
         colors="true"
         beStrictAboutTestsThatDoNotTestAnything="true"
         beStrictAboutOutputDuringTests="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**Step 3: Create `.gitignore`**

```
.DS_Store
.idea/
.vscode/
vendor/
.phpunit.result.cache
.phpunit.cache/
*.zip
```

**Step 4: Install**

Run: `composer install`
Expected: ends with `Generating autoload files`, no errors. `vendor/bin/phpunit --version` prints `PHPUnit 10.5.x`.

**Step 5: Commit**

```bash
git add composer.json composer.lock phpunit.xml.dist .gitignore
git commit -m "Add Composer and PHPUnit dev tooling"
```

---

### Task 2: Snapshot value object + simple product rows + price formatting

**Files:**
- Create: `includes/ProductSnapshot.php`
- Create: `includes/InventoryRows.php`
- Test: `tests/Unit/InventoryRowsTest.php`

**Step 1: Create `includes/ProductSnapshot.php`** (data only, no behavior to test)

```php
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
```

**Step 2: Write the failing tests** in `tests/Unit/InventoryRowsTest.php`

Tests build `InventoryRows` through the `rows()` helper, which uses `,` as decimal separator and 2 decimals
(a typical Greek shop), so expected prices look like `'12,50'`.

```php
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
```

**Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit`
Expected: 6 errors, `Class "BeyondWeb\ExportInventory\InventoryRows" not found`.

**Step 4: Write the minimal implementation** in `includes/InventoryRows.php`

```php
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
```

**Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: `OK (6 tests, 7 assertions)`

**Step 6: Commit**

```bash
git add includes/ProductSnapshot.php includes/InventoryRows.php tests/Unit/InventoryRowsTest.php
git commit -m "Export rows for in-stock simple products"
```

---

### Task 3: Variation rows (filtering, naming, quantities)

**Files:**
- Modify: `includes/InventoryRows.php`
- Test: `tests/Unit/InventoryRowsTest.php`

**Step 1: Add the failing tests** to `InventoryRowsTest` (above the `rows()` helper), and add the two helpers below `simple()`.

```php
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

    public function test_variation_name_omits_any_attributes(): void
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
```

Helpers (below `simple()`). The variable product's own price (`'15'`) must never appear in the output:

```php
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
            name: 'T-Shirt',
            sku: "TS-{$id}",
            price: '9.9',
            stockStatus: $stockStatus,
            manageStock: $manageStock,
            stockQuantity: $quantity,
            enabled: $enabled,
            attributeLabels: $labels,
        );
    }
```

**Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit`
Expected: 3 failures/errors in the new tests (`variable` hits `default => []`).
`test_variable_product_without_qualifying_variations_writes_nothing` already passes — that is expected.

**Step 3: Implement.** In `includes/InventoryRows.php`:

Add the `variable` arm to the `match`:

```php
        return match ($product->type) {
            'simple' => $this->simpleRows($product),
            'variable' => $this->variableRows($product),
            default => [],
        };
```

Add below `simpleRows()`:

```php
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
```

**Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: `OK (10 tests, …)`

**Step 5: Commit**

```bash
git add includes/InventoryRows.php tests/Unit/InventoryRowsTest.php
git commit -m "Export rows for enabled in-stock variations"
```

---

### Task 4: Parent-managed stock (one parent row above its variations)

**Files:**
- Modify: `includes/InventoryRows.php`
- Test: `tests/Unit/InventoryRowsTest.php`

**Step 1: Add the failing tests** (above the `rows()` helper)

```php
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
```

**Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit`
Expected: `test_parent_managed_variations_get_one_parent_row_above_them` fails (no parent row);
the second new test already passes.

**Step 3: Implement.** Replace `variableRows()` with:

```php
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
                $product->id,
                '',
                'variable',
                $product->name,
                $product->sku,
                '',
                $product->manageStock,
                $product->stockQuantity,
            );
        }

        foreach ($variations as $variation) {
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
```

Add below `exportedVariations()`:

```php
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
```

**Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: `OK (12 tests, …)`

**Step 5: Commit**

```bash
git add includes/InventoryRows.php tests/Unit/InventoryRowsTest.php
git commit -m "Write one parent row for variations sharing parent stock"
```

---

### Task 5: Stock total

**Files:**
- Modify: `includes/InventoryRows.php`
- Test: `tests/Unit/InventoryRowsTest.php`

**Step 1: Add the failing tests** (above the `rows()` helper)

```php
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
```

(5 from the managed simple + 50 parent row + 4 own-stock variation = 59. The unmanaged simple, the out-of-stock simple
and the parent-managed variations contribute nothing.)

**Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit`
Expected: 2 errors, `Call to undefined method …InventoryRows::totalRow()` / `stockTotal()`.

**Step 3: Implement.** In `includes/InventoryRows.php`:

Add the property below the constants:

```php
    private int $stockTotal = 0;
```

Add the public methods below `forProduct()`:

```php
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
```

In `row()`, add to the total before returning:

```php
        $exportedQuantity = $manageStock === ProductSnapshot::MANAGED ? $quantity : null;
        $this->stockTotal += $exportedQuantity ?? 0;

        return [$id, $parentId, $type, $name, $sku, $this->formatPrice($price), $manageStock, $exportedQuantity ?? ''];
```

**Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: `OK (14 tests, …)` and no other output.

**Step 5: Commit**

```bash
git add includes/InventoryRows.php tests/Unit/InventoryRowsTest.php
git commit -m "Keep a stock total and expose the total row"
```

---

### Task 6: ProductReader (WooCommerce → snapshot)

No unit test: this class only calls WooCommerce APIs, and mocking `WC_Product` would test the mocks.
It is verified on a real shop in Task 9.

**Files:**
- Create: `includes/ProductReader.php`

**Step 1: Write the reader**

```php
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
```

**Step 2: Syntax check**

Run: `php -l includes/ProductReader.php`
Expected: `No syntax errors detected in includes/ProductReader.php`

**Step 3: Unit tests still green**

Run: `vendor/bin/phpunit`
Expected: `OK (14 tests, …)`

**Step 4: Commit**

```bash
git add includes/ProductReader.php
git commit -m "Read WooCommerce products into snapshots"
```

---

### Task 7: The `wp inventory-export` command and plugin bootstrap

**Files:**
- Create: `includes/Cli/ExportInventoryCommand.php`
- Create: `bw-export-inventory.php`

**Step 1: Write the command**

```php
<?php

// ABOUTME: WP-CLI command `wp inventory-export` that writes in-stock products and variations to a CSV file.
// ABOUTME: Pages through product IDs, streams rows to disk, appends the stock total and prints a summary.

declare(strict_types=1);

namespace BeyondWeb\ExportInventory\Cli;

use BeyondWeb\ExportInventory\InventoryRows;
use BeyondWeb\ExportInventory\ProductReader;
use stdClass;
use WC_Product;
use WP_CLI;

use function WP_CLI\Utils\make_progress_bar;
use function WP_CLI\Utils\wp_clear_object_cache;

final class ExportInventoryCommand
{
    private const PAGE_SIZE = 500;

    private const EXPORTED_TYPES = ['simple', 'variable'];

    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Exports in-stock simple products and variations to a CSV file with a stock total.
     *
     * ## OPTIONS
     *
     * [--status=<list>]
     * : Comma-separated product statuses, or "any" for every status except trash and auto-draft.
     * ---
     * default: publish
     * ---
     *
     * [--file=<path>]
     * : Output CSV path. Defaults to inventory-YYYY-MM-DD-HHMMSS.csv in the current directory.
     *
     * [--delimiter=<char>]
     * : CSV column delimiter.
     * ---
     * default: ";"
     * ---
     *
     * ## EXAMPLES
     *
     *     wp inventory-export
     *     wp inventory-export --status=publish,draft --file=/tmp/stock.csv
     *     wp inventory-export --status=any --delimiter=,
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc_args
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        if (!function_exists('wc_get_products')) {
            WP_CLI::error('WooCommerce is not active.');
        }

        $statuses = $this->statuses($assoc_args['status'] ?? 'publish');

        $delimiter = $assoc_args['delimiter'] ?? ';';
        if (strlen($delimiter) !== 1) {
            WP_CLI::error('--delimiter must be exactly one character.');
        }

        $file = $assoc_args['file'] ?? 'inventory-' . wp_date('Y-m-d-His') . '.csv';
        $handle = fopen($file, 'w');
        if ($handle === false) {
            WP_CLI::error("Cannot write to: {$file}");
        }

        fwrite($handle, self::UTF8_BOM);
        $this->writeRow($handle, InventoryRows::HEADER, $delimiter);

        $rows = new InventoryRows(wc_get_price_decimal_separator(), wc_get_price_decimals());
        $reader = new ProductReader();
        $written = 0;
        $skippedTypes = [];
        $unreadable = [];

        $firstPage = $this->productIdPage($statuses, 1);
        $progress = make_progress_bar('Exporting products', $firstPage->total);

        for ($page = 1; $page <= $firstPage->max_num_pages; $page++) {
            $ids = $page === 1 ? $firstPage->products : $this->productIdPage($statuses, $page)->products;

            foreach ($ids as $id) {
                $progress->tick();

                $product = wc_get_product($id);
                if (!$product instanceof WC_Product) {
                    WP_CLI::warning("Could not load product {$id}, skipped.");
                    $unreadable[] = $id;
                    continue;
                }

                $type = $product->get_type();
                if (!in_array($type, self::EXPORTED_TYPES, true)) {
                    $skippedTypes[$type] = ($skippedTypes[$type] ?? 0) + 1;
                    continue;
                }

                foreach ($rows->forProduct($reader->read($product)) as $row) {
                    $this->writeRow($handle, $row, $delimiter);
                    $written++;
                }
            }

            // Clears only this process's in-memory object cache; a persistent cache (e.g. Redis) is left untouched.
            wp_clear_object_cache();
        }

        $progress->finish();

        $this->writeRow($handle, $rows->totalRow(), $delimiter);
        fclose($handle);

        WP_CLI::log("Rows written: {$written}");
        WP_CLI::log('Stock total: ' . $rows->stockTotal());
        WP_CLI::log('Skipped types: ' . ($skippedTypes === [] ? 'none' : $this->describeCounts($skippedTypes)));
        if ($unreadable !== []) {
            WP_CLI::log('Unreadable product IDs: ' . implode(', ', $unreadable));
        }
        WP_CLI::success("Inventory written to {$file}");
    }

    /**
     * @return list<string>
     */
    private function statuses(string $option): array
    {
        $known = array_keys(get_post_stati());

        if ($option === 'any') {
            return array_values(array_diff($known, ['trash', 'auto-draft']));
        }

        $requested = array_map('trim', explode(',', $option));
        $unknown = array_diff($requested, $known);
        if ($unknown !== []) {
            WP_CLI::error(sprintf(
                'Unknown status: %s. Valid statuses: %s',
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }

        return $requested;
    }

    /**
     * @param list<string> $statuses
     */
    private function productIdPage(array $statuses, int $page): stdClass
    {
        return wc_get_products([
            'status' => $statuses,
            'limit' => self::PAGE_SIZE,
            'page' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
            'paginate' => true,
        ]);
    }

    /**
     * @param resource         $handle
     * @param list<int|string> $row
     */
    private function writeRow($handle, array $row, string $delimiter): void
    {
        fputcsv($handle, $row, $delimiter, '"', '');
    }

    /**
     * @param array<string, int> $counts
     */
    private function describeCounts(array $counts): string
    {
        return implode(', ', array_map(
            static fn (string $type, int $count): string => "{$type}: {$count}",
            array_keys($counts),
            $counts,
        ));
    }
}
```

**Step 2: Write the plugin bootstrap** `bw-export-inventory.php`

```php
<?php

// ABOUTME: Main plugin file for BW Export Inventory.
// ABOUTME: Registers the `wp inventory-export` WP-CLI command and declares WooCommerce HPOS compatibility.

/**
 * Plugin Name: BW Export Inventory
 * Plugin URI: https://github.com/tsarbo/bw-export-inventory
 * Description: WP-CLI command that exports in-stock WooCommerce products and variations to CSV with a stock total.
 * Version: 0.1.0
 * Author: BeyondWeb
 * Author URI: https://beyondweb.gr
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bw-export-inventory
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/includes/ProductSnapshot.php';
    require_once __DIR__ . '/includes/ProductReader.php';
    require_once __DIR__ . '/includes/InventoryRows.php';
    require_once __DIR__ . '/includes/Cli/ExportInventoryCommand.php';
    WP_CLI::add_command('inventory-export', \BeyondWeb\ExportInventory\Cli\ExportInventoryCommand::class);
}
```

**Step 3: Syntax check**

Run: `php -l includes/Cli/ExportInventoryCommand.php && php -l bw-export-inventory.php`
Expected: `No syntax errors detected` for both.

**Step 4: Unit tests still green**

Run: `vendor/bin/phpunit`
Expected: `OK (14 tests, …)`

**Step 5: Commit**

```bash
git add includes/Cli/ExportInventoryCommand.php bw-export-inventory.php
git commit -m "Add wp inventory-export command and plugin bootstrap"
```

---

### Task 8: README

**Files:**
- Create: `README.md`

**Step 1: Write `README.md`**

````markdown
# BW Export Inventory

WP-CLI command that exports in-stock WooCommerce products and variations to a CSV file, with the stock total on the last line.

## Requirements

PHP 8.1+, WordPress 6.4+, WooCommerce 8.0+, WP-CLI.

## Usage

```
wp inventory-export [--status=<list>] [--file=<path>] [--delimiter=<char>]
```

| Option | Default | Description |
|---|---|---|
| `--status` | `publish` | Comma-separated product statuses (`publish,draft,private`), or `any` for every status except trash and auto-draft |
| `--file` | `inventory-YYYY-MM-DD-HHMMSS.csv` | Output path |
| `--delimiter` | `;` | Column delimiter |

The file is UTF-8 with a BOM so Excel shows non-Latin product names correctly.

## Output

Columns: `id`, `parent_id`, `type`, `name`, `sku`, `price`, `manage_stock`, `stock_quantity`.

- Only items with stock status **In stock** are exported (not "On backorder").
- **Simple products**: one row.
- **Variable products**: one row per enabled, in-stock variation, named `Product - Attribute - Attribute`.
- `manage_stock` is `yes`, `no` or `parent`. The quantity is blank unless it is `yes`.
- When variations use their parent's stock (`parent`), the parent product gets its own row with the shared quantity,
  directly above its variations — so the shared stock is counted once.
- SKUs are the item's own; a variation without a SKU shows a blank, not its parent's SKU.
- `price` is the current selling price: the sale price while a sale is active, otherwise the regular price.
  It is blank when no price is set, and always blank on parent rows. It uses the shop's decimal separator and
  number of decimals (WooCommerce → Settings → General), with no thousands separator.
- Other product types (grouped, external, …) are skipped and counted in the command's summary.
- The last row is `TOTAL (stock-managed rows)` with the sum of the `stock_quantity` column.

## Development

```
composer install
composer test
```

## License

GPL-2.0-or-later
````

**Step 2: Commit**

```bash
git add README.md
git commit -m "Add README"
```

---

### Task 9: Manual verification on a real WooCommerce shop

**Ask Giorgos which staging/local shop to use** (needs WooCommerce + WP-CLI, ideally a copy of a large catalog).
Install the plugin there (symlink or copy, without `vendor/`), activate it, then check each item and record the result:

1. `wp help inventory-export` shows the options and examples.
2. `wp inventory-export --status=nope` → error listing valid statuses; no file created.
3. `wp inventory-export --delimiter=ab` → error; no file created.
4. `wp inventory-export --file=/nonexistent/dir/x.csv` → `Cannot write to: …` error.
5. `wp inventory-export` → progress bar, summary, file in the current directory named `inventory-<site-local time>.csv`.
6. Open the file in Excel: Greek names display correctly, columns split on `;`.
7. Spot-check, comparing with wp-admin:
   - a simple product with managed stock (quantity matches) and one without (`no`, blank quantity);
   - an out-of-stock and an on-backorder simple product are absent;
   - a variable product: disabled and out-of-stock variations absent; name `Parent - Color - Size`;
     taxonomy attribute shows term name, not slug; "Any…" attribute omitted;
   - a variation without its own SKU shows a blank SKU;
   - a variable product whose variations use parent stock: parent row above, variations `parent` with blank quantity.
   - price: a product on an active sale shows the sale price; one with a sale scheduled in the future shows the
     regular price; a draft without a price shows a blank; parent rows have a blank price;
     prices use the shop's decimal separator and Excel treats them as numbers (right-aligned, summable).
8. Last row is `TOTAL (stock-managed rows)`; select the `stock_quantity` column above it in Excel — the Excel sum equals the total.
9. `wp inventory-export --status=any` and `--status=publish,draft` include draft products; trash is never included with `any`.
10. Summary lists skipped types if the shop has grouped/external products.
11. Memory: on the large catalog, run `/usr/bin/time -l wp inventory-export` (macOS) or `/usr/bin/time -v` (Linux) and note peak memory;
    it should stay roughly flat rather than growing with catalog size.

Fix any issue found with a failing unit test first when it is an `InventoryRows` rule.

**Commit** any fixes, then use superpowers:finishing-a-development-branch.
