{{-- Primary CTA button — email-safe with VML fallback for Outlook --}}
@props([
    'url',
    'color' => '#4f46e5',
])
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
  <tr>
    <td align="center" style="border-radius:8px;background-color:{{ $color }};">
      <!--[if mso]>
      <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:44px;v-text-anchor:middle;width:200px;" arcsize="18%" strokecolor="{{ $color }}" fillcolor="{{ $color }}">
        <w:anchorlock/>
        <center style="color:#ffffff;font-family:sans-serif;font-size:14px;font-weight:bold;">{{ $slot }}</center>
      </v:roundrect>
      <![endif]-->
      <!--[if !mso]><!-->
      <a href="{{ $url }}" style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;background-color:{{ $color }};mso-hide:all;">{{ $slot }}</a>
      <!--<![endif]-->
    </td>
  </tr>
</table>
