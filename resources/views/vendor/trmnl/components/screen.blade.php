@props([
    'noBleed' => false,
    'darkMode' => false,
    'deviceVariant' => 'ogv2',
    'deviceOrientation' => null,
    'colorDepth' => '1bit',
    'paletteClass' => null,
    'scaleLevel' => null,
    'cssVariables' => null,
    'frameworkVersion' => null,
])

@php
    $resolvedFrameworkVersion = $frameworkVersion
        ?? config('trmnl-blade.framework_css_version')
        ?? config('trmnl-blade.framework_version', '3.4.6');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <link rel="stylesheet"
          href="{{ \Bnussbau\TrmnlBlade\Support\FrameworkAssets::cssUrl($resolvedFrameworkVersion) }}">
    <script src="{{ \Bnussbau\TrmnlBlade\Support\FrameworkAssets::jsUrl($resolvedFrameworkVersion) }}"></script>
    <title>{{ $title ?? config('app.name') }}</title>
    @if(config('app.puppeteer_window_size_strategy') === 'v2' && !empty($cssVariables) && is_array($cssVariables))
        <style>
            :root {
                @foreach($cssVariables as $name => $value)
                {{ $name }}: {{ $value }};
                @endforeach
            }
        </style>
    @endif
</head>
<body class="environment trmnl">
<div class="screen {{ $noBleed ? 'screen--no-bleed' : '' }} {{ $darkMode ? 'screen--dark-mode' : '' }} {{ $deviceVariant ? 'screen--' . $deviceVariant : '' }} {{ $deviceOrientation ? 'screen--' . $deviceOrientation : '' }} {{ $paletteClass ? $paletteClass : '' }} {{ $scaleLevel ? 'screen--scale-' . $scaleLevel : '' }}">
    {{ $slot }}
</div>
</body>
</html>
