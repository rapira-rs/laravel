<?php

declare(strict_types=1);

namespace Rapira\Laravel\Http;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exception\FileNotSendableException;
use Rapira\Http\Exception\HeadAlreadyWrittenError;
use Rapira\Http\Exchange;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a Symfony response into a Rapira {@see Exchange}: the dispatcher-mode counterpart of
 * {@see Response::send()}. One call finalizes the exchange.
 *
 * Three shapes of body:
 * - a buffered body goes out together with the head, in one write when it fits the chunk size;
 * - a {@see StreamedResponse} — and anything else that prints its body — is captured from the output
 *   layer and forwarded chunk by chunk, the head going out with the first chunk;
 * - a {@see BinaryFileResponse} is handed to the host with {@see Exchange::sendFile()}, so PHP never
 *   holds the bytes.
 *
 * Until the head is on its way an error response can still take the place of the one that failed: the
 * buffered body is read and the streamed one is started before the head is committed.
 */
final class ExchangeEmitter
{
    private const DEFAULT_CHUNK_SIZE = 8_388_608; // 8MB

    /**
     * @param int<1, max> $chunkSize Bytes per body write. A buffered body no longer than this goes out
     *        in a single write; output captured from a streamed body is forwarded once this much has
     *        accumulated, or earlier on an explicit `flush()`/`ob_flush()`.
     * @param int<1, max> $streamBufferSize Bytes of streamed output held before they are forwarded.
     */
    public function __construct(
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        private readonly int $streamBufferSize = 8192,
    ) {
        if ($chunkSize < 1 || $streamBufferSize < 1) {
            throw new \InvalidArgumentException('Chunk and buffer sizes must be greater than zero.');
        }
    }

    /**
     * @param string $prefix Output printed by the application while it built the response. Goes out
     *        ahead of the body, as it would have under a SAPI.
     *
     * @throws WorkDiscardedException The host closed the exchange: the client left or the deadline passed.
     */
    public function emit(Exchange $exchange, Response $response, string $prefix = ''): void
    {
        // The file goes out byte-exact under the length `prepare()` computed, so stray output cannot lead it.
        if ($response instanceof BinaryFileResponse) {
            if (!$this->sendFile($exchange, $response)) {
                $this->emitStream($exchange, $response);
            }
            return;
        }

        if ($response instanceof StreamedResponse) {
            $this->emitStream($exchange, $response, $prefix);
            return;
        }

        $this->emitBuffered($exchange, $response, $prefix . $response->getContent());
    }

    /**
     * Answers with a plain-text error unless a head has already been committed: then the exchange is
     * left alone, and the host cuts the response once the exchange is released.
     */
    public function emitError(Exchange $exchange, int $status, string $message): void
    {
        if ($exchange->isFinalized()) {
            return;
        }

        try {
            $exchange->writeHead($status, ['content-type' => ['text/plain; charset=UTF-8']]);
            $exchange->writeBody($message);
        } catch (HeadAlreadyWrittenError|WorkDiscardedException) {
        }
    }

    private function emitBuffered(Exchange $exchange, Response $response, string $content): void
    {
        /** @var int<100, 599> $status */
        $status = $response->getStatusCode();
        // The host computes the length of what it is given and enforces any length it is told, so a
        // stale one carried by the response would reject the whole response.
        $headers = $this->collectHeaders($response, keepLength: false);
        $length = \strlen($content);

        if ($length <= $this->chunkSize) {
            $exchange->writeHead($status, $headers);
            $exchange->writeBody($content);
            return;
        }

        // Keeps HTTP/1.1 off chunked encoding: the length is known, only the writes are split.
        $headers['Content-Length'] = [(string) $length];
        $exchange->writeHead($status, $headers);
        for ($offset = 0; $offset + $this->chunkSize < $length; $offset += $this->chunkSize) {
            $exchange->writeBody(\substr($content, $offset, $this->chunkSize), eos: false);
        }
        $exchange->writeBody(\substr($content, $offset));
    }

