<?php

declare(strict_types=1);

namespace Ephpm\WordPress\Tests;

use Ephpm\WordPress\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the per-request WordPress action lifecycle re-fire helpers —
 * {@see Worker::LIFECYCLE_ACTIONS}, {@see Worker::resetBootActionCounters()},
 * and {@see Worker::runRequestLifecycle()}.
 *
 * These helpers are what fix the "boot-once actions never re-fire" bug
 * (a WooCommerce `wp_loaded` handler like `WC_Form_Handler::add_to_cart_action()`
 * would otherwise only see the boot-time — empty — `$_GET`). The e2e suite is
 * the real gate; this unit test locks the exact contract in place.
 */
final class LifecycleActionsTest extends TestCase
{
    protected function setUp(): void
    {
        // Undo any $wp_actions state left over from a previous test.
        unset($GLOBALS['wp_actions']);
    }

    public function testLifecycleActionsIncludesInitAndWpLoaded(): void
    {
        // These two are load-bearing: WooCommerce, Yoast SEO, WPML, WP-CLI-plugin
        // authors, and countless others hook wp_loaded / init expecting the
        // handler to run per request.
        self::assertContains('init', Worker::LIFECYCLE_ACTIONS);
        self::assertContains('wp_loaded', Worker::LIFECYCLE_ACTIONS);
    }

    public function testLifecycleActionsExcludesBootOnceActions(): void
    {
        // These MUST NOT re-fire per request — they're contract-one-shot.
        // Re-firing plugins_loaded would double-fire plugin bootstrap code;
        // re-firing after_setup_theme would double-load textdomains / re-run
        // add_theme_support() calls.
        self::assertNotContains('plugins_loaded', Worker::LIFECYCLE_ACTIONS);
        self::assertNotContains('muplugins_loaded', Worker::LIFECYCLE_ACTIONS);
        self::assertNotContains('setup_theme', Worker::LIFECYCLE_ACTIONS);
        self::assertNotContains('after_setup_theme', Worker::LIFECYCLE_ACTIONS);
        self::assertNotContains('set_current_user', Worker::LIFECYCLE_ACTIONS);
        // widgets_init is hooked onto init at priority 1 in WP core, so it will
        // fire naturally when init re-fires — re-firing it explicitly would
        // double-fire it.
        self::assertNotContains('widgets_init', Worker::LIFECYCLE_ACTIONS);
    }

    public function testResetBootActionCountersZeroesOnlyLifecycleActions(): void
    {
        $GLOBALS['wp_actions'] = [
            'muplugins_loaded' => 1,
            'plugins_loaded' => 1,
            'setup_theme' => 1,
            'after_setup_theme' => 1,
            'init' => 1,
            'wp_loaded' => 1,
        ];

        Worker::resetBootActionCounters();

        // Lifecycle actions -> zero (so first request's re-fire counts as 1).
        self::assertSame(0, $GLOBALS['wp_actions']['init']);
        self::assertSame(0, $GLOBALS['wp_actions']['wp_loaded']);
        // Boot-once actions -> untouched (plugin code reading did_action
        // ('plugins_loaded') should still see 1 after boot completed).
        self::assertSame(1, $GLOBALS['wp_actions']['muplugins_loaded']);
        self::assertSame(1, $GLOBALS['wp_actions']['plugins_loaded']);
        self::assertSame(1, $GLOBALS['wp_actions']['setup_theme']);
        self::assertSame(1, $GLOBALS['wp_actions']['after_setup_theme']);
    }

    public function testResetBootActionCountersIsIdempotent(): void
    {
        $GLOBALS['wp_actions'] = ['init' => 1, 'wp_loaded' => 1];

        Worker::resetBootActionCounters();
        Worker::resetBootActionCounters();
        Worker::resetBootActionCounters();

        self::assertSame(0, $GLOBALS['wp_actions']['init']);
        self::assertSame(0, $GLOBALS['wp_actions']['wp_loaded']);
    }

    public function testResetBootActionCountersNoOpsWithoutWpActions(): void
    {
        // Before WordPress boot, $wp_actions doesn't exist. The reset helper
        // must not fatal in that case (it's called at global scope in the
        // entry script; a mis-ordered call site shouldn't take the worker down).
        unset($GLOBALS['wp_actions']);

        Worker::resetBootActionCounters();

        self::assertArrayNotHasKey('wp_actions', $GLOBALS);
    }

    public function testResetBootActionCountersOnlyTouchesActionsThatExist(): void
    {
        // If a lifecycle action name isn't in $wp_actions yet (e.g. a very old
        // WP that hasn't fired wp_loaded), the reset must not accidentally
        // create an entry for it and make did_action() return true.
        $GLOBALS['wp_actions'] = ['init' => 1];

        Worker::resetBootActionCounters();

        self::assertSame(0, $GLOBALS['wp_actions']['init']);
        self::assertArrayNotHasKey('wp_loaded', $GLOBALS['wp_actions']);
    }

    public function testRunRequestLifecycleNoOpsWithoutDoAction(): void
    {
        // Without a live WordPress loaded, do_action() does not exist. The
        // helper must not fatal — the unit suite deliberately runs without WP,
        // and a mis-ordered call site (before wp-load.php) mustn't crash.
        self::assertFalse(\function_exists('do_action'));

        Worker::runRequestLifecycle();

        // If we reached here, the helper survived the missing do_action().
        self::assertTrue(true);
    }

    public function testShutdownActionsIncludesShutdown(): void
    {
        // WordPress' `shutdown` action is the one FPM fires from PHP's
        // register_shutdown_function at script end. Load-bearing for
        // WooCommerce session persistence.
        self::assertContains('shutdown', Worker::SHUTDOWN_ACTIONS);
    }

    public function testFireShutdownActionsNoOpsWithoutDoAction(): void
    {
        // Same shape as runRequestLifecycle: must not fatal if called before
        // wp-load.php ever executed.
        self::assertFalse(\function_exists('do_action'));

        Worker::fireShutdownActions();

        self::assertTrue(true);
    }
}
