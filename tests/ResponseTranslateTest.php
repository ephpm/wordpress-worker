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

    public function testResetGlobalsListCoversCoreRequestScopedGlobals(): void
    {
        // Guard rails: these MUST stay in the reset list or state leaks.
        foreach (['wp_query', 'wp_the_query', 'wp', 'post', 'wp_did_header', 'pages'] as $required) {
            self::assertContains(
                $required,
                Worker::RESET_GLOBALS,
                "\${$required} must be reset between requests",
            );
        }

        // The list must not accidentally include boot-once globals.
        foreach (['wpdb', 'wp_object_cache', 'wp_filter'] as $forbidden) {
            self::assertNotContains(
                $forbidden,
                Worker::RESET_GLOBALS,
                "\${$forbidden} is boot-once state and must NOT be reset per request",
            );
        }
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
