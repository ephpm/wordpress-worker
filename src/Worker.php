<?php

declare(strict_types=1);

namespace Ephpm\WordPress;

use Ephpm\Worker\Runtime;
use Throwable;

/**
 * Runs WordPress under ePHPm persistent worker mode.
 *
 * WordPress is procedural, not a re-entrant kernel: there is no "handle one
 * request" callable you can invoke in a loop, and its request cycle (`wp()`, the
 * template loader, the active theme) assumes it runs at GLOBAL scope. Because a
 * `require` inside a class method executes in method scope (trapping the
 * top-level `$wpdb`/`$wp_query`/… variables WordPress creates without `global`),
 * **the request loop itself must live in the worker entry script at global
 * scope** — not inside a method here. This class therefore provides the pieces
 * the entry script calls around the global-scope `wp()` + template run:
 *
 *   - {@see defineBootConstants()} + a global-scope `require wp-load.php`
 *     (in the entry script) boot WordPress ONCE.
 *   - {@see installHooks()} installs the per-worker hooks (redirect capture).
 *   - {@see beforeRequest()} marshals the Envelope into the superglobals, resets
 *     request-scoped globals, and returns a routed entry script (or null for the
 *     front controller).
 *   - {@see finishResponse()} turns the captured output buffer into the
 *     `[status, headers, body]` triple for `send_response()`.
 *
 * See `bin/ephpm-wp-worker` for the canonical global-scope loop.
 *
 * EMPIRICAL engine behaviour this adapter is built around (observed in the e2e
 * harness against a real ePHPm worker build — these CORRECT some of the
 * originally-assumed facts):
 *   - With `worker_populate_superglobals = true` the engine natively populates
 *     `$_GET` and `$_COOKIE`, but leaves `$_SERVER` essentially EMPTY (no
 *     `REQUEST_URI`/`REQUEST_METHOD`/`HTTP_HOST`). So this adapter populates
 *     `$_SERVER` from the Envelope itself, and MUST NOT reassign `$_GET`/
 *     `$_COOKIE` — replacing the engine-owned superglobal zvals crashes the
 *     worker. `$_POST`/`$_FILES` are also never populated natively, so the
 *     adapter parses the raw body for those.
 *   - `wp_redirect()` ends in `exit`; letting WordPress `exit` mid-request from
 *     `redirect_canonical` (e.g. on a WP_HOME host/port mismatch) crashes the
 *     worker. {@see installHooks()} intercepts `wp_redirect` and unwinds
 *     cleanly via {@see RedirectSignal} so the loop emits a real 3xx instead.
 *   - `send_response()` concatenates captured `echo` output with the explicit
 *     `$body`, so the adapter `ob_start()`s and passes the captured buffer as
 *     `$body` (native output stays at zero).
 *
 * HONEST CAVEATS (see README → Known limitations, validated by the e2e suite):
 *   - WordPress **block rendering** (`do_blocks()` / block themes) crashes the
 *     worker; use classic themes and non-block content.
 *   - Only the request-scoped globals this class knows about are reset; anything
 *     a plugin stashes elsewhere can leak. Recycling bounds the blast radius.
 */
final class Worker
{
    /**
     * Request-scoped WordPress globals that MUST be reset between requests by
     * UNSETTING them. WordPress lazily re-creates or re-derives each of these
     * during `WP::main()` / the template stack, so clearing them prevents the
     * previous request's values from bleeding forward.
     *
     * NOTE: the query *objects* `$wp`, `$wp_query` and `$wp_the_query` are NOT
     * in this list — they are boot-once infrastructure that `wp()` mutates in
     * place (unsetting them makes `wp()` fatal with "Call to a member function
     * main() on null"). They are instead re-initialised to fresh instances by
     * {@see resetRequestGlobals()}. Kept as a named constant so the exact
     * key-list is directly unit-testable and reviewable.
     *
     * @var list<string>
     */
    public const RESET_GLOBALS = [
        // The post loop / template globals.
        'post',
        'authordata',
        'currentday',
        'currentmonth',
        'page',
        'pages',
        'multipage',
        'more',
        'numpages',
        // Routing / header state.
        'wp_did_header',
        // Misc per-request state.
        'pagenow',
        'typenow',
        'taxnow',
    ];

    /**
     * @param string $absPath absolute path to the WordPress root (ABSPATH),
     *                        with a trailing slash
     */
    public function __construct(private readonly string $absPath)
    {
    }

