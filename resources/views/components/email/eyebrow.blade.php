{{-- Small uppercase label above the title --}}
@props(['color' => '#6b7280'])
<p style="margin:0 0 8px;font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:{{ $color }};line-height:1;">{{ $slot }}</p>
