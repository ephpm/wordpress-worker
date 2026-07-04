<?php

declare(strict_types=1);

namespace Ephpm\WordPress\Tests;

/**
 * A stand-in for Ephpm\Worker\Envelope with the same accessor surface, so the
 * pure marshaling/parsing logic can be driven without the native engine.
 */
final class FakeEnvelope
{
    /**
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $query
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, mixed>  $files
     */
    public function __construct(
        private array $server = [],
        private array $headers = [],
        private array $cookies = [],
        private array $query = [],
        private ?array $parsedBody = null,
        private array $files = [],
        private string $rawBody = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function serverVars(): array
    {
        return $this->server;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    /** @return array<string, mixed>|null */
    public function parsedBody(): ?array
    {
        return $this->parsedBody;
    }

    /** @return array<string, mixed> */
    public function files(): array
    {
        return $this->files;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function bodyStream(): string
    {
        return $this->rawBody;
    }
}
