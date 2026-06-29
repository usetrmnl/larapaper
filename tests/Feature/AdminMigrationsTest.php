<?php
// tests/Feature/AdminMigrationsTest.php
declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('users table has is_admin and confirmed_at columns', function (): void {
    expect(Schema::hasColumn('users', 'is_admin'))->toBeTrue();
    expect(Schema::hasColumn('users', 'confirmed_at'))->toBeTrue();
});

it('plugins table has is_shared column', function (): void {
    expect(Schema::hasColumn('plugins', 'is_shared'))->toBeTrue();
});
