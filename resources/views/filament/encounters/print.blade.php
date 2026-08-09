<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encounter {{ $encounter->encounter_number }} — Clinical Record</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 13px; color: #1a1a1a; padding: 24px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 16px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .meta { color: #555; font-size: 12px; margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; }
        .field { margin-bottom: 8px; }
        .field-label { font-weight: 600; font-size: 11px; text-transform: uppercase; color: #666; }
        .field-value { margin-top: 2px; white-space: pre-wrap; }
        .full-width { grid-column: 1 / -1; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .addendum { margin-top: 16px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background: #f9fafb; }
        .addendum-header { font-weight: 600; margin-bottom: 8px; }
        .addendum-label { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .addendum-correction { background: #fef3c7; color: #92400e; }
        .addendum-supplement { background: #dbeafe; color: #1e40af; }
        .addendum-meta { font-size: 11px; color: #666; margin-bottom: 8px; }
        .addendum-content { white-space: pre-wrap; }
        .original-label { text-align: center; font-weight: 600; font-size: 14px; margin: 16px 0; padding: 8px; background: #f3f4f6; }
        @media print { body { padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 16px;">
        <button onclick="window.print()" style="padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer;">Print</button>
        <button onclick="window.close()" style="padding: 8px 16px; background: #6b7280; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 8px;">Close</button>
    </div>

    <div class="original-label">Original Clinical Record</div>

    <h1>Encounter {{ $encounter->encounter_number }}</h1>
    <div class="meta">
        Patient: {{ $encounter->patient?->full_name ?? '—' }} · 
        Appointment Type: {{ $encounter->appointment?->appointmentType?->name ?? '—' }} · 
        Date: {{ $encounter->started_at?->format('M d, Y g:i A') ?? '—' }}
    </div>

    <h2>Patient Information</h2>
    <div class="grid">
        <div class="field">
            <div class="field-label">Full Name</div>
            <div class="field-value">{{ $encounter->patient?->full_name ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Date of Birth</div>
            <div class="field-value">{{ $encounter->patient?->date_of_birth?->format('M d, Y') ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Gender</div>
            <div class="field-value">{{ \Illuminate\Support\Str::headline($encounter->patient?->gender ?? '—') }}</div>
        </div>
        <div class="field">
            <div class="field-label">Phone</div>
            <div class="field-value">{{ $encounter->patient?->phone ?? '—' }}</div>
        </div>
        <div class="field full-width">
            <div class="field-label">Address</div>
            <div class="field-value">{{ $encounter->patient?->address ?? '—' }}</div>
        </div>
    </div>

    <h2>Encounter Details</h2>
    <div class="grid">
        <div class="field">
            <div class="field-label">Treating Optometrist</div>
            <div class="field-value">{{ $encounter->optometrist?->full_name ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Status</div>
            <div class="field-value"><span class="badge badge-success">Completed</span></div>
        </div>
        <div class="field">
            <div class="field-label">Started</div>
            <div class="field-value">{{ $encounter->started_at?->format('M d, Y g:i A') ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Completed</div>
            <div class="field-value">{{ $encounter->completed_at?->format('M d, Y g:i A') ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Completed By</div>
            <div class="field-value">{{ $encounter->completedBy?->full_name ?? '—' }}</div>
        </div>
    </div>

    <h2>History</h2>
    <div class="grid">
        <div class="field full-width">
            <div class="field-label">Chief Complaint</div>
            <div class="field-value">{{ $encounter->chief_complaint ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Past Ocular History</div>
            <div class="field-value">{{ $encounter->past_ocular_history ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Past Surgical History</div>
            <div class="field-value">{{ $encounter->past_surgical_history ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Past Medical History</div>
            <div class="field-value">{{ $encounter->past_medical_history ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Allergies</div>
            <div class="field-value">{{ $encounter->allergies ?? 'None reported' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Current Medications</div>
            <div class="field-value">{{ $encounter->medications ?? 'None reported' }}</div>
        </div>
    </div>

    <h2>Examination</h2>
    <div class="grid">
        <div class="field full-width">
            <div class="field-label">Findings</div>
            <div class="field-value">{{ $encounter->findings ?? '—' }}</div>
        </div>
        <div class="field full-width">
            <div class="field-label">Supporting Test Results</div>
            <div class="field-value">{{ $encounter->supporting_test_results ?? '—' }}</div>
        </div>
    </div>

    <h2>Assessment & Plan</h2>
    <div class="grid">
        <div class="field full-width">
            <div class="field-label">Assessment</div>
            <div class="field-value">{{ $encounter->assessment ?? '—' }}</div>
        </div>
        <div class="field full-width">
            <div class="field-label">Plan</div>
            <div class="field-value">{{ $encounter->plan ?? '—' }}</div>
        </div>
    </div>

    @if($encounter->prescriptions->isNotEmpty())
    <h2>Prescription</h2>
    @foreach($encounter->prescriptions as $prescription)
    <div class="grid">
        <div class="field">
            <div class="field-label">Prescription #</div>
            <div class="field-value">{{ $prescription->prescription_number ?? '—' }}</div>
        </div>
        <div class="field">
            <div class="field-label">Prescribed At</div>
            <div class="field-value">{{ $prescription->prescribed_at?->format('M d, Y g:i A') ?? '—' }}</div>
        </div>
        <div class="field full-width">
            <div class="field-label">Remarks</div>
            <div class="field-value">{{ $prescription->remarks ?? '—' }}</div>
        </div>
    </div>
    @endforeach
    @endif

    @if($encounter->addenda->isNotEmpty())
    <div class="original-label">Addenda — Original Record Unchanged</div>
    
    @foreach($encounter->addenda as $addendum)
    <div class="addendum">
        <div class="addendum-header">
            <span class="addendum-label {{ $addendum->type->value === 'correction' ? 'addendum-correction' : 'addendum-supplement' }}">
                {{ ucfirst($addendum->type->value) }}
            </span>
            #{{ $addendum->sequence_number }}
        </div>
        <div class="addendum-meta">
            {{ $addendum->author?->full_name ?? '—' }} · {{ $addendum->authored_at?->format('M d, Y g:i A') ?? '—' }}
        </div>
        <div class="field-label">Reason</div>
        <div class="addendum-content" style="margin-bottom: 8px;">{{ $addendum->reason }}</div>
        <div class="field-label">Content</div>
        <div class="addendum-content">{{ $addendum->content }}</div>
    </div>
    @endforeach
    @endif

    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
