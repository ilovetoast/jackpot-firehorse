{{-- Paste-this-link fallback below buttons --}}
@props(['url', 'color' => '#4f46e5'])
<p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.5;">Or copy this link:
<br><a href="{{ $url }}" style="color:{{ $color }};word-break:break-all;text-decoration:none;">{{ $url }}</a></p>
