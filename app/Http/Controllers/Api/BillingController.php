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
     * Only the customer who owns the billing may view it.
     */
    public function show(Request $request, Billing $billing): BillingResource|JsonResponse
    {
        abort_unless($billing->customer_id === $request->user()->id, 403);

        $billing->load(['status', 'items', 'payments.status']);

        return new BillingResource($billing);
    }
}
