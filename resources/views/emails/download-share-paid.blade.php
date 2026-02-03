@php
    $accentColor = $branding['branding_options']['accent_color'] ?? '#4F46E5';
    $logoUrl = $branding['branding_options']['logo_url'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $brandName }} shared files with you</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0; }
        .email-container { background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
        .email-header { padding: 24px; text-align: center; }
        .email-body { padding: 32px 24px; }
        .email-footer { background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; }
        .button { display: inline-block; padding: 12px 24px; color: #ffffff !important; text-decoration: none; border-radius: 6px; font-weight: 500; margin: 16px 0; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header" style="background: {{ $accentColor }}; color: #ffffff;">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="" style="max-height: 48px; margin-bottom: 12px;" />
            @endif
            <h1 style="margin: 0; font-size: 20px;">{{ $brandName }} shared files with you</h1>
        </div>
        <div class="email-body">
            <p>Hi there,</p>
            <p>{{ $brandName }} has shared files with you.</p>
            @if($personalMessage)
                <p style="font-style: italic; color: #4b5563;">{{ $personalMessage }}</p>
            @endif
            <div style="text-align: center; margin: 24px 0;">
                <a href="{{ $shareUrl }}" class="button" style="background-color: {{ $accentColor }};">Download Files</a>
            </div>
            <p style="font-size: 14px; color: #6b7280;">
                Or copy and paste this link into your browser:<br>
                <a href="{{ $shareUrl }}" style="color: #6366f1; word-break: break-all;">{{ $shareUrl }}</a>
            </p>
            <p style="font-size: 14px; color: #6b7280;">
                This link may expire. If you didn't expect this email, you can safely ignore it.
            </p>
        </div>
        <div class="email-footer">
            <p>© {{ date('Y') }} {{ $brandName }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
