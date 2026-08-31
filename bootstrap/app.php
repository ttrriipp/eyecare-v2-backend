<?php

use App\Exceptions\OtpRateLimitReached;
use App\Http\Middleware\RequireActivePatientLink;
use App\Http\Middleware\RequireStepUpToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
        $middleware->alias([
            'require.patient.link' => RequireActivePatientLink::class,
            'require.step-up' => RequireStepUpToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (OtpRateLimitReached $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            Log::warning('OTP rate limit reached', [
                'route' => $request->route()?->uri(),
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'ip' => $request->ip(),
                'retry_after_seconds' => $exception->retryAfterSeconds,
                'error_code' => 'OTP_RATE_LIMIT_REACHED',
            ]);

            return response()->json([
                'error' => [
                    'code' => 'OTP_RATE_LIMIT_REACHED',
                    'message' => $exception->getMessage(),
                    'retry_after_seconds' => $exception->retryAfterSeconds,
                ],
            ], 429, [
                'Retry-After' => (string) $exception->retryAfterSeconds,
            ]);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $headers = $exception->getHeaders();
            $retryAfter = (int) ($headers['Retry-After'] ?? 0);

            Log::warning('API rate limit reached', [
                'route' => $request->route()?->uri(),
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'ip' => $request->ip(),
                'retry_after_seconds' => $retryAfter,
                'error_code' => 'API_RATE_LIMIT_REACHED',
            ]);

            return response()->json([
                'error' => [
                    'code' => 'API_RATE_LIMIT_REACHED',
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after_seconds' => $retryAfter,
                ],
            ], 429, $headers);
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $request->is('api/*') || $response->getStatusCode() < 500) {
                return $response;
            }

            return response()->json([
                'error' => [
                    'code' => 'INTERNAL_SERVER_ERROR',
                    'message' => 'An unexpected server error occurred.',
                ],
            ], 500);
        });
    })->create();
