@props([
    'title' => config('app.name', 'Jackpot'),
    'headerText' => null,
    'footerText' => null,
])
@php
    $appUrl = rtrim((string) config('app.url'), '/');
    $year = date('Y');
    $footerLine = $footerText ?? "© {$year} ".config('app.name').'. All rights reserved.';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f5f7;margin:0;padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);border:1px solid #e8e8e8;">
        <tr>
          <td style="background:#ffffff;padding:0;border-bottom:1px solid #e8e8e8;">
            <div style="height:3px;background:linear-gradient(90deg,#4f46e5 0%,#7c3aed 50%,#06b6d4 100%);"></div>
            <div style="padding:24px 28px 20px;">
              <img src="{{ $appUrl }}/jp-logo.svg" alt="{{ config('app.name') }}" width="132" height="32" style="display:block;height:32px;width:auto;max-width:100%;border:0;" />
              @if($headerText)
                <p style="margin:12px 0 0;font-size:13px;color:#64748b;">{{ $headerText }}</p>
              @endif
            </div>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 32px 36px;color:#425466;font-size:15px;line-height:1.6;">
            {{ $slot }}
          </td>
        </tr>
      </table>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;">
        <tr>
          <td style="padding:20px 8px 8px;font-size:12px;line-height:1.5;color:#8898aa;text-align:center;">
            <p style="margin:0 0 8px;">{{ $footerLine }}</p>
            <p style="margin:0;"><a href="{{ $appUrl }}" style="color:#556cd6;text-decoration:none;">{{ config('app.name') }}</a></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
