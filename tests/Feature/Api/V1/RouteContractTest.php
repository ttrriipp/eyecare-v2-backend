<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('every approved v1 route is present exactly once', function () {
    $v1Routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri, 'api/v1'))
        ->map(fn ($r) => implode('|', (array) $r->methods).' '.$r->uri)
        ->sort()
        ->values()
        ->all();

    $expected = [
        'GET|HEAD api/v1/appointment-availability',
        'GET|HEAD api/v1/appointment-types',
        'GET|HEAD api/v1/appointments',
        'GET|HEAD api/v1/appointments/{appointment}',
        'GET|HEAD api/v1/appointments/{appointment}/intake',
        'GET|HEAD api/v1/conversation',
        'GET|HEAD api/v1/conversation/attachments/{attachment}',
        'GET|HEAD api/v1/conversation/messages',
        'GET|HEAD api/v1/frame-reservations',
        'GET|HEAD api/v1/frames',
        'GET|HEAD api/v1/frames/{frame}',
        'GET|HEAD api/v1/invoices',
        'GET|HEAD api/v1/invoices/{invoice}',
        'GET|HEAD api/v1/job-orders',
        'GET|HEAD api/v1/job-orders/{jobOrder}',
        'GET|HEAD api/v1/me',
        'GET|HEAD api/v1/prescriptions',
        'GET|HEAD api/v1/prescriptions/{prescription}',
        'GET|HEAD api/v1/quotations',
        'GET|HEAD api/v1/quotations/{quotation}',
        'PATCH api/v1/me',
        'POST api/v1/appointments',
        'POST api/v1/appointments/{appointment}/cancel',
        'POST api/v1/appointments/{appointment}/intake/submit',
        'POST api/v1/appointments/{appointment}/reschedule',
        'POST api/v1/conversation/messages',
        'POST api/v1/feedback',
        'POST api/v1/frame-reservations',
        'POST api/v1/frame-reservations/{reservation}/cancel',
        'POST api/v1/job-order-items/{item}/rating',
        'POST api/v1/login',
        'POST api/v1/logout',
        'POST api/v1/register',
        'PUT api/v1/appointments/{appointment}/intake',
    ];

    expect($v1Routes)->toBe($expected);
});

test('unversioned patient routes are absent', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->not->toContain('api/register')
        ->and($routes)->not->toContain('api/login')
        ->and($routes)->not->toContain('api/user')
        ->and($routes)->not->toContain('api/orders')
        ->and($routes)->not->toContain('api/billing/{billing}');
});

test('no checkout or purchase routes exist', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    expect($routes)->not->toContain(fn ($uri) => str_contains($uri, 'checkout'))
        ->and($routes)->not->toContain(fn ($uri) => str_contains($uri, 'purchase'));
});

test('staff-only api mutations are absent from the patient mobile contract', function () {
    $staffRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri, 'api/v1/staff'))
        ->pluck('uri')
        ->toArray();

    expect($staffRoutes)->toBe([]);
});
