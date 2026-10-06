<?php

declare(strict_types=1);

namespace Rapira\Laravel\Internal;

use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Rapira\Exception\ClosedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;
use Rapira\Laravel\Octane\ExchangeClient;
use Rapira\Mode;

/**
 * Serves {@see Mode::Dispatcher}: takes {@see Exchange} units from the HTTP dispatcher and runs each
 * through the Octane worker.
 *
 * One unit at a time, on a blocking `receive()`: the application lives for the whole process and Octane
 * sandboxes it per request, so two requests in flight would share one sandbox.
 *
 * @internal
 */
final readonly class DispatcherServer
{
    public function __construct(
        private Worker $worker,
        private ExchangeClient $client,
        private HttpDispatcher $dispatcher,
        private ErrorReporter $reporter,
    ) {}

    /**
     * Keeps receiving exchanges until the dispatcher is drained or Octane asks the worker to stop.
     */
    public function run(): void
    {
        try {
            while (!$this->client->isStopped()) {
                $this->serve($this->dispatcher->receive());
                \gc_collect_cycles();
            }
        } catch (ClosedException) {
            // Drained: no more work will ever arrive.
        } finally {
            $this->worker->terminate();
        }
    }

    /**
     * Keeps request-local references out of the receive loop: an exchange left unfinalized is released
     * here, and failed by the host, before the worker waits for more work. Holding it across `receive()`
     * would be an error.
     */
    private function serve(Exchange $exchange): void
    {
        // The host closed the unit while it waited in the queue: the client left or the deadline
        // passed. Nothing the application produces for it would be accepted.
        if ($exchange->isCancelled()) {
            return;
        }

        $context = new RequestContext([ExchangeClient::EXCHANGE => $exchange]);

        try {
            [$request, $context] = $this->client->marshalRequest($context);
        } catch (\Throwable $e) {
            $this->reporter->report($e);
            $this->client->reject($context, $e, $this->reporter->debug());
            return;
        }

        $this->worker->handle($request, $context);
    }
}
