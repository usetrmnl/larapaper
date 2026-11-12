@props([
    'noBleed' => false,
    'darkMode' => false,
    'deviceVariant' => 'og',
    'deviceOrientation' => null,
    'colorDepth' => '1bit',
    'scaleLevel' => null,
    'paletteClass' => null,
    'cssVariables' => null,
    'frameworkVersion' => null,
])

@if (config('app.puppeteer_window_size_strategy') === 'v2')
    <x-trmnl::screen
        colorDepth="{{ $colorDepth }}"
        no-bleed="{{ $noBleed }}"
        dark-mode="{{ $darkMode }}"
        device-variant="{{ $deviceVariant }}"
        device-orientation="{{ $deviceOrientation }}"
        scale-level="{{ $scaleLevel }}"
        :palette-class="$paletteClass"
        :css-variables="$cssVariables"
        :framework-version="$frameworkVersion"
    >
        {!! $slot !!}
    </x-trmnl::screen>
@else
    <x-trmnl::screen
        colorDepth="{{ $colorDepth }}"
        no-bleed="{{ $noBleed }}"
        dark-mode="{{ $darkMode }}"
        device-variant="{{ $deviceVariant }}"
        device-orientation="{{ $deviceOrientation }}"
        scale-level="{{ $scaleLevel }}"
        :palette-class="$paletteClass"
        :css-variables="$cssVariables"
        :framework-version="$frameworkVersion"
    >
        {!! $slot !!}
    </x-trmnl::screen>
@endif
