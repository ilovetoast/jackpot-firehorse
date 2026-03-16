{{--
  Jackpot-styled error layout.
  White background, hero-style typography, brand colors (indigo).
  Future: for brand-scoped errors, pass $brand (logo, name, primary_color) and use
  it here for nav/buttons so the error page matches the brand the user was in.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ($title ?? $code ?? 'Error') . ' | ' . config('app.name', 'Jackpot') }}</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <meta property="og:image" content="{{ url('/og-image-1200x630.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        body { font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif; }
    </style>
</head>
<body class="bg-white font-sans antialiased min-h-screen flex flex-col">
    {{-- Top nav: same as hero homepage --}}
    <nav class="bg-white shadow-sm relative z-50">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 justify-between items-center">
                <a href="{{ url('/') }}" class="flex items-center gap-2">
                    <img src="{{ asset('jp-logo.svg') }}" alt="" class="h-8 w-auto" aria-hidden="true" />
                    <span class="text-xl font-bold text-gray-900">Jackpot</span>
                </a>
                <a href="{{ url('/') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    Go home
                </a>
            </div>
        </div>
    </nav>

    {{-- Main content: centered, hero-style --}}
    <main class="flex-1 flex flex-col justify-center px-4 sm:px-6 lg:px-8 py-16 sm:py-24">
        <div class="mx-auto max-w-2xl text-center">
            @if(isset($code))
                <p class="text-base font-semibold text-indigo-600">{{ $code }}</p>
            @endif
            <h1 class="mt-2 text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl">
                {{ $title ?? 'Something went wrong' }}
            </h1>
            @if(isset($message) && $message)
                <p class="mt-4 text-lg leading-8 text-gray-600">
                    {{ $message }}
                </p>
            @endif
            <div class="mt-10 flex items-center justify-center gap-x-6">
                <a href="{{ url('/') }}" class="rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    Back to home
                </a>
                @if(auth()->check())
                    <a href="{{ url('/app/companies') }}" class="text-sm font-semibold leading-6 text-gray-900 hover:text-gray-700">
                        Dashboard <span aria-hidden="true">→</span>
                    </a>
                @else
                    <a href="{{ url('/login') }}" class="text-sm font-semibold leading-6 text-gray-900 hover:text-gray-700">
                        Sign in <span aria-hidden="true">→</span>
                    </a>
                @endif
            </div>
        </div>
    </main>
</body>
</html>
