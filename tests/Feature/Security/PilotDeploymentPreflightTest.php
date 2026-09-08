<?php

use App\Models\PilotParticipantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    configureValidPilotDeployment();
    Storage::fake('message_attachments');
});

function configureValidPilotDeployment(): void
{
    $appKey = 'base64:'.base64_encode(str_repeat('application-key', 2).'xx');

    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://pilot.example.test',
        'app.key' => $appKey,
        'app.privacy_policy_url' => 'https://pilot.example.test/privacy',
        'app.terms_url' => 'https://pilot.example.test/terms',
        'patient_accounts.contact_lookup_key' => str_repeat('contact-lookup-key', 3),
        'session.driver' => 'database',
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'database.default' => 'mysql',
        'cache.default' => 'file',
        'queue.default' => 'database',
        'mail.default' => 'smtp',
        'logging.default' => 'stack',
        'deployment.release_id' => 'release-20260907.1',
        'deployment.readiness_token' => str_repeat('readiness-token', 3),
        'deployment.uptime_alert_url' => 'https://monitor.example.test/hooks/eyecare',
        'deployment.mode' => 'pilot',
        'deployment.trusted_hosts' => ['pilot.example.test'],
        'deployment.trusted_proxies' => ['*'],
        'deployment.allowed_origins' => ['https://pilot.example.test'],
        'cors.allowed_origins' => ['https://pilot.example.test'],
        'capstone_pilot.enabled' => true,
        'capstone_pilot.expires_at' => now()->addMonth()->toISOString(),
        'capstone_pilot.participant_limit' => 75,
        'ar.assets.base_url' => 'https://cdn.example.test',
        'ar.assets.quarantine_disk' => 'ar_quarantine',
        'ar.assets.published_disk' => 'ar_published',
        'filesystems.message_attachments_disk' => 'message_attachments',
        'filesystems.disks.message_attachments.visibility' => 'private',
        'filesystems.disks.ar_quarantine.visibility' => 'private',
        'filesystems.disks.ar_published.visibility' => 'public',
        'services.sms.driver' => 'semaphore',
        'services.semaphore.enabled' => false,
        'services.textbee.enabled' => false,
    ]);

    Cache::clearResolvedInstances();
}

test('valid provider-neutral pilot deployment passes preflight', function (): void {
    $this->artisan('pilot:preflight')
        ->expectsOutputToContain('Deployment preflight passed.')
        ->assertSuccessful();
});

test('valid staff-only demo deployment passes without pilot accounts or SMS', function (): void {
    config([
        'deployment.mode' => 'demo',
        'capstone_pilot.enabled' => false,
        'capstone_pilot.expires_at' => null,
        'capstone_pilot.participant_limit' => 0,
    ]);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain('Deployment preflight passed.')
        ->assertSuccessful();
});

test('preflight accepts an explicitly configured catalog disk alias', function (): void {
    config([
        'filesystems.catalog_disk' => 'catalog_cloud',
        'filesystems.disks.catalog_cloud' => [
            'driver' => 's3',
            'visibility' => 'public',
        ],
    ]);

    $this->artisan('pilot:preflight')->assertSuccessful();
});

test('preflight fails closed when the catalog disk alias is missing or private', function (string $diskName, ?array $disk): void {
    config([
        'filesystems.catalog_disk' => $diskName,
        "filesystems.disks.{$diskName}" => $disk,
    ]);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain('storage.catalog')
        ->assertFailed();
})->with([
    ['missing_catalog_disk', null],
    ['private_catalog_disk', ['driver' => 's3', 'visibility' => 'private']],
]);

test('demo-only deployment fails closed if pilot mode is enabled', function (): void {
    config([
        'deployment.mode' => 'demo',
        'capstone_pilot.enabled' => true,
    ]);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain('pilot.disabled')
        ->assertFailed();
});

test('demo-only deployment fails closed when pilot accounts exist', function (): void {
    PilotParticipantAccount::factory()->create();

    config([
        'deployment.mode' => 'demo',
        'capstone_pilot.enabled' => false,
    ]);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain('pilot.participant_accounts')
        ->assertFailed();
});

test('preflight fails closed for unsafe application settings', function (string $key, mixed $value, string $check): void {
    config([$key => $value]);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain($check)
        ->assertFailed();
})->with([
    ['app.env', 'local', 'app.environment'],
    ['app.debug', true, 'app.debug'],
    ['app.url', 'http://pilot.example.test', 'app.url'],
    ['app.url', 'https://user@pilot.example.test', 'app.url'],
    ['app.key', 'short-key', 'app.key'],
    ['patient_accounts.contact_lookup_key', 'same-key', 'contact_lookup_key'],
]);

