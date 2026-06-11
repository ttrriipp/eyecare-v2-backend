<?php

namespace App\Http\Controllers\Api;

use App\Actions\Audit\CreateAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFeedbackRequest;
use App\Http\Resources\FeedbackResource;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $feedback = Feedback::query()->create([
            ...$request->validated(),
            'customer_id' => $request->user()->id,
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
