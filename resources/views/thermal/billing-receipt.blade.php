<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt {{ $billing->or_number ?? $billing->billing_number }}</title>
<style>
    @media print {
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body { margin: 0; }
        .no-print { display: none !important; }
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Courier New', Courier, monospace;
        font-size: 12px;
        color: #000;
        width: 72mm;
        margin: 0 auto;
        padding: 4mm;
    }

    .center { text-align: center; }
    .right { text-align: right; }
    .bold { font-weight: bold; }
    .divider { border-top: 1px dashed #000; margin: 3mm 0; }
    .small { font-size: 10px; }
    .large { font-size: 14px; font-weight: bold; }

    table { width: 100%; border-collapse: collapse; }
    td { padding: 1mm 0; vertical-align: top; }
    td.qty { width: 8mm; }
    td.desc { }
    td.amount { width: 18mm; text-align: right; }

    .total-row td { font-weight: bold; border-top: 1px solid #000; padding-top: 2mm; }
    .balance-row td { font-size: 14px; font-weight: bold; }

    .print-btn {
        display: block;
        margin: 8mm auto;
        padding: 2mm 8mm;
        background: #4F8DD7;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
    }
</style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">🖨 Print Receipt</button>

{{-- Clinic Header --}}
<div class="center">
    <div class="bold large">Padilla Optical Clinic</div>
    <div class="small">Eyecare Management System</div>
</div>

<div class="divider"></div>

{{-- Receipt Info --}}
<table>
    <tr>
        <td class="bold">OR #:</td>
        <td class="right">{{ $billing->or_number ?? '—' }}</td>
    </tr>
    <tr>
        <td class="small">Billing #:</td>
        <td class="right small">{{ $billing->billing_number }}</td>
    </tr>
    <tr>
        <td>Date:</td>
        <td class="right">{{ ($billing->issued_at ?? $billing->created_at)->format('m/d/Y H:i') }}</td>
    </tr>
    <tr>
        <td>Patient:</td>
        <td class="right">{{ $billing->customer?->name ?? '—' }}</td>
    </tr>
</table>

<div class="divider"></div>

{{-- Line Items --}}
<table>
    <tr>
        <td class="qty bold">Qty</td>
        <td class="bold">Item</td>
        <td class="amount bold">Amount</td>
    </tr>
    @foreach($billing->items as $item)
    <tr>
        <td class="qty">{{ $item->quantity }}x</td>
        <td>{{ $item->description }}</td>
        <td class="amount">₱{{ number_format((float) $item->amount, 2) }}</td>
    </tr>
    @endforeach
</table>

<div class="divider"></div>

{{-- Totals --}}
<table>
    <tr>
        <td>Subtotal</td>
        <td class="right">₱{{ number_format((float) $billing->subtotal, 2) }}</td>
    </tr>
    @if((float) $billing->discount_amount > 0)
    <tr>
        <td>Discount</td>
        <td class="right">-₱{{ number_format((float) $billing->discount_amount, 2) }}</td>
    </tr>
    @endif
    <tr class="total-row">
        <td>TOTAL</td>
        <td class="right">₱{{ number_format((float) $billing->total_amount, 2) }}</td>
    </tr>
    <tr>
        <td>Paid</td>
        <td class="right">₱{{ number_format((float) $billing->amount_paid, 2) }}</td>
    </tr>
    @if((float) $billing->balance_due > 0)
    <tr class="balance-row">
        <td>BALANCE</td>
        <td class="right">₱{{ number_format((float) $billing->balance_due, 2) }}</td>
    </tr>
    @endif
</table>

@if($billing->payments->isNotEmpty())
<div class="divider"></div>
<div class="small">
    @foreach($billing->payments->where('status.name', 'posted') as $payment)
    <table>
        <tr>
            <td>{{ $payment->paymentMethod?->name ?? 'Payment' }}</td>
            <td class="right">₱{{ number_format((float) $payment->amount, 2) }}</td>
        </tr>
        @if($payment->reference_number)
        <tr><td colspan="2" class="small">Ref: {{ $payment->reference_number }}</td></tr>
        @endif
    </table>
    @endforeach
</div>
@endif

<div class="divider"></div>

<div class="center small">
    Thank you for visiting!<br>
    Padilla Optical Clinic<br>
    {{ now()->format('m/d/Y H:i') }}
</div>

</body>
</html>
