<x-email.layout>
    <h2 style="margin-top: 0;">You've been invited to view a collection</h2>

    <p>Hi there,</p>

    <p>
        <strong>{{ $inviter->name }}</strong> has invited you to view the collection
        <strong>{{ $collection->name }}</strong> on {{ config('app.name') }}.
    </p>

    <p>
        Click the button below to accept the invitation:
    </p>

    <div style="text-align: center;">
        <a href="{{ $inviteUrl }}" class="button">Accept Invitation</a>
    </div>

    <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
        Or copy and paste this link into your browser:<br>
        <a href="{{ $inviteUrl }}" style="color: #6366f1; word-break: break-all;">{{ $inviteUrl }}</a>
    </p>

    <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
        If you didn't expect this invitation, you can safely ignore this email.
    </p>
</x-email.layout>
