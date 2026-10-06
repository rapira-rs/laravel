<?php

declare(strict_types=1);

namespace Rapira\Laravel\Octane;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Contracts\StoppableClient;
use Laravel\Octane\Octane;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exchange;
use Rapira\Laravel\Http\ExchangeEmitter;
use Rapira\Laravel\Http\ExchangeRequestFactory;
use Rapira\Mode;

/**
 * Octane client for {@see Mode::Dispatcher}: the request comes from an {@see Exchange} and the response
 * is written back into it. The exchange travels in the {@see RequestContext} under {@see self::EXCHANGE}.
 *
 * @internal
 */
final class ExchangeClient implements StoppableClient
{
    public const EXCHANGE = 'rapiraExchange';

    private bool $stopped = false;

    public function __construct(
        private readonly ExchangeRequestFactory $requestFactory,
        private readonly ExchangeEmitter $emitter,
    ) {}

    public function marshalRequest(RequestContext $context): array
    {
        return [$this->requestFactory->create($this->exchange($context)), $context];
    }

    public function respond(RequestContext $context, OctaneResponse $octaneResponse): void
    {
        try {
            $this->emitter->emit(
                $this->exchange($context),
                $octaneResponse->response,
                (string) $octaneResponse->outputBuffer,
            );
        } catch (WorkDiscardedException) {
            // The client left or the deadline passed; the host has already failed the exchange. Nobody
            // to answer, and nothing wrong with the worker either.
        }
    }

    public function error(\Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        $this->reject($context, $e, (bool) $app->make('config')->get('app.debug'));
    }

    /**
     * Answers a request that never reached the application.
     */
    public function reject(RequestContext $context, \Throwable $e, bool $debug): void
    {
        $this->emitter->emitError($this->exchange($context), 500, Octane::formatExceptionForClient($e, $debug));
    }

    /**
     * Asked by Octane after a failure that may have left the application in a broken state: the loop
     * ends after the current exchange and the host starts the script afresh.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    private function exchange(RequestContext $context): Exchange
    {
        /** @var Exchange */
        return $context[self::EXCHANGE];
    }
}
