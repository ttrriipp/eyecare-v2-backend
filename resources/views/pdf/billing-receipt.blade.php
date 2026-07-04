<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
    .page { padding: 32px 40px; }

    /* Header */
    .header { text-align: center; margin-bottom: 24px; border-bottom: 2px solid #4F8DD7; padding-bottom: 16px; }
    .clinic-name { font-size: 22px; font-weight: bold; color: #4F8DD7; }
    .clinic-sub { font-size: 11px; color: #666; margin-top: 2px; }

    /* Meta */
    .meta { display: flex; justify-content: space-between; margin-bottom: 20px; }
    .meta-left, .meta-right { width: 48%; }
    .meta-label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
    .meta-value { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .meta-secondary { font-size: 11px; color: #555; margin-top: 1px; }

    /* Items table */
    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    thead tr { background: #4F8DD7; color: white; }
    thead th { padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 600; }
    thead th.right { text-align: right; }
    tbody tr { border-bottom: 1px solid #e8e8e8; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody td { padding: 7px 10px; font-size: 11px; }
    tbody td.right { text-align: right; }

    /* Totals */
    .totals { width: 240px; margin-left: auto; margin-bottom: 20px; }
    .totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 11px; }
    .totals-row.total { border-top: 2px solid #4F8DD7; margin-top: 4px; padding-top: 8px; font-weight: bold; font-size: 13px; color: #4F8DD7; }
    .totals-row.balance { color: #e53e3e; font-weight: bold; }
    .totals-row.paid-label { color: #38a169; }

    /* Payments */
    .section-title { font-size: 11px; font-weight: bold; color: #4F8DD7; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; margin-top: 16px; }

    /* Status badge */
    .badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: bold; }
    .badge-paid { background: #c6f6d5; color: #276749; }
    .badge-partial { background: #fefcbf; color: #7b6b00; }
    .badge-issued { background: #e2e8f0; color: #4a5568; }
    .badge-voided { background: #fed7d7; color: #c53030; }

    /* Footer */
    .footer { margin-top: 32px; border-top: 1px solid #e8e8e8; padding-top: 12px; text-align: center; font-size: 10px; color: #999; }
</style>
</head>
<body>
<div class="page">

    <div class="header">
        <div class="clinic-name">Padilla Optical Clinic</div>
        <div class="clinic-sub">Eyecare Management System</div>
    </div>

    <div class="meta">
        <div class="meta-left">
            <div class="meta-label">Billing Number</div>
            <div class="meta-value">{{ $billing->billing_number }}</div>
            <div class="meta-secondary">
                Status:
                @php $statusName = $billing->status?->name ?? 'issued'; @endphp
                <span class="badge badge-{{ $statusName === 'partially_paid' ? 'partial' : $statusName }}">
                    {{ ucwords(str_replace('_', ' ', $statusName)) }}
                </span>
            </div>
        </div>
        <div class="meta-right" style="text-align: right;">
            <div class="meta-label">Patient</div>
            <div class="meta-value">{{ $billing->customer?->name ?? '—' }}</div>
            <div class="meta-secondary">Issued: {{ $billing->issued_at ? $billing->issued_at->format('M j, Y') : $billing->created_at->format('M j, Y') }}</div>
        </div>
    </div>

    {{-- Line Items --}}
    <table>
        <thead>
            <tr>
                <th style="width: 50%;">Description</th>
                <th style="width: 10%;" class="right">Qty</th>
                <th style="width: 20%;" class="right">Unit Price</th>
                <th style="width: 20%;" class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($billing->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">₱{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="right">₱{{ number_format((float) $item->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; color:#888;">No items</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totals --}}
    <div class="totals">
        <div class="totals-row">
            <span>Subtotal</span>
            <span>₱{{ number_format((float) $billing->subtotal, 2) }}</span>
        </div>
        @if ((float) $billing->discount_amount > 0)
        <div class="totals-row">
            <span>Discount {{ $billing->discountType ? "({$billing->discountType->name})" : '' }}</span>
            <span>− ₱{{ number_format((float) $billing->discount_amount, 2) }}</span>
        </div>
        @endif
        <div class="totals-row total">
            <span>Total</span>
            <span>₱{{ number_format((float) $billing->total_amount, 2) }}</span>
        </div>
        <div class="totals-row paid-label">
            <span>Amount Paid</span>
            <span>₱{{ number_format((float) $billing->amount_paid, 2) }}</span>
        </div>
        @if ((float) $billing->balance_due > 0)
        <div class="totals-row balance">
            <span>Balance Due</span>
            <span>₱{{ number_format((float) $billing->balance_due, 2) }}</span>
        </div>
        @endif
    </div>

    {{-- Payments --}}
    @if ($billing->payments->isNotEmpty())
    <div class="section-title">Payment History</div>
    <table>
        <thead>
            <tr>
                <th>Method</th>
                <th>Reference</th>
                <th class="right">Amount</th>
                <th class="right">Date</th>
                <th class="right">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($billing->payments as $payment)
            <tr>
                <td>{{ $payment->paymentMethod?->name ?? '—' }}</td>
                <td>{{ $payment->reference_number ?? '—' }}</td>
                <td class="right">₱{{ number_format((float) $payment->amount, 2) }}</td>
                <td class="right">{{ $payment->paid_at ? $payment->paid_at->format('M j, Y') : '—' }}</td>
                <td class="right">{{ ucfirst($payment->status?->name ?? '—') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">
        Thank you for choosing Padilla Optical Clinic &bull; Generated {{ now()->format('M j, Y g:i A') }}
    </div>

</div>
</body>
</html>
