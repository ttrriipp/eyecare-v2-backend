<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Health Record — {{ $appointment->appointment_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 13px; color: #1a1a1a; padding: 24px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 16px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .meta { color: #555; font-size: 12px; margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; }
        .field { margin-bottom: 8px; }
        .field-label { font-weight: 600; font-size: 11px; text-transform: uppercase; color: #666; }
        .field-value { margin-top: 2px; }
        .full-width { grid-column: 1 / -1; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-gray { background: #f3f4f6; color: #374151; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
    <h1>Patient Health Record</h1>
    <div class="meta">
        Appointment {{ $appointment->appointment_number }} · {{ $appointment->scheduled_at?->format('M d, Y g:i A') }}
    </div>

    <h2>Patient Information</h2>
    <div class="grid">
        <div class="field">
            <div class="field-label">Full Name</div>
            <div class="field-value">{{ $intake?->full_name ?? $appointment->patient?->full_name ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Date of Birth</div>
            <div class="field-value">{{ ($intake?->date_of_birth ?? $appointment->patient?->date_of_birth)?->format('M d, Y') ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Gender</div>
            <div class="field-value">{{ \Illuminate\Support\Str::headline($intake?->gender ?? $appointment->patient?->gender ?? '—') }}</div>
        </div>
        <div class="field">
            <div class="field-label">Occupation</div>
            <div class="field-value">{{ $intake?->occupation ?? $appointment->patient?->occupation ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Phone</div>
            <div class="field-value">{{ $intake?->phone ?? $appointment->patient?->phone ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Email</div>
            <div class="field-value">{{ $intake?->email ?? $appointment->patient?->contact_email ?? '—' }}</div>
        </div>
        <div class="field full-width">
            <div class="field-label">Address</div>
            <div class="field-value">{{ $intake?->address ?? $appointment->patient?->address ?? '—' }}</div>
        </div>
    </div>

    <h2>Clinical Information</h2>
    <div class="grid">
        <div class="field full-width">
            <div class="field-label">Chief Complaint</div>
            <div class="field-value">{{ $intake?->chief_complaint ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Past Ocular History</div>
            <div class="field-value">{{ $intake?->past_ocular_history ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Past Surgical History</div>
            <div class="field-value">{{ $intake?->past_surgical_history ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Past Medical History</div>
            <div class="field-value">{{ $intake?->past_medical_history ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Allergies</div>
            <div class="field-value">{{ $intake?->allergies ?? 'None reported' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Current Medications</div>
            <div class="field-value">{{ $intake?->medications ?? 'None reported' }}</div>
        </div>
    </div>

    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
