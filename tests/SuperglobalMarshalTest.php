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

    public function testServerVarsAreSeededFromEnvelope(): void
    {
        // The engine leaves $_SERVER empty in worker mode; marshal fills it from
        // the Envelope. $_GET / $_COOKIE are engine-owned and NOT touched here.
        $worker = new Worker('/tmp/wp/');
        $worker->marshalSuperglobals(new FakeEnvelope(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?p=42', 'HTTP_HOST' => 'x'],
            cookies: ['ignored' => 'by-marshal'],
            query: ['p' => '42'],
        ));

        self::assertSame('GET', $_SERVER['REQUEST_METHOD']);
        self::assertSame('/?p=42', $_SERVER['REQUEST_URI']);
        self::assertSame('x', $_SERVER['HTTP_HOST']);
        // Pre-existing $_SERVER keys are preserved (per-key assign, not replace).
        self::assertSame('/index.php', $_SERVER['SCRIPT_NAME']);
        // marshal must NOT overwrite the engine-owned $_GET / $_COOKIE.
        self::assertSame([], $_COOKIE, 'marshal must not touch engine-owned $_COOKIE');
    }

    public function testServerVarsDoNotBleedRequestToRequest(): void
    {
        $worker = new Worker('/tmp/wp/');

        $worker->marshalSuperglobals(new FakeEnvelope(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/one'],
        ));
        self::assertSame('/one', $_SERVER['REQUEST_URI']);

        $worker->marshalSuperglobals(new FakeEnvelope(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/two'],
        ));
        self::assertSame('/two', $_SERVER['REQUEST_URI']);
        self::assertSame('POST', $_SERVER['REQUEST_METHOD']);
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
