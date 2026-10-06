<?php

declare(strict_types=1);

namespace Rapira\Laravel\Http;

use Illuminate\Http\Request;
use Rapira\Http\Exchange;
use Rapira\Http\Multipart;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Http\UploadedFile;
use Rapira\InetAddress;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

use function parse_str;

/**
 * Builds an Illuminate request from the {@see Exchange} the host hands the worker in dispatcher mode.
 *
 * The request is filled the way PHP fills the superglobals in classic and worker modes, so the
 * application sees the same request whatever mode serves it: `$_SERVER`-style meta-variables, query and
 * cookie values decoded and nested by PHP's own rules, form bodies parsed.
 */
final readonly class ExchangeRequestFactory
{
    /**
     * @param non-empty-string $publicPath The directory `public/index.php` lives in. The request is
     *        dated as if that script served it, as under php-fpm, so URL generation is unchanged.
     */
    public function __construct(
        private string $publicPath,
    ) {}

    public function create(Exchange $exchange): Request
    {
        $source = $exchange->getRequest();
        $query = \explode('?', $source->target, 2)[1] ?? '';
        $cookie = $this->headerLine($source->headers, 'cookie', '; ');

        if ($source->body instanceof Multipart) {
            $post = $this->parseFields($source->body);
            $files = $this->createFiles($source->body);
            $content = '';
        } else {
            $post = $this->parseForm($source);
            $files = [];
            $content = $source->body;
        }

        $request = new SymfonyRequest(
            query: $this->parseQuery($query),
            request: $post,
            cookies: $this->parseCookies($cookie),
            files: $files,
            server: $this->createServerParams($source, $query),
            content: $content,
        );

        return Request::createFromBase($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function createServerParams(RapiraRequest $request, string $query): array
    {
        $https = \str_starts_with($request->uri, 'https:') || $request->tls !== null;

        $params = [
            'DOCUMENT_ROOT' => $this->publicPath,
            'SCRIPT_FILENAME' => $this->publicPath . '/index.php',
            'SCRIPT_NAME' => '/index.php',
            'PHP_SELF' => '/index.php',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_SOFTWARE' => 'Rapira',
            'SERVER_PROTOCOL' => $request->protocol,
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $request->target,
            'DOCUMENT_URI' => \explode('?', $request->target, 2)[0],
            'QUERY_STRING' => $query,
            'REQUEST_SCHEME' => $https ? 'https' : 'http',
            'REQUEST_TIME' => (int) $request->receivedAt,
            'REQUEST_TIME_FLOAT' => $request->receivedAt,
        ];

        if ($https) {
            $params['HTTPS'] = 'on';
        }

        if ($request->remote instanceof InetAddress) {
            $params['REMOTE_ADDR'] = $request->remote->ip;
            $params['REMOTE_PORT'] = $request->remote->port;
        } elseif ($request->remote->path !== null) {
            $params['REMOTE_ADDR'] = $request->remote->path;
        }

        if ($request->server instanceof InetAddress) {
            $params['SERVER_ADDR'] = $request->server->ip;
            $params['SERVER_PORT'] = $request->server->port;
        }

        $host = \parse_url($request->uri, \PHP_URL_HOST);
        if (\is_string($host) && $host !== '') {
            $params['SERVER_NAME'] = \trim($host, '[]');
        }

        // The rapira SAPI names fields the same way in worker mode: `-` and `.` become `_`, and a later
        // field overwrites an earlier one that maps to the same name.
        foreach ($request->headers as $name => $values) {
            $key = \strtoupper(\str_replace(['-', '.'], '_', $name));
            $value = \implode($key === 'COOKIE' ? '; ' : ', ', $values);
            $params['HTTP_' . $key] = $value;
            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $params[$key] = $value;
            }
        }

        if ($request->authority !== null) {
            $params['HTTP_HOST'] = $request->authority;
        }

        return $params;
    }

    /**
     * PHP parses a form body into `$_POST` for `POST` only; Symfony's `createFromGlobals()` extends
     * that to `PUT`, `PATCH` and `DELETE`, and Laravel apps rely on it.
     *
     * @return array<array-key, mixed>
     */
    private function parseForm(RapiraRequest $request): array
    {
        if (!\in_array(\strtoupper($request->method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return [];
        }

        $contentType = \strtolower($this->headerLine($request->headers, 'content-type', ', '));
        if (\preg_match('~^application/x-www-form-urlencoded(?:$|[ ;])~', $contentType) !== 1) {
            return [];
        }

        return $this->parseQuery($request->body);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseFields(Multipart $multipart): array
    {
        // Re-encoded as a query string so PHP's own parser builds the `name[key][]` structure and
        // mangles the names exactly as it does for `$_POST`.
        $pairs = [];
        foreach ($multipart->fields as $field) {
            $pairs[] = \rawurlencode($field->name) . '=' . \rawurlencode($field->value);
        }

        return $this->parseQuery(\implode('&', $pairs));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function createFiles(Multipart $multipart): array
    {
        // Same trick as the fields: PHP nests indexes, and each index is then swapped for its file.
        $pairs = [];
        $files = [];
        foreach ($multipart->files as $index => $file) {
            $pairs[] = \rawurlencode($file->name) . '=' . $index;
            $files[$index] = $this->createFile($file);
        }

        $tree = $this->parseQuery(\implode('&', $pairs));
        \array_walk_recursive($tree, static function (mixed &$value) use ($files): void {
            $value = $files[(int) $value];
        });

        return $tree;
    }

    /**
     * @return SymfonyUploadedFile|array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function createFile(UploadedFile $file): SymfonyUploadedFile|array
    {
        // An empty file input: Symfony's file bag turns this `$_FILES` shape into null, as it does
        // under any other SAPI.
        if ($file->clientFilename === '') {
            return ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => \UPLOAD_ERR_NO_FILE, 'size' => 0];
        }

        return new SymfonyUploadedFile(
            $file->tmpPath,
            $file->clientFilename,
            $file->clientMediaType,
            \UPLOAD_ERR_OK,
        );
    }

    /**
     * Parses a `Cookie` header the way PHP fills `$_COOKIE`: names and values URL-decoded, names
     * mangled and nested by bracket syntax, and the first of two equal names wins.
     *
     * @return array<array-key, mixed>
     */
    private function parseCookies(string $header): array
    {
        $pairs = [];
        foreach (\explode(';', $header) as $pair) {
            $parts = \explode('=', \trim($pair), 2);
            if (\count($parts) !== 2 || $parts[0] === '' || isset($pairs[$parts[0]])) {
                continue;
            }
            // `&` is a legal cookie octet but a separator for `parse_str()`.
            $pairs[$parts[0]] = \str_replace('&', '%26', $parts[0]) . '=' . \str_replace('&', '%26', $parts[1]);
        }

        return $this->parseQuery(\implode('&', $pairs));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        \parse_str($query, $result);

        return $result;
    }

    /**
     * Case-insensitive header lookup: on HTTP/1.1 names arrive as the client spelled them.
     *
     * @param array<non-empty-string, list<string>> $headers
     */
    private function headerLine(array $headers, string $name, string $separator): string
    {
        $values = [];
        foreach ($headers as $key => $list) {
            if (\strtolower($key) === $name) {
                foreach ($list as $value) {
                    $values[] = $value;
                }
            }
        }

        return \implode($separator, $values);
    }
}
