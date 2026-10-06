<?php

declare(strict_types=1);

namespace Rapira\Laravel\Tests\Support;

use Rapira\Http\HttpDispatcher;
use Rapira\Laravel\Internal\Runtime;
use Rapira\Mode;

/**
 * Scripted {@see Runtime}: reports the given mode and dispatcher, and in worker mode serves the queued
 * requests by filling the superglobals the way the host does, capturing what each handler printed.
 */
final class FakeRuntime implements Runtime
{
    /** @var list<string> Output printed by each served request, in order. */
    public array $outputs = [];

    public int $finishRequestCalls = 0;

    /** @var list<array{server: array<string, mixed>, get: array<string, mixed>, cookie: array<string, mixed>}> */
    private array $requests = [];

    private array $originalServer;

    public function __construct(
        private readonly Mode $mode,
        private readonly ?HttpDispatcher $dispatcher = null,
    ) {
        $this->originalServer = $_SERVER;
    }

    /**
     * Queues a worker-mode request.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     */
    public function queue(string $method, string $uri, array $server = [], array $cookies = []): self
    {
        $query = \explode('?', $uri, 2)[1] ?? '';
        \parse_str($query, $get);
        $this->requests[] = [
            'server' => $server + [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $uri,
                'QUERY_STRING' => $query,
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'HTTP_HOST' => 'localhost',
                'SERVER_PORT' => 80,
                'REMOTE_ADDR' => '127.0.0.1',
                'SCRIPT_NAME' => '/worker.php',
                'SCRIPT_FILENAME' => '/app/worker.php',
            ],
            'get' => $get,
            'cookie' => $cookies,
        ];

        return $this;
    }

    public function mode(): Mode
    {
        return $this->mode;
    }

    public function dispatcher(): HttpDispatcher
    {
        return $this->dispatcher ?? throw new \LogicException('No dispatcher configured.');
    }

    public function handleRequest(callable $handler): bool
    {
        $request = \array_shift($this->requests);
        if ($request === null) {
            return false;
        }

        $_SERVER = $request['server'] + $this->originalServer;
        $_GET = $request['get'];
        $_POST = [];
        $_COOKIE = $request['cookie'];
        $_FILES = [];

        \ob_start();
        try {
            $handler();
        } finally {
            $this->outputs[] = (string) \ob_get_clean();
            $_SERVER = $this->originalServer;
            $_GET = $_COOKIE = [];
        }

        return true;
    }

    public function finishRequest(): void
    {
        ++$this->finishRequestCalls;
    }
}
