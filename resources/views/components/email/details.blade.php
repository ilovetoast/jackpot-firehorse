{{-- Key-value detail panel (light gray box) --}}
@props(['items' => []])
@if(count($items))
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:20px 0;background-color:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
  <tr>
    <td style="padding:16px 20px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        @foreach($items as $label => $value)
        <tr>
          <td style="padding:{{ $loop->first ? '0' : '8px' }} 0 0;font-size:13px;color:#6b7280;font-weight:500;white-space:nowrap;vertical-align:top;width:120px;">{{ $label }}</td>
          <td style="padding:{{ $loop->first ? '0' : '8px' }} 0 0 12px;font-size:13px;color:#111827;vertical-align:top;">{{ $value }}</td>
        </tr>
        @endforeach
      </table>
    </td>
  </tr>
</table>
@endif
