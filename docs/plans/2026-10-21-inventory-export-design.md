# Inventory Export — Design

WP-CLI command that exports in-stock WooCommerce products and variations to a CSV, with a stock total on the last line.

## Plugin shape

```
bw-export-inventory/
├── bw-export-inventory.php              # ABOUTME + header; registers the CLI command only under WP_CLI
├── composer.json                        # dev only: phpunit
├── phpunit.xml.dist
├── includes/
│   ├── ProductSnapshot.php              # plain values read from a WC_Product (no WC types inside)
│   ├── ProductReader.php                # WC_Product -> ProductSnapshot
│   ├── InventoryRows.php                # ProductSnapshot -> 0..n CSV rows (pure PHP, no I/O)
│   └── Cli/ExportInventoryCommand.php   # flags, query loop, CSV writing, summary
└── tests/Unit/InventoryRowsTest.php
```

- Header follows the newer BeyondWeb plugins: `Plugin Name: BW Export Inventory`, `Author: BeyondWeb`,
  `Author URI: https://beyondweb.gr`, `Version: 0.1.0`, `Requires at least: 6.5` (needed by `Requires Plugins`), `Requires PHP: 8.1`,
  `Requires Plugins: woocommerce`,
  `Text Domain: bw-export-inventory`, `License: GPL v2 or later`,
  `License URI: https://www.gnu.org/licenses/gpl-2.0.html`, HPOS compatibility declaration.
- Namespace `BeyondWeb\ExportInventory`. Runtime has no dependencies: files are loaded with `require_once`
  (like `beyondweb-istante-pylon-sync`). Composer is used only for PHPUnit in development.
- No `beyondweb-updater` for now.

## Command

```
wp inventory-export [--status=<list>] [--file=<path>] [--delimiter=<char>]
```

| Flag | Default | Notes |
|---|---|---|
| `--status` | `publish` | Comma list (`publish,draft,private`) or `any` = every status except `trash` and `auto-draft` |
| `--file` | `./inventory-YYYY-MM-DD-HHMMSS.csv` | Existing file is overwritten |
| `--delimiter` | `;` | Exactly one character |

Output file is UTF-8 with BOM. Terminal shows a progress bar, then a summary: rows written, stock total,
skipped product types with counts (e.g. `grouped: 12, external: 3`), unreadable product IDs, and the file path.

## CSV contents

Columns: `id ; parent_id ; type ; name ; sku ; price ; manage_stock ; stock_quantity`

- Only stock status `instock` is exported (`onbackorder` and `outofstock` are not).
- **Simple** product in stock → one row. `manage_stock` is `yes`/`no`; quantity blank when `no`.
- **Variable** product → its variations that are **enabled** (`publish`) and `instock`:
  - `manage_stock = yes` → own quantity.
  - `manage_stock = no` → blank quantity.
  - `manage_stock = parent` → blank quantity. If at least one exported variation is `parent`, one `variable`
    row for the parent is written directly above its variations, carrying the shared quantity.
  - No qualifying variation → nothing written for that product.
- Other product types (grouped, external, plugin types) are skipped and counted for the summary.
- **Variation name**: `Parent name - Yellow - XL`. Taxonomy attributes use the term name, custom attributes
  their raw value, "Any…" attributes are omitted.
- **Name**: the stored product name (`get_name('edit')`), unaffected by translation plugins.
- **Stock quantity**: the stored value (`get_stock_quantity('edit')`). Decimal stock (plugins that make stock fractional) is not supported.
- **SKU**: the product's/variation's own SKU (`edit` context); a variation without a SKU stays blank instead
  of inheriting the parent's.
- **Formula injection protection**: a name or SKU starting with `=`, `+`, `-`, `@`, tab or carriage return gets a
  leading `'`, so Excel shows it as text instead of running it as a formula. The `'` is visible in Excel; such
  values are rare in product catalogs. Documented in the README.
- **Price**: the stored current selling price (`get_price('edit')`, i.e. `_price`, unaffected by pricing or currency plugins): the sale price while a sale is active, otherwise the
  regular price; a scheduled sale that has not started does not count. Blank when no price is set (common on
  unpublished items). Parent rows of variable products always have a blank price. Formatted with the shop's
  decimal separator and number of decimals (WooCommerce → Settings → General), no thousands separator,
  e.g. `12,50`, so it is read as a number by Excel in the shop's locale.
- **Total row** (last line): `name = TOTAL (stock-managed rows)`, `stock_quantity` = sum of the column.
  Parent-managed stock appears only on the parent row, so nothing is double-counted. Its presence also
  marks the file as complete.

## Data flow

1. Validate flags; abort on error before opening the file.
2. Fetch all matching product IDs once (ordered by ID, filtered by status, all product types), so products
   created or deleted during the run cannot shift batches; process them in batches of 500.
3. For each ID: `wc_get_product()` → `ProductReader` → `ProductSnapshot` → `InventoryRows` → `fputcsv` immediately.
4. After each batch, flush the object cache so memory stays flat on catalogs with tens of thousands of products.
5. Write the total row, close the file, print the summary.

## Error handling

Abort with `WP_CLI::error` (nothing written) when:
- WooCommerce is not active.
- `--status` contains an unknown status (message lists valid ones from `get_post_stati()`).
- `--status` is empty (e.g. `--status=,`), or combines `any` with other statuses.
- `--delimiter` is not exactly one single-byte character, or is a double quote or a line break.
- The output file cannot be opened for writing.

Abort with `WP_CLI::error` when writing to the file fails during the run (e.g. disk full), including when closing
it: the message says the file is incomplete, and no success is reported.

During the run, if `wc_get_product()` returns nothing for an ID: `WP_CLI::warning` with the ID, count it in
the summary, continue.

## Testing

- `InventoryRows` is unit-tested with PHPUnit using plain `ProductSnapshot` values — no mocks:
  simple yes/no stock management, variation own/none/parent stock, parent row placement and single counting,
  disabled and out-of-stock/backorder variations excluded, naming with taxonomy/custom/"Any" attributes,
  price formatting and blank prices, formula-injection prefix, total row.
- `ProductReader` and the CLI command are verified manually with `wp inventory-export` on a staging shop.
