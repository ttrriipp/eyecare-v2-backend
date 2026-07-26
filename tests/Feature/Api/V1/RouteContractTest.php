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
        'GET|HEAD api/v1/appointments',
        'GET|HEAD api/v1/appointments/availability',
        'GET|HEAD api/v1/appointments/{appointment}',
        'GET|HEAD api/v1/attachments/{attachment}',
        'GET|HEAD api/v1/brands',
        'GET|HEAD api/v1/categories',
        'GET|HEAD api/v1/conversations',
        'GET|HEAD api/v1/conversations/{conversation}/messages',
        'GET|HEAD api/v1/feedback',
        'GET|HEAD api/v1/feedback/{feedback}',
        'GET|HEAD api/v1/frame-reservations',
        'GET|HEAD api/v1/frames',
        'GET|HEAD api/v1/frames/{product}',
        'GET|HEAD api/v1/invoices',
        'GET|HEAD api/v1/invoices/{invoice}',
        'GET|HEAD api/v1/job-orders',
        'GET|HEAD api/v1/job-orders/{job_order}',
        'GET|HEAD api/v1/me',
        'GET|HEAD api/v1/notifications',
        'GET|HEAD api/v1/notifications/unread-count',
        'GET|HEAD api/v1/patient/intakes',
        'GET|HEAD api/v1/patient/profile',
        'GET|HEAD api/v1/prescriptions',
        'GET|HEAD api/v1/prescriptions/{prescription}',
        'GET|HEAD api/v1/products',
        'GET|HEAD api/v1/products/{product}',
        'GET|HEAD api/v1/quotations',
        'GET|HEAD api/v1/quotations/{quotation}',
        'GET|HEAD api/v1/visit-reasons',
        'PATCH api/v1/appointments/{appointment}/contact-note',
        'PATCH api/v1/me',
        'PATCH api/v1/patient/intakes/{intake}',
        'PATCH api/v1/patient/profile',
        'PATCH api/v1/staff/appointments/{appointment}/status',
        'POST api/v1/appointments',
        'POST api/v1/appointments/{appointment}/cancel',
        'POST api/v1/appointments/{appointment}/reschedule',
        'POST api/v1/conversations/{conversation}/messages',
        'POST api/v1/conversations/{conversation}/messages/read',
        'POST api/v1/feedback',
        'POST api/v1/frame-reservations',
        'POST api/v1/frame-reservations/{reservation}/cancel',
        'POST api/v1/login',
        'POST api/v1/logout',
        'POST api/v1/notifications/mark-all-read',
        'POST api/v1/notifications/{notification}/mark-read',
        'POST api/v1/patient/intakes',
        'POST api/v1/patient/intakes/{intake}/submit',
        'POST api/v1/patient/intakes/{intake}/verify',
        'POST api/v1/ratings',
        'POST api/v1/register',
    ];

    expect($v1Routes)->toBe($expected);
});

test('unversioned patient routes are absent', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->pluck('uri')
        ->toArray();

    // These old unversioned routes should not exist
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

test('staff-only mutations are outside patient route group', function () {
    $staffRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri, 'api/v1/staff'))
        ->pluck('uri')
        ->toArray();

    // Staff routes exist but are separate
    expect($staffRoutes)->toContain('api/v1/staff/appointments/{appointment}/status');
});
