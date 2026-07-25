<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QuotationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $quotations = Quotation::query()
            ->where('patient_id', $patient->id)
            ->with(['revisions.items'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return QuotationResource::collection($quotations);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $quotation->patient_id === $patient->id, 404);

        $quotation->load('revisions.items');

        return response()->json([
            'data' => QuotationResource::make($quotation),
        ]);
    }
}