test('preflight fails closed for unsafe pilot bounds', function (string $key, mixed $value, string $check): void {
    config([$key => $value]);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain($check)
        ->assertFailed();
})->with([
    ['capstone_pilot.enabled', false, 'pilot.enabled'],
    ['capstone_pilot.expires_at', now()->subMinute()->toISOString(), 'pilot.expiry'],
    ['capstone_pilot.participant_limit', 74, 'pilot.participant_limit'],
]);

test('preflight fails closed for cookie proxy origin and cors settings', function (string $key, mixed $value, string $check): void {
    config([$key => $value]);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain($check)
        ->assertFailed();
})->with([
    ['session.secure', false, 'session.cookies'],
    ['deployment.trusted_hosts', [], 'deployment.trusted_hosts'],
    ['deployment.trusted_proxies', [], 'deployment.trusted_proxies'],
    ['deployment.allowed_origins', ['*'], 'deployment.allowed_origins'],
    ['cors.allowed_origins', ['*'], 'deployment.cors'],
]);

test('preflight fails closed when a production service uses an in-memory or synchronous driver', function (string $key, mixed $value, string $check): void {
    config([$key => $value]);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain($check)
        ->assertFailed();
})->with([
    ['cache.default', 'array', 'runtime.cache'],
    ['queue.default', 'sync', 'runtime.queue'],
    ['session.driver', 'array', 'runtime.session'],
    ['mail.default', 'log', 'runtime.mail'],
]);

test('preflight fails closed for storage release policy and alert settings', function (string $key, mixed $value, string $check): void {
    config([$key => $value]);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain($check)
        ->assertFailed();
})->with([
    ['filesystems.disks.message_attachments.visibility', 'public', 'storage.message_attachments'],
    ['filesystems.disks.ar_quarantine.visibility', 'public', 'storage.ar_quarantine'],
    ['filesystems.disks.ar_published.visibility', 'private', 'storage.ar_published'],
    ['deployment.release_id', '', 'deployment.release_id'],
    ['deployment.uptime_alert_url', 'http://monitor.example.test/hooks/eyecare', 'deployment.uptime_alert'],
    ['app.privacy_policy_url', 'https://eyecare.example.com/privacy', 'policy.urls'],
    ['app.terms_url', 'not-a-url', 'policy.urls'],
]);

test('disabled SMS is accepted only when all phone-dependent routes remain gated', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'api/v1/auth/step-up/otp' && in_array('POST', $route->methods(), true));

    expect($route)->not->toBeNull();
    $action = $route->getAction();
    $action['middleware'] = array_values(array_diff($route->middleware(), ['reject.phone.auth']));
    $route->setAction($action);

    $this->artisan('pilot:preflight')
        ->expectsOutputToContain('sms.phone_routes')
        ->assertFailed();
});

test('enabled SMS requires a reviewed adapter configuration without printing credentials', function (): void {
    $secret = 'semaphore-secret-not-for-output';
    config([
        'services.semaphore.enabled' => true,
        'services.semaphore.api_key' => $secret,
        'services.semaphore.endpoint' => 'not-a-url',
    ]);

    $result = $this->artisan('pilot:preflight');

    $result
        ->expectsOutputToContain('sms.provider')
        ->doesntExpectOutputToContain($secret)
        ->assertFailed();
});

test('a reviewed enabled Semaphore configuration remains provider-neutral', function (): void {
    config([
        'services.semaphore.enabled' => true,
        'services.semaphore.api_key' => 'provider-key',
        'services.semaphore.endpoint' => 'https://sms.example.test/messages',
        'services.semaphore.sender_name' => 'PilotStudy',
        'services.semaphore.timeout' => 5,
        'services.semaphore.retries' => 2,
    ]);

    $this->artisan('pilot:preflight')->assertSuccessful();
});

test('readiness is protected, keeps failure details private, and leaves up minimal', function (): void {
    $this->get('/internal/readiness')
        ->assertNotFound();

    $this->withHeader('X-Readiness-Token', config('deployment.readiness_token'))
        ->get('/internal/readiness')
        ->assertOk()
        ->assertExactJson(['status' => 'ready']);

    $this->get('/up')
        ->assertOk()
        ->assertHeaderMissing('X-Readiness-Token');
});

test('readiness returns an opaque unavailable response when a required dependency fails', function (): void {
    config(['filesystems.message_attachments_disk' => 'missing_disk']);

    $response = $this->withHeader('X-Readiness-Token', config('deployment.readiness_token'))
        ->get('/internal/readiness');

    $response->assertServiceUnavailable()
        ->assertExactJson(['status' => 'unready']);
});
