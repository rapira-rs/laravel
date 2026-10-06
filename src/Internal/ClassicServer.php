<?php

declare(strict_types=1);

namespace Rapira\Laravel\Internal;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Rapira\Mode;

/**
 * Serves {@see Mode::Classic}: one request per script run, through the same lifecycle as Laravel's own
 * `public/index.php`. Nothing outlives the request, so there is nothing for Octane to reset.
 *
 * @internal
 */
final readonly class ClassicServer
{
    public function __construct(
        private string $basePath,
        private Runtime $runtime,
    ) {}

    public function run(): void
    {
        \defined('LARAVEL_START') or \define('LARAVEL_START', \microtime(true));

        // Answers with the maintenance page, and exits, while the application is down.
        if (\is_file($maintenance = $this->basePath . '/storage/framework/maintenance.php')) {
            require $maintenance;
        }

        /** @var Application $app */
        $app = require $this->basePath . '/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);

        $request = Request::capture();
        $response = $kernel->handle($request);
        $response->send();
        $this->runtime->finishRequest();

        $kernel->terminate($request, $response);
    }
}
