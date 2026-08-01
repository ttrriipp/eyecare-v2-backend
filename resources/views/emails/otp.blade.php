<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Instrument Sans', sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4F8DD7; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 8px 8px; }
        .code-box { background: #fff; border: 2px dashed #4F8DD7; padding: 16px; text-align: center; margin: 16px 0; border-radius: 8px; }
        .code { font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #4F8DD7; font-family: monospace; }
        .footer { font-size: 12px; color: #666; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Eyecare</h1>
    </div>
    <div class="content">
        <p>Your verification code is:</p>

        <div class="code-box">
            <div class="code">{{ $code }}</div>
        </div>

        <p>This code will expire in 10 minutes.</p>

        <p>If you didn't request this code, you can safely ignore this email.</p>

        <div class="footer">
            <p>Padilla Optical Clinic</p>
        </div>
    </div>
</body>
</html>
