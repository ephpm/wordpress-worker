<?php

/**
 * Plugin Name: ePHPm — Elementor idempotent lifecycle (worker-mode workaround)
 * Description: Elementor boot workaround for ePHPm persistent worker mode.
 *              Drop into wp-content/mu-plugins/ when running Elementor under
 *              ephpm/wordpress-worker. Without it, the second request onward
 *              crashes with a `Cannot redeclare class Elementor\Element_Column`
 *              fatal from wp-content/plugins/elementor/includes/elements/column.php.
 * Author:      ePHPm
 * License:     MIT
 *
 * Root cause: Elementor's `Plugin::init()` runs at `init` priority 0 and, via
 * `Elements_Manager::init_elements()`, includes its element class files
 * (`elements/base.php`, `elements/section.php`, `elements/column.php`, …). Under
 * classic PHP-FPM the `init` action fires exactly once per process, so those
 * includes run once and are the design-correct behaviour.
 *
 * Under a persistent worker, ePHPm's adapter re-fires `init` per request to
 * match FPM's per-request lifecycle contract (needed by, e.g., WooCommerce's
 * `add_to_cart_action()`). Elementor's `init` handler re-runs each time and
 * re-includes the element files — which for the classes not defined behind a
 * `class_exists()` guard, produces `Cannot redeclare class …` fatals on request
 * N ≥ 2 and kills the worker.
 *
 * Elementor's Plugin singleton and element registry are already fully
 * populated by the time boot's own `wp_loaded` fires. Stripping just its
 * `init`-priority-0 handler after boot therefore removes the redeclaration
 * risk without losing any per-request Elementor functionality (Elementor's
 * per-request work happens during page render via widgets/documents, not on
 * `init`; its `init` handler is fundamentally a boot-time initializer).
 *
 * This mu-plugin does the smallest thing that works: on the first `wp_loaded`
 * fire (which is boot-time), snapshot Elementor's `Plugin::init` binding and
 * `remove_action()` it, then no-op on subsequent fires. All other hooks
 * Elementor registered — REST, admin, editor, widget-render — stay intact.
 *
 * SCOPE: this fixes the redeclaration fatal only. It does NOT audit the
 * remaining Elementor codepaths for per-request idempotency (e.g. widget-cache
 * clearing, breakpoint state, editor-preview flow). Ship together with a
 * conservative `worker_max_requests` and instrument for regressions.
 */

add_action(
    'wp_loaded',
    static function (): void {
        static $stripped = false;
        if ($stripped) {
            return;
        }
        $stripped = true;

        if (!\class_exists('\Elementor\Plugin')) {
            return;
        }

        $plugin = \Elementor\Plugin::instance();
        if (!\is_object($plugin)) {
            return;
        }

        // Elementor 3.x registers Plugin::init at priority 0 on the `init`
        // action inside its constructor. This is the callback that ultimately
        // reaches Elements_Manager::init_elements() and does the include-file
        // work whose second run causes the redeclaration fatal. Removing the
        // exact [$plugin, 'init'] binding — not the whole action — leaves any
        // other Elementor init hooks (e.g. registered by add-ons) untouched.
        if (\method_exists($plugin, 'init')) {
            \remove_action('init', [$plugin, 'init'], 0);
        }

        // Elementor also wires an `elementor_loaded` action fire inside init.
        // We deliberately do NOT strip that — plugins that hook onto it expect
        // it to fire once per Elementor-loaded event, which under the worker
        // is once per process (identical to FPM).
    },
    \PHP_INT_MAX,
);
