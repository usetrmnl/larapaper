<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\VerifyEmailController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Event;

test('verify email controller marks user verified and dispatches event', function (): void {
    $user = User::factory()->unverified()->create();
    Event::fake();

    $request = Mockery::mock(EmailVerificationRequest::class);
    $request->allows('user')->andReturn($user);

    $response = (new VerifyEmailController)($request);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expect($response->isRedirect())->toBeTrue();
});

test('verify email controller redirects without event when already verified', function (): void {
    $user = User::factory()->create();
    Event::fake();

    $request = Mockery::mock(EmailVerificationRequest::class);
    $request->allows('user')->andReturn($user);

    $response = (new VerifyEmailController)($request);

    Event::assertNotDispatched(Verified::class);
    expect($response->isRedirect())->toBeTrue();
});
