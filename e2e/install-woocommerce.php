<?php

declare(strict_types=1);

/**
 * Build-time installer for the WooCommerce lifecycle e2e (Dockerfile.woocommerce).
 *
 * Runs after `install.php` has done a headless WP install. This script:
 *   1. Activates the bundled WooCommerce plugin (files were copied by the
 *      Dockerfile),
 *   2. Skips the WooCommerce onboarding wizard (sets the flags WC checks for),
 *   3. Creates one simple product ("E2E Product") with a known price so the
 *      Store API cart totals are deterministic,
 *   4. Prints the product ID on stdout so the e2e script can capture it.
 *
 * NB: run under plain PHP CLI (SQLite backend), NOT under the ephpm worker.
 */

$abspath = rtrim(getenv('EPHPM_WP_PATH') ?: '/var/www/html', '/') . '/';

define('WP_INSTALLING', true);
define('WP_USE_THEMES', false);
define('WP_ADMIN', true);

require $abspath . 'wp-load.php';
require ABSPATH . 'wp-admin/includes/plugin.php';
require ABSPATH . 'wp-admin/includes/upgrade.php';

// Activate WooCommerce. `silent = true` avoids the activation redirect / wizard.
$activate = activate_plugin('woocommerce/woocommerce.php', '', false, true);
if (is_wp_error($activate)) {
    fwrite(STDERR, 'WC activation failed: ' . $activate->get_error_message() . "\n");
    exit(1);
}

// Skip the onboarding wizard — the flags WooCommerce actually checks.
update_option('woocommerce_onboarding_profile', ['completed' => true, 'skipped' => true]);
update_option('woocommerce_task_list_hidden', 'yes');
update_option('woocommerce_task_list_complete', 'yes');
update_option('woocommerce_admin_install_timestamp', time());

// Run WC install so its tables get created (wc_customer_lookup, etc.). WooCommerce
// hooks its installer onto activation; make sure the DB is fully migrated even
// without a browser hit.
if (class_exists('\\WC_Install') && method_exists('\\WC_Install', 'install')) {
    \WC_Install::install();
}

// Sanity: WC must load cleanly here.
if (!function_exists('WC')) {
    fwrite(STDERR, "WC() not available after activation\n");
    exit(1);
}

// Create two simple products with known prices (the e2e two-user isolation
// gate needs one product per shopper).
$product = new \WC_Product_Simple();
$product->set_name('E2E Product');
$product->set_slug('e2e-product');
$product->set_regular_price('9.99');
$product->set_price('9.99');
$product->set_stock_status('instock');
$product->set_catalog_visibility('visible');
$product->set_status('publish');
$product_id = $product->save();

if (!$product_id) {
    fwrite(STDERR, "WC product creation failed\n");
    exit(1);
}

$product2 = new \WC_Product_Simple();
$product2->set_name('E2E Product 2');
$product2->set_slug('e2e-product-2');
$product2->set_regular_price('19.99');
$product2->set_price('19.99');
$product2->set_stock_status('instock');
$product2->set_catalog_visibility('visible');
$product2->set_status('publish');
$product_id_2 = $product2->save();

if (!$product_id_2) {
    fwrite(STDERR, "WC second product creation failed\n");
    exit(1);
}

// Emit the product IDs for the e2e script to capture.
fwrite(STDOUT, "WC_PRODUCT_ID={$product_id}\n");
fwrite(STDOUT, "WC_PRODUCT_ID_2={$product_id_2}\n");
exit(0);
