<?php

declare(strict_types=1);

namespace Ephpm\WordPress\Tests;

use Ephpm\WordPress\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the defensive body parser (Worker::parseBody / parseMultipart /
 * multipartBoundary) — the piece that compensates for the engine NOT parsing
 * $_POST / $_FILES in worker mode.
 */
final class BodyParseTest extends TestCase
{
    private static function env(): FakeEnvelope
    {
        return new FakeEnvelope();
    }

    public function testUrlencodedSimple(): void
    {
        [$post, $files] = Worker::parseBody(
            'POST',
            'application/x-www-form-urlencoded',
            'a=1&b=two',
            self::env(),
        );

        self::assertSame(['a' => '1', 'b' => 'two'], $post);
        self::assertSame([], $files);
    }

    public function testUrlencodedArrays(): void
    {
        [$post] = Worker::parseBody(
            'POST',
            'application/x-www-form-urlencoded',
            'tax[]=news&tax[]=sports&meta[color]=red',
            self::env(),
        );

        self::assertSame(['news', 'sports'], $post['tax']);
        self::assertSame(['color' => 'red'], $post['meta']);
    }

    public function testEmptyBodyReturnsEmpty(): void
    {
        [$post, $files] = Worker::parseBody('POST', 'application/x-www-form-urlencoded', '', self::env());
        self::assertSame([], $post);
        self::assertSame([], $files);
    }

    public function testJsonIsNotMarshaled(): void
    {
        [$post, $files] = Worker::parseBody('POST', 'application/json', '{"x":1}', self::env());
        self::assertSame([], $post);
        self::assertSame([], $files);
    }

    public function testGetMethodNeverParses(): void
    {
        [$post] = Worker::parseBody('GET', 'application/x-www-form-urlencoded', 'a=1', self::env());
        self::assertSame([], $post);
    }

    public function testMultipartBoundaryExtraction(): void
    {
        self::assertSame(
            '----WebKitFormBoundaryABC123',
            Worker::multipartBoundary('multipart/form-data; boundary=----WebKitFormBoundaryABC123'),
        );
        self::assertSame(
            'quoted-boundary',
            Worker::multipartBoundary('multipart/form-data; boundary="quoted-boundary"'),
        );
        self::assertNull(Worker::multipartBoundary('multipart/form-data'));
    }

    public function testMultipartFieldsAndFile(): void
    {
        $boundary = '----ephpmBoundary';
        $body = self::multipart($boundary, [
            ['name' => 'title', 'value' => 'Hello World'],
            ['name' => 'status', 'value' => 'draft'],
            [
                'name' => 'upload',
                'filename' => 'note.txt',
                'ctype' => 'text/plain',
                'value' => "file-contents\nsecond line",
            ],
        ]);

        [$post, $files] = Worker::parseBody(
            'POST',
            "multipart/form-data; boundary={$boundary}",
            $body,
            self::env(),
        );

        self::assertSame('Hello World', $post['title']);
        self::assertSame('draft', $post['status']);

        self::assertArrayHasKey('upload', $files);
        self::assertSame('note.txt', $files['upload']['name']);
        self::assertSame('text/plain', $files['upload']['type']);
        self::assertSame(\UPLOAD_ERR_OK, $files['upload']['error']);
        self::assertSame(\strlen("file-contents\nsecond line"), $files['upload']['size']);
        self::assertFileExists($files['upload']['tmp_name']);
        self::assertSame(
            "file-contents\nsecond line",
            \file_get_contents($files['upload']['tmp_name']),
        );

        @\unlink($files['upload']['tmp_name']);
    }

