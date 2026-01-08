<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ownership Transfer Completed</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{ config('app.name') }}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: #059669;">Ownership Transfer Complete</h2>
            
            <p>Hi {{ $recipient->name }},</p>
            
            <p>
                The ownership transfer of <strong>{{ $tenant->name }}</strong> has been completed.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #ecfdf5; border-left: 4px solid #10b981; border-radius: 4px;">
                <strong>Transfer Details:</strong><br>
                • Previous Owner: {{ $previousOwner->name }} ({{ $previousOwner->email }})<br>
                • New Owner: {{ $newOwner->name }} ({{ $newOwner->email }})<br>
                • Previous owner has been downgraded to Admin role<br>
                • New owner now has full administrative control
            </p>
            
            <div style="text-align: center; margin: 24px 0;">
                <a href="{{ config('app.url') }}/app/companies/settings" style="display: inline-block; padding: 12px 24px; background-color: #10b981; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500;">Access Company Settings</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                This transfer has been logged in the activity history for audit purposes.
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
