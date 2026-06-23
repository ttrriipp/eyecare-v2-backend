<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login page loads with custom branding', function () {
    $this->get('/admin/login')
        ->assertSuccessful()
        ->assertSee('EyeCare')
        ->assertSee('When elegance meets convenience');
});

test('unauthenticated access to admin panel redirects to login', function () {
    $this->get('/admin')
        ->assertRedirect('/admin/login');
});

test('authenticated admin can access the admin panel', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertSuccessful();
});
