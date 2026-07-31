<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Instrument Sans', sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4F8DD7; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; background: #4F8DD7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 16px 0; }
        .footer { font-size: 12px; color: #666; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Eyecare</h1>
    </div>
    <div class="content">
        <p>Hello {{ $patientName }},</p>

        <p>You've been invited to connect your account with <strong>Padilla Optical Clinic</strong> on Eyecare.</p>

        <p>Once connected, you'll be able to:</p>
        <ul>
            <li>Book and manage appointments</li>
            <li>View your prescriptions and eyewear orders</li>
            <li>Message the clinic directly</li>
        </ul>

        <p>To accept this invitation, open the Eyecare app and enter the invitation code provided by the clinic.</p>

        <p><strong>This invitation expires on {{ $expiresAt->format('M j, Y g:i A') }}.</strong></p>

        <div class="footer">
            <p>If you didn't expect this invitation, you can safely ignore this email.</p>
            <p>Padilla Optical Clinic</p>
        </div>
    </div>
</body>
</html>
