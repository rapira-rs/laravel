<?php

declare(strict_types=1);

namespace Rapira\Laravel\Octane;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Contracts\StoppableClient;
use Laravel\Octane\Octane;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Rapira\Laravel\Internal\Runtime;
use Rapira\Mode;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Octane client for {@see Mode::Worker}: the request arrives through the superglobals the host filled
 * and the response leaves through `header()` and the output, as under any other SAPI.
 *
 * @internal
 */
final class SapiClient implements StoppableClient
{
    private bool $stopped = false;

    public function __construct(
        private readonly Runtime $runtime,
    ) {}

    public function marshalRequest(RequestContext $context): array
    {
        return [Request::capture(), $context];
    }

    public function respond(RequestContext $context, OctaneResponse $octaneResponse): void
    {
        $response = $octaneResponse->response;

        if ((string) $octaneResponse->outputBuffer !== ''
            && !$response instanceof StreamedResponse
            && !$response instanceof BinaryFileResponse
        ) {
            $response->setContent($octaneResponse->outputBuffer . $response->getContent());
        }

        $response->send();
        // What php-fpm does with `fastcgi_finish_request()`: the client has its answer while the
        // terminating middleware and Octane's own cleanup still run.
        $this->runtime->finishRequest();
    }

    public function error(\Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        $this->reject($e, (bool) $app->make('config')->get('app.debug'));
    }

    /**
     * Answers with a plain-text error, unless the head of another response is already out.
     */
    public function reject(\Throwable $e, bool $debug): void
    {
        if (\headers_sent()) {
            return;
        }

        (new Response(
            Octane::formatExceptionForClient($e, $debug),
            500,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        ))->send();
        $this->runtime->finishRequest();
    }

    /**
     * Asked by Octane after a failure that may have left the application in a broken state: the loop
     * ends after the current request and the host starts the script afresh.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }
}
