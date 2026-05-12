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
        <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
        <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

        {{--
          Social preview / unfurl tags — consumed by Microsoft Teams, Slack,
          LinkedIn, iMessage, Facebook, Twitter/X, Discord, etc.
          Keep title + description in sync with the marketing hero.
        --}}
        @php
            $ogTitle = trim($page['props']['meta']['og_title'] ?? '') !== ''
                ? $page['props']['meta']['og_title']
                : config('app.name', 'Jackpot') . ' — Brand execution, not asset management';
            $ogDescription = trim($page['props']['meta']['og_description'] ?? '') !== ''
                ? $page['props']['meta']['og_description']
                : 'Not another digital asset manager — a brand asset manager built for execution. Every asset, every brand, every deliverable lined up and ready to hit.';
            $ogImage = url('/jp-og.png');
        @endphp
        <meta name="description" content="{{ $ogDescription }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name', 'Jackpot') }}">
        <meta property="og:title" content="{{ $ogTitle }}">
        <meta property="og:description" content="{{ $ogDescription }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:secure_url" content="{{ $ogImage }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ config('app.name', 'Jackpot') }} — Brand execution, not asset management">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $ogTitle }}">
        <meta name="twitter:description" content="{{ $ogDescription }}">
        <meta name="twitter:image" content="{{ $ogImage }}">
        <meta name="twitter:image:alt" content="{{ config('app.name', 'Jackpot') }} — Brand execution, not asset management">

        <title>{{ config('app.name', 'Jackpot') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @viteReactRefresh
        {{-- Privacy bootstrap: prefer helpers.php (Composer "files" + bootstrap/app.php require). If both are missing on a bad deploy, require here or inline the same logic so first paint never fatals. --}}
        @php
            if (! \function_exists('jackpot_privacy_bootstrap_array')) {
                $helpersPath = base_path('app/helpers.php');
                if (is_file($helpersPath)) {
                    require_once $helpersPath;
                }
            }
            if (\function_exists('jackpot_privacy_bootstrap_array')) {
                $jackpotPrivacyBootstrap = jackpot_privacy_bootstrap_array(request());
            } else {
                $req = request();
                $country = null;
                $cf = $req->header('CF-IPCountry');
                if (is_string($cf) && strlen($cf) === 2) {
                    $country = strtoupper($cf);
                }
                $codes = config('privacy.strict_opt_in_countries', []);
                $strictOptIn = $country !== null && strlen($country) === 2
                    && \in_array(strtoupper($country), $codes, true);
                $jackpotPrivacyBootstrap = [
                    'cookie_policy_version' => config('privacy.cookie_policy_version', '1'),
                    'strict_opt_in_region' => $strictOptIn,
                    'gpc' => $req->header('Sec-GPC') === '1',
                ];
            }
        @endphp
        <script>
            window.__performanceMetricsEnabled = @json(config('performance.client_metrics_enabled', false));
            window.__jackpotPrivacyBootstrap = @json($jackpotPrivacyBootstrap);
        </script>
        @if(config('services.onesignal.push_enabled') && config('services.onesignal.app_id'))
            {{-- Meta always present for dynamic SDK load after functional consent (see pushService.loadOneSignalSdkIfConfigured). --}}
            <meta name="onesignal-app-id" content="{{ config('services.onesignal.app_id') }}">
            <meta name="onesignal-allow-local-http" content="@json(app()->environment(['local', 'development']) || config('services.onesignal.allow_http_local'))">
            @unless(config('privacy.gate_onesignal_behind_consent', true))
                {{-- Legacy: load SDK immediately (not recommended for ePrivacy). --}}
                <script>window.OneSignalDeferred = window.OneSignalDeferred || [];</script>
                <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
            @endunless
        @endif
    @vite('resources/js/app.jsx')
    @inertiaHead
    @routes
  </head>
  <body class="font-sans antialiased">
    {{-- Immediate overlay after full-page workspace/brand switch (sessionStorage set before navigation). Keep markup/CSS aligned with resources/js/utils/workspaceSwitchOverlay.js --}}
    <script>
        (function () {
            try {
                var kind = sessionStorage.getItem('jackpot_workspace_switching');
                if (!kind) return;
                var label = kind === 'brand' ? 'Switching brand…' : 'Switching workspace…';
                var C = '/jp-parts/cherry-slot.svg';
                var S = '/jp-parts/seven-slot.svg';
                var D = '/jp-parts/diamond-slot.svg';
                function cell(src) {
                    return '<div class="jp-ws-slot-cell"><img src="' + src + '" alt="" width="40" height="40" decoding="async" draggable="false"></div>';
                }
                function strip6(a, b, c, d, e, f) {
                    var half = cell(a) + cell(b) + cell(c) + cell(d) + cell(e) + cell(f);
                    return half + half;
                }
                var r1 = strip6(C, S, D, C, S, D);
                var r2 = strip6(S, D, C, S, D, C);
                var r3 = strip6(D, C, S, D, C, S);
                var el = document.createElement('div');
                el.id = 'jackpot-workspace-switch-overlay';
                el.setAttribute('style', 'position:fixed;inset:0;z-index:2147483647;background:rgba(11,11,13,0.94);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1.35rem;font-family:ui-sans-serif,system-ui,sans-serif;');
                el.innerHTML =
                    '<div role="status" aria-live="polite" aria-busy="true" style="display:flex;align-items:center;justify-content:center;gap:10px;height:64px">' +
                    '<div class="jp-ws-reel-window"><div class="jp-ws-reel-strip jp-ws-reel-strip--a">' + r1 + '</div></div>' +
                    '<div class="jp-ws-reel-window"><div class="jp-ws-reel-strip jp-ws-reel-strip--b">' + r2 + '</div></div>' +
                    '<div class="jp-ws-reel-window"><div class="jp-ws-reel-strip jp-ws-reel-strip--c">' + r3 + '</div></div>' +
                    '</div>' +
                    '<p style="color:rgba(255,255,255,0.88);font-size:0.95rem;margin:0;font-weight:500;letter-spacing:0.02em">' + label + '</p>' +
                    '<p style="color:rgba(255,255,255,0.45);font-size:0.75rem;margin:0">Just a moment</p>';
                document.body.appendChild(el);
                var st = document.createElement('style');
                st.id = 'jackpot-ws-slot-style';
                st.textContent =
                    '@keyframes jp-ws-reel-spin{from{transform:translateY(0)}to{transform:translateY(-50%)}}' +
                    '.jp-ws-reel-window{overflow:hidden;width:52px;height:56px;border-radius:8px;background:#fff;box-shadow:0 4px 28px rgba(0,0,0,0.4)}' +
                    '.jp-ws-reel-strip{display:flex;flex-direction:column;width:100%;animation:jp-ws-reel-spin linear infinite;will-change:transform}' +
                    '.jp-ws-reel-strip--a{animation-duration:1.45s}' +
                    '.jp-ws-reel-strip--b{animation-duration:1.9s}' +
                    '.jp-ws-reel-strip--c{animation-duration:1.65s}' +
                    '.jp-ws-slot-cell{flex-shrink:0;height:28px;display:flex;align-items:center;justify-content:center;padding:12% 14%;box-sizing:border-box}' +
                    '.jp-ws-slot-cell img{height:100%;width:100%;object-fit:contain;filter:invert(1);pointer-events:none;-webkit-user-select:none;user-select:none}' +
                    '@media (prefers-reduced-motion:reduce){.jp-ws-reel-strip{animation:none!important}}';
                document.head.appendChild(st);
            } catch (e) {}
        })();
    </script>
    @inertia
    
  </body>
</html>
