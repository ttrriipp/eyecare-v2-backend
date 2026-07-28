<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
    .page { padding: 20px 24px; }

    .header { text-align: center; margin-bottom: 12px; border-bottom: 1.5px solid #4F8DD7; padding-bottom: 10px; }
    .clinic-name { font-size: 16px; font-weight: bold; color: #4F8DD7; }
    .clinic-address { font-size: 9px; color: #666; margin-top: 2px; }
    .clinic-contact { font-size: 9px; color: #666; margin-top: 1px; }
    .clinic-hours { font-size: 8px; color: #888; margin-top: 2px; }
    .doc-title { font-size: 12px; font-weight: bold; margin-top: 6px; color: #2d3748; letter-spacing: 1px; text-transform: uppercase; }

    .patient-row { display: flex; justify-content: space-between; margin-bottom: 14px; }
    .patient-field { }
    .field-label { font-size: 9px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
    .field-value { font-size: 12px; font-weight: bold; margin-top: 1px; }

    .section-title { font-size: 10px; font-weight: bold; color: #4F8DD7; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; margin-top: 14px; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    thead tr { background: #4F8DD7; color: white; }
    thead th { padding: 6px 8px; text-align: center; font-size: 10px; font-weight: 600; }
    thead th.left { text-align: left; }
    tbody tr { border-bottom: 1px solid #e8e8e8; }
    tbody td { padding: 5px 8px; font-size: 10px; text-align: center; }
    tbody td.label { text-align: left; font-weight: bold; }
    td.empty { color: #bbb; }

    .remarks-section { margin-top: 14px; margin-bottom: 14px; }
    .remarks-label { font-size: 9px; font-weight: bold; color: #555; text-transform: uppercase; margin-bottom: 4px; }
    .remarks-content { font-size: 10px; min-height: 30px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }

    .footer { margin-top: 24px; border-top: 1px solid #e8e8e8; padding-top: 10px; }
    .signature-area { display: flex; justify-content: flex-end; }
    .signature-box { text-align: center; width: 180px; }
    .signature-line { border-bottom: 1px solid #4a5568; margin-bottom: 4px; height: 35px; }
    .signature-name { font-size: 10px; font-weight: bold; }
    .signature-title { font-size: 9px; color: #888; }
</style>
</head>
<body>
<div class="page">

    <div class="header">
        <div class="clinic-name">{{ $clinic['name'] ?? 'Padilla Optical Clinic' }}</div>
        <div class="clinic-address">{{ $clinic['address'] ?? '' }}</div>
        <div class="clinic-contact">
            Tel: {{ $clinic['telephone'] ?? '' }} · Mobile: {{ $clinic['mobile'] ?? '' }}
        </div>
        @if (!empty($clinicHours))
        <div class="clinic-hours">{{ $clinicHours }}</div>
        @endif
    </div>

    <div class="patient-row">
        <div class="patient-field">
            <div class="field-label">Name</div>
            <div class="field-value">{{ $prescription->patient?->full_name ?? '—' }}</div>
        </div>
        <div class="patient-field">
            <div class="field-label">Date</div>
            <div class="field-value">{{ $prescription->prescribed_at?->format('M j, Y') ?? '—' }}</div>
        </div>
    </div>

    <div class="section-title">Prescription</div>
    <table>
        <thead>
            <tr>
                <th class="left" style="width: 22%;"></th>
                <th style="width: 26%;">Value</th>
                <th style="width: 26%;">SPH</th>
                <th style="width: 26%;">CX</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="label">O.D.</td>
                <td>{{ $prescription->main_od_value ?? '—' }}</td>
                <td>{{ $prescription->main_od_sphere ?? '—' }}</td>
                <td>{{ $prescription->main_od_cylinder ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">O.S.</td>
                <td>{{ $prescription->main_os_value ?? '—' }}</td>
                <td>{{ $prescription->main_os_sphere ?? '—' }}</td>
                <td>{{ $prescription->main_os_cylinder ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    @if ($prescription->add_od_value || $prescription->add_od_sphere || $prescription->add_od_cylinder || $prescription->add_os_value || $prescription->add_os_sphere || $prescription->add_os_cylinder)
    <div class="section-title">ADD</div>
    <table>
        <thead>
            <tr>
                <th class="left" style="width: 22%;"></th>
                <th style="width: 26%;">Value</th>
                <th style="width: 26%;">SPH</th>
                <th style="width: 26%;">CX</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="label">O.D.</td>
                <td>{{ $prescription->add_od_value ?? '—' }}</td>
                <td>{{ $prescription->add_od_sphere ?? '—' }}</td>
                <td>{{ $prescription->add_od_cylinder ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">O.S.</td>
                <td>{{ $prescription->add_os_value ?? '—' }}</td>
                <td>{{ $prescription->add_os_sphere ?? '—' }}</td>
                <td>{{ $prescription->add_os_cylinder ?? '—' }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    @if ($prescription->remarks)
    <div class="remarks-section">
        <div class="remarks-label">Remarks</div>
        <div class="remarks-content">{{ $prescription->remarks }}</div>
    </div>
    @endif

    <div class="footer">
        <div class="signature-area">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-name">{{ $prescription->author?->name ?? 'Attending Optometrist' }}</div>
                <div class="signature-title">Optometrist</div>
            </div>
        </div>
    </div>

</div>
</body>
</html>
