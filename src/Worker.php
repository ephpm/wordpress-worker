<?php

declare(strict_types=1);

namespace Ephpm\WordPress;

use Ephpm\Worker\Runtime;
use Throwable;

/**
 * Runs WordPress under ePHPm persistent worker mode.
 *
 * WordPress is procedural, not a re-entrant kernel: there is no "handle one
 * request" callable you can invoke in a loop. This adapter works around that by
 *
 *   1. **Booting WordPress ONCE** — `wp-load.php` is loaded a single time so the
 *      autoloader, `wp-config.php` constants, the `$wpdb` connection, must-use
 *      plugins and the (deterministic) hook graph stay resident in memory.
 *   2. **Resetting request-scoped globals per request** before re-running the
 *      main query, then re-seeding the superglobals from the request Envelope
 *      and re-running `wp()` so the routing, query and template stack execute
 *      fresh for each request.
 *
 * HONEST CAVEAT: because WordPress is procedural, only globals that can be
 * cleanly reset are reset (see {@see RESET_GLOBALS}); anything a plugin stashes
 * elsewhere is a potential leak. The e2e suite — not this class — is the arbiter
 * of correctness. Object caching should use the existing `ephpm/cache-wordpress`
 * drop-in so the persistent object cache is flushed between requests.
 *
 * Engine facts this adapter relies on (verified in ephpm_wrapper.c):
 *   - With `worker_populate_superglobals = true` the engine natively rebuilds
 *     `$_SERVER` / `$_GET` / `$_COOKIE` via `php_hash_environment()` at a
 *     quiescent point and resets its server-var registration each request, so
 *     there is no `$_SERVER` bleed between requests.
 *   - The native path does NOT parse `$_POST` / `$_FILES` — `Envelope::parsedBody()`
 *     returns null. This adapter therefore parses the raw body itself for
 *     `application/x-www-form-urlencoded` and `multipart/form-data` POSTs.
 *   - `send_response()` concatenates any captured `echo` output with the explicit
 *     `$body`. We `ob_start()` and pass the captured buffer as `$body`, leaving
 *     native output at zero, so there is no double-emission.
 *   - The engine resets SAPI headers / status / `http_response_code()` → 200 /
 *     `headers_sent()` → 0 at the start of each iteration.
 */
