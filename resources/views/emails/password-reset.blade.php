<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
        }
        .message {
            font-size: 16px;
            color: #555;
            margin-bottom: 30px;
            line-height: 1.8;
        }
        .button-container {
            text-align: center;
            margin: 35px 0;
        }
        .reset-button {
            display: inline-block;
            padding: 14px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            transition: transform 0.2s;
        }
        .reset-button:hover {
            transform: translateY(-2px);
        }
        .alternative {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 6px;
            font-size: 14px;
        }
        .alternative p {
            margin: 0 0 10px 0;
            color: #666;
        }
        .token-box {
            background: #ffffff;
            border: 1px solid #dee2e6;
            padding: 12px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #495057;
            word-break: break-all;
            margin-top: 10px;
        }
        .warning {
            margin-top: 30px;
            padding: 15px;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
            font-size: 14px;
            color: #856404;
        }
        .footer {
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            font-size: 14px;
            color: #6c757d;
        }
        .footer p {
            margin: 5px 0;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🔐 TickerPilot</h1>
    </div>

    <div class="content">
        <p class="greeting">Hello {{ $user->name }},</p>

        <p class="message">
            We received a request to reset your password for your TickerPilot account.
            Click the button below to create a new password.
        </p>

        <div class="button-container">
            <a href="{{ $resetUrl }}" class="reset-button">Reset Password</a>
        </div>

        <div class="alternative">
            <p><strong>Alternative method:</strong></p>
            <p>If the button doesn't work, copy and paste this link into your browser:</p>
            <div class="token-box">{{ $resetUrl }}</div>
        </div>

        <div class="warning">
            <strong>⚠️ Security Notice:</strong> This password reset link will expire in 60 minutes.
            If you didn't request this password reset, please ignore this email or contact our support team.
        </div>
    </div>

    <div class="footer">
        <p>&copy; {{ date('Y') }} TickerPilot. All rights reserved.</p>
        <p>Need help? <a href="mailto:support@tickerpilot.com">Contact Support</a></p>
    </div>
</div>
</body>
</html>
