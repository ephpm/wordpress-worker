<?php
/**
 * Plugin Name: ePHPm WC lifecycle probe
 * Description: Records the $_GET a wp_loaded handler observes, the
 *              did_action() counters for the re-fired lifecycle, and the
 *              wall-clock time between a wp_loaded handler and the request
 *              finishing. Read via /?ephpm-wc-probe=1.
 */

$GLOBALS['ephpm_wc_probe'] = [
    'wp_loaded_get_add_to_cart' => '(none)',
    'wp_loaded_fired_count' => 0,
    't_wp_loaded_start_us' => 0,
];

add_action('wp_loaded', static function (): void {
    $GLOBALS['ephpm_wc_probe']['wp_loaded_get_add_to_cart']
        = $_GET['add-to-cart'] ?? '(none)';
    $GLOBALS['ephpm_wc_probe']['wp_loaded_fired_count']++;
    $GLOBALS['ephpm_wc_probe']['t_wp_loaded_start_us'] = (int) (microtime(true) * 1e6);
}, 1);

add_action('template_redirect', static function (): void {
    if (!isset($_GET['ephpm-wc-probe'])) {
        return;
    }
    $probe = $GLOBALS['ephpm_wc_probe'];
    $now_us = (int) (microtime(true) * 1e6);
    header('Content-Type: text/plain; charset=UTF-8');
    status_header(200);
    printf("did_action_init=%d\n", (int) did_action('init'));
    printf("did_action_wp_loaded=%d\n", (int) did_action('wp_loaded'));
    printf("wp_loaded_get_add_to_cart=%s\n", $probe['wp_loaded_get_add_to_cart']);
    printf("wp_loaded_fired_count=%d\n", $probe['wp_loaded_fired_count']);
    printf("t_wp_loaded_to_now_us=%d\n", $now_us - $probe['t_wp_loaded_start_us']);
    exit;
}, PHP_INT_MAX);
