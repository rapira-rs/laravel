<?php

declare(strict_types=1);

namespace Rapira\Laravel\Tests\Unit;

use Illuminate\Http\Request;
use Rapira\Http\FormField;
use Rapira\Http\Multipart;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Http\UploadedFile;
use Rapira\InetAddress;
use Rapira\UnixAddress;
use Rapira\Laravel\Http\ExchangeRequestFactory;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Testo\Assert;
use Testo\Test;

/**
 * The request the application sees in dispatcher mode must match what PHP builds from the superglobals
 * in classic and worker modes.
 */
#[Test]
final class ExchangeRequestFactoryTest
{
    public function routingFactsComeFromTheTarget(): void
    {
        $request = $this->create(FakeExchange::for('/users/42?page=2'));

        Assert::same($request->method(), 'GET');
        Assert::same($request->path(), 'users/42');
        Assert::same($request->getRequestUri(), '/users/42?page=2');
        Assert::same($request->url(), 'http://localhost:8080/users/42');
        Assert::same($request->getBaseUrl(), '');
        Assert::same($request->server('QUERY_STRING'), 'page=2');
        Assert::same($request->server('SCRIPT_NAME'), '/index.php');
    }

    public function frontControllerInThePathBecomesTheBaseUrl(): void
    {
        $request = $this->create(FakeExchange::for('/index.php/users'));

        Assert::same($request->getBaseUrl(), '/index.php');
        Assert::same($request->path(), 'users');
    }

    public function queryIsNestedByPhpRules(): void
    {
        $request = $this->create(FakeExchange::for('/?a[b][]=1&a[b][]=2&c.d=x+y&e=%26'));

        Assert::same($request->query(), ['a' => ['b' => ['1', '2']], 'c_d' => 'x y', 'e' => '&']);
    }

    public function connectionFactsBecomeServerParams(): void
    {
        $request = $this->create(FakeExchange::for('/', headers: ['X-Custom' => ['one', 'two']]));

        Assert::same($request->ip(), '10.0.0.7');
        Assert::same($request->server('REMOTE_PORT'), 40000);
        Assert::same($request->server('SERVER_PORT'), 8080);
        Assert::null($request->server('SERVER_NAME'));
        Assert::same($request->server('REQUEST_TIME'), 1_700_000_000);
        Assert::same($request->server('REQUEST_TIME_FLOAT'), 1_700_000_000.25);
        Assert::same($request->server('SERVER_SOFTWARE'), 'Rapira');
        Assert::same($request->header('x-custom'), 'one, two');
        Assert::same($request->getHost(), 'localhost');
        Assert::same($request->getPort(), 8080);
        Assert::false($request->secure());
    }

    public function unixPeerIsLoopback(): void
    {
        $request = $this->create(new FakeExchange(self::request(remote: new UnixAddress(null))));

        Assert::same($request->ip(), '127.0.0.1');
        Assert::same($request->server('REMOTE_PORT'), 0);
    }

    public function hostWithoutAuthorityComesFromTheSynthesizedUri(): void
    {
        $request = $this->create(new FakeExchange(self::request(
            uri: 'http://app.internal:80/',
            authority: null,
            server: new UnixAddress('/run/rapira.sock'),
        )));

        Assert::same($request->server('SERVER_NAME'), 'app.internal');
        Assert::same($request->getHost(), 'app.internal');
    }

    public function headersAreNamedTheWayTheSapiNamesThem(): void
    {
        $request = $this->create(FakeExchange::for('/', headers: [
            'content-type' => ['text/plain'],
            'content-length' => ['3'],
            'x.dotted' => ['yes'],
        ]));

        Assert::same($request->server('CONTENT_TYPE'), 'text/plain');
        Assert::same($request->server('CONTENT_LENGTH'), '3');
        Assert::same($request->server('HTTP_X_DOTTED'), 'yes');
    }

    public function httpsSchemeMarksTheRequestSecure(): void
    {
        $request = $this->create(FakeExchange::for('/', scheme: 'https'));

        Assert::true($request->secure());
        Assert::same($request->server('HTTPS'), 'on');
    }

    public function protocolIsKeptAsReceived(): void
    {
        $request = $this->create(FakeExchange::for('/'));

        Assert::same($request->getProtocolVersion(), 'HTTP/1.1');
    }