final class Worker
{
    /**
     * Request-scoped WordPress globals that MUST be reset between requests.
     *
     * These are re-initialised (unset or reassigned) before each request's main
     * query so state from the previous request cannot bleed forward. Kept as a
     * named constant so it is directly unit-testable and reviewable.
     *
     * @var list<string>
     */
    public const RESET_GLOBALS = [
        // The query objects — the single biggest source of leakage.
        'wp_query',
        'wp_the_query',
        'wp',
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
        'is_iis',
        // Misc per-request state.
        'wp_current_filter',
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
     * Boot WordPress once, then enter the worker loop.
     *
     * @return int process exit code (0 on clean shutdown/recycle)
     */
    public function run(): int
    {
        Runtime::assertAvailable();

        $this->boot();

        while (($envelope = \Ephpm\Worker\take_request()) !== null) {
            [$status, $headers, $body] = $this->handleEnvelope($envelope);
            \Ephpm\Worker\send_response($status, $headers, $body);
        }

        return 0;
    }

    /**
     * Load WordPress exactly once.
     *
     * Defines the constants WordPress needs to run headless under a worker, then
     * requires `wp-load.php`. After this returns the framework is fully resident.
     */
    public function boot(): void
    {
        if (!\defined('ABSPATH')) {
            \define('ABSPATH', $this->absPath);
        }

        // WP_USE_THEMES is normally set by index.php; a headless boot must set it
        // so template_loader.php renders the theme for front-end requests.
        if (!\defined('WP_USE_THEMES')) {
            \define('WP_USE_THEMES', true);
        }

        // A boot marker so the e2e suite can prove WordPress bootstrapped ONCE
        // (per worker) rather than per request.
        if (!\defined('EPHPM_WP_BOOTED')) {
            \define('EPHPM_WP_BOOTED', \microtime(true));
        }

        require ABSPATH . 'wp-load.php';
    }

    /**
     * Handle a single request Envelope end-to-end and return the
     * `[status, headers, body]` triple for `send_response()`.
     *
     * Any `Throwable` — including a fatal surfaced by a shutdown handler — is
     * turned into a clean 500 so a single bad request cannot wedge the loop.
     * Engine-level fatals that PHP cannot catch are left to the engine's worker
     * recycler (the e2e FATAL-IN-HOOK test exercises that path).
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public function handleEnvelope(object $envelope): array
    {
        \ob_start();
        try {
            $this->marshalSuperglobals($envelope);
            $this->resetRequestGlobals();
            $this->dispatch();

            $body = (string) \ob_get_clean();

            return [
                self::currentStatus(),
                self::collectHeaders(),
                $body,
            ];
        } catch (Throwable $e) {
            // Discard any partial output the failing request produced.
            while (\ob_get_level() > 0) {
                \ob_end_clean();
            }

            return self::errorResponse($e);
        }
    }

    /**
     * Run the WordPress front controller for the current request.
     *
     * Split out so `handleEnvelope()` stays testable: tests override this (via a
     * subclass) with a no-op / stub so they never touch a real WordPress.
     */
    protected function dispatch(): void
    {
        // Re-run the main query + routing for this request, then render the
        // template. Mirrors wp-blog-header.php without re-loading WordPress.
        if (\function_exists('wp')) {
            wp();
        }

        $templateLoader = ABSPATH . WPINC . '/template-loader.php';
        if (\defined('WPINC') && \is_file($templateLoader)) {
            require $templateLoader;
        }
    }

    /**
     * Seed the PHP superglobals from the request Envelope.
     *
     * The engine natively repopulates `$_SERVER` / `$_GET` / `$_COOKIE` when
     * `worker_populate_superglobals = true`, but we defensively re-seed them all
     * anyway (idempotent, and keeps the adapter correct even if that knob is off
     * in a mis-configured deployment) and — critically — parse `$_POST` /
     * `$_FILES` ourselves, since the native path never does.
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     */
    public function marshalSuperglobals(object $envelope): void
    {
        /** @var array<string, mixed> $server */
        $server = $envelope->serverVars();
        /** @var array<string, string> $cookies */
        $cookies = $envelope->cookies();
        /** @var array<string, mixed> $query */
        $query = $envelope->query();
        $rawBody = (string) $envelope->rawBody();

        $_SERVER = \array_merge($_SERVER, $server);
        $_GET = $query;
        $_COOKIE = $cookies;

        $contentType = self::headerValue($server, 'CONTENT_TYPE');
        $method = \strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        [$post, $files] = self::parseBody($method, $contentType, $rawBody, $envelope);

        $_POST = $post;
        $_FILES = $files;

        // $_REQUEST is what a lot of WP/plugin code actually reads. Rebuild it
        // GET + POST + COOKIE (PHP's default request_order is "GP", but WP code
        // frequently assumes cookies too; GP is the safe subset — match PHP).
        $_REQUEST = \array_merge($_GET, $_POST);

        // php://input parity: WordPress' REST API and many plugins read the raw
        // body. The engine's Envelope::rawBody() is the source of truth; expose
        // it via $GLOBALS for the shim used in the e2e image (see README).
        $GLOBALS['EPHPM_WP_RAW_BODY'] = $rawBody;
    }

    /**
     * Reset the request-scoped WordPress globals listed in {@see RESET_GLOBALS}.
     *
     * Unsetting is enough: WordPress lazily re-creates `$wp_query`, `$wp`, etc.
     * during `wp()` / `WP::main()`. Booted-once state (`$wpdb`, `$wp_object_cache`,
     * the hook arrays) is deliberately left untouched.
     */
    public function resetRequestGlobals(): void
    {
        foreach (self::RESET_GLOBALS as $name) {
            unset($GLOBALS[$name]);
        }

        // WordPress caches the "did we already send headers" flag; force a fresh
        // main query each request.
        if (\function_exists('wp_cache_flush')) {
            // Only flush the *runtime* (non-persistent) cache. Persistent object
            // caches (ephpm/cache-wordpress) manage their own per-request reset.
            wp_cache_flush_runtime_if_available();
        }
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
