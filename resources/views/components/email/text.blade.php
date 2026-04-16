{{-- Body paragraph wrapper --}}
@props(['muted' => false])
<p style="margin:0 0 16px;font-size:15px;line-height:1.65;color:{{ $muted ? '#6b7280' : '#374151' }};">{{ $slot }}</p>
