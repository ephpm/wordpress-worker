<?php

declare(strict_types=1);

namespace Ephpm\WordPress\Tests;

use Ephpm\WordPress\Worker;
use PHPUnit\Framework\TestCase;

/**
 * SECURITY: proves request-scoped user identity does not leak across requests
 * served by the same resident worker.
 *
 * WordPress derives the current user lazily from the auth cookie and caches it
 * in `$GLOBALS['current_user']` (plus the legacy `$user_ID`/`$userdata`/…
 * globals). Under FPM that state dies with the process at request end. Under a
 * persistent worker it survives — so unless the per-request reset clears it, a
 * logged-in request (request N) pins its identity onto the worker and the next
 * anonymous visitor (request N+1) is seen as that user.
 *
 * These tests run WITHOUT WordPress loaded (the WP_Query / WP re-instantiation
 * in resetRequestGlobals() is class_exists-guarded), so they exercise exactly
 * the identity-clearing behaviour.
 */
final class RequestIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any identity globals a previous test may have set.
        foreach (Worker::RESET_GLOBALS as $name) {
            unset($GLOBALS[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach (Worker::RESET_GLOBALS as $name) {
            unset($GLOBALS[$name]);
        }
    }

    public function testResetGlobalsIncludesCurrentUserIdentity(): void
    {
        // The load-bearing one plus its legacy setup_userdata() companions.
        self::assertContains('current_user', Worker::RESET_GLOBALS);
        self::assertContains('user_ID', Worker::RESET_GLOBALS);
        self::assertContains('userdata', Worker::RESET_GLOBALS);
    }

    public function testLoggedInIdentityDoesNotLeakToNextRequest(): void
    {
        $worker = new Worker('/tmp/wp/');

        // --- Request N: a logged-in admin. WordPress would set these after
        // resolving the auth cookie; simulate that resolved state. ---
        $GLOBALS['current_user'] = (object) ['ID' => 7, 'user_login' => 'admin'];
        $GLOBALS['user_ID'] = 7;
        $GLOBALS['userdata'] = (object) ['ID' => 7, 'user_login' => 'admin'];
        $GLOBALS['user_login'] = 'admin';

        // --- Request N+1 begins (an anonymous visitor). The per-request reset
        // must clear the pinned identity so WordPress re-derives the user from
        // this request's (cookie-less) $_COOKIE instead of reusing admin. ---
        $worker->resetRequestGlobals();

        self::assertArrayNotHasKey('current_user', $GLOBALS, 'current_user leaked across requests');
        self::assertArrayNotHasKey('user_ID', $GLOBALS, 'user_ID leaked across requests');
        self::assertArrayNotHasKey('userdata', $GLOBALS, 'userdata leaked across requests');
        self::assertArrayNotHasKey('user_login', $GLOBALS, 'user_login leaked across requests');
    }
}
