<?php

declare(strict_types=1);

namespace Rapira\Laravel\Internal;

use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Rapira\Laravel\Octane\SapiClient;
use Rapira\Mode;

/**
 * Serves {@see Mode::Worker}: the loop over `Rapira\handle_request()`, each request run through the
 * Octane worker.
 *
 * @internal
 */
final readonly class WorkerServer
{
    public function __construct(
        private Worker $worker,
        private SapiClient $client,
        private Runtime $runtime,
        private ErrorReporter $reporter,
    ) {}

    /**
     * Keeps serving until the host drains the worker or Octane asks it to stop.
     */
    public function run(): void
    {
        // The host keeps the worker serving only while the handler answers true.
        $handler = function (): bool {
            $this->serve();

            return true;
        };

        try {
            while (!$this->client->isStopped() && $this->runtime->handleRequest($handler)) {
                \gc_collect_cycles();
            }
        } finally {
            $this->worker->terminate();
        }
    }

    private function serve(): void
    {
        try {
            [$request, $context] = $this->client->marshalRequest(new RequestContext());
        } catch (\Throwable $e) {
            $this->reporter->report($e);
            $this->client->reject($e, $this->reporter->debug());
            return;
        }

        $this->worker->handle($request, $context);
    }
}
