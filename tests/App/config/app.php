<?php

declare(strict_types=1);

return [
    'name' => 'Rapira',
    'env' => 'testing',
    'debug' => (bool) ($_SERVER['APP_DEBUG'] ?? true),
    'url' => 'http://localhost',
    'key' => 'base64:' . \base64_encode(\str_repeat('k', 32)),
    'cipher' => 'AES-256-CBC',
];
