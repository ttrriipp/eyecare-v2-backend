<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('login page renders successfully', function () {
    $this->get('/admin/login')
        ->assertSuccessful();
});

test('login page contains EyeCare branding', function () {
    $this->get('/admin/login')
        ->assertSee('EYECARE')
        ->assertSee('When elegance meets convenience');
});

test('login page displays stock images', function () {
    $this->get('/admin/login')
        ->assertSee('images/login/eyeglass1.png')
        ->assertSee('images/login/eyeglass2.png')
        ->assertSee('images/login/eyeglass3.png');
});

test('valid credentials authenticate user', function () {
    $user = User::factory()->admin()->create([
        'email' => 'admin@eyecare.test',
        'password' => bcrypt('password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'admin@eyecare.test',
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertRedirect('/admin');
});

test('invalid credentials are rejected', function () {
    User::factory()->admin()->create([
        'email' => 'admin@eyecare.test',
        'password' => bcrypt('password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'admin@eyecare.test',
            'password' => 'wrong-password',
        ])
        ->call('authenticate')
        ->assertHasErrors();
});

test('root route redirects to login page', function () {
    $this->get('/')
        ->assertRedirect('/admin/login');
});
