<?php
/**
 * Plugin Name: ePHPm Worker E2E probes
 * Description: Must-use plugin that exposes probe endpoints for the worker e2e
 *              suite: a boot counter (BOOT-ONCE), a per-request global mutation
 *              (PLUGIN MUTATION), a state echo (STATE-LEAKAGE), and a fatal
 *              trigger (FATAL-IN-HOOK).
 *
 * This file is loaded ONCE at WordPress boot (mu-plugins load during wp-load).
 * The static counters therefore persist for the life of the worker, which is
 * exactly what lets us prove boot-once vs per-request behaviour.
 */

// --- BOOT-ONCE: increment a process-lifetime counter at plugin load time. ----
// mu-plugins are required exactly once per WP boot. If WordPress re-bootstrapped
// per request this file would be re-required and the counter would climb with
// every request. A file-scoped static via a closure keeps it out of $GLOBALS so
// the per-request reset can't touch it.
(static function (): void {
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;
    $GLOBALS['__ephpm_boot_count'] = ($GLOBALS['__ephpm_boot_count'] ?? 0) + 1;
})();

// A request counter, bumped every request via the 'init' hook, to distinguish
// "boots" from "requests".
add_action('init', static function (): void {
    $GLOBALS['__ephpm_request_count'] = ($GLOBALS['__ephpm_request_count'] ?? 0) + 1;
}, 0);

// --- PLUGIN MUTATION: a plugin global mutated per request. -------------------
// On every request this appends the current query-string 'tag' to a plugin
// global. If the worker's per-request reset is incomplete, request N will see
// values from request N-1 in this global.
add_action('init', static function (): void {
    // This global is intentionally NOT in Worker::RESET_GLOBALS — a well-behaved
    // plugin resets its own per-request scratch state on 'init'. The test proves
    // the WORKER doesn't corrupt the NEXT request: we set fresh each request.
    $GLOBALS['ephpm_plugin_scratch'] = [];
    $tag = isset($_GET['tag']) ? (string) $_GET['tag'] : '(none)';
    $GLOBALS['ephpm_plugin_scratch'][] = $tag;
}, 5);

// --- Probe router: intercept /ephpm-probe?... before the main query. ---------
// Registered on 'init' so it runs each request. Emits a plain-text snapshot of
// per-request state so the test can assert no bleed. Exits directly (like an
// AJAX/REST short-circuit) so we don't depend on a theme.
add_action('init', static function (): void {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!str_contains($uri, 'ephpm-probe')) {
        return;
    }

    header('Content-Type: text/plain; charset=UTF-8');

    // FATAL-IN-HOOK: if asked, trigger an uncatchable fatal to exercise worker
    // recycling. A call to an undefined function is a fatal E_ERROR.
    if (($_GET['boom'] ?? '') === '1') {
        ephpm_this_function_does_not_exist_and_will_fatal(); // intentional fatal
    }

    $snapshot = [
        'boot_count' => $GLOBALS['__ephpm_boot_count'] ?? 0,
        'request_count' => $GLOBALS['__ephpm_request_count'] ?? 0,
        'get_tag' => $_GET['tag'] ?? '(none)',
        'cookie_probe' => $_COOKIE['probe'] ?? '(none)',
        'plugin_scratch' => implode(',', $GLOBALS['ephpm_plugin_scratch'] ?? []),
        'post_field' => $_POST['field'] ?? '(none)',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '(none)',
        'pid' => getmypid(),
    ];

    foreach ($snapshot as $k => $v) {
        echo $k, '=', $v, "\n";
    }

    exit;
}, 10);
