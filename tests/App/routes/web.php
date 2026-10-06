<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn() => 'OK');

Route::get('/hello/{name}', static fn(string $name) => "Hello, {$name}!");

Route::get('/status', static fn() => [
    'status' => 'ok',
    'mode' => \strtolower(\Rapira\get_mode()->name),
]);

Route::get('/config/leak', static function () {
    config(['app.leaked' => true]);

    return 'set';
});

Route::get('/config/check', static fn() => ['leaked' => config('app.leaked', false)]);

Route::get('/session/put/{value}', static function (Request $request, string $value) {
    $request->session()->put('value', $value);

    return 'stored';
});

Route::get('/session/get', static fn(Request $request) => ['value' => $request->session()->get('value')]);

Route::get('/cookie', static fn() => response('cookie')->cookie('flavor', 'chocolate chip'));

Route::get('/cookie/read', static fn(Request $request) => ['flavor' => $request->cookie('flavor')]);

Route::get('/fail', static function (): never {
    throw new RuntimeException('Route failure');
});

Route::get('/echo-output', static function () {
    echo 'printed;';

    return 'returned';
});

Route::get('/stream', static fn() => response()->stream(static function (): void {
    echo 'chunk-1;';
    \ob_flush();
    echo 'chunk-2';
}, 200, ['X-Stream' => 'yes']));

Route::get('/stream/fail', static fn() => response()->stream(static function (): never {
    throw new RuntimeException('Stream failure');
}));

Route::get('/download', static fn() => response()->download(storage_path('app/download.txt'), 'file.txt'));

Route::any('/inspect', static fn(Request $request) => [
    'method' => $request->method(),
    'path' => $request->path(),
    'url' => $request->url(),
    'fullUrl' => $request->fullUrl(),
    'secure' => $request->secure(),
    'ip' => $request->ip(),
    'query' => $request->query(),
    'input' => $request->post(),
    'json' => $request->isJson() ? $request->json()->all() : null,
    'cookies' => $request->cookies->all(),
    'headers' => [
        'x-custom' => $request->header('x-custom'),
        'content-type' => $request->header('content-type'),
    ],
    'files' => \array_map(
        static fn(?UploadedFile $file) => $file === null ? null : [
            'name' => $file->getClientOriginalName(),
            'valid' => $file->isValid(),
            'content' => $file->getContent(),
        ],
        \Illuminate\Support\Arr::dot($request->allFiles()),
    ),
]);
