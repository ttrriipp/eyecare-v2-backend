<?php

namespace App\Services\Deployment;

use App\Http\Middleware\RejectPhoneAuthenticationDuringPilot;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class PilotDeploymentPreflight
{
    /**
     * Every route that can issue or verify a phone/SMS OTP must stay hidden
     * while participant-code mode is active.
     *
     * @var list<array{method: string, uri: string}>
     */
    private const PhoneDependentRoutes = [
        ['method' => 'POST', 'uri' => 'api/v1/auth/registration/otp'],
        ['method' => 'POST', 'uri' => 'api/v1/auth/registration/verify'],
        ['method' => 'POST', 'uri' => 'api/v1/auth/register'],
        ['method' => 'POST', 'uri' => 'api/v1/auth/login'],
        ['method' => 'POST', 'uri' => 'api/v1/auth/login/verify'],
        ['method' => 'POST', 'uri' => 'api/v1/auth/password-recovery/otp'],
        ['method' => 'POST', 'uri' => 'api/v1/auth/password-recovery/verify'],
        ['method' => 'POST', 'uri' => 'api/v1/auth/step-up/otp'],
        ['method' => 'POST', 'uri' => 'api/v1/auth/step-up/verify'],
        ['method' => 'POST', 'uri' => 'api/v1/account/contacts/otp'],
        ['method' => 'POST', 'uri' => 'api/v1/account/contacts/verify'],
        ['method' => 'POST', 'uri' => 'api/v1/patient-invitations/acceptance/otp'],
        ['method' => 'POST', 'uri' => 'api/v1/patient-invitations/accept'],
    ];

    /**
     * Run all production configuration and infrastructure checks.
     *
     * @return array{passed: bool, checks: array<string, array{passed: bool, message: string}>}
     */
    public function run(): array
    {
        $checks = [];

        $this->addCheck($checks, 'app.environment', fn (): ?string => config('app.env') === 'production'
            ? null
            : 'APP_ENV must be production.');
        $this->addCheck($checks, 'app.debug', fn (): ?string => config('app.debug') === false
            ? null
            : 'APP_DEBUG must be disabled.');
        $this->addCheck($checks, 'app.url', fn (): ?string => $this->secureUrl(config('app.url'), true)
            ? null
            : 'APP_URL must be an HTTPS URL without credentials or localhost.');
        $this->addCheck($checks, 'app.key', fn (): ?string => $this->strongKey(config('app.key'))
            ? null
            : 'APP_KEY must contain a stable encryption key.');
        $this->addCheck($checks, 'contact_lookup_key', fn (): ?string => $this->contactLookupKey()
            ? null
            : 'CONTACT_LOOKUP_KEY must be a dedicated stable key.');

        $deploymentMode = config('deployment.mode', 'pilot');
        $this->addCheck($checks, 'deployment.mode', fn (): ?string => in_array($deploymentMode, ['demo', 'pilot'], true)
            ? null
            : 'DEPLOYMENT_MODE must be either demo or pilot.');

        if ($deploymentMode === 'demo') {
            $this->addCheck($checks, 'pilot.disabled', fn (): ?string => config('capstone_pilot.enabled') === false
                ? null
                : 'CAPSTONE_PILOT_ENABLED must be disabled in demo mode.');
            $this->addCheck($checks, 'pilot.participant_accounts', fn (): ?string => $this->pilotAccountsAbsent()
                ? null
                : 'Pilot participant accounts must not exist in demo mode.');
        } else {
            $this->addCheck($checks, 'pilot.enabled', fn (): ?string => config('capstone_pilot.enabled') === true
                ? null
                : 'CAPSTONE_PILOT_ENABLED must be explicitly enabled.');
            $this->addCheck($checks, 'pilot.expiry', fn (): ?string => $this->pilotExpiry() !== null
                ? null
                : 'CAPSTONE_PILOT_EXPIRES_AT must be a future timestamp.');
            $this->addCheck($checks, 'pilot.participant_limit', fn (): ?string => (int) config('capstone_pilot.participant_limit', 0) === 75
                ? null
                : 'The pilot participant limit must be exactly 75.');
        }

        $this->addCheck($checks, 'session.cookies', fn (): ?string => $this->secureSessionCookies()
            ? null
            : 'Session cookies must be secure, HTTP-only, and use a safe SameSite mode.');
        $this->addCheck($checks, 'deployment.trusted_hosts', fn (): ?string => $this->trustedHosts()
            ? null
            : 'At least one concrete trusted host is required.');
        $this->addCheck($checks, 'deployment.trusted_proxies', fn (): ?string => $this->trustedProxies()
            ? null
            : 'At least one trusted proxy or an explicit provider proxy wildcard is required.');
        $this->addCheck($checks, 'deployment.allowed_origins', fn (): ?string => $this->allowedOrigins()
            ? null
            : 'Allowed frontend origins must be explicit HTTPS origins.');
        $this->addCheck($checks, 'deployment.cors', fn (): ?string => $this->corsConfiguration()
            ? null
            : 'Production CORS must use the same explicit origins as deployment configuration.');

        $this->addCheck($checks, 'runtime.database', fn (): ?string => $this->databaseConfiguration()
            ? null
            : 'A configured non-empty database connection is required.');
        $this->addCheck($checks, 'runtime.cache', fn (): ?string => $this->cacheConfiguration()
            ? null
            : 'Cache must use a configured shared persistent driver.');
        $this->addCheck($checks, 'runtime.queue', fn (): ?string => $this->queueConfiguration()
            ? null
            : 'Queue must use a configured asynchronous driver.');
        $this->addCheck($checks, 'runtime.session', fn (): ?string => $this->sessionConfiguration()
            ? null
            : 'Session must use a configured persistent driver.');
        $this->addCheck($checks, 'runtime.mail', fn (): ?string => $this->mailConfiguration()
            ? null
            : 'Mail must use a configured delivery transport and sender address.');
        $this->addCheck($checks, 'runtime.logging', fn (): ?string => $this->loggingConfiguration()
            ? null
            : 'A configured non-null logging destination is required.');
        $this->addCheck($checks, 'runtime.scheduler', fn (): ?string => $this->schedulerConfiguration()
            ? null
            : 'Required once-per-minute and retention scheduler events are missing.');

        $this->addCheck($checks, 'storage.message_attachments', fn (): ?string => $this->privateDisk(
            config('filesystems.message_attachments_disk'),
        ) ? null : 'Message attachments must use a configured private disk.');
        $this->addCheck($checks, 'storage.ar_quarantine', fn (): ?string => $this->privateDisk(
            config('ar.assets.quarantine_disk'),
        ) ? null : 'AR quarantine assets must use a configured private disk.');
        $this->addCheck($checks, 'storage.ar_published', fn (): ?string => $this->publicDisk(
            config('ar.assets.published_disk'),
        ) ? null : 'Published AR assets must use a configured public disk.');
        $catalogDisk = config('filesystems.catalog_disk');
        $this->addCheck($checks, 'storage.catalog', fn (): ?string => is_string($catalogDisk)
            && $this->publicDisk($catalogDisk)
            ? null
            : 'Published catalog assets must use a configured public disk.');
        $this->addCheck($checks, 'storage.ar_base_url', fn (): ?string => $this->secureUrl(
            config('ar.assets.base_url'),
            true,
        ) ? null : 'AR_ASSET_BASE_URL must be an HTTPS URL without localhost.');

        $this->addCheck($checks, 'deployment.release_id', fn (): ?string => $this->nonEmptyIdentifier(
            config('deployment.release_id'),
        ) ? null : 'A release identifier is required.');
        $this->addCheck($checks, 'deployment.readiness_token', fn (): ?string => $this->strongSecret(
            config('deployment.readiness_token'),
        ) ? null : 'A dedicated readiness token is required.');
        $this->addCheck($checks, 'deployment.uptime_alert', fn (): ?string => $this->secureUrl(
            config('deployment.uptime_alert_url'),
            true,
        ) ? null : 'An HTTPS uptime alert destination is required.');
        $this->addCheck($checks, 'policy.urls', fn (): ?string => $this->policyUrls()
            ? null
            : 'Privacy-policy and terms URLs must be real HTTPS URLs.');

        $this->addCheck($checks, 'sms.phone_routes', fn (): ?string => $this->phoneRoutesAreGated()
            ? null
            : 'Every phone/SMS-dependent route must carry the pilot gate.');
        $this->addCheck($checks, 'sms.provider', fn (): ?string => $this->smsProviderConfiguration()
            ? null
            : 'The selected SMS adapter must have reviewed endpoint, credential, sender, timeout, and retry settings.');

        $this->addInfrastructureChecks($checks);

        return $this->summarize($checks);
    }

    /**
     * Check only dependencies needed by the protected readiness endpoint.
     *
     * @return array{passed: bool, checks: array<string, array{passed: bool, message: string}>}
     */
    public function readiness(): array
    {
        $checks = [];
        $this->addInfrastructureChecks($checks);

        return $this->summarize($checks);
    }

    /**
     * @param  array<string, array{passed: bool, message: string}>  &$checks
     */
    private function addInfrastructureChecks(array &$checks): void
    {
        $this->addCheck($checks, 'database', function (): ?string {
            DB::connection()->getPdo();
            DB::select('select 1');

            return null;
        });

        $this->addCheck($checks, 'cache', function (): ?string {
            $key = 'pilot-readiness:'.Str::uuid();

            try {
                Cache::put($key, 'ready', 10);

                return Cache::get($key) === 'ready' ? null : 'Cache probe did not round-trip.';
            } finally {
                Cache::forget($key);
            }
        });

        $this->addCheck($checks, 'queue', function (): ?string {
            Queue::connection()->size();

            return null;
        });

        $this->addCheck($checks, 'storage', function (): ?string {
            $disk = Storage::disk((string) config('filesystems.message_attachments_disk'));
            $path = '.readiness/'.Str::uuid().'.probe';

            try {
                if (! $disk->put($path, 'ready', ['visibility' => 'private'])) {
                    return 'Storage probe could not write.';
                }

                return $disk->get($path) === 'ready' ? null : 'Storage probe did not round-trip.';
            } finally {
                try {
                    $disk->delete($path);
                } catch (Throwable) {
                    // Cleanup failure must not expose adapter details.
                }
            }
        });

        $this->addCheck($checks, 'scheduler', fn (): ?string => $this->schedulerConfiguration()
            ? null
            : 'Required scheduler events are missing.');
    }

    /**
     * @param  array<string, array{passed: bool, message: string}>  &$checks
     * @param  \Closure(): ?string  $check
     */
    private function addCheck(array &$checks, string $key, \Closure $check): void
    {
        try {
            $message = $check();
        } catch (Throwable) {
            $message = 'The check could not be completed.';
        }

        $checks[$key] = [
            'passed' => $message === null,
            'message' => $message ?? 'ok',
        ];
    }

    /**
     * @param  array<string, array{passed: bool, message: string}>  $checks
     * @return array{passed: bool, checks: array<string, array{passed: bool, message: string}>}
     */
    private function summarize(array $checks): array
    {
        return [
            'passed' => ! in_array(false, array_column($checks, 'passed'), true),
            'checks' => $checks,
        ];
    }

    private function contactLookupKey(): bool
    {
        $appKey = config('app.key');
        $lookupKey = config('patient_accounts.contact_lookup_key');

        return $this->strongSecret($lookupKey)
            && is_string($appKey)
            && ! hash_equals($appKey, (string) $lookupKey);
    }

    private function strongKey(mixed $key): bool
    {
        if (! is_string($key) || trim($key) === '') {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false && strlen($decoded) >= 32;
        }

        return strlen($key) >= 32;
    }

    private function strongSecret(mixed $secret): bool
    {
        return is_string($secret)
            && strlen(trim($secret)) >= 32
            && preg_match('/\\s/', $secret) !== 1;
    }

    private function nonEmptyIdentifier(mixed $identifier): bool
    {
        return is_string($identifier)
            && trim($identifier) !== ''
            && strlen($identifier) <= 128
            && preg_match('/[\\x00-\\x1F\\x7F]/', $identifier) !== 1;
    }

    private function secureSessionCookies(): bool
    {
        return config('session.secure') === true
            && config('session.http_only') === true
            && in_array(config('session.same_site'), ['lax', 'strict', 'none'], true);
    }

    private function trustedHosts(): bool
    {
        $hosts = $this->list(config('deployment.trusted_hosts'));

        if ($hosts === []) {
            return false;
        }

        foreach ($hosts as $host) {
            if ($host === '*' || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                return false;
            }
        }

        $applicationHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($applicationHost)
            && in_array(strtolower($applicationHost), array_map('strtolower', $hosts), true);
    }

    private function trustedProxies(): bool
    {
        $proxies = $this->list(config('deployment.trusted_proxies'));

        if ($proxies === []) {
            return false;
        }

        foreach ($proxies as $proxy) {
            if ($proxy === '*') {
                continue;
            }

            [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $maxPrefix = str_contains($address, ':') ? 128 : 32;
                if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > $maxPrefix)) {
                    return false;
                }

                continue;
            }

            if ($prefix !== null || preg_match('/^[A-Za-z0-9.-]+$/', $proxy) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function allowedOrigins(): bool
    {
        $origins = $this->list(config('deployment.allowed_origins'));

        if ($origins === []) {
            return false;
        }

        foreach ($origins as $origin) {
            if ($origin === '*' || ! $this->secureUrl($origin, true)) {
                return false;
            }
        }

        return true;
    }

    private function corsConfiguration(): bool
    {
        $origins = $this->list(config('deployment.allowed_origins'));
        $corsOrigins = $this->list(config('cors.allowed_origins'));

        sort($origins);
        sort($corsOrigins);

        return $origins !== []
            && $origins === $corsOrigins
            && ! in_array('*', $corsOrigins, true);
    }

    private function databaseConfiguration(): bool
    {
        $name = config('database.default');

        return is_string($name)
            && $name !== ''
            && is_array(config('database.connections.'.$name));
    }

    private function cacheConfiguration(): bool
    {
        $name = config('cache.default');
        $store = is_string($name) ? config('cache.stores.'.$name) : null;

        return is_array($store)
            && is_string($store['driver'] ?? null)
            && ! in_array($store['driver'], ['array', 'null'], true);
    }

    private function queueConfiguration(): bool
    {
        $name = config('queue.default');
        $connection = is_string($name) ? config('queue.connections.'.$name) : null;

        return is_array($connection)
            && is_string($connection['driver'] ?? null)
            && ! in_array($connection['driver'], ['sync', 'null'], true);
    }

    private function sessionConfiguration(): bool
    {
        $driver = config('session.driver');

        return is_string($driver)
            && $driver !== ''
            && ! in_array($driver, ['array', 'cookie'], true);
    }

    private function mailConfiguration(): bool
    {
        $name = config('mail.default');
        $mailer = is_string($name) ? config('mail.mailers.'.$name) : null;
        $address = config('mail.from.address');

        return is_array($mailer)
            && is_string($mailer['transport'] ?? null)
            && ! in_array($mailer['transport'], ['log', 'array'], true)
            && is_string($address)
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            && $address !== 'hello@example.com';
    }

    private function loggingConfiguration(): bool
    {
        $default = config('logging.default');
        $channel = is_string($default) ? config('logging.channels.'.$default) : null;

        if (! is_array($channel) || ($channel['driver'] ?? null) === 'null') {
            return false;
        }

        if (($channel['driver'] ?? null) !== 'stack') {
            return true;
        }

        $channels = $channel['channels'] ?? [];

        return is_array($channels)
            && $channels !== []
            && ! in_array('null', $channels, true);
    }

    private function schedulerConfiguration(): bool
    {
        $required = ['sms:process', 'appointments:expire-requests', 'patient-accounts:prune'];

        foreach ($required as $command) {
            $found = false;

            foreach (Schedule::events() as $event) {
                if (is_string($event->command) && str_contains($event->command, $command)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private function privateDisk(mixed $diskName): bool
    {
        if (! is_string($diskName) || $diskName === '') {
            return false;
        }

        $disk = config('filesystems.disks.'.$diskName);

        return is_array($disk)
            && is_string($disk['driver'] ?? null)
            && ($disk['visibility'] ?? 'private') === 'private';
    }

    private function publicDisk(mixed $diskName): bool
    {
        if (! is_string($diskName) || $diskName === '') {
            return false;
        }

        $disk = config('filesystems.disks.'.$diskName);

        return is_array($disk)
            && is_string($disk['driver'] ?? null)
            && ($disk['visibility'] ?? null) === 'public';
    }

    private function policyUrls(): bool
    {
        return $this->secureUrl(config('app.privacy_policy_url'), true)
            && $this->secureUrl(config('app.terms_url'), true);
    }

    private function secureUrl(mixed $url, bool $rejectLocalhost): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (($parts['scheme'] ?? null) !== 'https'
            || ! is_string($host)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return false;
        }

        if (! $rejectLocalhost) {
            return true;
        }

        $normalizedHost = strtolower(rtrim($host, '.'));

        return ! in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true)
            && $normalizedHost !== 'example.com'
            && ! str_ends_with($normalizedHost, '.example.com');
    }

    private function pilotExpiry(): ?CarbonInterface
    {
        $configuredExpiry = config('capstone_pilot.expires_at');

        if ($configuredExpiry instanceof CarbonInterface) {
            return $configuredExpiry->isAfter(now()) ? $configuredExpiry : null;
        }

        if (! is_string($configuredExpiry) || trim($configuredExpiry) === '') {
            return null;
        }

        try {
            $expiry = Carbon::parse($configuredExpiry, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $expiry->isAfter(now()) ? $expiry : null;
    }

    private function pilotAccountsAbsent(): bool
    {
        $schema = DB::connection()->getSchemaBuilder();

        return $schema->hasTable('pilot_participant_accounts')
            && ! DB::table('pilot_participant_accounts')->exists();
    }

    private function phoneRoutesAreGated(): bool
    {
        $routes = Route::getRoutes()->getRoutes();

        foreach (self::PhoneDependentRoutes as $requiredRoute) {
            $route = null;

            foreach ($routes as $candidate) {
                if ($candidate->uri() === $requiredRoute['uri']
                    && in_array($requiredRoute['method'], $candidate->methods(), true)) {
                    $route = $candidate;
                    break;
                }
            }

            if ($route === null
                || (! in_array('reject.phone.auth', $route->middleware(), true)
                    && ! in_array(RejectPhoneAuthenticationDuringPilot::class, $route->middleware(), true))) {
                return false;
            }
        }

        return true;
    }

    private function smsProviderConfiguration(): bool
    {
        $enabledDrivers = [];

        if (config('services.semaphore.enabled') === true) {
            $enabledDrivers[] = 'semaphore';
        }

        if (config('services.textbee.enabled') === true) {
            $enabledDrivers[] = 'textbee';
        }

        $driver = config('services.sms.driver');
        if (! in_array($driver, ['semaphore', 'textbee'], true) || count($enabledDrivers) > 1) {
            return false;
        }

        if ($enabledDrivers === []) {
            return true;
        }

        if ($enabledDrivers[0] !== $driver) {
            return false;
        }

        $settings = config('services.'.$driver);
        if (! is_array($settings)
            || ! $this->secureUrl($settings['endpoint'] ?? null, false)
            || ! $this->providerCredential($settings['api_key'] ?? null)
            || ! is_int($settings['timeout'] ?? null)
            || $settings['timeout'] < 1
            || $settings['timeout'] > 60
            || ! is_int($settings['retries'] ?? null)
            || $settings['retries'] < 0
            || $settings['retries'] > 5) {
            return false;
        }

        if ($driver === 'semaphore') {
            return is_string($settings['sender_name'] ?? null)
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{2,19}$/', $settings['sender_name']) === 1;
        }

        return is_string($settings['device_id'] ?? null)
            && trim($settings['device_id']) !== '';
    }

    private function providerCredential(mixed $credential): bool
    {
        return is_string($credential)
            && trim($credential) !== ''
            && preg_match('/\\s/', $credential) !== 1;
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => is_string($item) ? trim($item) : '',
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
