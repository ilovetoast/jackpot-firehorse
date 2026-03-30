<x-email.layout :title="'Reset your password — '.config('app.name')">
    <h2 style="margin:0 0 16px;font-size:24px;font-weight:600;color:#0f172a;letter-spacing:-0.02em;">Reset your password</h2>
    <p style="margin:0 0 16px;">We received a request to reset your password. If you didn’t make this request, you can ignore this email.</p>
    <p style="margin:0 0 12px;">Click the button below:</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;">
        <tr>
            <td align="left" style="border-radius:9999px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);box-shadow:0 1px 2px rgba(0,0,0,0.08);">
                <a href="{{ $url }}" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:9999px;">Reset password</a>
            </td>
        </tr>
    </table>
    <p style="margin:20px 0 0;font-size:13px;color:#64748b;">Or copy this link:<br><a href="{{ $url }}" style="color:#4f46e5;word-break:break-all;">{{ $url }}</a></p>
    <div style="margin-top:24px;padding:14px 16px;background-color:#fef2f2;border-left:4px solid #dc2626;border-radius:6px;font-size:13px;color:#64748b;">
        <strong style="color:#991b1b;">Security</strong> — This link expires in 60 minutes.
    </div>
</x-email.layout>
