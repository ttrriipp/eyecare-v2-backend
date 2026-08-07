<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $request->validate([
            'filter' => ['nullable', 'string', Rule::in(['current', 'history'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $filter = $request->input('filter', 'current');

        $quotations = Quotation::query()
            ->where('patient_id', $patient->id)
            ->where('status', '!=', QuotationStatus::Draft)
            ->when($filter === 'current', fn ($query) => $query->where('status', QuotationStatus::Presented))
            ->when($filter === 'history', fn ($query) => $query->whereIn('status', [
                QuotationStatus::Accepted,
                QuotationStatus::Declined,
                QuotationStatus::Expired,
            ]))
            ->with(['items'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return QuotationResource::collection($quotations);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $quotation->patient_id === $patient->id, 404);
        abort_if($quotation->status === QuotationStatus::Draft, 404);

        $quotation->load('items');

        return response()->json([
            'data' => QuotationResource::make($quotation),
        ]);
    }
}
