@props([
    'mashupLayout' => '1Tx1B',
    'noBleed' => false,
    'darkMode' => false,
    'deviceVariant' => 'og',
    'deviceOrientation' => null,
    'colorDepth' => '1bit',
    'scaleLevel' => null,
    'cssVariables' => null,
    'frameworkVersion' => null,
])

@if(config('app.puppeteer_window_size_strategy') === 'v2')
    <x-trmnl::screen colorDepth="{{$colorDepth}}" no-bleed="{{$noBleed}}" dark-mode="{{$darkMode}}"
                     device-variant="{{$deviceVariant}}" device-orientation="{{$deviceOrientation}}"
                     scale-level="{{$scaleLevel}}"
                     :css-variables="$cssVariables"
                     :framework-version="$frameworkVersion">
        <x-trmnl::mashup mashup-layout="{{ $mashupLayout }}">
            {!! $slot !!}
        </x-trmnl::mashup>
    </x-trmnl::screen>
@else
    <x-trmnl::screen colorDepth="{{$colorDepth}}" device-variant="{{$deviceVariant}}" device-orientation="{{$deviceOrientation}}"
                     scale-level="{{$scaleLevel}}"
                     :css-variables="$cssVariables"
                     :framework-version="$frameworkVersion">
        <x-trmnl::mashup mashup-layout="{{ $mashupLayout }}">
            {!! $slot !!}
        </x-trmnl::mashup>
    </x-trmnl::screen>
@endif
