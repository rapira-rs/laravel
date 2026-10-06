<?php

declare(strict_types=1);

namespace Rapira\Laravel\Tests\Unit;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Laravel\Http\ExchangeEmitter;
use Rapira\Laravel\Tests\Support\FakeExchange;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class ExchangeEmitterTest
{
    private string $file;

    #[BeforeTest]
    public function createFile(): void
    {
        $this->file = \tempnam(\sys_get_temp_dir(), 'rapira');
        \file_put_contents($this->file, '0123456789');
    }

    #[AfterTest]
    public function removeFile(): void
    {
        @\unlink($this->file);
    }

    public function bufferedBodyGoesOutInOneWrite(): void
    {
        $exchange = new FakeExchange();

        (new ExchangeEmitter())->emit($exchange, new Response('Hello', 201, ['X-Custom' => 'yes']));

        Assert::same($exchange->status, 201);
        Assert::same($exchange->chunks, ['Hello']);
        Assert::same($exchange->header('x-custom'), 'yes');
        Assert::true($exchange->isFinalized());
    }

    public function staleContentLengthIsLeftToTheHost(): void
    {
        $exchange = new FakeExchange();

        (new ExchangeEmitter())->emit($exchange, new Response('Hello', 200, ['Content-Length' => '999']));

        Assert::null($exchange->header('content-length'));
    }

    public function cookiesBecomeSetCookieFields(): void
    {
        $exchange = new FakeExchange();
        $response = new Response('');
        $response->headers->setCookie(Cookie::create('a', '1'));
        $response->headers->setCookie(Cookie::create('b', 'x y'));

        (new ExchangeEmitter())->emit($exchange, $response);

        Assert::count($exchange->headers['Set-Cookie'], 2);
        Assert::string($exchange->headers['Set-Cookie'][1])->startsWith('b=x%20y;');
    }

    public function printedOutputLeadsTheBody(): void
    {
        $exchange = new FakeExchange();

        (new ExchangeEmitter())->emit($exchange, new Response('body'), 'printed;');

        Assert::same($exchange->getBody(), 'printed;body');
    }

    public function largeBodyIsSplitUnderItsLength(): void
    {
        $exchange = new FakeExchange();

        (new ExchangeEmitter(chunkSize: 4))->emit($exchange, new Response('0123456789'));

        Assert::same($exchange->chunks, ['0123', '4567', '89']);
        Assert::same($exchange->header('content-length'), '10');
        Assert::true($exchange->isFinalized());
    }

    public function streamIsForwardedAsItIsPrinted(): void
    {
        $exchange = new FakeExchange();
        $response = new StreamedResponse(static function () use ($exchange): void {
            echo 'one;';
            \ob_flush();
            // The head goes out with the first chunk, not before it.
            Assert::same($exchange->status, 200);
            echo 'two';
        }, 200, ['X-Stream' => 'yes']);

        (new ExchangeEmitter())->emit($exchange, $response, 'printed;');

        Assert::same($exchange->getBody(), 'printed;one;two');
        Assert::same($exchange->header('x-stream'), 'yes');
        Assert::same(\end($exchange->chunks), '');
        Assert::true($exchange->isFinalized());
    }

    public function cleanedOutputIsNotForwarded(): void
    {
        $exchange = new FakeExchange();
        $response = new StreamedResponse(static function (): void {
            echo 'dropped';
            \ob_clean();
            echo 'kept';
        });

        (new ExchangeEmitter())->emit($exchange, $response);

        Assert::same($exchange->getBody(), 'kept');
    }

    public function emptyStreamStillAnswers(): void
    {
        $exchange = new FakeExchange();

        (new ExchangeEmitter())->emit($exchange, new StreamedResponse(static function (): void {}, 204));

        Assert::same($exchange->status, 204);
        Assert::true($exchange->isFinalized());
    }

    public function streamFailingBeforeOutputLeavesRoomForAnErrorResponse(): void
    {
        $exchange = new FakeExchange();
        $emitter = new ExchangeEmitter();
        $level = \ob_get_level();

        try {
            $emitter->emit($exchange, new StreamedResponse(static function (): never {
                throw new \RuntimeException('Broken stream');
            }));
            Assert::fail('The stream failure must propagate.');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'Broken stream');
        }

        Assert::same(\ob_get_level(), $level);
        Assert::null($exchange->status);

        $emitter->emitError($exchange, 500, 'Internal server error.');

        Assert::same($exchange->status, 500);
        Assert::same($exchange->getBody(), 'Internal server error.');
        Assert::true($exchange->isFinalized());
    }

    public function streamFailingAfterOutputIsLeftForTheHostToCut(): void
    {
        $exchange = new FakeExchange();
        $emitter = new ExchangeEmitter();

        try {
            $emitter->emit($exchange, new StreamedResponse(static function (): never {
                echo 'partial';
                \ob_flush();
                throw new \RuntimeException('Broken stream');
            }));
        } catch (\RuntimeException) {
        }

        $emitter->emitError($exchange, 500, 'Internal server error.');

        Assert::same($exchange->status, 200);
        Assert::same($exchange->getBody(), 'partial');
        Assert::false($exchange->isFinalized());
    }

    public function discardedExchangeStopsTheStream(): void
    {
        $exchange = new FakeExchange();
        $exchange->discardOnWrite();
        $printed = [];

        try {
            (new ExchangeEmitter())->emit($exchange, new StreamedResponse(static function () use (&$printed): void {
                echo 'one';
                \ob_flush();
                $printed[] = 'after';
            }));
            Assert::fail('The discard must propagate.');
        } catch (WorkDiscardedException) {
        }

        Assert::same($printed, ['after']);
        Assert::same($exchange->chunks, []);
    }

    public function fileIsHandedToTheHost(): void
    {
        $exchange = new FakeExchange();
        $response = new BinaryFileResponse($this->file);
        $response->prepare(Request::create('/'));

        (new ExchangeEmitter())->emit($exchange, $response);

        Assert::same($exchange->sentFiles, [['path' => $this->file, 'offset' => 0, 'length' => null]]);
        Assert::same($exchange->header('content-length'), '10');
        Assert::true($exchange->isFinalized());
    }

    public function rangeOfAFileIsHandedToTheHost(): void
    {
        $exchange = new FakeExchange();
        $response = new BinaryFileResponse($this->file);
        $response->prepare(Request::create('/', server: ['HTTP_RANGE' => 'bytes=2-5']));

        (new ExchangeEmitter())->emit($exchange, $response);

        Assert::same($exchange->status, 206);
        Assert::same($exchange->sentFiles, [['path' => $this->file, 'offset' => 2, 'length' => 4]]);
        Assert::same($exchange->header('content-range'), 'bytes 2-5/10');
    }

    public function fileTheHostRefusesIsReadByPhp(): void
    {
        $exchange = new FakeExchange();
        $exchange->refuseFiles = true;
        $response = new BinaryFileResponse($this->file);
        $response->prepare(Request::create('/', server: ['HTTP_RANGE' => 'bytes=2-5']));

        (new ExchangeEmitter(chunkSize: 3))->emit($exchange, $response);

        Assert::same($exchange->status, 206);
        Assert::same($exchange->chunks, ['234', '5', '']);
        Assert::true($exchange->isFinalized());
    }

    public function fileDeletedAfterSendIsReadByPhpFirst(): void
    {
        $exchange = new FakeExchange();
        $response = new BinaryFileResponse($this->file);
        $response->deleteFileAfterSend();
        $response->prepare(Request::create('/'));

        (new ExchangeEmitter())->emit($exchange, $response);

        Assert::same($exchange->sentFiles, []);
        Assert::same($exchange->getBody(), '0123456789');
        Assert::false(\is_file($this->file));
    }

    public function errorResponseIsSkippedOnceTheHeadIsCommitted(): void
    {
        $exchange = new FakeExchange();
        $exchange->writeHead(200);

        (new ExchangeEmitter())->emitError($exchange, 500, 'Internal server error.');

        Assert::same($exchange->status, 200);
        Assert::false($exchange->isFinalized());
    }

    public function errorResponseIsSkippedForADiscardedExchange(): void
    {
        $exchange = new FakeExchange();
        $exchange->discard();

        try {
            (new ExchangeEmitter())->emitError($exchange, 500, 'Internal server error.');
        } catch (\Throwable $e) {
            Assert::fail('No error may escape: ' . $e::class);
        }

        Assert::null($exchange->status);
    }
}
