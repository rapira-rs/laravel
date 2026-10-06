<?php

declare(strict_types=1);

namespace Rapira\Laravel\Internal;

use Rapira\Http\HttpDispatcher;
use Rapira\Mode;

/**
 * The slice of the Rapira runtime the runner talks to.
 *
 * The extension's functions are global and, with `rapira/contract` installed, always declared, so they
 * cannot be swapped out per test. The runner goes through this seam instead.
 *
 * @internal
 */
interface Runtime
{
    /**
     * The mode the host launched this process in.
     */
    public function mode(): Mode;

    /**
     * The HTTP dispatcher. Valid only in {@see Mode::Dispatcher}.
     *
     * @throws \LogicException The pool serves a plugin other than HTTP.
     */
    public function dispatcher(): HttpDispatcher;

    /**
     * Serves the next SAPI request through $handler. False once the worker drains.
     *
     * @param callable(): void $handler
     */
    public function handleRequest(callable $handler): bool;

    /**
     * Hands the response written so far to the client, so the script can keep working after it.
     * Classic and worker modes only.
     */
    public function finishRequest(): void;
}
