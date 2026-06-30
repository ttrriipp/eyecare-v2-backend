<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 7px; color: #1a1a1a; }
    .card { padding: 6px 8px; width: 100%; }

    .header { display: flex; justify-content: space-between; align-items: center;
              border-bottom: 1px solid #4F8DD7; padding-bottom: 3px; margin-bottom: 4px; }
    .clinic { font-size: 8px; font-weight: bold; color: #4F8DD7; }
    .rx-label { font-size: 14px; font-weight: bold; color: #4F8DD7; font-style: italic; }

    .patient { margin-bottom: 3px; }
    .patient-name { font-size: 8px; font-weight: bold; }
    .patient-meta { font-size: 6px; color: #666; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 3px; }
    thead th { background: #4F8DD7; color: white; padding: 2px 3px; font-size: 6px; text-align: center; }
    thead th.left { text-align: left; }
    tbody td { padding: 2px 3px; font-size: 6.5px; text-align: center; border-bottom: 1px solid #eee; }
    tbody td.label { text-align: left; font-weight: bold; }

    .footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 3px; }
    .pd { font-size: 7px; }
    .pd span { font-weight: bold; font-size: 8px; }
    .validity { font-size: 6px; color: #666; text-align: right; }
    .sig-line { border-bottom: 1px solid #555; width: 55px; margin-bottom: 1px; }
    .sig-text { font-size: 5.5px; color: #888; }
</style>
</head>
<body>
<div class="card">
    <div class="header">
        <div>
            <div class="clinic">Padilla Optical Clinic</div>
            <div style="font-size: 5.5px; color: #888;">Eyecare Management System</div>
        </div>
        <div class="rx-label">Rx</div>
    </div>

    <div class="patient">
        <div class="patient-name">{{ $prescription->customer?->name ?? '—' }}</div>
        <div class="patient-meta">
            Prescribed: {{ $prescription->prescribed_at?->format('M j, Y') ?? '—' }}
            @if($prescription->expires_at)
                &bull; Expires: {{ $prescription->expires_at->format('M j, Y') }}
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="left" style="width: 18%;">Eye</th>
                <th>SPH</th>
                <th>CYL</th>
                <th>Axis</th>
                <th>Add</th>
                <th>Prism</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="label">OD (R)</td>
                <td>{{ $prescription->od_sphere ?? '—' }}</td>
                <td>{{ $prescription->od_cylinder ?? '—' }}</td>
                <td>{{ $prescription->od_axis ?? '—' }}</td>
                <td>{{ $prescription->od_add ?? '—' }}</td>
                <td>{{ $prescription->od_prism ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">OS (L)</td>
                <td>{{ $prescription->os_sphere ?? '—' }}</td>
                <td>{{ $prescription->os_cylinder ?? '—' }}</td>
                <td>{{ $prescription->os_axis ?? '—' }}</td>
                <td>{{ $prescription->os_add ?? '—' }}</td>
                <td>{{ $prescription->os_prism ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <div class="pd">PD: <span>{{ $prescription->pd ?? '—' }} mm</span></div>
        <div>
            <div class="sig-line"></div>
            <div class="sig-text">{{ $prescription->createdBy?->name ?? 'Optometrist' }}</div>
        </div>
    </div>
</div>
</body>
</html>
