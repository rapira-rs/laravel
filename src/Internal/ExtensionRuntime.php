<?php

declare(strict_types=1);

namespace Rapira\Laravel\Internal;

use Rapira\Http\HttpDispatcher;
use Rapira\Mode;

use function Rapira\get_dispatcher;
use function Rapira\get_mode;
use function Rapira\handle_request;

/**
 * {@see Runtime} backed by the Rapira extension.
 *
 * Outside a Rapira process — the CLI, tests — the process behaves as {@see Mode::Classic}.
 *
 * @internal
 */
final class ExtensionRuntime implements Runtime
{
    public function mode(): Mode
    {
        return \function_exists('Rapira\get_mode') ? get_mode() : Mode::Classic;
    }

    public function dispatcher(): HttpDispatcher
    {
        $dispatcher = get_dispatcher();
        // The pool may serve any plugin; this bridge speaks HTTP only.
        $dispatcher instanceof HttpDispatcher or throw new \LogicException(
            \sprintf('Only the "http" dispatcher is supported, "%s" was given.', $dispatcher->name()),
        );

        return $dispatcher;
    }

    public function handleRequest(callable $handler): bool
    {
        return handle_request($handler);
    }

    public function finishRequest(): void
    {
        if (\function_exists('rapira_finish_request')) {
            \rapira_finish_request();
        }
    }
}