    /**
     * Define the constants WordPress needs for a headless worker boot.
     *
     * Call this from the entry script BEFORE requiring `wp-load.php` at global
     * scope. Safe to call from any scope (it only `define()`s constants).
     *
     * @param string $absPath the WordPress root (ABSPATH), trailing slash optional
     */
    public static function defineBootConstants(string $absPath): void
    {
        if (!\defined('ABSPATH')) {
            \define('ABSPATH', \rtrim($absPath, '/\\') . '/');
        }

        // WP_USE_THEMES is normally set by index.php; a headless boot must set it
        // so template-loader.php renders the theme for front-end requests.
        if (!\defined('WP_USE_THEMES')) {
            \define('WP_USE_THEMES', true);
        }

        // A boot marker so tooling can prove WordPress bootstrapped ONCE per
        // worker rather than per request.
        if (!\defined('EPHPM_WP_BOOTED')) {
            \define('EPHPM_WP_BOOTED', \microtime(true));
        }
    }

    /**
     * Install the per-worker WordPress hooks. Call ONCE after boot (from the
     * entry script's global scope, before the request loop).
     *
     * Two interceptions, both to avoid a mid-`wp()` `exit`/`die` (which crashes
     * the resident worker, unlike an exit from a top-level required script):
     *
     *   - `wp_redirect`: WordPress redirects with `wp_redirect($loc); exit;`.
     *     The filter throws a {@see RedirectSignal} the loop turns into a 3xx.
     *   - `rest_pre_serve_request`: the REST server ends `serve_request()` with
     *     `die()`. The filter serialises the response itself, then throws a
     *     {@see RestServed} signal the loop turns into the JSON response — so the
     *     REST API works without the crashing die().
     */
    public function installHooks(): void
    {
        if (!\function_exists('add_filter')) {
            return;
        }

        add_filter('wp_redirect', static function ($location, $status) {
            // Unwind cleanly to the worker loop instead of letting WP exit().
            throw new RedirectSignal(
                \is_string($location) ? $location : '/',
                \is_int($status) && $status >= 300 && $status < 400 ? $status : 302,
            );
        }, \PHP_INT_MAX, 2);

        add_filter('rest_pre_serve_request', static function ($served, $result, $request, $server) {
            // Serialise the REST response here and unwind, so WordPress' own
            // echo + die() in WP_REST_Server::serve_request() never runs.
            $embed = isset($_GET['_embed']) && $_GET['_embed'] !== '0';
            $data = $server->response_to_data($result, $embed);
            $json = \wp_json_encode($data);
            if ($json === false) {
                $json = '{"code":"rest_encoding_error"}';
            }
            $status = $result instanceof \WP_REST_Response ? $result->get_status() : 200;

            throw new RestServed((string) $json, \is_int($status) ? $status : 200);
        }, \PHP_INT_MAX, 4);
    }

    /**
     * Prepare for one request: marshal the Envelope into the superglobals and
     * reset request-scoped WordPress globals. Returns a concrete entry script to
     * `require` (e.g. `wp-login.php`) or null when the request should run through
     * the front controller (`wp()` + the template loader).
     *
     * MUST be called from the entry script's global scope, immediately before
     * the global-scope `wp()` + template run (WordPress' request cycle assumes
     * global scope — see the class docblock).
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     *
     * @return string|null absolute entry-script path, or null for front controller
     */
    public function beforeRequest(object $envelope): ?string
    {
        $this->marshalSuperglobals($envelope);
        $this->resetRequestGlobals();

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        // REST route rewrite: with plain permalinks (no rewrite rules) WordPress
        // reaches the REST server via ?rest_route=/… rather than /wp-json/….
        // Translate so the REST API works without pretty permalinks.
        $restRoute = self::restRoute($uri);
        if ($restRoute !== null) {
            $_GET['rest_route'] = $restRoute;
            $_REQUEST['rest_route'] = $restRoute;
        }

        $target = self::routeScript($uri, $this->absPath);
        if ($target !== null) {
            $_SERVER['SCRIPT_FILENAME'] = $target;
            $_SERVER['SCRIPT_NAME'] = '/' . \ltrim(\substr($target, \strlen($this->absPath)), '/');
            $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
        }

        return $target;
    }

