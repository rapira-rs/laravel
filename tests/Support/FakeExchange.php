<?php

declare(strict_types=1);

namespace Rapira\Laravel\Tests\Support;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exception\FileNotSendableException;
use Rapira\Http\Exception\HeadAlreadyWrittenError;
use Rapira\Http\Exception\HeadNotWrittenError;
use Rapira\Http\Exchange;
use Rapira\Http\Multipart;
use Rapira\Http\Request;
use Rapira\InetAddress;

/**
 * In-memory {@see Exchange} that records what a worker writes into it and enforces the ordering rules
 * the host does: one final head, body only until `$eos`, nothing after finalization.
 */
final class FakeExchange implements Exchange
{
    /** @var int<100, 599>|null */
    public ?int $status = null;

    /** @var array<non-empty-string, list<string>> */
    public array $headers = [];

    /** @var list<string> Body chunks in write order, empty ones included. */
    public array $chunks = [];

    /** @var list<array{path: string, offset: int, length: int|null}> */
    public array $sentFiles = [];

    /** Makes {@see sendFile()} refuse, as for a path outside the host's sendfile root. */
    public bool $refuseFiles = false;

    private bool $finalized = false;
    private bool $discarded = false;
    private bool $discardOnWrite = false;

    public function __construct(
        private readonly Request $request = new Request(
            method: 'GET',
            uri: 'http://localhost/',
            target: '/',
            authority: 'localhost',
            protocol: 'HTTP/1.1',
            headers: [],
            body: '',
            remote: new InetAddress('127.0.0.1', 40000),
            server: new InetAddress('127.0.0.1', 8080),
            tls: null,
            receivedAt: 1_700_000_000.25,
        ),
    ) {}

    /**
     * @param array<non-empty-string, list<string>> $headers
     */
    public static function for(
        string $target,
        string $method = 'GET',
        array $headers = [],
        string|Multipart $body = '',
        string $scheme = 'http',
    ): self {
        return new self(new Request(
            method: $method,
            uri: $scheme . '://localhost:8080' . $target,
            target: $target,
            authority: 'localhost:8080',
            protocol: 'HTTP/1.1',
            headers: $headers,
            body: $body,
            remote: new InetAddress('10.0.0.7', 40000),
            server: new InetAddress('127.0.0.1', 8080),
            tls: null,
            receivedAt: 1_700_000_000.25,
        ));
    }

    /**
     * The host closed the exchange before the worker took it.
     */
    public function discard(): void
    {
        $this->discarded = true;
    }

    /**
     * The host closes the exchange while the request is being handled: the first write finds it gone.
     */
    public function discardOnWrite(): void
    {
        $this->discardOnWrite = true;
    }

    public function getBody(): string
    {
        return \implode('', $this->chunks);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (\strcasecmp($key, $name) === 0) {
                return \implode(', ', $values);
            }
        }

        return null;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function writeHead(int $status, array $headers = []): void
    {
        $this->assertOpen();
        if ($status < 100 || $status > 599) {
            throw new \ValueError("Status $status is outside 100-599.");
        }
        if ($status < 200 && $status !== 101) {
            return;
        }
        if ($this->status !== null) {
            throw new HeadAlreadyWrittenError();
        }

        $this->status = $status;
        $this->headers = $headers;
    }

    public function writeBody(string $content, bool $eos = true): void
    {
        $this->assertOpen();
        $this->status ??= 200;
        $this->chunks[] = $content;
        $this->finalized = $eos;
    }

    public function sendFile(string $path, int $offset = 0, ?int $length = null, bool $eos = true): void
    {
        $this->assertOpen();
        if ($this->refuseFiles) {
            throw new FileNotSendableException();
        }
        $this->status ??= 200;
        $this->sentFiles[] = ['path' => $path, 'offset' => $offset, 'length' => $length];
        $this->finalized = $eos;
    }

    public function writeTrailers(array $trailers): void
    {
        $this->assertOpen();
        $this->status ?? throw new HeadNotWrittenError();
        $this->finalized = true;
    }

    public function flush(): void
    {
        $this->assertOpen();
        $this->status ??= 200;
    }

    public function isFinalized(): bool
    {
        return $this->finalized || $this->discarded;
    }

    public function isCancelled(): bool
    {
        return $this->discarded;
    }

    public function __destruct() {}

    private function assertOpen(): void
    {
        $this->discarded = $this->discarded || $this->discardOnWrite;
        if ($this->discarded) {
            throw new WorkDiscardedException();
        }
        if ($this->finalized) {
            throw new AlreadyFinalizedError();
        }
    }
}
