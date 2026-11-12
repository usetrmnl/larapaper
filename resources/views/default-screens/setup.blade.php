@props([
    'noBleed' => false,
    'darkMode' => false,
    'deviceVariant' => 'og',
    'deviceOrientation' => null,
    'colorDepth' => '1bit',
    'scaleLevel' => null,
    'paletteClass' => null,
    'cssVariables' => null,
])

<x-trmnl::screen
    colorDepth="{{ $colorDepth }}"
    no-bleed="{{ $noBleed }}"
    dark-mode="{{ $darkMode }}"
    device-variant="{{ $deviceVariant }}"
    device-orientation="{{ $deviceOrientation }}"
    scale-level="{{ $scaleLevel }}"
    :palette-class="$paletteClass"
    :css-variables="$cssVariables"
>
    <x-trmnl::view>
        <x-trmnl::layout>
            <x-trmnl::richtext gapSize="large" align="center">
                <x-trmnl::title>Welcome to LaraPaper!</x-trmnl::title>
                <x-trmnl::content>Your device is connected.</x-trmnl::content>
            </x-trmnl::richtext>
        </x-trmnl::layout>
        <x-trmnl::title-bar title="LaraPaper" />
    </x-trmnl::view>
</x-trmnl::screen>
