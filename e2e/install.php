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
// NOTE: post_content is deliberately PLAIN text (no Gutenberg block markup).
// WordPress block rendering (do_blocks()) currently crashes the ePHPm worker
// (see e2e findings / README known limitations), so the golden-path fixtures
// avoid block content to stay deterministic.
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

// Rewrite the default "Hello world!" post (id 1) to PLAIN content — its stock
// body is block markup that crashes the worker's block renderer.
$hello = get_post(1);
if ($hello) {
    wp_update_post([
        'ID'           => 1,
        'post_content' => 'Welcome to the ePHPm worker e2e site. Plain-text body (no blocks).',
    ]);
}

// Pretty permalinks would need rewrite rules flushed; keep default (plain) so
// ?p=<id> and ?page_id work without .htaccess.
update_option('permalink_structure', '');

// Activate the minimal CLASSIC theme (block themes crash the worker via the
// block rendering engine — see e2e findings).
switch_theme('ephpm-e2e-classic');
update_option('template', 'ephpm-e2e-classic');
update_option('stylesheet', 'ephpm-e2e-classic');

fwrite(STDOUT, "WordPress installed. Golden post id={$post_id}\n");
exit(0);
