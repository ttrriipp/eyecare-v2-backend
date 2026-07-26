<?php

namespace App\Http\Controllers\Api;

use App\Actions\Audit\CreateAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFeedbackRequest;
use App\Http\Resources\FeedbackResource;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FeedbackController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $feedback = Feedback::query()
            ->where('patient_id', $patient->id)
            ->latest()
            ->get();

        return FeedbackResource::collection($feedback);
    }

    public function show(Request $request, Feedback $feedback): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $feedback->patient_id === $patient->id, 404);

        return response()->json(['data' => FeedbackResource::make($feedback)]);
    }

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 422, 'No patient record linked to this account.');

        $feedback = Feedback::query()->create([
            ...$request->validated(),
            'patient_id' => $patient->id,
        ]);

        app(CreateAuditLog::class)->handle(
            subject: $feedback,
            action: 'feedback.submitted',
            actorId: $request->user()->id,
        );

        return response()->json([
            'data' => FeedbackResource::make($feedback),
        ], 201);
    }
}
