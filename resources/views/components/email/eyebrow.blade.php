{{-- Small uppercase label above the title --}}
@props(['color' => null])
@php
    $__eyebrowColor = $color ?? config('mail.branding.eyebrow', '#6d28d9');
@endphp
<p style="margin:0 0 8px;font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:{{ $__eyebrowColor }};line-height:1;">{{ $slot }}</p>
