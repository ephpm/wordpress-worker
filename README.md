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
[server]
listen = "0.0.0.0:8080"
document_root = "/var/www/html"         # the WordPress root (ABSPATH)

[php]
mode = "worker"
worker_script = "worker.php"            # your loop; see below
worker_populate_superglobals = true     # REQUIRED for WordPress
worker_count = 4                         # WordPress ~40 MB/worker — size accordingly
worker_max_requests = 500                # recycle to reclaim slow memory growth
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

These are **real**, validated by the e2e suite, and you should understand them
before shipping:

- **Block rendering crashes the worker.** WordPress' block engine
  (`do_blocks()`, block themes) crashes the ePHPm worker mid-render. Use a
  **classic theme** and **non-block (plain/classic) post content**. This is the
  single biggest limitation today and is an engine-level issue, not something
  this adapter can work around. (The e2e site uses a bundled classic theme and
  plain-content fixtures for exactly this reason.)
- **The request loop must run at global scope.** WordPress' request cycle
  (`wp()`, the template loader, the theme) assumes global scope. Running it
  inside a class method traps WordPress' top-level variables and crashes the
  worker, so the loop lives in the entry script (`bin/ephpm-wp-worker`); this
  class only provides the marshaling/response helpers. Boot `wp-load.php` at
  global scope too.
- **`exit`/`die` deep inside `wp()` crashes the worker.** An `exit` from a
  top-level required entry script (`wp-login.php`) is fine, but an `exit`/`die`
  that unwinds *through the resident `wp()` call* (canonical redirects, the REST
  server's `serve_request()` die) crashes the worker. This adapter intercepts
  `wp_redirect` and `rest_pre_serve_request` and unwinds cleanly instead — so
  redirects and the REST API work — but any *other* plugin/core path that
  `exit`s from within `wp()` will still take the worker down (it recycles and
  serves the next request cleanly, at the cost of one boot).
- **REST needs the adapter's rewrite.** With plain permalinks the adapter
  rewrites `/wp-json/…` → `?rest_route=…` and captures the REST JSON before the
  `die()`. Pretty-permalink REST rewrites are not required.
- **Procedural reset is best-effort.** The transient loop globals in
  `Worker::RESET_GLOBALS` are unset and the query objects re-instantiated each
  request, but a plugin that stashes per-request state in a static property, a
  global not on the list, or a **dynamically-registered hook** will leak across
  requests within a worker. (The bundled mu-plugin demonstrates the hook-leak
  trap and how to avoid it — register filters once, self-gate per request.)
  Recycling (`worker_max_requests`) bounds the blast radius.
- **`$_SERVER` is populated by this adapter.** The engine populates `$_GET` /
  `$_COOKIE` natively but leaves `$_SERVER` empty in worker mode, so the adapter
  fills `$_SERVER` (REQUEST_URI/HTTP_HOST/…) from the Envelope. It deliberately
  does **not** reassign `$_GET`/`$_COOKIE` — replacing those engine-owned
  superglobals crashes the worker.
- **`$_POST` / `$_FILES` are parsed by this adapter, not the engine.** The
  native worker path never populates them (`Envelope::parsedBody()` returns
  `null`), so this package parses the raw body for
  `application/x-www-form-urlencoded` and `multipart/form-data`. The multipart
  parser is pragmatic, not a byte-for-byte clone of PHP's C rfc1867 parser.
- **No true streaming (Phase 1).** The request body is buffered and the response
  body is fully materialised before `send_response()`.
- **Fatals recycle the worker.** An uncatchable fatal in a hook takes down the
  worker; ePHPm respawns it and serves the next request from a clean boot. A
  single request's fatal never serves stale state, but costs one worker boot.
  Run enough workers (`worker_count`) that recycling doesn't starve the pool.

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
