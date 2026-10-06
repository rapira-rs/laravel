<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: \dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php')
    ->withMiddleware(static function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['*']);
        // Raw cookies the tests send to check how they are parsed.
        $middleware->encryptCookies(except: ['plain', 'spaced', 'dup', 'arr']);
    })
    ->withExceptions(static function (Exceptions $exceptions): void {})
    ->create();
