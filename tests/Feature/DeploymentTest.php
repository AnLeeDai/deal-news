<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('the deployment health endpoint is publicly reachable', function () {
    $this->get('/up')->assertOk();
});

test('forwarded HTTPS and client IP are used only for configured proxies', function (string $proxies, bool $trusted) {
    config(['trustedproxy.proxies' => $proxies]);

    Route::get('/deployment-probe', fn (Request $request): array => [
        'secure' => $request->isSecure(),
        'ip' => $request->ip(),
        'url' => url('/account'),
    ]);

    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.2',
    ])->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '203.0.113.10',
        'X-Forwarded-Host' => 'attacker.example',
        'X-Forwarded-Port' => '4444',
    ])->get('http://news.example.com/deployment-probe');

    $response->assertExactJson([
        'secure' => $trusted,
        'ip' => $trusted ? '203.0.113.10' : '10.0.0.2',
        'url' => $trusted ? 'https://news.example.com/account' : 'http://news.example.com/account',
    ]);
})->with([
    'local default' => ['', false],
    'Render edge' => ['*', true],
    'known proxy subnet' => ['10.0.0.0/8', true],
    'untrusted source' => ['192.168.0.0/16', false],
]);
