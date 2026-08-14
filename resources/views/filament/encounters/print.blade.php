<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encounter {{ $encounter->encounter_number }} — Clinical Record</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        @page { size: A4; margin: 12mm; }

        html, body {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        body { font-family: Arial, sans-serif; font-size: 11px; line-height: 1.3; color: #1a1a1a; padding: 16px; }

        h2 {
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #4F8DD7;
            margin: 10px 0 5px;
            padding-bottom: 3px;
            border-bottom: 1.25px solid #4F8DD7;
            break-after: avoid;
            break-inside: avoid;
        }
        h2:first-of-type { margin-top: 8px; }

        section { break-inside: avoid; }

        .letterhead {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            border-bottom: 2px solid #4F8DD7;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .clinic-name { font-size: 14px; font-weight: bold; color: #4F8DD7; }
        .clinic-details { font-size: 9px; color: #666; margin-top: 1px; }
        .doc-title { text-align: right; }
        .doc-title .label { font-size: 9.5px; text-transform: uppercase; letter-spacing: 1px; color: #888; }
        .doc-title .number { font-size: 13px; font-weight: bold; }

        .meta { color: #555; font-size: 10px; margin-bottom: 6px; }
        .meta span:not(:last-child)::after { content: '·'; margin: 0 6px; color: #ccc; }

        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; }
        .field { margin-bottom: 4px; break-inside: avoid; }
        .field-label { font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; color: #666; }
        .field-value { margin-top: 1px; white-space: pre-wrap; }
        .full-width { grid-column: 1 / -1; }

        .badge { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 9.5px; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }

        .prescription-block { padding: 4px 0; border-top: 1px dashed #ddd; break-inside: avoid; }
        .prescription-block:first-of-type { border-top: none; }

        .addendum { margin-top: 8px; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 6px; background: #f9fafb; break-inside: avoid; }
        .addendum-header { font-weight: 600; margin-bottom: 5px; }
        .addendum-label { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; text-transform: uppercase; }
        .addendum-correction { background: #fef3c7; color: #92400e; }
        .addendum-supplement { background: #dbeafe; color: #1e40af; }
        .addendum-meta { font-size: 9.5px; color: #666; margin-bottom: 5px; }
        .addendum-content { white-space: pre-wrap; }

        .original-label {
            text-align: center;
            font-weight: 600;
            font-size: 10.5px;
            letter-spacing: 0.3px;
            margin: 8px 0 6px;
            padding: 4px;
            background: #f3f4f6;
            break-after: avoid;
            break-inside: avoid;
        }

        .signature-area { margin-top: 16px; display: flex; justify-content: flex-end; break-inside: avoid; }
        .signature-box { text-align: center; width: 190px; }
        .signature-line { border-bottom: 1px solid #4a5568; height: 24px; }
        .signature-name { font-size: 10.5px; font-weight: bold; margin-top: 3px; }
        .signature-title { font-size: 9px; color: #888; }

        .print-footer { margin-top: 10px; padding-top: 5px; border-top: 1px solid #e5e7eb; font-size: 8px; color: #999; text-align: center; }

        .no-print { margin-bottom: 12px; }
        .no-print button { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
        .no-print .btn-print { background: #3b82f6; color: white; }
        .no-print .btn-close { background: #6b7280; color: white; margin-left: 8px; }

        @media print { body { padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn-print" onclick="window.print()">Print</button>
        <button class="btn-close" onclick="window.close()">Close</button>
    </div>

    <div class="letterhead">
        <div>
            <div class="clinic-name">{{ $clinic['name'] ?? 'Padilla Optical Clinic' }}</div>
            <div class="clinic-details">{{ $clinic['address'] ?? '' }}</div>
            <div class="clinic-details">
                Tel: {{ $clinic['telephone'] ?? '' }} · Mobile: {{ $clinic['mobile'] ?? '' }}
            </div>
        </div>
        <div class="doc-title">
            <div class="label">Clinical Record</div>
            <div class="number">{{ $encounter->encounter_number }}</div>
        </div>
    </div>

    <div class="original-label">Original Clinical Record</div>

    <div class="meta">
        <span>Patient: {{ $encounter->patient?->full_name ?? '—' }}</span>
        <span>Appointment Type: {{ $encounter->appointment?->appointmentType?->name ?? '—' }}</span>
        <span>Date: {{ $encounter->started_at?->format('M d, Y g:i A') ?? '—' }}</span>
    </div>

    <section>
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
    </section>

    <section>
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
    </section>

    <section>
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
    </section>

    <section>
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
    </section>

    <section>
        <h2>Assessment &amp; Plan</h2>
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
    </section>

    @if($encounter->prescriptions->isNotEmpty())
    <section>
        <h2>Prescription</h2>
        @foreach($encounter->prescriptions as $prescription)
        <div class="prescription-block">
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
        </div>
        @endforeach
    </section>
    @endif

    <div class="signature-area">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-name">{{ $encounter->optometrist?->full_name ?? 'Attending Optometrist' }}</div>
            <div class="signature-title">Optometrist</div>
        </div>
    </div>

    @if($encounter->addenda->isNotEmpty())
    <div class="original-label" style="margin-top: 16px;">Addenda — Original Record Unchanged</div>

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
        <div class="addendum-content" style="margin-bottom: 5px;">{{ $addendum->reason }}</div>
        <div class="field-label">Content</div>
        <div class="addendum-content">{{ $addendum->content }}</div>
    </div>
    @endforeach
    @endif

    <div class="print-footer">
        Printed {{ now()->format('M d, Y g:i A') }} · Encounter {{ $encounter->encounter_number }} · Confidential Patient Record
    </div>

    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
