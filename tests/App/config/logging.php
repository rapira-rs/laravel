<?php

declare(strict_types=1);

return [
    'default' => 'null',
    'channels' => [
        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],
    ],
];
