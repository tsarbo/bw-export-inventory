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
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.8
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
