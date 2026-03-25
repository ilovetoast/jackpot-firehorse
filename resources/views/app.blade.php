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
        @if(filter_var(env('PUSH_NOTIFICATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN) && config('services.onesignal.app_id'))
            {{-- OneSignal Web SDK v16: HTTP only with allowLocalhostAsSecureOrigin; v16 blocks plain HTTP otherwise. See pushService.js + ONESIGNAL_ALLOW_HTTP_LOCAL --}}
            <meta name="onesignal-app-id" content="{{ config('services.onesignal.app_id') }}">
            <meta name="onesignal-allow-local-http" content="@json(app()->environment(['local', 'development']) || config('services.onesignal.allow_http_local'))">
            <script>window.OneSignalDeferred = window.OneSignalDeferred || [];</script>
            <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
        @endif
    @vite('resources/js/app.jsx')
    @inertiaHead
    @routes
  </head>
  <body class="font-sans antialiased">
    {{-- Immediate overlay after full-page workspace/brand switch (sessionStorage set before navigation) --}}
    <script>
        (function () {
            try {
                var kind = sessionStorage.getItem('jackpot_workspace_switching');
                if (!kind) return;
                var label = kind === 'brand' ? 'Switching brand…' : 'Switching workspace…';
                var el = document.createElement('div');
                el.id = 'jackpot-workspace-switch-overlay';
                el.setAttribute('style', 'position:fixed;inset:0;z-index:2147483647;background:rgba(11,11,13,0.94);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1.25rem;font-family:ui-sans-serif,system-ui,sans-serif;');
                el.innerHTML = '<div style="width:2.5rem;height:2.5rem;border:3px solid rgba(255,255,255,0.15);border-top-color:rgba(255,255,255,0.95);border-radius:50%;animation:jackpot-ws-spin 0.75s linear infinite"></div>' +
                    '<p style="color:rgba(255,255,255,0.88);font-size:0.95rem;margin:0;font-weight:500;letter-spacing:0.02em">' + label + '</p>' +
                    '<p style="color:rgba(255,255,255,0.45);font-size:0.75rem;margin:0">Just a moment</p>';
                document.body.appendChild(el);
                var st = document.createElement('style');
                st.id = 'jackpot-ws-spin-style';
                st.textContent = '@keyframes jackpot-ws-spin{to{transform:rotate(360deg)}}';
                document.head.appendChild(st);
            } catch (e) {}
        })();
    </script>
    @inertia
    
  </body>
</html>
