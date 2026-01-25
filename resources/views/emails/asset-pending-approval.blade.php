<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Pending Approval</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px;">
        <h1 style="font-size: 24px; font-weight: 600; margin: 0 0 16px 0; color: #111827;">
            Asset Pending Approval
        </h1>
        
        <p style="margin: 0 0 24px 0; color: #6b7280;">
            A new asset has been uploaded and requires your approval.
        </p>
        
        <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; margin: 0 0 24px 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; font-weight: 600; color: #374151; width: 140px;">Asset Name:</td>
                    <td style="padding: 8px 0; color: #111827;">{{ $assetName }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: 600; color: #374151;">Category:</td>
                    <td style="padding: 8px 0; color: #111827;">{{ $categoryName }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: 600; color: #374151;">Uploaded By:</td>
                    <td style="padding: 8px 0; color: #111827;">{{ $uploaderName }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: 600; color: #374151;">Uploaded:</td>
                    <td style="padding: 8px 0; color: #111827;">{{ $uploadTimestamp }}</td>
                </tr>
            </table>
        </div>
        
        <div style="text-align: center; margin: 24px 0;">
            <a href="{{ $approvalUrl }}" 
               style="display: inline-block; background-color: #6366f1; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 500; font-size: 14px;">
                Review Pending Assets
            </a>
        </div>
        
        <p style="margin: 24px 0 0 0; font-size: 14px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 16px;">
            This email was sent because you have permission to approve assets. 
            You can review and approve assets in the approval inbox.
        </p>
    </div>
</body>
</html>
