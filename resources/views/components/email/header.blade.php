{{-- Jackpot System header: wordmark logo --}}
@php
    $appUrl  = rtrim((string) config('app.url'), '/');
    $appName = config('app.name', 'Jackpot');
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
  <tr>
    <td style="padding:0 0 20px;">
      <img src="{{ $appUrl }}/icons/jp-wordmark-email@2x.png" alt="{{ $appName }}" width="160" height="61" style="display:block;width:160px;height:auto;border:0;" />
    </td>
  </tr>
</table>
