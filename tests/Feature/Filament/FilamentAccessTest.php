<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('staff and admin users can access the Filament admin panel', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertSuccessful();
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
]);

test('customer users are denied Filament admin panel access', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get('/admin')
        ->assertForbidden();
});
