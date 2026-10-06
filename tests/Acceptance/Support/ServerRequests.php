<?php

declare(strict_types=1);

namespace Rapira\Laravel\Tests\Acceptance\Support;

use Rapira\Sdk\Common\Mode;
use Testo\Assert;

/**
 * The requests every mode must answer identically. A test case picks the mode with `RunRapira` and
 * names it in {@see mode()}; the mode is the host's business and the application must not notice it.
 * Each case listens on its own `ADDRESS`, so a server that outlived the previous case can never answer
 * for the current one.
 */
trait ServerRequests
{
    public function homeReturnsOk(): void
    {
        [$status, , $body] = $this->request('/');

        Assert::same($status, 200);
        Assert::same($body, 'OK');
    }

    public function routeArgumentReachesTheController(): void
    {
        [, , $body] = $this->request('/hello/Rapira');

        Assert::same($body, 'Hello, Rapira!');
    }

    public function processRunsInTheRequestedMode(): void
    {
        [, , $body] = $this->request('/status');

        Assert::same(\json_decode($body, true, flags: \JSON_THROW_ON_ERROR)['mode'], $this->mode()->value);
    }

    public function unknownRouteReturns404(): void
    {
        [$status] = $this->request('/unknown-route');

        Assert::same($status, 404);
    }

    public function configChangedByOneRequestDoesNotLeak(): void
    {
        $this->request('/config/leak');
        [, , $body] = $this->request('/config/check');

        Assert::same($body, '{"leaked":false}');
    }

    public function routeFailureIsAnsweredAndTheServerGoesOn(): void
    {
        [$status, , $body] = $this->request('/fail');
        Assert::same($status, 500);
        Assert::string($body)->contains('Route failure');

        [$status, , $body] = $this->request('/');
        Assert::same($status, 200);
        Assert::same($body, 'OK');
    }

    public function sessionSurvivesBetweenRequests(): void
    {
        [, $headers] = $this->request('/session/put/rapira');
        $cookies = [];
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $cookies[] = \explode(';', $cookie, 2)[0];
        }

        [, , $body] = $this->request('/session/get', headers: ['Cookie: ' . \implode('; ', $cookies)]);

        Assert::same($body, '{"value":"rapira"}');
    }

    public function formPostReachesTheApplication(): void
    {
        [, , $body] = $this->request('/inspect?q=1', 'POST', ['X-Custom: value'], 'name=Rapira&tags[]=a');

        $data = \json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        Assert::same($data['method'], 'POST');
        Assert::same($data['query'], ['q' => '1']);
        Assert::same($data['input'], ['name' => 'Rapira', 'tags' => ['a']]);
        Assert::same($data['headers']['x-custom'], 'value');
    }

    public function streamedResponseArrivesWhole(): void
    {
        [$status, $headers, $body] = $this->request('/stream');

        Assert::same($status, 200);
        Assert::same($headers['x-stream'] ?? null, ['yes']);
        Assert::same($body, 'chunk-1;chunk-2');
    }

    public function downloadArrivesWithItsHeaders(): void
    {
        [$status, $headers, $body] = $this->request('/download');

        Assert::same($status, 200);
        Assert::same($body, "downloadable file\n");
        Assert::string($headers['content-disposition'][0] ?? '')->contains('file.txt');
    }

    abstract protected function mode(): Mode;

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, list<string>>, 2: string} Status, headers by lowercase name, body.
     */
    private function request(string $path, string $method = 'GET', array $headers = [], ?string $body = null): array
    {
        $responseHeaders = [];
        $curl = \curl_init();
        \curl_setopt_array($curl, [
            \CURLOPT_URL => 'http://' . self::ADDRESS . $path,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 10,
            \CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = \explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[\strtolower(\trim($parts[0]))][] = \trim($parts[1]);
                }

                return \strlen($line);
            },
        ]);
        if ($body !== null) {
            \curl_setopt($curl, \CURLOPT_POSTFIELDS, $body);
        }

        $result = \curl_exec($curl);
        if ($result === false) {
            throw new \RuntimeException(\sprintf('Request to %s failed: %s', $path, \curl_error($curl)));
        }

        return [(int) \curl_getinfo($curl, \CURLINFO_HTTP_CODE), $responseHeaders, $result];
    }
}
