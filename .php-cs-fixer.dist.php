<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

return \Spiral\CodeStyle\Builder::create()
    ->include(__DIR__ . '/src')
    ->include(__DIR__ . '/stubs')
    ->include(__DIR__ . '/tests')
    ->exclude(__DIR__ . '/tests/App/bootstrap/cache')
    ->exclude(__DIR__ . '/tests/App/storage')
    ->include(__FILE__)
    ->include(__DIR__ . '/testo.php')
    ->build();
