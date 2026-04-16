{{-- Tenant mode footer with "via / Powered by Jackpot" attribution --}}
@props([
    'tenantName' => null,
    'tenantIsFree' => false,
])
@php
    $appUrl  = rtrim((string) config('app.url'), '/');
    $appName = config('app.name', 'Jackpot');
    $year    = date('Y');
    $name    = $tenantName ?: $appName;
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
  <tr>
    <td style="padding:24px 4px 0;text-align:center;">
      @if($tenantIsFree)
        {{-- Free plan: prominent Jackpot attribution --}}
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 12px;">
          <tr>
            <td style="vertical-align:middle;padding-right:6px;">
              <img src="{{ $appUrl }}/icons/pwa-192.png" alt="" width="20" height="20" style="display:block;width:20px;height:20px;border-radius:4px;border:0;" />
            </td>
            <td style="vertical-align:middle;">
              <a href="{{ $appUrl }}" style="font-size:12px;font-weight:600;color:#4f46e5;text-decoration:none;">Powered by {{ $appName }}</a>
            </td>
          </tr>
        </table>
      @else
        <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;line-height:1.5;">Sent via <a href="{{ $appUrl }}" style="color:#6b7280;text-decoration:none;">{{ $appName }}</a></p>
      @endif
      <p style="margin:0;font-size:11px;color:#d1d5db;">&copy; {{ $year }} {{ $name }}</p>
    </td>
  </tr>
</table>
