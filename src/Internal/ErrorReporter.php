<?php

declare(strict_types=1);

namespace Rapira\Laravel\Internal;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Laravel\Octane\Stream;

/**
 * Reports failures that happen outside a request the application handles — a request that could not
 * even be built — through the application's exception handler.
 *
 * @internal
 */
final readonly class ErrorReporter
{
    public function __construct(
        private Application $app,
    ) {}

    public function report(\Throwable $e): void
    {
        try {
            $this->app->make(ExceptionHandler::class)->report($e);
        } catch (\Throwable) {
            // The handler itself is broken; stderr is the last place left to say it.
            Stream::shutdown($e);
        }
    }

    public function debug(): bool
    {
        try {
            return (bool) $this->app->make('config')->get('app.debug');
        } catch (\Throwable) {
            return false;
        }
    }
}