    /**
     * Captures whatever {@see Response::sendContent()} prints and forwards it into the exchange.
     */
    private function emitStream(Exchange $exchange, Response $response, string $prefix = ''): void
    {
        /** @var int<100, 599> $status */
        $status = $response->getStatusCode();
        $headers = $this->collectHeaders($response, keepLength: $prefix === '');
        $headWritten = false;
        /** @var \Throwable|null $failure */
        $failure = null;

        $write = static function (string $chunk) use ($exchange, $status, $headers, &$headWritten): void {
            if (!$headWritten) {
                $headWritten = true;
                $exchange->writeHead($status, $headers);
            }
            $exchange->writeBody($chunk, eos: false);
        };

        // A throw out of an output handler does not reach the code that printed, so the first failure
        // is kept, the rest of the output is dropped, and the failure is raised once the body is done.
        $handler = static function (string $buffer, int $phase) use ($write, &$failure): string {
            if ($buffer === '' || $failure !== null || ($phase & \PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                return '';
            }
            try {
                $write($buffer);
            } catch (\Throwable $e) {
                $failure = $e;
            }

            return '';
        };

        if ($prefix !== '') {
            $write($prefix);
        }

        $level = \ob_get_level();
        \ob_start($handler, $this->streamBufferSize);
        try {
            $response->sendContent();
        } finally {
            // Buffers the body opened and left behind are flushed into this one, as the SAPI would.
            while (\ob_get_level() > $level) {
                \ob_end_flush();
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        if (!$headWritten) {
            $exchange->writeHead($status, $headers);
        }
        $exchange->writeBody('');
    }

    /**
     * Hands a file on disk to the host. False when the response needs PHP to produce its body instead.
     */
    private function sendFile(Exchange $exchange, BinaryFileResponse $response): bool
    {
        $reader = \Closure::bind(
            static fn(BinaryFileResponse $r): array => [$r->offset, $r->maxlen, $r->deleteFileAfterSend, $r->tempFileObject],
            null,
            BinaryFileResponse::class,
        );
        /** @var array{int, int, bool, ?\SplTempFileObject} $state */
        $state = $reader($response);
        [$offset, $maxlen, $deleteAfterSend, $tempFile] = $state;

        // A temporary file has no path to hand over, and a file deleted after sending must outlive the
        // host's read of it: PHP streams both. So does everything `sendContent()` would not send a file for.
        if ($tempFile !== null || $deleteAfterSend || !$response->isSuccessful() || $maxlen === 0) {
            return false;
        }

        $path = $response->getFile()->getPathname();
        if ($path === '') {
            return false;
        }

        /** @var int<100, 599> $status */
        $status = $response->getStatusCode();
        $exchange->writeHead($status, $this->collectHeaders($response, keepLength: true));

        try {
            $exchange->sendFile($path, \max(0, $offset), $maxlen > 0 ? $maxlen : null);
        } catch (FileNotSendableException) {
            // Outside the host's sendfile root, or gone. Nothing has been written yet, so PHP reads it
            // into the exchange under the head already committed.
            $this->streamFile($exchange, $path, $offset, $maxlen);
        }

        return true;
    }

    private function streamFile(Exchange $exchange, string $path, int $offset, int $maxlen): void
    {
        $file = new \SplFileObject($path, 'rb');
        if ($offset > 0) {
            $file->fseek($offset);
        }

        $remaining = $maxlen;
        while ($remaining !== 0 && !$file->eof()) {
            $size = $remaining < 0 || $remaining > $this->chunkSize ? $this->chunkSize : $remaining;
            $data = $file->fread($size);
            if ($data === false || $data === '') {
                break;
            }
            $exchange->writeBody($data, eos: false);
            if ($remaining > 0) {
                $remaining -= \strlen($data);
            }
        }

        $exchange->writeBody('');
    }

    /**
     * @return array<non-empty-string, list<string>>
     */
    private function collectHeaders(Response $response, bool $keepLength): array
    {
        $headers = [];
        /**
         * @var non-empty-string $name
         * @var list<string|null> $values
         */
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            if (!$keepLength && \strcasecmp($name, 'content-length') === 0) {
                continue;
            }
            foreach ($values as $value) {
                $headers[$name][] = (string) $value;
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'][] = (string) $cookie;
        }

        return $headers;
    }
}
