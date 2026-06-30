<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
    .page { padding: 32px 40px; }

    .header { text-align: center; margin-bottom: 24px; border-bottom: 2px solid #4F8DD7; padding-bottom: 16px; }
    .clinic-name { font-size: 22px; font-weight: bold; color: #4F8DD7; }
    .clinic-sub { font-size: 11px; color: #666; margin-top: 2px; }
    .doc-title { font-size: 15px; font-weight: bold; margin-top: 8px; color: #2d3748; letter-spacing: 1px; text-transform: uppercase; }

    .patient-section { display: flex; justify-content: space-between; margin-bottom: 20px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; }
    .field-label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
    .field-value { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .field-secondary { font-size: 11px; color: #555; margin-top: 1px; }

    .rx-title { font-size: 11px; font-weight: bold; color: #4F8DD7; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; margin-top: 16px; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    thead tr { background: #4F8DD7; color: white; }
    thead th { padding: 8px 10px; text-align: center; font-size: 11px; font-weight: 600; }
    thead th.left { text-align: left; }
    tbody tr { border-bottom: 1px solid #e8e8e8; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody td { padding: 7px 10px; font-size: 11px; text-align: center; }
    tbody td.label { text-align: left; font-weight: bold; }
    td.empty { color: #bbb; }

    .pd-section { display: flex; gap: 24px; margin-bottom: 16px; }
    .pd-box { background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 16px; }

    .notes-section { background: #fffbeb; border-left: 3px solid #f6ad55; padding: 10px 14px; margin-bottom: 16px; font-size: 11px; }
    .notes-label { font-weight: bold; margin-bottom: 4px; color: #7b6b00; }

    .validity { text-align: right; font-size: 10px; color: #888; margin-bottom: 16px; }
    .validity span { font-weight: bold; color: {{ (float) optional($prescription->expires_at)->diffInDays(now()) <= 30 ? '#e53e3e' : '#2d3748' }}; }

    .footer { margin-top: 32px; border-top: 1px solid #e8e8e8; padding-top: 12px; }
    .signature-area { display: flex; justify-content: flex-end; }
    .signature-box { text-align: center; width: 200px; }
    .signature-line { border-bottom: 1px solid #4a5568; margin-bottom: 4px; height: 40px; }
    .signature-name { font-size: 11px; font-weight: bold; }
    .signature-title { font-size: 10px; color: #888; }
    .generated { text-align: center; font-size: 10px; color: #999; margin-top: 16px; }
</style>
</head>
<body>
<div class="page">

    <div class="header">
        <div class="clinic-name">Padilla Optical Clinic</div>
        <div class="clinic-sub">Eyecare Management System</div>
        <div class="doc-title">Prescription Record</div>
    </div>

    <div class="patient-section">
        <div>
            <div class="field-label">Patient</div>
            <div class="field-value">{{ $prescription->customer?->name ?? '—' }}</div>
        </div>
        <div>
            <div class="field-label">Date Prescribed</div>
            <div class="field-value">{{ $prescription->prescribed_at ? $prescription->prescribed_at->format('M j, Y') : '—' }}</div>
        </div>
        <div>
            <div class="field-label">Prescribed By</div>
            <div class="field-value">{{ $prescription->createdBy?->name ?? '—' }}</div>
        </div>
    </div>

    @if ($prescription->expires_at)
    <div class="validity">
        Valid until: <span>{{ $prescription->expires_at->format('M j, Y') }}</span>
    </div>
    @endif

    <div class="rx-title">Prescription Details</div>
    <table>
        <thead>
            <tr>
                <th class="left" style="width: 30%;">Eye</th>
                <th>Sphere (SPH)</th>
                <th>Cylinder (CYL)</th>
                <th>Axis</th>
                <th>Add</th>
                <th>Prism</th>
                <th>Base</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="label">OD (Right Eye)</td>
                <td>{{ $prescription->od_sphere ?? '—' }}</td>
                <td>{{ $prescription->od_cylinder ?? '—' }}</td>
                <td>{{ $prescription->od_axis ?? '—' }}</td>
                <td>{{ $prescription->od_add ?? '—' }}</td>
                <td>{{ $prescription->od_prism ?? '—' }}</td>
                <td>{{ $prescription->od_base ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">OS (Left Eye)</td>
                <td>{{ $prescription->os_sphere ?? '—' }}</td>
                <td>{{ $prescription->os_cylinder ?? '—' }}</td>
                <td>{{ $prescription->os_axis ?? '—' }}</td>
                <td>{{ $prescription->os_add ?? '—' }}</td>
                <td>{{ $prescription->os_prism ?? '—' }}</td>
                <td>{{ $prescription->os_base ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <div class="pd-section">
        <div class="pd-box">
            <div class="field-label">Pupillary Distance (PD)</div>
            <div class="field-value" style="font-size: 16px;">{{ $prescription->pd ?? '—' }} mm</div>
        </div>
    </div>

    @if ($prescription->notes)
    <div class="notes-section">
        <div class="notes-label">Clinical Notes</div>
        <div>{{ $prescription->notes }}</div>
    </div>
    @endif

    <div class="footer">
        <div class="signature-area">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-name">{{ $prescription->createdBy?->name ?? 'Attending Optometrist' }}</div>
                <div class="signature-title">Optometrist / Prescribing Staff</div>
            </div>
        </div>
        <div class="generated">
            Generated {{ now()->format('M j, Y g:i A') }} &bull; Padilla Optical Clinic
        </div>
    </div>

</div>
</body>
</html>
