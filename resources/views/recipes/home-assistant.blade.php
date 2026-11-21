@php
    $weatherEntity = collect(Arr::get($data, 'data'))->first(function ($entity) {
        return $entity['entity_id'] === 'weather.forecast_home';
    });
@endphp

<x-trmnl::layout class="layout--col gap--space-between">
    @if ($weatherEntity)
        <div class="grid" style="gap: 9px">
            <div class="row row--center col--span-3 col--end">
                <img
                    class="weather-image"
                    style="max-height: 150px; margin: auto"
                    src="{{ config('services.trmnl.base_url') }}/images/plugins/weather/wi-thermometer.svg"
                />
            </div>
            <div class="col col--span-3 col--end">
                <div class="item h--full">
                    <div class="meta"></div>
                    <div class="justify-center">
                        <span
                            class="value @if($size === 'full' || $size === 'half_horizontal') value--xxlarge @else value--medium @endif"
                            data-fit-value="true"
                        >{{ $weatherEntity['attributes']['temperature'] }}</span>
                        <span class="label">Temperature {{ $weatherEntity['attributes']['temperature_unit'] }}</span>
                    </div>
                </div>
            </div>
            <div class="col col--span-3 col--end gap--medium">
                <div class="item">
                    <div class="meta"></div>
                    <div class="icon">
                        {{-- <img class="weather-icon" src="{{ config('services.trmnl.base_url') }}/images/plugins/weather/wi-thermometer.svg"> --}}
                    </div>
                    <div class="content">
                        <span class="value value--small">{{ $weatherEntity['attributes']['wind_speed'] }} {{ $weatherEntity['attributes']['wind_speed_unit'] }}</span>
                        <span class="label">Wind Speed</span>
                    </div>
                </div>

                <div class="item">
                    <div class="meta"></div>
                    <div class="icon">
                        {{-- <img class="weather-icon" src="{{ config('services.trmnl.base_url') }}/images/weather/wi-raindrops.svg"> --}}
                    </div>
                    <div class="content">
                        <span class="value value--small">{{ $weatherEntity['attributes']['humidity'] }}%</span>
                        <span class="label">Humidity</span>
                    </div>
                </div>

                <div class="item">
                    <div class="meta"></div>
                    <div class="icon">
                        {{-- <img class="weather-icon" src="{{ config('services.trmnl.base_url') }}/images/weather/wi-day-sunny.svg"> --}}
                    </div>
                    <div class="content">
                        <span class="value value--xsmall">{{ Str::title($weatherEntity['state']) }}</span>
                        <span class="label">Right Now</span>
                    </div>
                </div>
            </div>
        </div>

    @else
        <p>Weather forecast data not found.</p>
    @endif
</x-trmnl::layout>

<x-trmnl::title-bar title="Home Assistant" />
