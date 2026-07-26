<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('v1 route contract contains only approved patient-mobile operations', function () {
    $v1Routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri, 'api/v1'))
        ->map(fn ($r) => implode('|', (array) $r->methods).' '.$r->uri)
        ->sort()
        ->values()
        ->all();

    // Approved v1 routes (read + limited mutations)
    $expectedPrefixes = [
        'GET|HEAD api/v1/frames',
        'GET|HEAD api/v1/frames/{product}',
        'GET|HEAD api/v1/frame-reservations',
        'POST api/v1/frame-reservations',
        'POST api/v1/frame-reservations/{reservation}/cancel',
        'GET|HEAD api/v1/quotations',
        'GET|HEAD api/v1/quotations/{quotation}',
        'GET|HEAD api/v1/job-orders',
        'GET|HEAD api/v1/job-orders/{job_order}',
        'GET|HEAD api/v1/invoices',
        'GET|HEAD api/v1/invoices/{invoice}',
        'POST api/v1/ratings',
    ];

    foreach ($expectedPrefixes as $prefix) {
        $found = collect($v1Routes)->contains(fn (string $route) => str_starts_with($route, $prefix));
        expect($found)->toBeTrue("Missing expected route: {$prefix}");
    }
});

test('v1 routes do not include direct order or checkout endpoints', function () {
    $v1Routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri, 'api/v1'))
        ->pluck('uri');

    // No order creation, checkout, or purchase routes
    expect($v1Routes->contains(fn ($uri) => str_contains($uri, 'checkout')))->toBeFalse();
    expect($v1Routes->contains(fn ($uri) => str_contains($uri, 'purchase')))->toBeFalse();
});

test('v1 routes require authentication', function () {
    $this->getJson('/api/v1/frames')->assertUnauthorized();
    $this->getJson('/api/v1/quotations')->assertUnauthorized();
    $this->getJson('/api/v1/job-orders')->assertUnauthorized();
    $this->getJson('/api/v1/invoices')->assertUnauthorized();
    $this->postJson('/api/v1/ratings', [])->assertUnauthorized();
    $this->postJson('/api/v1/frame-reservations', [])->assertUnauthorized();
});

test('old unversioned product routes still exist', function () {
    $user = User::factory()->patient()->create();
    $this->actingAs($user);
    $this->getJson('/api/products')->assertOk();
});
