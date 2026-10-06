<?php

declare(strict_types=1);

return [
    'driver' => 'file',
    'lifetime' => 120,
    'files' => storage_path('framework/sessions'),
    'cookie' => 'rapira_session',
];
