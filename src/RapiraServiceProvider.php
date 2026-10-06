<?php

declare(strict_types=1);

namespace Rapira\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Publishes the entry script and a starter server config:
 *
 * ```shell
 * php artisan vendor:publish --tag=rapira
 * ```
 */
final class RapiraServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $stubs = \dirname(__DIR__) . '/stubs';
        $this->publishes([
            $stubs . '/worker.php' => $this->app->basePath('worker.php'),
            $stubs . '/rapira.toml' => $this->app->basePath('rapira.toml'),
        ], 'rapira');
    }
}
