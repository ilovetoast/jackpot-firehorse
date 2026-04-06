<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending approvals</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px;">
        <h1 style="font-size: 22px; font-weight: 600; margin: 0 0 12px 0; color: #111827;">
            Upload approvals waiting for you
        </h1>
        <p style="margin: 0 0 20px 0; color: #6b7280;">
            Here is a summary of assets pending approval for <strong>{{ $brandName }}</strong>.
        </p>

        @if(($teamStats['count'] ?? 0) > 0)
        <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; margin: 0 0 16px 0;">
            <p style="margin: 0 0 8px 0; font-weight: 600; color: #111827;">Team uploads</p>
            <p style="margin: 0; color: #374151;">
                <strong>{{ $teamStats['count'] }}</strong> {{ $teamStats['count'] === 1 ? 'asset' : 'assets' }} pending.
                @if(!empty($teamStats['oldest_summary']))
                    Longest wait: {{ $teamStats['oldest_summary'] }}.
                @endif
            </p>
            <div style="text-align: left; margin-top: 14px;">
                <a href="{{ $teamReviewUrl }}"
                   style="display: inline-block; padding: 10px 18px; font-size: 14px; font-weight: 600; color: #ffffff; background-color: #4f46e5; text-decoration: none; border-radius: 6px;">
                    Review team uploads
                </a>
            </div>
        </div>
        @endif

        @if(($creatorStats['count'] ?? 0) > 0)
        <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; margin: 0 0 16px 0;">
            <p style="margin: 0 0 8px 0; font-weight: 600; color: #111827;">Creator program</p>
            <p style="margin: 0; color: #374151;">
                <strong>{{ $creatorStats['count'] }}</strong> {{ $creatorStats['count'] === 1 ? 'asset' : 'assets' }} pending.
                @if(!empty($creatorStats['oldest_summary']))
                    Longest wait: {{ $creatorStats['oldest_summary'] }}.
                @endif
            </p>
            <div style="text-align: left; margin-top: 14px;">
                <a href="{{ $creatorReviewUrl }}"
                   style="display: inline-block; padding: 10px 18px; font-size: 14px; font-weight: 600; color: #ffffff; background-color: #4f46e5; text-decoration: none; border-radius: 6px;">
                    Review creator uploads
                </a>
            </div>
        </div>
        @endif

        <p style="margin: 20px 0 0; font-size: 13px; color: #9ca3af;">
            You are receiving this because approval notifications are enabled for your workspace. This is a daily summary; you will not get a separate email for each upload.
        </p>
    </div>
</body>
</html>
