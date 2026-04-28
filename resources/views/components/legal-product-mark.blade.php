{{--
  Matches the Gateway (frontend) product line: muted, uppercase, wide tracking — e.g. login footer.
  @param string $variant  light (default, for pale surfaces) | dark (for cinematic / near-black)
--}}
@props(['variant' => 'light'])
@php
    $isDark = $variant === 'dark';
    $class = $isDark
        ? 'text-[11px] font-medium uppercase tracking-widest text-white/25'
        : 'text-[11px] font-medium uppercase tracking-widest text-zinc-500';
@endphp
<p {{ $attributes->merge(['class' => $class]) }}>{{ config('app.name', 'Jackpot') }}<span class="{{ $isDark ? 'text-white/20' : 'text-zinc-400' }}"> · </span>Brand asset manager</p>
