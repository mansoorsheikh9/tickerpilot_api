<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4;">

<div style="max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">

    <!-- Header -->
    <div style="background: #ffffff; padding: 40px 30px; text-align: center;">
        <div style="display: inline-flex; align-items: center; gap: 12px;">
            <div style="width: 48px; height: 48px; background: #000; color: #fff; border-radius: 10px; display: flex; justify-content: center; align-items: center; font-weight: 700; font-size: 22px; line-height: 1;">
                TP
            </div>
            <div style="font-size: 26px; font-weight: 600; color: #000;">
                TickerPilot
            </div>
        </div>
    </div>

    <!-- Content -->
    <div style="padding: 40px 30px;">
        <p style="font-size: 18px; margin-bottom: 20px;">Hello {{ $user->name }},</p>

        <p style="font-size: 16px; color: #555; margin-bottom: 30px;">
            We received a request to reset your password for your TickerPilot account.
            Click the button below to create a new password.
        </p>

        <!-- Button - Using table for better email compatibility -->
        <div style="text-align: center; margin: 35px 0;">
            <table cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;">
                <tr>
                    <td style="background: #000; border-radius: 6px; padding: 14px 40px;">
                        <a href="{{ $resetUrl }}" style="color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px; display: inline-block;">
                            Reset Password
                        </a>
                    </td>
                </tr>
            </table>
        </div>

        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 6px; font-size: 14px;">
            <p style="margin: 0 0 10px 0; color: #666;"><strong>Alternative method:</strong></p>
            <p style="margin: 0 0 10px 0; color: #666;">If the button does not work, copy and paste this link into your browser:</p>
            <div style="background: #ffffff; border: 1px solid #dee2e6; padding: 12px; border-radius: 4px; font-family: 'Courier New', monospace; font-size: 13px; color: #495057; word-break: break-all; margin-top: 10px;">
                {{ $resetUrl }}
            </div>
        </div>

        <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; font-size: 14px; color: #856404;">
            <strong>Security Notice:</strong> This password reset link will expire in 60 minutes.
            If you did not request this, please ignore this email or reach out to our support team.
        </div>
    </div>

    <!-- Footer -->
    <div style="padding: 30px; text-align: center; background: #f8f9fa; border-top: 1px solid #e9ecef; font-size: 14px; color: #6c757d;">
        <p>&copy; {{ date('Y') }} TickerPilot. All rights reserved.</p>
        <p>Need help? <a href="mailto:support@tickerpilot.com" style="color: #000; text-decoration: none;">Contact Support</a></p>
    </div>
</div>

</body>
</html>
