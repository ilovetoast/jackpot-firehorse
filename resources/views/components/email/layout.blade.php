@props([
    'title' => config('app.name', 'Jackpot'),
    'mode' => 'system',
    'preheader' => null,

    'tenantName' => null,
    'tenantLogoUrl' => null,
    'tenantAccentColor' => null,
    'tenantIsFree' => false,
])
@php
    $appUrl  = rtrim((string) config('app.url'), '/');
    $appName = config('app.name', 'Jackpot');
    $year    = date('Y');
    $isTenant = $mode === 'tenant';

    $jackpotIndigo = '#4f46e5';

    // Tenant accent: validate hex + contrast safety (must be dark enough for white text)
    $accent = $jackpotIndigo;
    if ($isTenant && $tenantAccentColor) {
        $hex = ltrim($tenantAccentColor, '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $r = hexdec(substr($hex, 0, 2)) / 255;
            $g = hexdec(substr($hex, 2, 2)) / 255;
            $b = hexdec(substr($hex, 4, 2)) / 255;
            // Relative luminance (WCAG)
            $lum = 0.2126 * ($r <= 0.03928 ? $r/12.92 : pow(($r+0.055)/1.055, 2.4))
                 + 0.7152 * ($g <= 0.03928 ? $g/12.92 : pow(($g+0.055)/1.055, 2.4))
                 + 0.0722 * ($b <= 0.03928 ? $b/12.92 : pow(($b+0.055)/1.055, 2.4));
            // Contrast ratio against white (lum=1): (1+0.05)/(lum+0.05)
            $contrast = (1.05) / ($lum + 0.05);
            if ($contrast >= 3.0) {
                $accent = '#' . $hex;
            }
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>{{ $title }}</title>
    <!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
    <style>
        body, table, td { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        a { color: {{ $accent }}; }
    </style>
</head>
<body style="margin:0;padding:0;word-spacing:normal;background-color:#f5f6f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
@if($preheader)
<div style="display:none;font-size:1px;color:#f5f6f8;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{{ $preheader }}</div>
@endif
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f5f6f8;margin:0;padding:0;">
  <tr>
    <td align="center" style="padding:32px 16px;">

      {{-- ============ HEADER ============ --}}
      @if($isTenant)
        <x-email.tenant-header
            :tenantName="$tenantName"
            :tenantLogoUrl="$tenantLogoUrl"
            :accentColor="$accent"
        />
      @else
        <x-email.header />
      @endif

      {{-- ============ CARD ============ --}}
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
        <tr>
          <td>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
              {{-- Accent rule --}}
              <tr>
                <td style="height:3px;background:{{ $isTenant ? $accent : 'linear-gradient(90deg,'.$jackpotIndigo.' 0%,#7c3aed 50%,#06b6d4 100%)' }};font-size:0;line-height:0;">&nbsp;</td>
              </tr>
              {{-- Content --}}
              <tr>
                <td style="padding:36px 40px 40px;color:#374151;font-size:15px;line-height:1.65;">
                  {{ $slot }}
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      {{-- ============ FOOTER ============ --}}
      @if($isTenant)
        <x-email.powered-by-footer :tenantName="$tenantName" :tenantIsFree="$tenantIsFree" />
      @else
        <x-email.footer />
      @endif

    </td>
  </tr>
</table>
</body>
</html>