    public function cookiesAreDecodedLikeTheCookieSuperglobal(): void
    {
        $request = $this->create(FakeExchange::for('/', headers: [
            'Cookie' => ['plain=a%20b+c; dup=first; dup=second', 'amp=x&y; arr[k]=v; broken'],
        ]));

        Assert::same($request->cookies->all(), [
            'plain' => 'a b c',
            'dup' => 'first',
            'amp' => 'x&y',
            'arr' => ['k' => 'v'],
        ]);
    }

    public function formBodyIsParsedForPost(): void
    {
        $request = $this->create(FakeExchange::for(
            '/',
            method: 'POST',
            headers: ['Content-Type' => ['application/x-www-form-urlencoded; charset=UTF-8']],
            body: 'name=Rapira&tags[]=a&tags[]=b',
        ));

        Assert::same($request->request->all(), ['name' => 'Rapira', 'tags' => ['a', 'b']]);
        Assert::same($request->getContent(), 'name=Rapira&tags[]=a&tags[]=b');
    }

    public function formBodyIsParsedForPutAsSymfonyDoes(): void
    {
        $request = $this->create(FakeExchange::for(
            '/',
            method: 'PUT',
            headers: ['content-type' => ['application/x-www-form-urlencoded']],
            body: 'a=1',
        ));

        Assert::same($request->request->all(), ['a' => '1']);
    }

    public function formBodyIsIgnoredForGet(): void
    {
        $request = $this->create(FakeExchange::for(
            '/',
            headers: ['content-type' => ['application/x-www-form-urlencoded']],
            body: 'a=1',
        ));

        Assert::same($request->request->all(), []);
    }

    public function jsonBodyIsReadFromTheContent(): void
    {
        $request = $this->create(FakeExchange::for(
            '/',
            method: 'POST',
            headers: ['content-type' => ['application/json']],
            body: '{"a":{"b":1}}',
        ));

        Assert::same($request->getContent(), '{"a":{"b":1}}');
        Assert::same($request->input('a.b'), 1);
    }

    public function multipartFieldsAreNestedByPhpRules(): void
    {
        $request = $this->create(FakeExchange::for('/', method: 'POST', body: new Multipart(
            fields: [
                new FormField('user[name]', 'Ann', []),
                new FormField('user[roles][]', 'admin', []),
                new FormField('user[roles][]', 'dev', []),
                new FormField('a b', '1', []),
            ],
            files: [],
        )));

        Assert::same($request->request->all(), [
            'user' => ['name' => 'Ann', 'roles' => ['admin', 'dev']],
            'a_b' => '1',
        ]);
    }

    public function multipartFilesBecomeUploadedFiles(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'rapira');
        \file_put_contents($tmp, 'hello');

        try {
            $request = $this->create(FakeExchange::for('/', method: 'POST', body: new Multipart(
                fields: [],
                files: [
                    new UploadedFile('avatar', 'me.png', 'image/png', [], $tmp, 5),
                    new UploadedFile('docs[]', 'a.txt', null, [], $tmp, 5),
                    new UploadedFile('docs[]', 'b.txt', 'text/plain', [], $tmp, 5),
                    new UploadedFile('empty', '', null, [], $tmp, 0),
                ],
            )));

            $avatar = $request->file('avatar');
            Assert::instanceOf($avatar, SymfonyUploadedFile::class);
            Assert::same($avatar->getClientOriginalName(), 'me.png');
            Assert::same($avatar->getClientMimeType(), 'image/png');
            Assert::same($avatar->getContent(), 'hello');

            $docs = $request->file('docs');
            Assert::count($docs, 2);
            Assert::same($docs[1]->getClientOriginalName(), 'b.txt');
            Assert::null($request->file('empty'));
        } finally {
            @\unlink($tmp);
        }
    }

    private static function request(
        string $uri = 'http://localhost:8080/',
        ?string $authority = 'localhost:8080',
        InetAddress|UnixAddress $remote = new InetAddress('10.0.0.7', 40000),
        InetAddress|UnixAddress $server = new InetAddress('127.0.0.1', 8080),
    ): RapiraRequest {
        return new RapiraRequest(
            method: 'GET',
            uri: $uri,
            target: '/',
            authority: $authority,
            protocol: 'HTTP/1.1',
            headers: [],
            body: '',
            remote: $remote,
            server: $server,
            tls: null,
            receivedAt: 1_700_000_000.25,
        );
    }

    private function create(FakeExchange $exchange): Request
    {
        return (new ExchangeRequestFactory('/app/public'))->create($exchange);
    }
}
