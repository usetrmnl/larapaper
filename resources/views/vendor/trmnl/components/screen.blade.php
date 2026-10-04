@props([
    'noBleed' => false,
    'darkMode' => false,
    'deviceVariant' => 'ogv2',
    'deviceOrientation' => null,
    'colorDepth' => '1bit',
    'scaleLevel' => null,
    'cssVariables' => null,
    'frameworkVersion' => null,
])

@php
    $resolvedFrameworkVersion = $frameworkVersion
        ?? config('trmnl-blade.framework_css_version')
        ?? config('trmnl-blade.framework_version', '2.3.7');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=Inter:300,400,500" rel="stylesheet"/>
    @if (config('trmnl-blade.framework_css_url'))
        <link rel="stylesheet"
              href="{{ config('trmnl-blade.framework_css_url') }}">
    @else
        <link
            rel="stylesheet"
            href="{{ \Bnussbau\TrmnlBlade\Support\FrameworkAssets::cssUrl($resolvedFrameworkVersion) }}"
        />
    @endif
    @if (config('trmnl-blade.framework_js_url'))
        <script src="{{ config('trmnl-blade.framework_js_url') }}"></script>
    @else
        <script src="{{ \Bnussbau\TrmnlBlade\Support\FrameworkAssets::jsUrl($resolvedFrameworkVersion) }}"></script>
    @endif
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
<div class="screen {{ $noBleed ? 'screen--no-bleed' : '' }} {{ $darkMode ? 'screen--dark-mode' : '' }} {{ $deviceVariant ? 'screen--' . $deviceVariant : '' }} {{ $deviceOrientation ? 'screen--' . $deviceOrientation : '' }} {{ $colorDepth ? 'screen--' . $colorDepth : '' }} {{ $scaleLevel ? 'screen--scale-' . $scaleLevel : '' }}">
    {{ $slot }}
</div>
</body>
</html>
