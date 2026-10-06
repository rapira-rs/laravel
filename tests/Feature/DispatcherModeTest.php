<?php

declare(strict_types=1);

namespace Rapira\Laravel\Tests\Feature;

use Rapira\Laravel\Runner;
use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * The runner against the fixture application in {@see Mode::Dispatcher}, with the host replaced by a
 * scripted dispatcher.
 */
#[Test]
final class DispatcherModeTest
{
    #[AfterTest]
    public function resetRuntime(): void
    {
        FakeRuntime::reset();
    }

    public function servesEveryExchangeUntilDrained(): void
    {
        $first = FakeExchange::for('/');
        $second = FakeExchange::for('/hello/Rapira');

        $this->run($first, $second);

        Assert::same($first->status, 200);
        Assert::same($first->getBody(), 'OK');
        Assert::same($second->getBody(), 'Hello, Rapira!');
        Assert::true($first->isFinalized());
        Assert::true($second->isFinalized());
    }

    public function stateDoesNotLeakIntoTheNextRequest(): void
    {
        $leak = FakeExchange::for('/config/leak');
        $check = FakeExchange::for('/config/check');

        $this->run($leak, $check);

        Assert::same($leak->getBody(), 'set');
        Assert::same($check->getBody(), '{"leaked":false}');
    }

    public function sessionSurvivesBetweenRequestsOfOneClient(): void
    {
        $put = FakeExchange::for('/session/put/rapira');
        $this->run($put);

        $cookies = [];
        foreach ($put->headers['Set-Cookie'] as $cookie) {
            $cookies[] = \explode(';', $cookie, 2)[0];
        }
        $get = FakeExchange::for('/session/get', headers: ['cookie' => [\implode('; ', $cookies)]]);
        $this->run($get);

        Assert::same($get->getBody(), '{"value":"rapira"}');
    }

    public function encryptedCookieRoundTrips(): void
    {
        $set = FakeExchange::for('/cookie');
        $this->run($set);

        $cookie = '';
        foreach ($set->headers['Set-Cookie'] as $header) {
            \str_starts_with($header, 'flavor=') and $cookie = \explode(';', $header, 2)[0];
        }
        $read = FakeExchange::for('/cookie/read', headers: ['cookie' => [$cookie]]);
        $this->run($read);

        Assert::same($read->getBody(), '{"flavor":"chocolate chip"}');
    }

    public function routeFailureIsAnsweredByTheApplicationAndTheWorkerGoesOn(): void
    {
        $failing = FakeExchange::for('/fail');
        $next = FakeExchange::for('/');

        $this->run($failing, $next);

        Assert::same($failing->status, 500);
        Assert::string($failing->getBody())->contains('Route failure');
        Assert::same($next->getBody(), 'OK');
    }

    public function printedOutputLeadsTheResponse(): void
    {
        $exchange = FakeExchange::for('/echo-output');

        $this->run($exchange);

        Assert::same($exchange->getBody(), 'printed;returned');
    }

    public function streamedResponseIsForwarded(): void
    {
        $exchange = FakeExchange::for('/stream');

        $this->run($exchange);

        Assert::same($exchange->getBody(), 'chunk-1;chunk-2');
        Assert::same($exchange->header('x-stream'), 'yes');
        Assert::true($exchange->isFinalized());
    }

    public function streamFailureBeforeOutputIsAnsweredAndStopsTheWorker(): void
    {
        $failing = FakeExchange::for('/stream/fail');
        $next = FakeExchange::for('/');

        $dispatcher = $this->run($failing, $next);

        Assert::same($failing->status, 500);
        Assert::string($failing->getBody())->contains('Stream failure');
        // Octane cannot tell what a failure outside the kernel left behind, so the script starts afresh.
        Assert::same($dispatcher->receives, 1);
        Assert::null($next->status);
    }

    public function downloadIsHandedToTheHost(): void
    {
        $exchange = FakeExchange::for('/download');

        $this->run($exchange);

        Assert::count($exchange->sentFiles, 1);
        Assert::string($exchange->sentFiles[0]['path'])->contains('download.txt');
        Assert::string((string) $exchange->header('content-disposition'))->contains('file.txt');
    }

    public function requestDataReachesTheApplication(): void
    {
        $exchange = FakeExchange::for(
            '/inspect?q=1',
            method: 'POST',
            headers: [
                'content-type' => ['application/x-www-form-urlencoded'],
                'cookie' => ['plain=a%20b'],
                'x-custom' => ['value'],
            ],
            body: 'name=Rapira',
        );

        $this->run($exchange);

        $data = \json_decode($exchange->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        Assert::same($data['method'], 'POST');
        Assert::same($data['fullUrl'], 'http://localhost:8080/inspect?q=1');
        Assert::same($data['ip'], '10.0.0.7');
        Assert::same($data['input'], ['name' => 'Rapira']);
        Assert::same($data['cookies']['plain'], 'a b');
        Assert::same($data['headers']['x-custom'], 'value');
    }

    public function cancelledExchangeIsSkipped(): void
    {
        $cancelled = FakeExchange::for('/');
        $cancelled->discard();
        $next = FakeExchange::for('/hello/next');

        $this->run($cancelled, $next);

        Assert::null($cancelled->status);
        Assert::same($next->getBody(), 'Hello, next!');
    }

    public function clientGoneMidResponseIsNotAnError(): void
    {
        $gone = FakeExchange::for('/');
        $gone->discardOnWrite();
        $next = FakeExchange::for('/hello/next');

        $this->run($gone, $next);

        Assert::same($next->getBody(), 'Hello, next!');
    }

    public function exchangeIsReleasedBeforeWaitingForMoreWork(): void
    {
        $exchange = FakeExchange::for('/');
        $reference = \WeakReference::create($exchange);
        $dispatcher = new FakeHttpDispatcher($exchange);
        unset($exchange);

        $released = null;
        $dispatcher->beforeReceive = static function () use ($dispatcher, $reference, &$released): void {
            $dispatcher->receives === 2 and $released = $reference->get() === null;
        };

        (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();
        (new Runner(\dirname(__DIR__) . '/App'))->run();

        Assert::true($released);
    }

    private function run(FakeExchange ...$exchanges): FakeHttpDispatcher
    {
        $dispatcher = new FakeHttpDispatcher(...$exchanges);
        (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();
        (new Runner(\dirname(__DIR__) . '/App'))->run();

        return $dispatcher;
    }
}
