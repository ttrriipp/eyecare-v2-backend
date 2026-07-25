<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        return Invoice::query()
            ->where('patient_id', $patient->id)
            ->with(['items', 'payments' => fn ($q) => $q->where('status', 'posted')])
            ->latest()
            ->paginate($request->integer('per_page', 15));
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $invoice->patient_id === $patient->id, 404);

        $invoice->load(['items', 'payments' => fn ($q) => $q->where('status', 'posted')]);

        return response()->json(['data' => $invoice]);
    }
}
