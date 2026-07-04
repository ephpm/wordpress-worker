<?php
/**
 * Plugin Name: ePHPm Worker E2E probes
 * Description: Must-use plugin exposing probe endpoints for the worker e2e
 *              suite: boot counter (BOOT-ONCE), per-request global mutation
 *              (PLUGIN MUTATION), state echo (STATE-LEAKAGE), fatal trigger
 *              (FATAL-IN-HOOK).
 *
 * IMPORTANT worker-mode facts encoded here:
 *   - mu-plugins are loaded ONCE per worker boot. The `init` action ALSO fires
 *     only once per boot in worker mode (it runs inside wp-settings.php during
 *     boot, not per request). So a per-request counter must hook a PER-REQUEST
 *     action — `template_redirect`, which fires on every wp() dispatch.
 *   - This ePHPm build delivers a request's captured output even when the
 *     script ends via exit()/die() (verified: wp-login.php returns 200), so the
 *     probe may echo + exit like a normal WordPress AJAX/REST short-circuit.
 */

// --- BOOT-ONCE: bump a process-lifetime counter at plugin load (once/boot). ---
$GLOBALS['__ephpm_boot_count'] = ($GLOBALS['__ephpm_boot_count'] ?? 0) + 1;

/**
 * Is the CURRENT request a probe request? Re-evaluated each call (never cached)
 * so the answer tracks the per-request $_SERVER.
 */
function ephpm_is_probe(): bool
{
    return str_contains($_SERVER['REQUEST_URI'] ?? '', 'ephpm-probe');
}

// Suppression filters are registered ONCE (at plugin load) and self-gate on the
// probe URL. Registering them per-probe-request would LEAK into later requests
// on the same persistent worker (hooks are boot-once state — a real worker-mode
// gotcha this suite deliberately avoids re-introducing).
add_filter('posts_pre_query', static function ($posts) {
    return ephpm_is_probe() ? [] : $posts;   // skip the DB query for probes only
}, PHP_INT_MAX);
add_filter('template_include', static function ($template) {
    return ephpm_is_probe() ? __DIR__ . '/empty-template.php' : $template;
}, PHP_INT_MAX);
// Force a 200 for probe responses (suppressing the main query would otherwise
// make WordPress set 404).
add_filter('status_header', static function ($header, $code) {
    if (ephpm_is_probe() && $code === 404) {
        return 'HTTP/1.1 200 OK';
    }
    return $header;
}, PHP_INT_MAX, 2);
add_action('template_redirect', static function (): void {
    if (ephpm_is_probe()) {
        status_header(200);
    }
}, 0);

// --- Per-request work runs on template_redirect (fires every wp() dispatch). --
add_action('template_redirect', static function (): void {
    // Request counter — distinguishes boots from requests.
    $GLOBALS['__ephpm_request_count'] = ($GLOBALS['__ephpm_request_count'] ?? 0) + 1;

    // PLUGIN MUTATION: reset this plugin's scratch each request, then append the
    // tag. If the worker's per-request reset were broken, request N would still
    // see request N-1's value.
    $GLOBALS['ephpm_plugin_scratch'] = [];
    $tag = isset($_GET['tag']) ? (string) $_GET['tag'] : '(none)';
    $GLOBALS['ephpm_plugin_scratch'][] = $tag;

    if (!ephpm_is_probe()) {
        return;
    }

    // FATAL-IN-HOOK: trigger an uncatchable fatal to exercise worker recycling.
    if (($_GET['boom'] ?? '') === '1') {
        ephpm_this_function_does_not_exist_and_will_fatal(); // intentional fatal
    }

    header('Content-Type: text/plain; charset=UTF-8');
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
    // NB: no exit() — an exit() deep inside wp() crashes the worker. wp() returns
    // normally to the worker loop; the empty template above suppresses theme HTML.
}, 1);
