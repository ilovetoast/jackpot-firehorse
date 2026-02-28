<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#ae2cf1">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Jackpot') }}">
        @if(app()->environment('staging'))
        <meta name="robots" content="noindex, nofollow">
        @endif
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" type="image/x-icon" href="/favicon.ico">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="icon" type="image/png" sizes="48x48" href="/favicon-48x48.png">
        <link rel="icon" type="image/png" sizes="64x64" href="/favicon-64x64.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <meta property="og:image" content="{{ url('/og-image-1200x630.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">

        <title>{{ config('app.name', 'Jackpot') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @viteReactRefresh
        <script>window.__performanceMetricsEnabled = @json(config('performance.client_metrics_enabled', false));</script>
    @vite('resources/js/app.jsx')
    @inertiaHead
    @routes
  </head>
  <body class="font-sans antialiased">
    @inertia
    
  </body>
</html>
