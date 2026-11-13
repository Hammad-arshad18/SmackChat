<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d1117">
    <meta name="color-scheme" content="dark light">
    <meta name="application-name" content="SmackChat">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SmackChat">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">

    @php
        $appName = config('app.name', 'SmackChat');
        $appUrl = rtrim(config('app.url'), '/');
        $title = $appName.' — your team\'s day, in one place';
        $description = 'SmackChat brings team chat, attendance, and office schedules together so your crew stays aligned through the day.';
        $ogImage = $appUrl.'/icons/smack-512.png';
    @endphp

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:site_name" content="{{ $appName }}">
    <meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:image:alt" content="{{ $appName }}">

    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.png" type="image/png" sizes="32x32">
    <link rel="icon" href="/brand/logo-mark-64.png" type="image/png" sizes="64x64">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/smack-apple-touch.png">
    <link rel="manifest" href="/manifest.webmanifest?v=9">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=bricolage-grotesque:400,500,600,700,800|inter:400,500,600,700" rel="stylesheet">

    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => $appName,
            'url' => $appUrl,
            'description' => $description,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'USD',
            ],
            'image' => $ogImage,
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <script>
        (function () {
            var standalone = window.matchMedia('(display-mode: standalone)').matches
                || window.matchMedia('(display-mode: window-controls-overlay)').matches
                || window.matchMedia('(display-mode: minimal-ui)').matches
                || window.navigator.standalone === true;
            if (standalone && window.location.pathname === '/') {
                window.location.replace('/app');
            }
        })();
    </script>
    <div id="app"></div>
</body>
</html>

