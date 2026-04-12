<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

test('local public disk matches asset helper when APP_URL is not set (localhost)', function (): void {
    Config::set('app.url', 'http://localhost');
    Config::set('filesystems.disks.public.url', mb_rtrim(Config::string('app.url'), '/').'/storage');
    Storage::purge('public');

    expect(Storage::disk('public')->url('images/image.png'))
        ->toBe('http://localhost/storage/images/image.png');
});

test('local public disk matches asset helper when APP_URL is set to custom value', function (): void {
    Config::set('app.url', 'https://larapaper.test');
    Config::set('filesystems.disks.public.driver', 'local');
    Config::set('filesystems.disks.public.url', mb_rtrim(Config::string('app.url'), '/').'/storage');
    Storage::purge('public');

    expect(Storage::disk('public')->url('images/image.png'))
        ->toBe('https://larapaper.test/storage/images/image.png');
});

test('public disk does not use asset helper when s3 is set', function (): void {
    Config::set('app.url', null);
    Config::set('filesystems.disks.public.driver', 's3');
    Config::set('filesystems.disks.public.key', 'key');
    Config::set('filesystems.disks.public.secret', 'secret');
    Config::set('filesystems.disks.public.region', 'us-east-1');
    Config::set('filesystems.disks.public.bucket', 'my-bucket');
    Config::set('filesystems.disks.public.url', 'https://s3.amazonaws.com/my-bucket');
    Config::set('filesystems.disks.public.root', '');

    Storage::purge('public');

    $url = Storage::disk('public')->url('images/image.png');

    expect($url)->toBe('https://s3.amazonaws.com/my-bucket/images/image.png');
});
