<?php

use Illuminate\Support\Facades\Storage;

test('local public disk generates root relative urls', function (): void {
    expect(Storage::disk('public')->url('images/generated/abc.png'))
        ->toBe('/storage/images/generated/abc.png');
});
