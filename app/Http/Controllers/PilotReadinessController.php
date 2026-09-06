<?php

namespace App\Http\Controllers;

use App\Services\Deployment\PilotDeploymentPreflight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PilotReadinessController extends Controller
{
    public function __construct(
        private readonly PilotDeploymentPreflight $preflight,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $configuredToken = (string) config('deployment.readiness_token', '');
        $providedToken = (string) $request->header('X-Readiness-Token', '');

        if ($configuredToken === ''
            || $providedToken === ''
            || ! hash_equals($configuredToken, $providedToken)) {
            return $this->response('unready', 404);
        }

        $result = $this->preflight->readiness();

        return $this->response(
            $result['passed'] ? 'ready' : 'unready',
            $result['passed'] ? 200 : 503,
        );
    }

    private function response(string $status, int $statusCode): JsonResponse
    {
        return response()
            ->json(['status' => $status], $statusCode)
            ->header('Cache-Control', 'no-store');
    }
}
