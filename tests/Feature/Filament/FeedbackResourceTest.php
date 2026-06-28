<?php

use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Filament\Resources\Feedback\Pages\ViewFeedback;
use App\Models\Feedback;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
});

test('staff and admin can list feedback', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $feedbacks = Feedback::factory()->count(3)->create();

    $this->actingAs($user);

    Livewire::test(ListFeedback::class)
        ->assertCanSeeTableRecords($feedbacks);
})->with(['staff', 'admin']);

test('staff can view a feedback record', function () {
    $staff = User::factory()->staff()->create();
    $feedback = Feedback::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ViewFeedback::class, ['record' => $feedback->getRouteKey()])
        ->assertSuccessful();
});
