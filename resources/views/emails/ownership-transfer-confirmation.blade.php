<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Ownership Transfer</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{ config('app.name') }}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: #d97706;">Confirm Ownership Transfer</h2>
            
            <p>Hi {{ $currentOwner->name }},</p>
            
            <p>
                You have initiated a request to transfer ownership of <strong>{{ $tenant->name }}</strong> to 
                <strong>{{ $newOwner->name }}</strong> ({{ $newOwner->email }}).
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 4px;">
                <strong>Important:</strong> This action will transfer all ownership rights and responsibilities to {{ $newOwner->name }}. 
                You will be downgraded to an Admin role after the transfer is completed.
            </p>
            
            <p>
                Click the button below to confirm this transfer:
            </p>
            
            <div style="text-align: center;">
                <a href="{{ $confirmationUrl }}" style="display: inline-block; padding: 12px 24px; background-color: #f59e0b; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500; margin: 16px 0;">Confirm Transfer</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                Or copy and paste this link into your browser:<br>
                <a href="{{ $confirmationUrl }}" style="color: #6366f1; word-break: break-all;">{{ $confirmationUrl }}</a>
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fef2f2; border-left: 4px solid #dc2626; border-radius: 4px; font-size: 14px;">
                <strong>Security Note:</strong> This link will expire in 7 days. If you did not initiate this transfer, 
                please contact support immediately.
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
