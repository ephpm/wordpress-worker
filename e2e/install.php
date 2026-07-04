<?php

declare(strict_types=1);

/**
 * Headless WordPress install for the e2e image (build time, plain PHP CLI).
 *
 * Runs wp_install() against the SQLite backend so the shipped image already has
 * an admin user and a first post — no runtime setup wizard. Mirrors what
 * wp-admin/install.php does, without the HTML.
 */

$abspath = rtrim(getenv('EPHPM_WP_PATH') ?: '/var/www/html', '/') . '/';

define('WP_INSTALLING', true);
define('WP_USE_THEMES', false);

require $abspath . 'wp-load.php';
require ABSPATH . 'wp-admin/includes/upgrade.php';
require ABSPATH . 'wp-includes/class-wp-error.php';

if (is_blog_installed()) {
    fwrite(STDOUT, "WordPress already installed.\n");
    exit(0);
}

$result = wp_install(
    'ePHPm Worker E2E',       // blog title
    'admin',                   // admin username
    'admin@example.com',       // admin email
    true,                      // public
    '',                        // deprecated
    'password123',             // admin password
);

if (is_wp_error($result)) {
    fwrite(STDERR, 'wp_install failed: ' . $result->get_error_message() . "\n");
    exit(1);
}

// Ensure there is a published post beyond the default "Hello world!".
$post_id = wp_insert_post([
    'post_title'   => 'E2E Golden Post',
    'post_content' => 'This is the golden-path post used by the worker e2e suite.',
    'post_status'  => 'publish',
    'post_type'    => 'post',
    'post_author'  => 1,
]);

if (is_wp_error($post_id)) {
    fwrite(STDERR, 'wp_insert_post failed: ' . $post_id->get_error_message() . "\n");
    exit(1);
}

// Pretty permalinks would need rewrite rules flushed; keep default (plain) so
// ?p=<id> and ?page_id work without .htaccess.
update_option('permalink_structure', '');

fwrite(STDOUT, "WordPress installed. Golden post id={$post_id}\n");
exit(0);
