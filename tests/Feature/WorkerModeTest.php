<?php

declare(strict_types=1);

namespace Rapira\Laravel\Tests\Feature;

use Rapira\Laravel\Runner;
use Rapira\Laravel\Tests\Support\FakeRuntime;
use Rapira\Mode;
use Testo\Assert;
use Testo\Test;

/**
 * The runner against the fixture application in {@see Mode::Worker} and {@see Mode::Classic}, with the
 * host replaced by a runtime that fills the superglobals per request.
 */
#[Test]
final class WorkerModeTest
{
    public function servesEveryRequestUntilTheHostDrains(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker))
            ->queue('GET', '/')
            ->queue('GET', '/hello/Rapira?x=1');

        $this->run($runtime);

        Assert::same($runtime->outputs, ['OK', 'Hello, Rapira!']);
    }

    public function stateDoesNotLeakIntoTheNextRequest(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker))
            ->queue('GET', '/config/leak')
            ->queue('GET', '/config/check');

        $this->run($runtime);

        Assert::same($runtime->outputs, ['set', '{"leaked":false}']);
    }

    public function responseIsFinishedBeforeTheRequestTerminates(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker))
            ->queue('GET', '/')
            ->queue('GET', '/');

        $this->run($runtime);

        Assert::same($runtime->finishRequestCalls, 2);
    }

    public function routeFailureIsAnsweredByTheApplicationAndTheWorkerGoesOn(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker))
            ->queue('GET', '/fail')
            ->queue('GET', '/');

        $this->run($runtime);

        Assert::string($runtime->outputs[0])->contains('Route failure');
        Assert::same($runtime->outputs[1], 'OK');
    }

    public function printedOutputLeadsTheResponse(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker))->queue('GET', '/echo-output');

        $this->run($runtime);

        Assert::same($runtime->outputs, ['printed;returned']);
    }

    public function streamFailureStopsTheWorker(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker))
            ->queue('GET', '/stream/fail')
            ->queue('GET', '/');

        $this->run($runtime);

        Assert::count($runtime->outputs, 1);
    }

    public function requestDataReachesTheApplication(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker))->queue(
            'GET',
            '/inspect?q=1',
            ['HTTP_X_CUSTOM' => 'value', 'REMOTE_ADDR' => '10.0.0.7'],
            ['plain' => 'a b'],
        );

        $this->run($runtime);

        $data = \json_decode($runtime->outputs[0], true, flags: \JSON_THROW_ON_ERROR);
        Assert::same($data['fullUrl'], 'http://localhost/inspect?q=1');
        Assert::same($data['ip'], '10.0.0.7');
        Assert::same($data['cookies']['plain'], 'a b');
        Assert::same($data['headers']['x-custom'], 'value');
    }

    public function classicModeServesOneRequestFromTheSuperglobals(): void
    {
        $runtime = new FakeRuntime(Mode::Classic);
        $server = $_SERVER;
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/hello/Classic',
            'HTTP_HOST' => 'localhost',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/app/public/index.php',
        ] + $server;

        \ob_start();
        try {
            $this->run($runtime);
        } finally {
            $output = \ob_get_clean();
            $_SERVER = $server;
        }

        Assert::same($output, 'Hello, Classic!');
        Assert::same($runtime->finishRequestCalls, 1);
    }

    private function run(FakeRuntime $runtime): void
    {
        (new Runner(\dirname(__DIR__) . '/App', $runtime))->run();
    }
}
