# ephpm/wordpress-worker

Run **WordPress** under [ePHPm](https://github.com/ephpm/ephpm) persistent
**worker mode** — WordPress boots **once** per worker thread and stays resident
in memory, servicing requests in a loop instead of paying the full
`wp-load.php` bootstrap on every request.

> **Status:** experimental. WordPress is procedural, not a re-entrant kernel.
> This adapter resets the request-scoped globals it knows about between
> requests; the bundled e2e suite (`e2e/`) is the arbiter of correctness. Read
> [Known limitations](#known-limitations) before shipping this.

## How it works

1. **Boot once** — `wp-load.php` is loaded a single time. The autoloader,
   `wp-config.php` constants, the `$wpdb` connection, must-use plugins and the
   hook graph stay resident.
2. **Per-request reset** — before re-running the main query the adapter unsets
   the request-scoped globals (`$wp_query`, `$wp_the_query`, `$wp`, `$post`,
   `$authordata`, `$pages`, `$wp_did_header`, request caches, …), re-seeds
   `$_GET/$_POST/$_SERVER/$_COOKIE/$_FILES` from the request `Envelope`, and
   re-runs `wp()` + the template loader.
3. **Response** — the request's output is captured with `ob_start()` and handed
   to `send_response()` as the body, with the status pulled from
   `http_response_code()` and headers from `headers_list()`.

## Requirements

- ePHPm built with worker mode (`[php] mode = "worker"`).
- **`worker_populate_superglobals = true`** — WordPress assumes real
  `$_SERVER/$_GET/$_COOKIE` superglobals. This is **not** the default; you must
  turn it on. Without it, WordPress routing and `$wpdb`-driven queries misbehave.

## Required `ephpm.toml`

```toml
[php]
mode = "worker"
worker_script = "worker.php"            # your loop; see below
worker_populate_superglobals = true     # REQUIRED for WordPress
document_root = "/var/www/html"         # the WordPress root (ABSPATH)
worker_count = 4                         # WordPress ~40 MB/worker — size accordingly
worker_max_requests = 500                # recycle to reclaim slow memory growth

[server]
listen = "0.0.0.0:8080"
```

Set `EPHPM_WP_PATH` to the WordPress root (defaults to `document_root` via the
`wp-load.php` walk if omitted):

```
EPHPM_WP_PATH=/var/www/html
```

Your `worker.php` (pointed to by `worker_script`) is a one-liner:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
exit((new Ephpm\WordPress\Worker(getenv('EPHPM_WP_PATH') . '/'))->run());
```

or just use the bundled `bin/ephpm-wp-worker` as your `worker_script`.

## Object cache

For a persistent object cache across requests, install the existing
[`ephpm/cache-wordpress`](https://github.com/ephpm/cache-wordpress) drop-in
(`wp-content/object-cache.php`). It plugs into ePHPm's built-in KV store. This
worker adapter flushes only the **runtime** (non-persistent) cache between
requests and leaves the persistent object cache to manage its own lifecycle.

## Known limitations

These are **real** and you should understand them before shipping:

- **Procedural reset is best-effort.** Only the globals in
  `Worker::RESET_GLOBALS` are reset. A plugin that stashes per-request state in
  a static property, a global not on that list, or a singleton **will** leak
  across requests within a worker. Recycling (`worker_max_requests`) bounds the
  blast radius but does not eliminate it. Test your plugin stack with the e2e
  harness.
- **`$_POST` / `$_FILES` are parsed by this adapter, not the engine.** The
  native worker path does not parse form or multipart bodies (`Envelope::parsedBody()`
  returns `null`), so this package parses the raw body itself. The multipart
  parser is pragmatic, not a byte-for-byte clone of PHP's C rfc1867 parser.
  Large uploads and exotic multipart shapes should be validated for your
  workload.
- **No true streaming (Phase 1).** The request body is buffered and the response
  body is fully materialised before `send_response()`. Large downloads/uploads
  hold memory.
- **Fatals recycle the worker.** An uncatchable engine-level fatal in a hook
  takes down the worker; ePHPm respawns it and serves the next request from a
  clean boot. A single request's fatal never serves stale state, but it does
  cost one worker boot.
- **Not all of WordPress is re-entrant.** Anything that assumes a fresh process
  per request (e.g. code relying on `register_shutdown_function` firing at
  process exit, or one-time `define()`s guarded only by `defined()`) may behave
  differently under a long-lived worker.

## Testing

Unit tests (pure helpers — superglobal marshaling, multipart parsing,
header/status translation, the reset key-list) run without WordPress or the
native engine:

```bash
composer install
vendor/bin/phpunit
```

End-to-end tests (the real gate — boots real WordPress in an ePHPm worker
container via podman) live in `e2e/`:

```bash
cd e2e && ./run.sh
```

## License

MIT
