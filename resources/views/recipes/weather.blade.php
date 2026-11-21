{{--@dump($data)--}}
<x-trmnl::layout class="layout--col gap--space-between">
    <div class="grid" style="gap: 9px">
        <div class="row row--center col--span-3 col--end">
            <img
                class="weather-image"
                style="max-height: 150px; margin: auto"
                src="{{ config('services.trmnl.base_url') }}/images/plugins/weather/wi-thermometer.svg"
            />
        </div>
        <div class="col col--span-3 col--center">
            <div class="item">
                <div class="meta"></div>
                <div class="justify-center">
                    <span
                        class="value @if($size === 'full' || $size === 'half_horizontal') value--xxlarge @else value--medium @endif"
                        data-fit-value="true"
                    >{{ Arr::get($data, 'properties.timeseries.0.data.instant.details.air_temperature', 'N/A') }}</span>
                    <span class="label">Temperature</span>
                </div>
            </div>
        </div>
        <div class="col col--span-3 col--end gap--medium">
            <div class="item">
                <div class="meta"></div>
                <div class="icon">
                    {{--                        <img class="weather-icon" src="{{ config('services.trmnl.base_url') }}/images/weather/wi-thermometer.svg">--}}
                </div>
                <div class="content">
                    <span class="value value--small">{{ Arr::get($data, 'properties.timeseries.0.data.instant.details.wind_speed', 'N/A') }}</span>
                    <span class="label">Wind Speed (km/h)</span>
                </div>
            </div>

            <div class="item">
                <div class="meta"></div>
                <div class="icon">
                    {{--                        <img class="weather-icon" src="{{ config('services.trmnl.base_url') }}/images/weather/wi-raindrops.svg">--}}
                </div>
                <div class="content">
                    <span class="value value--small">{{ Arr::get($data, 'properties.timeseries.0.data.instant.details.relative_humidity', 'N/A') }}%</span>
                    <span class="label">Humidity</span>
                </div>
            </div>

            <div class="item">
                <div class="meta"></div>
                <div class="icon">
                    {{--                        <img class="weather-icon" src="{{ config('services.trmnl.base_url') }}/images/weather/wi-day-sunny.svg">--}}
                </div>
                <div class="content">
                    <span class="value value--xsmall">{{ Str::title(Arr::get($data, 'properties.timeseries.0.data.next_1_hours.summary.symbol_code', 'N/A')) }}</span>
                    <span class="label">Right Now</span>
                </div>
            </div>
        </div>
    </div>
</x-trmnl::layout>
<x-trmnl::title-bar title="Weather Vienna" instance="updated: {{ now() }}" />
