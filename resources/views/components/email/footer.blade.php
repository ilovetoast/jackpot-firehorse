{{-- Jackpot System footer --}}
@php
    $appUrl  = rtrim((string) config('app.url'), '/');
    $appName = config('app.name', 'Jackpot');
    $year    = date('Y');
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
  <tr>
    <td style="padding:24px 4px 0;text-align:center;">
      <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;line-height:1.5;">&copy; {{ $year }} {{ $appName }}. All rights reserved.</p>
      <p style="margin:0;font-size:12px;">
        <a href="{{ $appUrl }}" style="color:#6b7280;text-decoration:none;">{{ $appName }}</a>
      </p>
    </td>
  </tr>
</table>
