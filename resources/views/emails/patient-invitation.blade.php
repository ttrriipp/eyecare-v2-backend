<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Instrument Sans', sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4F8DD7; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 8px 8px; }
        .code-box { background: #fff; border: 2px dashed #4F8DD7; padding: 16px; text-align: center; margin: 16px 0; border-radius: 8px; }
        .code { font-size: 28px; font-weight: bold; letter-spacing: 4px; color: #4F8DD7; font-family: monospace; }
        .footer { font-size: 12px; color: #666; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>EyeCare</h1>
    </div>
    <div class="content">
        <p>Hello {{ $patientName }},</p>

        <p>You've been invited to link your account with <strong>Padilla Optical Clinic</strong> on EyeCare.</p>

        <p>Linking your account connects it to your clinic patient record, giving you access to:</p>
        <ul>
            <li>Your prescriptions and eyewear orders</li>
            <li>Order status and payment details</li>
            <li>Saved and preferred frame preferences</li>
            <li>Appointment history</li>
        </ul>

        <p><strong>Your invitation code:</strong></p>

        <div class="code-box">
            <div class="code">{{ $invitationCode }}</div>
        </div>

        <p>Open the EyeCare app, go to <strong>Accept Invitation</strong>, and enter this code.</p>

        <p><strong>This invitation expires on {{ $expiresAt->format('M j, Y g:i A') }}.</strong></p>

        <div class="footer">
            <p>If you didn't expect this invitation, you can safely ignore this email.</p>
            <p>Padilla Optical Clinic</p>
        </div>
    </div>
</body>
</html>
