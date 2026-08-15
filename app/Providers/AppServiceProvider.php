<?php

namespace App\Providers;

use App\Listeners\RecordAuthenticationAudit;
use App\Models\Brand;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Models\User;
use App\Observers\CatalogObserver;
use App\Observers\PatientObserver;
use App\Observers\ProductObserver;
use App\Observers\UserObserver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Product::observe(ProductObserver::class);
        foreach ([Brand::class, ProductCategory::class, ProductVariant::class, LensCategory::class, LensOption::class, Service::class] as $catalogModel) {
            $catalogModel::observe(CatalogObserver::class);
        }
        Patient::observe(PatientObserver::class);
        User::observe(UserObserver::class);

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by($request->input('email')),
            ];
        });

        RateLimiter::for('api-account', fn (Request $request): Limit => $this->apiLimit($request, 120));
        RateLimiter::for('api-profile', fn (Request $request): Limit => $this->apiLimit($request, 300));
        RateLimiter::for('api-clinical', fn (Request $request): Limit => $this->apiLimit($request, 120));
        RateLimiter::for('conversation-send', fn (Request $request): Limit => $this->apiLimit($request, 10));
        RateLimiter::for('invitation-otp', fn (Request $request): Limit => $this->apiLimit(
            $request,
            5,
            'OTP_RATE_LIMIT_REACHED',
            'Too many invitation OTP requests. Please try again later.',
        ));
        RateLimiter::for('invitation-acceptance', fn (Request $request): Limit => $this->apiLimit(
            $request,
            120,
            'INVITATION_RATE_LIMIT_REACHED',
            'Too many invitation acceptance requests. Please try again later.',
        ));

        Password::defaults(fn () => app()->isProduction()
            ? Password::min(12)->mixedCase()->numbers()
            : Password::min(8));

        Event::listen(Login::class, [RecordAuthenticationAudit::class, 'handleLogin']);
        Event::listen(Logout::class, [RecordAuthenticationAudit::class, 'handleLogout']);
        Event::listen(Failed::class, [RecordAuthenticationAudit::class, 'handleFailed']);
    }

    private function apiLimit(
        Request $request,
        int $attempts,
        string $code = 'API_RATE_LIMIT_REACHED',
        string $message = 'Too many requests. Please try again later.',
    ): Limit {
        return Limit::perMinute($attempts)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (Request $request, array $headers): JsonResponse => $this->rateLimitResponse(
                request: $request,
                headers: $headers,
                code: $code,
                message: $message,
            ));
    }

    /**
     * @param  array<string, string|int>  $headers
     */
    private function rateLimitResponse(Request $request, array $headers, string $code, string $message): JsonResponse
    {
        $retryAfter = (int) ($headers['Retry-After'] ?? 0);

        Log::warning('API rate limit reached', [
            'route' => $request->route()?->uri(),
            'route_name' => $request->route()?->getName(),
            'method' => $request->method(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip' => $request->ip(),
            'retry_after_seconds' => $retryAfter,
            'error_code' => $code,
        ]);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'retry_after_seconds' => $retryAfter,
            ],
        ], 429, $headers);
    }
}
