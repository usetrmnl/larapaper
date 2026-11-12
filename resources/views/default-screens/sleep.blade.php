@props([
    'noBleed' => false,
    'darkMode' => true,
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
                <div class="image image-dither" alt="sleep">
                    <svg
                        class="h-64 w-64"
                        fill="#000"
                        xmlns="http://www.w3.org/2000/svg"
                        id="mdi-sleep"
                        viewBox="0 0 24 24"
                    >
                        <path d="M23,12H17V10L20.39,6H17V4H23V6L19.62,10H23V12M15,16H9V14L12.39,10H9V8H15V10L11.62,14H15V16M7,20H1V18L4.39,14H1V12H7V14L3.62,18H7V20Z"></path>
                    </svg>
                </div>
                <x-trmnl::title>Sleep Mode</x-trmnl::title>
            </x-trmnl::richtext>
        </x-trmnl::layout>
        <x-trmnl::title-bar title="LaraPaper" />
    </x-trmnl::view>
</x-trmnl::screen>