    /**
     * Map a `/wp-json[/PATH]` request URI to the `rest_route` value WordPress
     * expects with plain permalinks (`/`, `/wp/v2/posts`, …), or null when the
     * URI is not a REST request. Pure and testable.
     */
    public static function restRoute(string $requestUri): ?string
    {
        $path = \parse_url($requestUri, \PHP_URL_PATH);
        if (!\is_string($path)) {
            return null;
        }
        $path = '/' . \ltrim($path, '/');

        if ($path === '/wp-json' || $path === '/wp-json/') {
            return '/';
        }
        if (\str_starts_with($path, '/wp-json/')) {
            return '/' . \ltrim(\substr($path, \strlen('/wp-json/')), '/');
        }

        return null;
    }

    /**
     * Build the `[status, headers, body]` triple for `send_response()` from a
     * captured output-buffer body plus WordPress' emitted status/headers.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public static function finishResponse(string $body): array
    {
        return [self::currentStatus(), self::collectHeaders(), $body];
    }

    /**
     * Build a 3xx redirect response triple from a captured {@see RedirectSignal}.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public static function redirectResponse(RedirectSignal $r): array
    {
        return [
            $r->status,
            ['Location' => $r->location, 'Content-Type' => 'text/html; charset=UTF-8'],
            '',
        ];
    }

    /**
     * Build a JSON REST response triple from a captured {@see RestServed}.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public static function restResponse(RestServed $r): array
    {
        return [
            $r->status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
            $r->json,
        ];
    }

    /**
     * Build the 500 response triple for a request that threw.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public static function errorResponseTriple(Throwable $e): array
    {
        return self::errorResponse($e);
    }

    /**
     * Map a request URI to a concrete PHP entry script under `$absPath`, or null
     * when the request should run through the front controller.
     *
     * Pure and testable: takes the raw REQUEST_URI + the WordPress root and
     * returns an absolute script path (guaranteed to live under `$absPath`) or
     * null. Directory-traversal-safe via realpath containment.
     *
     * `/`, paths with no `.php` segment, and `/index.php` all return null (front
     * controller). `/wp-login.php`, `/wp-admin/edit.php`, … return the file.
     */
    public static function routeScript(string $requestUri, string $absPath): ?string
    {
        $path = \parse_url($requestUri, \PHP_URL_PATH);
        if (!\is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        // Only .php targets route to a script; everything else is front-end.
        $trimmed = \ltrim($path, '/');
        if ($trimmed === 'index.php' || !\str_contains($trimmed, '.php')) {
            return null;
        }

        // Keep only the part up to and including the first ".php" segment, so
        // /wp-admin/edit.php/extra still resolves to wp-admin/edit.php.
        $phpPos = \strpos($trimmed, '.php');
        $scriptRel = \substr($trimmed, 0, $phpPos + 4);

        $root = \rtrim($absPath, '/\\');
        $candidate = $root . '/' . $scriptRel;

        $real = \realpath($candidate);
        if ($real === false || !\is_file($real)) {
            return null;
        }

        // Containment: the resolved file MUST live under the WordPress root.
        $realRoot = \realpath($root);
        if ($realRoot === false || !\str_starts_with($real, $realRoot . \DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * Seed the PHP superglobals from the request Envelope.
     *
     * EMPIRICAL (see class docblock): the engine natively populates `$_GET` and
     * `$_COOKIE` but leaves `$_SERVER` essentially empty. So this method:
     *   - populates `$_SERVER` from the Envelope (WordPress needs REQUEST_URI /
     *     HTTP_HOST / REQUEST_METHOD to route);
     *   - does NOT reassign `$_GET` / `$_COOKIE` — replacing the engine-owned
     *     superglobal zvals crashes the worker; the engine already set them;
     *   - parses the raw body into `$_POST` / `$_FILES`, which the native path
     *     never populates;
     *   - rebuilds `$_REQUEST` from the (engine-owned) `$_GET` plus our `$_POST`.
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     */
    public function marshalSuperglobals(object $envelope): void
    {
        /** @var array<string, mixed> $server */
        $server = $envelope->serverVars();
        $rawBody = (string) $envelope->rawBody();

        // Populate $_SERVER from the Envelope (engine leaves it empty). Assigning
        // individual keys (rather than replacing the whole array) avoids
        // disturbing any engine-owned $_SERVER entries.
        foreach ($server as $k => $v) {
            $_SERVER[$k] = $v;
        }

        $contentType = self::headerValue($server, 'CONTENT_TYPE');
        $method = \strtoupper((string) ($server['REQUEST_METHOD'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET')));

        [$post, $files] = self::parseBody($method, $contentType, $rawBody, $envelope);

        // $_POST / $_FILES are ours to own (engine never populates them). These
        // are plain PHP arrays, safe to (re)assign.
        $_POST = $post;
        $_FILES = $files;

        // $_REQUEST = engine-owned $_GET + our $_POST (PHP default request_order
        // "GP"). Read $_GET, do not replace it.
        $_REQUEST = \array_merge($_GET ?? [], $post);

        // php://input parity: expose the raw body for REST/plugins that read it.
        $GLOBALS['EPHPM_WP_RAW_BODY'] = $rawBody;
    }

    /**
     * Reset request-scoped WordPress state before re-running the main query.
     *
     * Two-part reset:
     *   1. Unset the transient loop/template globals in {@see RESET_GLOBALS} —
     *      WordPress re-derives them during the template stack.
     *   2. Re-instantiate the query objects (`$wp_query`, `$wp_the_query`) as
     *      fresh `WP_Query` instances and reset the `$wp` router's per-request
     *      fields IN PLACE (not unset — `wp()` calls `$wp->main()` on it).
     *
     * Booted-once state (`$wpdb`, `$wp_object_cache`, the `$wp_filter` hook
     * arrays) is deliberately left untouched.
     */
    public function resetRequestGlobals(): void
    {
        foreach (self::RESET_GLOBALS as $name) {
            unset($GLOBALS[$name]);
        }

        // Fresh query objects so the previous request's posts/flags never carry
        // over. WP_Query only exists once WordPress is booted.
        if (\class_exists('WP_Query', false)) {
            $GLOBALS['wp_the_query'] = new \WP_Query();
            $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];
        }

        // Reset the resident WP router object in place. Its constructor-set
        // fields are cleared so parse_request() starts clean; the object itself
        // is preserved because wp() calls $wp->main() on this exact global.
        if (isset($GLOBALS['wp']) && $GLOBALS['wp'] instanceof \WP) {
            $wp = $GLOBALS['wp'];
            $wp->query_vars = [];
            $wp->query_string = '';
            $wp->matched_rule = '';
            $wp->matched_query = '';
            $wp->did_permalink = false;
            $wp->request = '';
        } elseif (\class_exists('WP', false)) {
            $GLOBALS['wp'] = new \WP();
        }

        // Flush only the runtime (non-persistent) object cache; a persistent
        // backend (ephpm/cache-wordpress) manages its own per-request reset.
        wp_cache_flush_runtime_if_available();
    }

    // ---------------------------------------------------------------------
    // Pure, unit-testable helpers (no WordPress, no native primitives).
    // ---------------------------------------------------------------------

    /**
     * Parse a request body into `[$_POST, $_FILES]`.
     *
     * Handles the two content types WordPress form handling relies on:
     *   - `application/x-www-form-urlencoded` → `parse_str()` into `$_POST`.
     *   - `multipart/form-data` → fields into `$_POST`, uploads into a
     *     `$_FILES`-shaped array (each file spooled to a temp file so
     *     `is_uploaded_file()`-free code and `move_uploaded_file` fallbacks work;
     *     WordPress' `wp_handle_upload` accepts an `action` override in tests).
     *
     * Anything else (JSON, raw) leaves `$_POST` / `$_FILES` empty — REST clients
     * read `php://input` / the Envelope raw body directly.
     *
     * @param object $envelope kept for signature symmetry / future streaming
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function parseBody(
        string $method,
        ?string $contentType,
        string $rawBody,
        object $envelope,
    ): array {
        unset($envelope);

        if ($method === 'GET' || $method === 'HEAD' || $rawBody === '') {
            return [[], []];
        }

        $ct = \strtolower($contentType ?? '');

        if (\str_starts_with($ct, 'application/x-www-form-urlencoded')) {
            $post = [];
            \parse_str($rawBody, $post);

            return [$post, []];
        }

        if (\str_starts_with($ct, 'multipart/form-data')) {
            $boundary = self::multipartBoundary($contentType ?? '');
            if ($boundary === null) {
                return [[], []];
            }

            return self::parseMultipart($rawBody, $boundary);
        }

        // JSON / raw / unknown: nothing to marshal into $_POST.
        return [[], []];
    }

    /**
     * Extract the boundary token from a multipart Content-Type header.
     */
    public static function multipartBoundary(string $contentType): ?string
    {
        if (\preg_match('/boundary=(?:"([^"]+)"|([^;,\s]+))/i', $contentType, $m) !== 1) {
            return null;
        }

        $boundary = $m[1] !== '' ? $m[1] : ($m[2] ?? '');

        return $boundary !== '' ? $boundary : null;
    }

    /**
     * Parse a `multipart/form-data` body into `[$_POST, $_FILES]`.
     *
     * A pragmatic parser: it covers simple and nested (`name[]`, `name[key]`)
     * field names and file uploads. It does NOT try to be a byte-perfect clone
     * of PHP's C rfc1867 parser — the e2e suite validates real WordPress form
     * posts end-to-end.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function parseMultipart(string $body, string $boundary): array
    {
        $post = [];
        $files = [];

        $delimiter = '--' . $boundary;
        // Split on the delimiter; drop the preamble and the closing "--" epilogue.
        $parts = \explode($delimiter, $body);
        foreach ($parts as $part) {
            $part = \ltrim($part, "\r\n");
            if ($part === '' || $part === '--' || \str_starts_with($part, '--')) {
                continue;
            }

            $split = \preg_split("/\r\n\r\n/", $part, 2);
            if ($split === false || \count($split) < 2) {
                continue;
            }
            [$rawHeaders, $value] = $split;
            // Strip the trailing CRLF that precedes the next delimiter.
            $value = \preg_replace('/\r\n$/', '', $value) ?? $value;

            $headers = self::parsePartHeaders($rawHeaders);
            $disposition = $headers['content-disposition'] ?? '';

            if (\preg_match('/name="([^"]*)"/', $disposition, $nm) !== 1) {
                continue;
            }
            $name = $nm[1];

            if (\preg_match('/filename="([^"]*)"/', $disposition, $fm) === 1) {
                self::assignFile(
                    $files,
                    $name,
                    $fm[1],
                    $headers['content-type'] ?? 'application/octet-stream',
                    $value,
                );

                continue;
            }

            self::assignField($post, $name, $value);
        }

        // Drop the internal bracket-accumulation scratch key.
        unset($post['__ephpm_bracket_buf__']);

        return [$post, $files];
    }

    /**
     * Parse the header block of one multipart part into a lowercased map.
     *
     * @return array<string, string>
     */
    private static function parsePartHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (\preg_split("/\r\n/", $rawHeaders) ?: [] as $line) {
            $pos = \strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = \strtolower(\trim(\substr($line, 0, $pos)));
            $headers[$key] = \trim(\substr($line, $pos + 1));
        }

        return $headers;
    }

    /**
     * Assign a scalar form field into `$post`, honouring `name[]` / `name[key]`
     * bracket syntax.
     *
     * PHP's rfc1867/urlencoded parser treats each `name[]` occurrence as an
     * append (successive values get indices 0, 1, 2, …). We reproduce that by
     * collecting all bracketed fields for the whole part list into a single
     * urlencoded query string and letting `parse_str` build the nested array in
     * one pass — that is the only way to get append semantics right without
     * re-implementing PHP's symbol-table logic.
     *
     * @param array<string, mixed> $post
     */
    private static function assignField(array &$post, string $name, string $value): void
    {
        if (!\str_contains($name, '[')) {
            $post[$name] = $value;

            return;
        }

        // Accumulate bracketed pairs in a hidden query buffer, then re-parse the
        // whole buffer so parse_str applies real append/merge semantics.
        $buffer = $post['__ephpm_bracket_buf__'] ?? '';
        if ($buffer !== '') {
            $buffer .= '&';
        }
        $buffer .= self::encodeBracketName($name) . '=' . \rawurlencode($value);
        $post['__ephpm_bracket_buf__'] = $buffer;

        $parsed = [];
        \parse_str($buffer, $parsed);
        foreach ($parsed as $k => $v) {
            $post[$k] = $v;
        }
    }

    /**
     * URL-encode a bracketed field name so parse_str keeps the brackets but
     * encodes the key/segments.
     */
    private static function encodeBracketName(string $name): string
    {
        return \preg_replace_callback(
            '/[^\[\]]+/',
            static fn (array $m): string => \rawurlencode($m[0]),
            $name,
        ) ?? $name;
    }

    /**
     * Spool one uploaded file to a temp path and register it in `$files`
     * in `$_FILES` shape.
     *
     * @param array<string, mixed> $files
     */
    private static function assignFile(
        array &$files,
        string $name,
        string $filename,
        string $type,
        string $content,
    ): void {
        $tmp = \tempnam(\sys_get_temp_dir(), 'ephpm-wp-upl');
        $error = \UPLOAD_ERR_OK;
        if ($tmp === false) {
            $tmp = '';
            $error = \UPLOAD_ERR_CANT_WRITE;
        } elseif (\file_put_contents($tmp, $content) === false) {
            $error = \UPLOAD_ERR_CANT_WRITE;
        }

        $files[$name] = [
            'name' => $filename,
            'type' => $type,
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => \strlen($content),
        ];
    }

    /**
     * The HTTP status WordPress set for the current request.
     *
     * Reads `http_response_code()`; defaults to 200. Extracted so it can be
     * stubbed in tests.
     */
    public static function currentStatus(): int
    {
        $code = \http_response_code();

        return \is_int($code) && $code >= 100 ? $code : 200;
    }

    /**
     * Collect the response headers WordPress emitted via `header()` and flatten
     * `headers_list()` (`['Name: value', ...]`) into the `['Name' => 'value']`
     * map that `send_response()` expects.
     *
     * Multiple values for the same header (e.g. several `Set-Cookie`s) are
     * joined with a comma to preserve them in the single-string-per-name shape.
     *
     * @return array<string, string>
     */
    public static function collectHeaders(): array
    {
        return self::flattenHeaderLines(\headers_list());
    }

    /**
     * Pure flattener for a `headers_list()`-shaped array. Split from
     * {@see collectHeaders()} so it is testable without a live SAPI.
     *
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    public static function flattenHeaderLines(array $lines): array
    {
        $flat = [];
        foreach ($lines as $line) {
            $pos = \strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = \trim(\substr($line, 0, $pos));
            $value = \trim(\substr($line, $pos + 1));
            if ($name === '') {
                continue;
            }

            $flat[$name] = isset($flat[$name])
                ? $flat[$name] . ', ' . $value
                : $value;
        }

        return $flat;
    }

    /**
     * Read a CGI-style header value out of a `$_SERVER`-shaped array.
     *
     * @param array<string, mixed> $server
     */
    private static function headerValue(array $server, string $cgiName): ?string
    {
        if (isset($server[$cgiName]) && \is_scalar($server[$cgiName])) {
            return (string) $server[$cgiName];
        }
        $httpName = 'HTTP_' . $cgiName;
        if (isset($server[$httpName]) && \is_scalar($server[$httpName])) {
            return (string) $server[$httpName];
        }

        return null;
    }

    /**
     * Build the 500 response triple used when the request throws.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private static function errorResponse(Throwable $e): array
    {
        // Log for the app's own tracing; do not leak internals to the client.
        \error_log('[ephpm/wordpress-worker] request failed: ' . $e::class . ': ' . $e->getMessage());

        return [
            500,
            ['Content-Type' => 'text/html; charset=UTF-8'],
            "<!DOCTYPE html>\n<html><head><title>Error</title></head>"
            . "<body><h1>Internal Server Error</h1></body></html>",
        ];
    }
}

/**
 * Thrown by the `wp_redirect` filter installed in {@see Worker::installHooks()}
 * to unwind cleanly to the worker loop instead of letting WordPress `exit`
 * mid-request (which crashes the worker). The loop catches it and emits a real
 * 3xx via {@see Worker::redirectResponse()}.
 */
final class RedirectSignal extends \RuntimeException
{
    public function __construct(
        public readonly string $location,
        public readonly int $status = 302,
    ) {
        parent::__construct('wp_redirect intercepted: ' . $location);
    }
}

/**
 * Thrown by the `rest_pre_serve_request` filter in {@see Worker::installHooks()}
 * to unwind cleanly with the serialised REST JSON instead of letting
 * `WP_REST_Server::serve_request()` echo + `die()` (which crashes the worker).
 * The loop catches it and emits the JSON via {@see Worker::restResponse()}.
 */
final class RestServed extends \RuntimeException
{
    public function __construct(
        public readonly string $json,
        public readonly int $status = 200,
    ) {
        parent::__construct('REST response served (' . $status . ')');
    }
}

/**
 * Flush only the in-process (non-persistent) WordPress object cache, if the
 * installed backend supports a runtime-only flush; otherwise fall back to a
 * full `wp_cache_flush()`.
 *
 * Declared as a plain function (not a method) so it can be no-op'd trivially in
 * unit tests by not defining the WordPress cache functions.
 *
 * @internal
 */
function wp_cache_flush_runtime_if_available(): void
{
    if (\function_exists('wp_cache_flush_runtime')) {
        wp_cache_flush_runtime();
    } elseif (\function_exists('wp_cache_flush')) {
        // A persistent backend (ephpm/cache-wordpress) makes wp_cache_flush()
        // clear only its local runtime map by design, so this is safe there too.
        wp_cache_flush();
    }
}
