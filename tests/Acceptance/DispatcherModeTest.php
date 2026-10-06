<?php

declare(strict_types=1);

namespace Rapira\Laravel\Tests\Acceptance;

use Rapira\Laravel\Tests\Acceptance\Support\ServerRequests;
use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Testo\Test;

/**
 * Real HTTP requests against `tests/App` served by the `rapira` binary in {@see Mode::Dispatcher}.
 */
#[Test]
#[RunRapira(mode: Mode::Dispatcher, address: self::ADDRESS, readyTimeout: 15.0)]
final class DispatcherModeTest
{
    use ServerRequests;

    private const ADDRESS = '127.0.0.1:8083';

    protected function mode(): Mode
    {
        return Mode::Dispatcher;
    }
}
