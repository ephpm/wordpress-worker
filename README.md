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
   `$_GET/$_POST/$_SERVER/$_COOKIE/$_FILES` from the request `Envelope`,
   **re-fires the per-request action lifecycle (`init`, `wp_loaded`)** so plugin
   handlers registered on those actions see this request's superglobals, and
   re-runs `wp()` + the template loader.
3. **Response** — the request's output is captured with `ob_start()` and handed
   to `send_response()` as the body, with the status pulled from
   `http_response_code()` and headers from `headers_list()`.

## Lifecycle contract

WordPress under classic FPM re-runs the entire bootstrap — including every
`wp-settings.php` `do_action()` — on every request. WordPress plugins are
written against that contract: a handler registered on `init` or `wp_loaded`
expects to fire per request, against the current request's superglobals.

A resident worker cannot re-run file inclusion (`require wp-load.php`) per
request — that would re-declare classes and functions and undo the whole point
of worker mode. So this adapter splits WordPress' bootstrap into **boot-once**
and **per-request** phases:

| Runs ONCE per worker boot                    | Runs PER request (in order)             |
| -------------------------------------------- | --------------------------------------- |
| `require wp-load.php` (file inclusion)       | Superglobal marshaling from Envelope    |
| `muplugins_loaded`                           | Reset of transient globals + query objs |
| `plugins_loaded`                             | `do_action('init')`                     |
| `sanitize_comment_cookies`                   | `do_action('wp_loaded')`                |
| `setup_theme` / `after_setup_theme`          | `wp()` (routing + main query)           |
| `set_current_user`                           | Template loader / active theme render   |
| First fire of `init` (with empty $_GET)      | (response sent to client)               |
| First fire of `wp_loaded` (with empty $_GET) | `do_action('shutdown')`                 |

**Why re-fire `init` and `wp_loaded`?** Plugins hook these expecting per-request
semantics: WooCommerce's `WC_Form_Handler::add_to_cart_action()` is registered on
`wp_loaded` and reads `$_GET['add-to-cart']` per request. Without re-firing,
`GET /?add-to-cart=<id>` renders a normal 200 page but the cart is empty and no
`wp_woocommerce_session_*` cookie is set — a silent no-op.

**Why not re-fire `plugins_loaded` / `after_setup_theme` / `set_current_user`?**
Their names and contracts are one-shot per process: plugin/theme file
inclusion, not request handling. Handlers on them typically do class wiring,
translation loading, or user-derivation — safe to run once. Re-firing would
double-fire class-wiring code with no request-scoped upside.

