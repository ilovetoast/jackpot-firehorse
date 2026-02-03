<x-email.layout
    title="Files shared with you"
    headerText="Files shared with you"
    footerText="Provided by {{ config('app.name') }} — Free asset management for teams"
>
    <p>Hi there,</p>

    <p>Someone has shared files with you.</p>

    @if($personalMessage)
        <p style="font-style: italic; color: #4b5563;">{{ $personalMessage }}</p>
    @endif

    <div style="text-align: center; margin: 24px 0;">
        <a href="{{ $shareUrl }}" class="button">Download Files</a>
    </div>

    <p style="font-size: 14px; color: #6b7280;">
        Or copy and paste this link into your browser:<br>
        <a href="{{ $shareUrl }}" style="color: #6366f1; word-break: break-all;">{{ $shareUrl }}</a>
    </p>

    <p style="font-size: 14px; color: #6b7280;">
        This link may expire. If you didn't expect this email, you can safely ignore it.
    </p>
</x-email.layout>
