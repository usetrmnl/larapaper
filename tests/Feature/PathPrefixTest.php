<?php

use Laravel\Fortify\Features;

it('puts the prefix from a trusted proxy in generated URLs', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);

    $response = $this->get('/login', ['X-Forwarded-Prefix' => '/larapaper']);

    $response->assertOk();
    $response->assertSee('action="http://localhost/larapaper/login"', false);
});

it('ignores the prefix from an untrusted client', function (): void {
    config(['trustedproxy.proxies' => []]);

    $response = $this->get('/login', ['X-Forwarded-Prefix' => '/larapaper']);

    $response->assertOk();
    $response->assertDontSee('/larapaper/', false);
});

it('sends passkey sign-ins to the dashboard under the prefix', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);
    $this->bootWithPasskeysEnabled();

    $this->skipUnlessFortifyHas(Features::passkeys());

    $response = $this->get('/login', ['X-Forwarded-Prefix' => '/larapaper']);

    $response->assertOk();
    $response->assertSee("response.redirect || 'http://localhost/larapaper/dashboard'", false);
});
