<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingResource;
use App\Models\Billing;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BillingController extends Controller
{
    /**
     * Show a single billing record, with payment history.
     * Only the customer who owns the billing may view it.
     */
    public function show(Request $request, Billing $billing): BillingResource|JsonResponse
    {
        abort_unless($billing->customer_id === $request->user()->id, 403);

        $billing->load(['status', 'items', 'payments.status', 'payments.paymentMethod']);

        return new BillingResource($billing);
    }

    /**
     * Download billing receipt as PDF.
     * Only the customer who owns the billing may download it.
     */
    public function receipt(Request $request, Billing $billing, PdfService $pdf): Response
    {
        abort_unless($billing->customer_id === $request->user()->id, 403);

        return $pdf->billingReceipt($billing);
    }
}
