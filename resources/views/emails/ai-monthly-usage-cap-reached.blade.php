<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI monthly limit reached</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 16px;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="padding: 24px;">
            <h1 style="margin-top: 0; font-size: 20px;">AI monthly limit reached</h1>
            <p>Your workspace <strong>{{ $tenant->name }}</strong> has reached its monthly AI usage limit for automated tagging or related AI features.</p>
            <p style="padding: 12px 16px; background-color: #fef3c7; border-radius: 6px; font-size: 14px;">{{ $detailMessage }}</p>
            <p style="font-size: 14px; color: #4b5563;">Usage typically resets at the start of the next calendar month. Review usage and plan limits in Company settings (Plan &amp; billing or AI).</p>
            @if($aiAgentRunId)
                <p style="font-size: 12px; color: #6b7280;">Reference: AI agent run #{{ $aiAgentRunId }}</p>
            @endif
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">— {{ config('app.name') }}</p>
        </div>
    </div>
</body>
</html>
