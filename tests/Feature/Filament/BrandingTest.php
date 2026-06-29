<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the admin panel renders the Eyecare brand logo lockup', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('fi-logo-eyecare', escape: false) // custom brand logo view rendered
        ->assertSee('Eyecare'); // wordmark + tab title
});

test('the admin panel references the branded favicon', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('images/favicon.svg', escape: false);
});
