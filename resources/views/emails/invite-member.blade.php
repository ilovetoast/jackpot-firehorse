<x-email.layout :title="'Invitation — '.config('app.name')">
    <h2 style="margin:0 0 16px;font-size:22px;font-weight:600;color:#0f172a;">You've been invited!</h2>
    <p style="margin:0 0 16px;">Hi there,</p>
    <p style="margin:0 0 20px;">
        <strong>{{ $inviter->name }}</strong> has invited you to join
        <strong>{{ $tenant->name }}</strong> on {{ config('app.name') }}.
    </p>
    <p style="margin:0 0 12px;">Click the button below to accept the invitation and create your account:</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;">
        <tr>
            <td align="left" style="border-radius:9999px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);box-shadow:0 1px 2px rgba(0,0,0,0.08);">
                <a href="{{ $inviteUrl }}" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:9999px;">Accept invitation</a>
            </td>
        </tr>
    </table>
    <p style="margin:20px 0 0;font-size:13px;color:#64748b;">
        Or copy and paste this link into your browser:<br>
        <a href="{{ $inviteUrl }}" style="color:#4f46e5;word-break:break-all;">{{ $inviteUrl }}</a>
    </p>
    <p style="margin:24px 0 0;font-size:13px;color:#94a3b8;">If you didn't expect this invitation, you can safely ignore this email.</p>
</x-email.layout>
