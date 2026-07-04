<?php

declare(strict_types=1);

namespace Ephpm\WordPress\Tests;

use Ephpm\WordPress\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the header/status translation helpers and the reset key-list.
 */
final class ResponseTranslateTest extends TestCase
{
    public function testFlattenHeaderLines(): void
    {
        $flat = Worker::flattenHeaderLines([
            'Content-Type: text/html; charset=UTF-8',
            'X-Powered-By: WordPress',
            'Link: <https://x/wp-json/>; rel="https://api.w.org/"',
        ]);

        self::assertSame('text/html; charset=UTF-8', $flat['Content-Type']);
        self::assertSame('WordPress', $flat['X-Powered-By']);
        self::assertSame('<https://x/wp-json/>; rel="https://api.w.org/"', $flat['Link']);
    }

    public function testFlattenHeaderLinesJoinsRepeatedNames(): void
    {
        $flat = Worker::flattenHeaderLines([
            'Set-Cookie: a=1',
            'Set-Cookie: b=2',
        ]);

        self::assertSame('a=1, b=2', $flat['Set-Cookie']);
    }

    public function testFlattenHeaderLinesIgnoresMalformed(): void
    {
        $flat = Worker::flattenHeaderLines([
            'no-colon-here',
            ': empty-name',
            'Good: value',
        ]);

        self::assertSame(['Good' => 'value'], $flat);
    }

    public function testCurrentStatusDefaultsTo200(): void
    {
        // In CLI/phpunit http_response_code() returns false → default 200.
        self::assertSame(200, Worker::currentStatus());
    }

    public function testResetGlobalsListCoversTransientLoopGlobals(): void
    {
        // Guard rails: these transient loop/template globals MUST be in the
        // unset list or state leaks between requests.
        foreach (['post', 'authordata', 'wp_did_header', 'pages', 'pagenow'] as $required) {
            self::assertContains(
                $required,
                Worker::RESET_GLOBALS,
                "\${$required} must be reset between requests",
            );
        }

        // The query OBJECTS and boot-once state must NOT be in the unset list:
        // $wp/$wp_query/$wp_the_query are re-instantiated in place (unsetting
        // $wp makes wp() fatal), and $wpdb/$wp_object_cache/$wp_filter are
        // boot-once infrastructure.
        foreach (
            ['wp', 'wp_query', 'wp_the_query', 'wpdb', 'wp_object_cache', 'wp_filter']
            as $forbidden
        ) {
            self::assertNotContains(
                $forbidden,
                Worker::RESET_GLOBALS,
                "\${$forbidden} must NOT be in the blind-unset list",
            );
        }
    }

    public function testRouteScriptFrontController(): void
    {
        // Front-end URLs → null (run the front controller).
        self::assertNull(Worker::routeScript('/', '/tmp/wp'));
        self::assertNull(Worker::routeScript('/?p=42', '/tmp/wp'));
        self::assertNull(Worker::routeScript('/2024/07/hello-world/', '/tmp/wp'));
        self::assertNull(Worker::routeScript('/wp-json/wp/v2/posts', '/tmp/wp'));
        self::assertNull(Worker::routeScript('/index.php', '/tmp/wp'));
    }

    public function testRouteScriptResolvesEntryScript(): void
    {
        $root = \sys_get_temp_dir() . '/ephpm-wp-route-' . \uniqid();
        \mkdir($root . '/wp-admin', 0777, true);
        \file_put_contents($root . '/wp-login.php', '<?php');
        \file_put_contents($root . '/wp-admin/edit.php', '<?php');

        self::assertSame(
            \realpath($root . '/wp-login.php'),
            Worker::routeScript('/wp-login.php', $root),
        );
        self::assertSame(
            \realpath($root . '/wp-admin/edit.php'),
            Worker::routeScript('/wp-admin/edit.php?post=1', $root),
        );
        // PATH_INFO after the script still resolves to the script.
        self::assertSame(
            \realpath($root . '/wp-login.php'),
            Worker::routeScript('/wp-login.php/extra', $root),
        );
        // Nonexistent script → null.
        self::assertNull(Worker::routeScript('/nope.php', $root));

        \unlink($root . '/wp-admin/edit.php');
        \unlink($root . '/wp-login.php');
        \rmdir($root . '/wp-admin');
        \rmdir($root);
    }

    public function testRestRouteRewrite(): void
    {
        self::assertSame('/', Worker::restRoute('/wp-json'));
        self::assertSame('/', Worker::restRoute('/wp-json/'));
        self::assertSame('/wp/v2/posts', Worker::restRoute('/wp-json/wp/v2/posts'));
        self::assertSame('/wp/v2/posts', Worker::restRoute('/wp-json/wp/v2/posts?per_page=1'));
        // Non-REST URIs are left alone.
        self::assertNull(Worker::restRoute('/'));
        self::assertNull(Worker::restRoute('/?p=4'));
        self::assertNull(Worker::restRoute('/wp-login.php'));
        self::assertNull(Worker::restRoute('/not-wp-json/x'));
    }

    public function testRouteScriptRejectsTraversal(): void
    {
        $root = \sys_get_temp_dir() . '/ephpm-wp-route2-' . \uniqid();
        \mkdir($root, 0777, true);
        // A traversal target that escapes the root must be rejected.
        self::assertNull(Worker::routeScript('/../../etc/passwd.php', $root));
        \rmdir($root);
    }

    public function testResetRequestGlobalsUnsetsListedGlobals(): void
    {
        // Seed every reset-listed global, then prove reset clears them all.
        foreach (Worker::RESET_GLOBALS as $name) {
            $GLOBALS[$name] = 'leaked-' . $name;
        }
        $GLOBALS['wpdb'] = 'boot-once-keep';

        (new Worker('/tmp/wp/'))->resetRequestGlobals();

        foreach (Worker::RESET_GLOBALS as $name) {
            self::assertArrayNotHasKey($name, $GLOBALS, "\${$name} should have been unset");
        }
        // Boot-once state survives.
        self::assertSame('boot-once-keep', $GLOBALS['wpdb']);
        unset($GLOBALS['wpdb']);
    }
}
