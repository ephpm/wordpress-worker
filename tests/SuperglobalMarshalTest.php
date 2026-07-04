<?php

declare(strict_types=1);

namespace Ephpm\WordPress\Tests;

use Ephpm\WordPress\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Exercises Worker::marshalSuperglobals() — that request data from an Envelope
 * lands in the PHP superglobals WordPress reads, and that state from a previous
 * request does not bleed into the next.
 */
final class SuperglobalMarshalTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = $_POST = $_COOKIE = $_FILES = $_REQUEST = [];
        $_SERVER = ['SCRIPT_NAME' => '/index.php'];
    }

    public function testGetQueryAndCookiesAreSeeded(): void
    {
        $worker = new Worker('/tmp/wp/');
        $worker->marshalSuperglobals(new FakeEnvelope(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?p=42'],
            cookies: ['wordpress_logged_in' => 'abc'],
            query: ['p' => '42'],
        ));

        self::assertSame(['p' => '42'], $_GET);
        self::assertSame(['wordpress_logged_in' => 'abc'], $_COOKIE);
        self::assertSame('GET', $_SERVER['REQUEST_METHOD']);
        // Pre-existing $_SERVER keys are preserved (merge, not replace).
        self::assertSame('/index.php', $_SERVER['SCRIPT_NAME']);
    }

    public function testNoBleedBetweenRequests(): void
    {
        $worker = new Worker('/tmp/wp/');

        $worker->marshalSuperglobals(new FakeEnvelope(
            server: ['REQUEST_METHOD' => 'GET'],
            cookies: ['sid' => 'first'],
            query: ['q' => 'one'],
        ));
        self::assertSame(['q' => 'one'], $_GET);
        self::assertSame(['sid' => 'first'], $_COOKIE);

        // Second request with entirely different values must fully replace.
        $worker->marshalSuperglobals(new FakeEnvelope(
            server: ['REQUEST_METHOD' => 'GET'],
            cookies: [],
            query: ['page_id' => '7'],
        ));
        self::assertSame(['page_id' => '7'], $_GET);
        self::assertSame([], $_COOKIE);
        self::assertArrayNotHasKey('q', $_GET);
    }

    public function testUrlencodedPostIsParsedIntoPost(): void
    {
        $worker = new Worker('/tmp/wp/');
        $worker->marshalSuperglobals(new FakeEnvelope(
            server: [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            rawBody: 'log=admin&pwd=secret&rememberme=forever',
        ));

        self::assertSame('admin', $_POST['log']);
        self::assertSame('secret', $_POST['pwd']);
        self::assertSame('forever', $_POST['rememberme']);
        // $_REQUEST is GET + POST.
        self::assertSame('admin', $_REQUEST['log']);
    }

    public function testGetRequestDoesNotParseBody(): void
    {
        $worker = new Worker('/tmp/wp/');
        $worker->marshalSuperglobals(new FakeEnvelope(
            server: ['REQUEST_METHOD' => 'GET'],
            rawBody: 'ignored=1',
        ));

        self::assertSame([], $_POST);
    }

    public function testRawBodyExposedForRestClients(): void
    {
        $worker = new Worker('/tmp/wp/');
        $json = '{"title":"hello"}';
        $worker->marshalSuperglobals(new FakeEnvelope(
            server: ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'],
            rawBody: $json,
        ));

        // JSON bodies are NOT marshaled into $_POST (REST reads php://input).
        self::assertSame([], $_POST);
        self::assertSame($json, $GLOBALS['EPHPM_WP_RAW_BODY']);
    }
}
