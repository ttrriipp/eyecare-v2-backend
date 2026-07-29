<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListEyewearRequest;
use App\Http\Resources\EyewearDetailResource;
use App\Http\Resources\EyewearSummaryResource;
use App\Services\Eyewear\FindPatientEyewear;
use App\Services\Eyewear\ListPatientEyewear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EyewearController extends Controller
{
    public function index(ListEyewearRequest $request, ListPatientEyewear $listEyewear): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $filter = $request->input('filter', 'current');
        $perPage = $request->integer('per_page', 15);

        $paginator = $listEyewear->handle($patient, $filter, $perPage);

        return response()->json([
            'data' => EyewearSummaryResource::collection($paginator),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'links' => [],
                'path' => $request->url(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, string $key, FindPatientEyewear $findEyewear): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $eyewear = $findEyewear->handle($patient, $key);

        abort_unless($eyewear !== null, 404);

        return response()->json([
            'data' => EyewearDetailResource::make($eyewear),
        ]);
    }
}
