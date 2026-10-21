<?php

// ABOUTME: WP-CLI command `wp inventory-export` that writes in-stock products and variations to a CSV file.
// ABOUTME: Processes product IDs in batches, streams rows to disk, appends the stock total and prints a summary.

declare(strict_types=1);

namespace BeyondWeb\ExportInventory\Cli;

use BeyondWeb\ExportInventory\InventoryRows;
use BeyondWeb\ExportInventory\ProductReader;
use WC_Product;
use WP_CLI;

use function WP_CLI\Utils\make_progress_bar;
use function WP_CLI\Utils\wp_clear_object_cache;

final class ExportInventoryCommand
{
    private const BATCH_SIZE = 500;

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
            WP_CLI::error('--delimiter must be exactly one single-byte character.');
        }
        if (in_array($delimiter, ['"', "\n", "\r"], true)) {
            WP_CLI::error('--delimiter cannot be a double quote or a line break.');
        }

        $file = $assoc_args['file'] ?? 'inventory-' . wp_date('Y-m-d-His') . '.csv';
        $handle = fopen($file, 'w');
        if ($handle === false) {
            WP_CLI::error("Cannot write to: {$file}");
        }

        if (fwrite($handle, self::UTF8_BOM) === false) {
            $this->abortIncomplete($file);
        }
        $this->writeRow($handle, InventoryRows::HEADER, $delimiter, $file);

        $rows = new InventoryRows(wc_get_price_decimal_separator(), wc_get_price_decimals());
        $reader = new ProductReader();
        $written = 0;
        $skippedTypes = [];
        $unreadable = [];

        $ids = $this->productIds($statuses);
        $progress = make_progress_bar('Exporting products', count($ids));

        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            foreach ($batch as $id) {
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
                    $this->writeRow($handle, $row, $delimiter, $file);
                    $written++;
                }
            }

            // Clears only this process's in-memory object cache; a persistent cache (e.g. Redis) is left untouched.
            wp_clear_object_cache();
        }

        $progress->finish();

        $this->writeRow($handle, $rows->totalRow(), $delimiter, $file);
        if (!fclose($handle)) {
            $this->abortIncomplete($file);
        }

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
        $requested = array_values(array_filter($requested, static fn (string $status): bool => $status !== ''));
        if ($requested === []) {
            WP_CLI::error('--status needs at least one status.');
        }
        if (in_array('any', $requested, true)) {
            WP_CLI::error('"any" cannot be combined with other statuses.');
        }

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
     * All matching IDs are fetched up front, so products created or deleted during the run cannot shift
     * later batches and cause skipped or repeated products.
     *
     * @param list<string> $statuses
     * @return list<int>
     */
    private function productIds(array $statuses): array
    {
        return wc_get_products([
            'status' => $statuses,
            'limit' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
        ]);
    }

    /**
     * Aborts the export when the row cannot be written, so a partial file never reports success.
     *
     * @param resource         $handle
     * @param list<int|string> $row
     */
    private function writeRow($handle, array $row, string $delimiter, string $file): void
    {
        if (fputcsv($handle, $row, $delimiter, '"', '') === false) {
            $this->abortIncomplete($file);
        }
    }

    private function abortIncomplete(string $file): never
    {
        WP_CLI::error("Writing to {$file} failed (disk full?). The file is incomplete.");
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
