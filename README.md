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
- A name or SKU starting with `=`, `+`, `-`, `@`, a tab or a carriage return is prefixed with `'`, so Excel shows it
  as text instead of running it as a formula (CSV injection protection). Excel displays that `'` in the cell,
  e.g. a SKU `-A12` appears as `'-A12`.
- `price` is the current selling price: the sale price while a sale is active, otherwise the regular price.
  It is blank when no price is set, and always blank on parent rows. It uses the shop's decimal separator and
  number of decimals (WooCommerce → Settings → General), with no thousands separator.
- Other product types (grouped, external, …) are skipped and counted in the command's summary.
- Names, prices and stock quantities are the values stored in WooCommerce, not as changed by translation, pricing
  or currency plugins. Decimal stock quantities (from plugins that allow fractional stock) are not supported.
- The last row is `TOTAL (stock-managed rows)` with the sum of the `stock_quantity` column.

## Development

```
composer install
composer test
```

## License

GPL-2.0-or-later
