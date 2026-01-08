<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ownership Transfer Request</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{ config('app.name') }}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0;">Ownership Transfer Request</h2>
            
            <p>Hi {{ $newOwner->name }},</p>
            
            <p>
                <strong>{{ $currentOwner->name }}</strong> ({{ $currentOwner->email }}) has initiated a request to transfer 
                ownership of <strong>{{ $tenant->name }}</strong> to you.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #f0f9ff; border-left: 4px solid #6366f1; border-radius: 4px;">
                <strong>What happens next:</strong><br>
                1. The current owner must confirm this transfer via email<br>
                2. Once confirmed, you will receive an acceptance email<br>
                3. After you accept, ownership will be transferred
            </p>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                You will receive another email once the current owner confirms the transfer. 
                If you did not expect this request, you can safely ignore this email.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{ config('app.url') }}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</body>
</html>
