<?php

declare(strict_types=1);

namespace Rapira\Laravel;

use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Worker;
use Rapira\Http\HttpDispatcher;
use Rapira\Laravel\Http\ExchangeEmitter;
use Rapira\Laravel\Http\ExchangeRequestFactory;
use Rapira\Laravel\Internal\ClassicServer;
use Rapira\Laravel\Internal\DispatcherServer;
use Rapira\Laravel\Internal\ErrorReporter;
use Rapira\Laravel\Internal\WorkerServer;
use Rapira\Laravel\Octane\ExchangeClient;
use Rapira\Laravel\Octane\SapiClient;
use Rapira\Mode;

use function Rapira\get_dispatcher;
use function Rapira\get_mode;

/**
 * Runs a Laravel application under Rapira.
 *
 * The mode belongs to how the host launched the process, not to the entry script: the same script serves
 * every {@see Mode}. The runner reads the mode at startup and drives the matching loop:
 *
 * - {@see Mode::Classic} — Laravel's own `public/index.php` lifecycle, one request per script run;
 * - {@see Mode::Worker} — a resident application on Laravel Octane, requests through the superglobals;
 * - {@see Mode::Dispatcher} — a resident application on Laravel Octane, requests as Rapira exchanges.
 *
 * ```php
 * require __DIR__ . '/vendor/autoload.php';
 *
 * (new \Rapira\Laravel\Runner(__DIR__))->run();
 * ```
 */
final readonly class Runner
{
    private string $basePath;

    /**
     * @param string $basePath The application root, the directory holding `bootstrap/app.php`.
     */
    public function __construct(string $basePath)
    {
        $this->basePath = \rtrim($basePath, '/\\');
    }

    public function run(): void
    {
        match (get_mode()) {
            Mode::Classic => (new ClassicServer($this->basePath))->run(),
            Mode::Worker => $this->runWorker(),
            Mode::Dispatcher => $this->runDispatcher(),
        };
    }

    private function runWorker(): void
    {
        $client = new SapiClient();
        $worker = $this->bootWorker($client);

        (new WorkerServer($worker, $client, new ErrorReporter($worker->application())))->run();
    }

    private function runDispatcher(): void
    {
        $dispatcher = get_dispatcher();
        // The pool may serve any plugin; this bridge speaks HTTP only.
        $dispatcher instanceof HttpDispatcher or throw new \LogicException(
            \sprintf('Only the "http" dispatcher is supported, "%s" was given.', $dispatcher->name()),
        );

        $client = new ExchangeClient(
            new ExchangeRequestFactory($this->basePath . '/public'),
            new ExchangeEmitter(),
        );
        $worker = $this->bootWorker($client);

        (new DispatcherServer($worker, $client, $dispatcher, new ErrorReporter($worker->application())))->run();
    }

    private function bootWorker(SapiClient|ExchangeClient $client): Worker
    {
        // A client that drops the connection must not abort the script halfway through a request: the
        // application state it leaves behind is the next request's state.
        \ignore_user_abort(true);

        // The SAPI is neither `cli` nor `phpdbg`, but say it outright: console-only bootstrapping would
        // otherwise run in the resident application.
        $_ENV['APP_RUNNING_IN_CONSOLE'] = false;

        // Upload temp files are spooled by the host, not by PHP's upload handler, so PHP does not know
        // them as uploads. Octane's default config installs the same fixes; this does not rely on it.
        (new EnsureUploadedFilesAreValid())->handle(null);
        (new EnsureUploadedFilesCanBeMoved())->handle(null);

        $worker = new Worker(new ApplicationFactory($this->basePath), $client);
        $worker->boot();

        return $worker;
    }
}