    public function testSpooledUploadTempFilesAreUnlinkedByCleanup(): void
    {
        // A persistent worker must not accumulate upload temp files: everything
        // parseMultipart() spools is unlinked by cleanupSpooledFiles(), which
        // the worker loop calls in a finally after each response is sent.
        $boundary = '----ephpmCleanup';
        $body = self::multipart($boundary, [
            [
                'name' => 'one',
                'filename' => 'one.txt',
                'ctype' => 'text/plain',
                'value' => 'first',
            ],
            [
                'name' => 'two',
                'filename' => 'two.txt',
                'ctype' => 'text/plain',
                'value' => 'second',
            ],
        ]);

        [, $files] = Worker::parseBody(
            'POST',
            "multipart/form-data; boundary={$boundary}",
            $body,
            self::env(),
        );

        $paths = [$files['one']['tmp_name'], $files['two']['tmp_name']];
        foreach ($paths as $path) {
            self::assertFileExists($path);
        }

        Worker::cleanupSpooledFiles();

        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }

        // Idempotent: a second cleanup (next request with no uploads) is a no-op.
        Worker::cleanupSpooledFiles();
    }

    public function testMultipartArrayField(): void
    {
        $boundary = 'B';
        $body = self::multipart($boundary, [
            ['name' => 'cats[]', 'value' => '1'],
            ['name' => 'cats[]', 'value' => '2'],
        ]);

        [$post] = Worker::parseBody('POST', "multipart/form-data; boundary={$boundary}", $body, self::env());

        self::assertSame(['1', '2'], $post['cats']);
    }

    public function testMultipartArrayOfFilesPivotsToPhpFilesShape(): void
    {
        // Two files under one name="photos[]" field plus a scalar name="avatar".
        // PHP builds the pivoted $_FILES shape: the five attributes are lifted
        // above the [] index, so BOTH files survive instead of the second
        // last-winning under the literal key "photos[]".
        $boundary = 'B';
        $body = self::multipart($boundary, [
            ['name' => 'photos[]', 'filename' => 'a.jpg', 'ctype' => 'image/jpeg', 'value' => 'AAA'],
            ['name' => 'photos[]', 'filename' => 'b.jpg', 'ctype' => 'image/jpeg', 'value' => 'BBBB'],
            ['name' => 'avatar', 'filename' => 'me.png', 'ctype' => 'image/png', 'value' => 'PNG'],
        ]);

        [, $files] = Worker::parseBody('POST', "multipart/form-data; boundary={$boundary}", $body, self::env());

        // Bracketed field: pivoted parallel arrays, index-aligned across attributes.
        self::assertSame(['a.jpg', 'b.jpg'], $files['photos']['name']);
        self::assertSame(['image/jpeg', 'image/jpeg'], $files['photos']['type']);
        self::assertSame([3, 4], $files['photos']['size']);
        self::assertSame([\UPLOAD_ERR_OK, \UPLOAD_ERR_OK], $files['photos']['error']);
        self::assertCount(2, $files['photos']['tmp_name']);
        self::assertSame('AAA', \file_get_contents($files['photos']['tmp_name'][0]));
        self::assertSame('BBBB', \file_get_contents($files['photos']['tmp_name'][1]));

        // Scalar field: classic single-file shape (unchanged).
        self::assertSame('me.png', $files['avatar']['name']);
        self::assertIsString($files['avatar']['tmp_name']);

        Worker::cleanupSpooledFiles();
    }

    /**
     * Build a multipart/form-data body from a list of parts.
     *
     * @param list<array{name: string, value: string, filename?: string, ctype?: string}> $parts
     */
    private static function multipart(string $boundary, array $parts): string
    {
        $out = '';
        foreach ($parts as $p) {
            $out .= "--{$boundary}\r\n";
            if (isset($p['filename'])) {
                $out .= "Content-Disposition: form-data; name=\"{$p['name']}\"; filename=\"{$p['filename']}\"\r\n";
                $out .= 'Content-Type: ' . ($p['ctype'] ?? 'application/octet-stream') . "\r\n";
            } else {
                $out .= "Content-Disposition: form-data; name=\"{$p['name']}\"\r\n";
            }
            $out .= "\r\n";
            $out .= $p['value'] . "\r\n";
        }
        $out .= "--{$boundary}--\r\n";

        return $out;
    }
}
