<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingResource;
use App\Models\Billing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    /**
     * Show a single billing record, with payment history.
     * Only the customer who owns the linked order may view this.
     */
    public function show(Request $request, Billing $billing): BillingResource|JsonResponse
    {
        $customerId = $billing->billable?->customer_id;

        abort_unless($customerId === $request->user()->id, 403);

        $billing->load(['status', 'billable', 'payments.status']);

        return new BillingResource($billing);
    }
}
