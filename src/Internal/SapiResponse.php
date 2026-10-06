<?php

declare(strict_types=1);

namespace Rapira\Laravel\Internal;

use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a response through the SAPI, the way it goes out under php-fpm.
 *
 * @internal
 */
final class SapiResponse
{
    /**
     * Sends $response inside an output buffer of its own.
     *
     * A streamed callback commonly calls `ob_flush()`, which needs an open buffer: php-fpm has one from
     * `output_buffering`, the Rapira SAPI starts without. Without it `ob_flush()` raises a notice that
     * Laravel turns into an exception halfway through the body.
     */
    public static function send(Response $response): void
    {
        $level = \ob_get_level();
        \ob_start();

        try {
            $response->send();
        } finally {
            // Symfony closes every buffer itself under a server SAPI; under the CLI ours is still open.
            while (\ob_get_level() > $level) {
                \ob_end_flush();
            }
        }
    }
}
