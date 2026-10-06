<?php

declare(strict_types=1);

use Rapira\Laravel\Runner;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Runner(__DIR__))->run();
