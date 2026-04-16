{{-- Tenant-branded header: tenant logo/name first, small "via Jackpot" --}}
@props([
    'tenantName' => null,
    'tenantLogoUrl' => null,
    'accentColor' => '#4f46e5',
])
@php
    $appUrl  = rtrim((string) config('app.url'), '/');
    $appName = config('app.name', 'Jackpot');
    $name    = $tenantName ?: $appName;
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
  <tr>
    <td style="padding:0 0 16px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
          {{-- Tenant identity --}}
          <td style="vertical-align:middle;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
              <tr>
                @if($tenantLogoUrl)
                <td style="vertical-align:middle;padding-right:12px;">
                  <img src="{{ $tenantLogoUrl }}" alt="{{ e($name) }}" width="140" height="36" style="display:block;max-height:36px;max-width:160px;width:auto;height:auto;border:0;" />
                </td>
                @else
                <td style="vertical-align:middle;">
                  <span style="font-size:16px;font-weight:700;color:#111827;letter-spacing:-0.01em;">{{ $name }}</span>
                </td>
                @endif
              </tr>
            </table>
          </td>
          {{-- "via Jackpot" --}}
          <td style="vertical-align:middle;text-align:right;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-table;">
              <tr>
                <td style="vertical-align:middle;padding-right:6px;">
                  <img src="{{ $appUrl }}/icons/pwa-192.png" alt="" width="18" height="18" style="display:block;width:18px;height:18px;border-radius:4px;border:0;" />
                </td>
                <td style="vertical-align:middle;">
                  <span style="font-size:11px;color:#9ca3af;font-weight:500;">via {{ $appName }}</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
