<?php

namespace Database\Seeders;

use App\Models\Plugin;
use Illuminate\Database\Seeder;

class ExampleRecipesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run($user_id = 1): void
    {
        Plugin::updateOrCreate(
            ['uuid' => '9e46c6cf-358c-4bfe-8998-436b3a207fec'],
            [
                'name' => 'ÖBB Departures',
                'user_id' => $user_id,
                'data_payload' => null,
                'data_stale_minutes' => 15,
                'data_strategy' => 'polling',
                'polling_url' => 'https://dbf.finalrewind.org/Wien%20Hbf%20(Bahnsteige%201-2).json?detailed=1&version=3&limit=8&admode=dep&hafas=%C3%96BB&platforms=1%2C2',
                'polling_verb' => 'get',
                'polling_header' => null,
                'render_markup' => null,
                'render_markup_view' => 'recipes.train',
                'detail_view_route' => null,
                'icon_url' => null,
                'flux_icon_name' => 'train-front',
            ]
        );

        Plugin::updateOrCreate(
            ['uuid' => '3b046eda-34e9-4232-b935-c33b989a284b'],
            [
                'name' => 'Weather',
                'user_id' => $user_id,
                'data_payload' => null,
                'data_stale_minutes' => 60,
                'data_strategy' => 'polling',
                'polling_url' => 'https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=48.2083&lon=16.3731',
                'polling_verb' => 'get',
                'polling_header' => null,
                'render_markup' => null,
                'render_markup_view' => 'recipes.weather',
                'detail_view_route' => null,
                'icon_url' => null,
                'flux_icon_name' => 'sun',
            ]
        );

        Plugin::updateOrCreate(
            ['uuid' => '21464b16-5f5a-4099-a967-f5c915e3da54'],
            [
                'name' => 'Zen Quotes',
                'user_id' => $user_id,
                'data_payload' => null,
                'data_stale_minutes' => 720,
                'data_strategy' => 'polling',
                'polling_url' => 'https://zenquotes.io/api/today',
                'polling_verb' => 'get',
                'polling_header' => null,
                'render_markup' => null,
                'render_markup_view' => 'recipes.zen',
                'detail_view_route' => null,
                'icon_url' => null,
                'flux_icon_name' => 'chat-bubble-bottom-center',
            ]
        );

        Plugin::updateOrCreate(
            ['uuid' => '8d472959-400f-46ee-afb2-4a9f1cfd521f'],
            [
                'name' => 'This Day in History',
                'user_id' => $user_id,
                'data_payload' => null,
                'data_stale_minutes' => 720,
                'data_strategy' => 'polling',
                'polling_url' => 'https://raw.githubusercontent.com/jvivona/tidbyt-data/refs/heads/main/thisdayinhistwikipedia/thisdayinhist.json',
                'polling_verb' => 'get',
                'polling_header' => null,
                'render_markup' => null,
                'render_markup_view' => 'recipes.day-in-history',
                'detail_view_route' => null,
                'icon_url' => null,
                'flux_icon_name' => 'calendar',
            ]
        );

        Plugin::updateOrCreate(
            ['uuid' => '4349fdad-a273-450b-aa00-3d32f2de788d'],
            [
                'name' => 'Home Assistant',
                'user_id' => $user_id,
                'data_payload' => null,
                'data_stale_minutes' => 30,
                'data_strategy' => 'polling',
                'polling_url' => 'http://raspberrypi.local:8123/api/states',
                'polling_verb' => 'get',
                'polling_header' => 'Authorization: Bearer YOUR_API_KEY',
                'render_markup' => null,
                'render_markup_view' => 'recipes.home-assistant',
                'detail_view_route' => null,
                'icon_url' => null,
                'flux_icon_name' => 'thermometer',
            ]
        );

        Plugin::updateOrCreate(
            ['uuid' => 'be5f7e1f-3ad8-4d66-93b2-36f7d6dcbd80'],
            [
                'name' => 'Sunrise/Sunset',
                'user_id' => $user_id,
                'data_payload' => null,
                'data_stale_minutes' => 720,
                'data_strategy' => 'polling',
                'polling_url' => 'https://suntracker.me/?lat=48.2083&lon=16.3731',
                'polling_verb' => 'get',
                'polling_header' => null,
                'render_markup' => null,
                'render_markup_view' => 'recipes.sunrise-sunset',
                'detail_view_route' => null,
                'icon_url' => null,
                'flux_icon_name' => 'sunrise',
            ]
        );

        Plugin::updateOrCreate(
            ['uuid' => '82d3ee14-d578-4969-bda5-2bbf825435fe'],
            [
                'name' => 'Pollen Forecast',
                'user_id' => $user_id,
                'data_payload' => null,
                'data_stale_minutes' => 720,
                'data_strategy' => 'polling',
                'polling_url' => 'https://air-quality-api.open-meteo.com/v1/air-quality?latitude=48.2083&longitude=16.3731&hourly=alder_pollen,birch_pollen,grass_pollen,mugwort_pollen,ragweed_pollen&current=alder_pollen,birch_pollen,grass_pollen,mugwort_pollen,ragweed_pollen&timezone=Europe%2FVienna&forecast_days=2',
                'polling_verb' => 'get',
                'polling_header' => null,
                'render_markup' => null,
                'render_markup_view' => 'recipes.pollen-forecast-eu',
                'detail_view_route' => null,
                'icon_url' => null,
                'flux_icon_name' => 'flower',
            ]
        );

        Plugin::updateOrCreate(
            ['uuid' => '1d98bca4-837d-4b01-b1a1-e3b6e56eca90'],
            [
                'name' => 'Holidays (iCal)',
                'user_id' => $user_id,
                'data_payload' => null,
                'data_stale_minutes' => 720,
                'data_strategy' => 'polling',
                'configuration_template' => [
                    'custom_fields' => [
                        [
                            'keyname' => 'calendar',
                            'field_type' => 'select',
                            'name' => 'Public Holidays Calendar',
                            'options' => [
                                ['USA' => 'usa'],
                                ['Austria' => 'austria'],
                                ['Australia' => 'australia'],
                                ['Canada' => 'canada'],
                                ['Germany' => 'germany'],
                                ['UK' => 'united-kingdom'],
                            ],
                        ],
                    ],
                ],
                'configuration' => ['calendar' => 'usa'],
                'polling_url' => 'https://www.officeholidays.com/ics-clean/{{calendar}}',
                'polling_verb' => 'get',
                'polling_header' => null,
                'render_markup' => null,
                'render_markup_view' => 'recipes.holidays-ical',
                'detail_view_route' => null,
                'icon_url' => null,
                'flux_icon_name' => 'calendar',
            ]
        );
    }
}
