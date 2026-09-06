<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\AuthenticatePilotParticipant;
use App\Actions\PatientAccounts\LoadPatientAccountContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PilotParticipantLoginRequest;
use App\Http\Resources\PatientAccountResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PilotParticipantLoginController extends Controller
{
    public function __invoke(
        PilotParticipantLoginRequest $request,
        AuthenticatePilotParticipant $authenticate,
        LoadPatientAccountContext $loadPatientAccountContext,
    ): JsonResponse {
        if (! $authenticate->isAvailableAt()) {
            throw new NotFoundHttpException;
        }

        $result = $authenticate->handle(
            participantCode: $request->validated('participant_code'),
            password: $request->validated('password'),
            deviceName: $request->validated('device_name'),
            installationId: $request->validated('installation_id'),
        );

        $user = $loadPatientAccountContext->handle($result['user']);

        return response()->json([
            'data' => [
                'step_up_required' => false,
                'token' => $result['token'],
                'user' => PatientAccountResource::make($user),
            ],
        ]);
    }
}
