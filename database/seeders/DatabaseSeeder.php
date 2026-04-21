<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\Playlist;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Database\Seeder;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isLocal()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'admin@example.com',
                'password' => bcrypt('admin@example.com'),
            ]);

            $device = Device::factory()->create([
                'mac_address' => '00:00:00:00:00:00',
                'api_key' => 'test-api-key',
                'proxy_cloud' => false,
            ]);

            Playlist::factory()->create([
                'device_id' => $device->id,
                'name' => 'Default',
                'is_active' => true,
                'active_from' => null,
                'active_until' => null,
                'weekdays' => null,
            ]);

            // Device::factory(5)->create();

            // Plugin::factory(3)->create();

            $this->call([
                ExampleRecipesSeeder::class,
                // MashupPocSeeder::class,
            ]);
        }
    }
}
