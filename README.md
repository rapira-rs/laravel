<div align="center">

# rapira/laravel

</div>

<br />

## About

Runs a Laravel application under [Rapira](https://rapira.rs/) in every run mode from one entry script:

- **classic** — one request per script run, the same lifecycle as Laravel's own `public/index.php`.
- **worker** — the application stays resident on [Laravel Octane](https://laravel.com/docs/octane); requests arrive through the superglobals.
- **dispatcher** — the application stays resident on Laravel Octane; requests arrive as `Rapira\Http\Exchange` units and responses are written back into them.

## Install

```bash
composer require rapira/laravel
```

[![PHP](https://img.shields.io/packagist/php-v/rapira/laravel.svg?style=flat-square&logo=php)](https://packagist.org/packages/rapira/laravel)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/rapira/laravel.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/rapira/laravel)
[![License](https://img.shields.io/packagist/l/rapira/laravel.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/rapira/laravel.svg?style=flat-square)](https://packagist.org/packages/rapira/laravel/stats)

## Usage

Publish the entry script and a starter server config into the project root:

```bash
php artisan vendor:publish --tag=rapira
```

`worker.php`:

```php
use Rapira\Laravel\Runner;

require __DIR__ . '/vendor/autoload.php';

(new Runner(__DIR__))->run();
```

`rapira.toml` — pick the mode in `[http.pool]`, the entry script stays the same:

```toml
[http]
listen = "127.0.0.1:8000"
middleware = ["static"]

[http.static]
root = "public"

[http.pool]
entrypoint = "worker.php"
mode = "dispatcher" # or "worker", "classic"
```

Start the server:

```bash
rapira serve rapira.toml
```
