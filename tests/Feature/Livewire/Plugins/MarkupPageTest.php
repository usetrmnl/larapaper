<?php

declare(strict_types=1);

use App\Jobs\GenerateScreenJob;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use ReflectionProperty;

test('hello world example fills blade markup when blade is selected', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('plugins.markup')
        ->assertSet('markup_language', 'blade')
        ->call('renderExample', 'helloWorld')
        ->assertSet('markup_code', fn (string $code): bool => str_contains($code, '<x-trmnl::screen>'));
});

test('hello world example fills liquid markup when liquid is selected', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('plugins.markup')
        ->set('markup_language', 'liquid')
        ->call('renderExample', 'helloWorld')
        ->assertSet('markup_code', fn (string $code): bool => str_contains($code, 'class="layout"')
            && ! str_contains($code, 'view view--{{ size }}')
            && ! str_contains($code, '<x-trmnl::'));
});

test('submit dispatches generate screen job for blade markup', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('plugins.markup')
        ->set('markup_code', '<div>Blade markup test</div>')
        ->set('checked_devices', [$device->id])
        ->call('submit')
        ->assertHasNoErrors();

    Bus::assertDispatched(GenerateScreenJob::class);
});

test('submit dispatches generate screen job with rendered liquid markup', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('plugins.markup')
        ->set('markup_language', 'liquid')
        ->set('markup_code', '<div class="view view--{{ size }}"><span class="title">Liquid test</span></div>')
        ->set('checked_devices', [$device->id])
        ->call('submit')
        ->assertHasNoErrors();

    Bus::assertDispatched(GenerateScreenJob::class, function (GenerateScreenJob $job): bool {
        $markupProperty = new ReflectionProperty(GenerateScreenJob::class, 'markup');
        $markup = $markupProperty->getValue($job);

        return str_contains($markup, 'view--full')
            && str_contains($markup, 'Liquid test')
            && ! str_contains($markup, '{{ size }}');
    });
});

test('submit adds generate screen error for invalid liquid markup', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('plugins.markup')
        ->set('markup_language', 'liquid')
        ->set('markup_code', '{% if %}')
        ->set('checked_devices', [$device->id])
        ->call('submit')
        ->assertHasErrors(['generate_screen']);

    Bus::assertNothingDispatched();
});

test('submit rejects invalid markup language', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('plugins.markup')
        ->set('markup_language', 'html')
        ->set('markup_code', '<div>Test</div>')
        ->set('checked_devices', [$device->id])
        ->call('submit')
        ->assertHasErrors(['markup_language']);

    Bus::assertNothingDispatched();
});
