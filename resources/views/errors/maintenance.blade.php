{{--
    Branded maintenance splash, rendered ONCE by `php artisan down --render=errors.maintenance`
    and then served as static HTML from storage/framework/maintenance.php until `artisan up`.

    ⚠️  Keep this view self-contained. Laravel pre-renders it at `down` time and the full
    framework is NOT booted while maintenance is active, so runtime helpers (auth(), route(),
    session, config beyond what's baked in at render) must not be referenced here. All CSS
    is inlined — no CDNs — so the page still renders if external services are down (which
    is often WHY we're in maintenance in the first place).

    Static files (SVG wordmark, favicon, OG image) are served by nginx/apache directly and
    bypass PHP entirely, so they continue to load during maintenance.

    Customize the operator-visible flags via {@see \App\Console\Commands\MaintenanceCommand}
    or directly: `artisan down --render=errors.maintenance --retry=60 --refresh=60`.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{--
        http-equiv refresh: the browser auto-reloads every 60s, so visitors don't
        need to keep hitting refresh to know when we're back. 60s matches the
        Retry-After header set by --retry=60 in MaintenanceCommand.
    --}}
    <meta http-equiv="refresh" content="60">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name', 'Jackpot') }} — Back shortly</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

    {{-- Social unfurl (Teams / Slack / LinkedIn / iMessage) --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name', 'Jackpot') }}">
    <meta property="og:title" content="{{ config('app.name', 'Jackpot') }} — Back shortly">
    <meta property="og:description" content="We're performing a quick update. Service will be restored momentarily.">
    <meta property="og:image" content="{{ url('/jp-og.png') }}">
    <meta name="twitter:card" content="summary_large_image">

    <style>
        /* Reset + base */
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            background: #0B0B0D;
            color: #fff;
            font-family: 'Figtree', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow: hidden;
            position: relative;
        }

        /* Ambient gradient blobs — match marketing layout aesthetic */
        .ambient {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }
        .ambient::before,
        .ambient::after {
            content: '';
            position: absolute;
            border-radius: 9999px;
            filter: blur(140px);
        }
        .ambient::before {
            top: -40%;
            left: -20%;
            width: 80%;
            height: 80%;
            background: rgba(99, 102, 241, 0.12); /* indigo */
        }
        .ambient::after {
            bottom: -30%;
            right: -15%;
            width: 60%;
            height: 70%;
            background: rgba(139, 92, 246, 0.10); /* violet */
        }
        .ambient-vignette {
            position: absolute;
            inset: 0;
            background: linear-gradient(to bottom, #0B0B0D, transparent 30%, transparent 70%, rgba(11, 11, 13, 0.9));
        }

        /* Layout */
        .wrap {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
            text-align: center;
        }

        .wordmark {
            height: 2.25rem;
            width: auto;
            margin-bottom: 3rem;
            opacity: 0.95;
        }

        /* Headline */
        h1 {
            margin: 0 0 1rem;
            font-size: 2.5rem;
            line-height: 1.1;
            font-weight: 700;
            letter-spacing: -0.02em;
            max-width: 42rem;
        }
        @media (min-width: 640px) {
            h1 { font-size: 3.25rem; }
        }

        .subtitle {
            margin: 0 auto 2.5rem;
            max-width: 32rem;
            font-size: 1.0625rem;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.55);
        }

        /* Status pill with pulsing dot */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.8125rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.65);
        }
        .dot {
            display: inline-block;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 9999px;
            background: #818cf8; /* indigo-400 */
            box-shadow: 0 0 0 0 rgba(129, 140, 248, 0.6);
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgba(129, 140, 248, 0.5); }
            70%  { box-shadow: 0 0 0 0.75rem rgba(129, 140, 248, 0); }
            100% { box-shadow: 0 0 0 0 rgba(129, 140, 248, 0); }
        }

        /* Footer */
        .footer {
            position: absolute;
            bottom: 1.5rem;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.3);
            z-index: 10;
        }
        .footer a {
            color: rgba(255, 255, 255, 0.45);
            text-decoration: none;
        }
        .footer a:hover { color: rgba(255, 255, 255, 0.7); }

        /* Reduced motion — respect user preferences */
        @media (prefers-reduced-motion: reduce) {
            .dot { animation: none; }
        }
    </style>
</head>
<body>
    <div class="ambient" aria-hidden="true">
        <div class="ambient-vignette"></div>
    </div>

    <div class="wrap">
        <img src="/jp-wordmark-inverted.svg" alt="Jackpot" class="wordmark" decoding="async" />

        <h1>We'll be right back.</h1>
        <p class="subtitle">
            We're performing a quick update to make Jackpot even better.
            This page will refresh automatically — no need to reload.
        </p>

        <div class="status" role="status" aria-live="polite">
            <span class="dot" aria-hidden="true"></span>
            <span>Service restoring shortly</span>
        </div>
    </div>

    <footer class="footer">
        Jackpot — Velvetysoft
    </footer>
</body>
</html>