**Why fire `shutdown` per iteration?** In classic FPM, `shutdown` fires when
PHP tears the script down at request end (via
`register_shutdown_function` → WordPress' `shutdown_action_hook()`). In
worker mode the script never ends, so `shutdown` never fires — but hooks
registered on it accumulate work that never runs. Most visibly,
`WC_Session_Handler::save_data()` is hooked on `shutdown`: without firing it
per iteration, guest cart state never lands in `wp_woocommerce_sessions`,
and `GET /wp-json/wc/store/v1/cart` keeps returning an empty guest cart
even after `/?add-to-cart=<id>` successfully set the session cookie. The
adapter fires `shutdown` in the request-loop `finally` block, so the
response has already been sent to the client and shutdown-hook errors don't
affect the response body. Hook exceptions are logged and swallowed — one
broken plugin should not take down the worker pool.

### Observable differences from FPM

- **`did_action('init')` / `did_action('wp_loaded')`**. The adapter zeros these
  counters after boot (`Worker::resetBootActionCounters()`), so the first
  request sees `did_action('init') === 1` — same as FPM. However, over the
  worker's lifetime the counter climbs monotonically instead of resetting to
  `0` per request. Well-behaved code that treats these as `>0` booleans works
  unchanged; code that expects `=== 1` per request will see higher numbers.
- **`did_action('plugins_loaded')` etc.** stay at `1` and never increment
  (deliberate — see above), so this is FPM-like from any single request's
  point of view.
- **Handlers that mutate global registrations from within `init` /
  `wp_loaded`.** A handler that calls `add_action(…)` / `add_filter(…)` /
  `register_post_type(…)` inside its own `init` body will register a fresh
  callback / a fresh post-type on every request, growing `$wp_filter` /
  `$wp_post_types` unboundedly. Well-behaved plugins register hooks at
  plugin-load time (or self-gate with a static flag); the `worker_max_requests`
  ceiling bounds the blast radius for the ones that don't.
- **Handlers on `shutdown` that call `exit`.** Under FPM, `shutdown` fires
  from PHP's tear-down and any `exit`/`die` inside a handler is harmless
  (the script was ending anyway). Under the worker, an `exit` inside a
  shutdown hook would terminate the worker mid-loop. The adapter catches
  `Throwable`s inside the shutdown re-fire and logs them but does NOT catch
  `exit()` calls (they can't be caught in PHP). Handlers that `exit` inside
  `shutdown` will therefore recycle the worker — noisy but safe (the
  response was already sent).
- **Per-request cost.** Re-firing `init` + `wp_loaded` + `shutdown` on a
  fresh WP 6.7 + WooCommerce install measures **on the order of a few ms
  per request** on a laptop (see the PR that added this contract; the
  regression harness reports a probe wall-clock median). This is the price
  of FPM parity for the plugin ecosystem; there is no path to a
  fully-correct worker mode that avoids it.

## Requirements

- ePHPm built with worker mode (`[php] mode = "worker"`).
- **`worker_populate_superglobals = true`** — WordPress assumes real
  `$_SERVER/$_GET/$_COOKIE` superglobals. This is **not** the default; you must
  turn it on. Without it, WordPress routing and `$wpdb`-driven queries misbehave.

## Install

ePHPm packages are distributed via their GitHub repositories (not Packagist).
Add every ePHPm repo in the dependency tree as a Composer `vcs` repository, then
require the adapter. This package pulls in `ephpm/worker`, so **both** repos are
listed — Composer does **not** resolve a VCS dependency's own VCS repositories
transitively, so each ePHPm package in the tree needs its own `repositories`
entry in your app's `composer.json`.

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ephpm/wordpress-worker" },
    { "type": "vcs", "url": "https://github.com/ephpm/php-worker" }
  ],
  "require": {
    "ephpm/wordpress-worker": "^0.1"
  }
}
```

Both `ephpm/wordpress-worker` and its `ephpm/worker` dependency are tagged
`v0.1.0`, so `^0.1` resolves for each; each still needs its own `repositories`
entry because Composer does not resolve VCS repos transitively. Then:

```bash
composer update
```

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

## Running WooCommerce

WooCommerce runs under this adapter, but its guest-session handler is a
boot-once singleton (`WC()->session` is set once per worker via
`WC::initialize_session()`). Under a persistent worker that means every
request re-uses the worker's boot-time session ID and never re-reads the
client's `wp_woocommerce_session_*` cookie — so guest cart data doesn't
follow the client across the pool: `/?add-to-cart=<id>` sets the cookies
correctly but `/wp-json/wc/store/v1/cart` returns an empty cart.

Drop the bundled workaround mu-plugin into your site to fix it:

```bash
cp vendor/ephpm/wordpress-worker/muplugins/woocommerce-session-per-request.php \
   wp-content/mu-plugins/
```

It re-reads `$_COOKIE` into `WC()->session` at the start of every `wp_loaded`
re-fire, so per-request cart state works correctly. The plugin lives in the
adapter package (not the adapter itself) because it is WooCommerce-specific —
the adapter should not know about individual plugins.

## Running Elementor

Elementor's `Plugin::init()` handler (registered on `init` at priority 0)
`include`s its element class files (`elements/base.php`, `elements/section.php`,
`elements/column.php`, …) via `Elements_Manager::init_elements()`. Under
classic PHP-FPM `init` fires once per process, so these run once — correct.
Under this adapter's per-request `init` re-fire, the second request re-runs
the include and crashes the worker with:

```
PHP Fatal error: Cannot redeclare class Elementor\Element_Column
.../wp-content/plugins/elementor/includes/elements/column.php:19
```

Elementor's Plugin singleton and element registry are already fully populated
by boot-time; the per-request replay of Elementor's `init` handler adds no
functional value and only re-triggers the include. The bundled mu-plugin
strips the `init`-priority-0 binding of `Plugin::init` after boot so the
replay skips it. All other Elementor hooks (REST, admin, editor, widget
render) stay intact.

Drop it into your site alongside the WooCommerce one:

```bash
cp vendor/ephpm/wordpress-worker/muplugins/elementor-idempotent-lifecycle.php \
   wp-content/mu-plugins/
```

Same rationale as the WooCommerce mu-plugin: plugin-specific workaround, so
it lives in this package's `muplugins/` rather than in the adapter itself.

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
