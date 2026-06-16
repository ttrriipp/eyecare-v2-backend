<?php

use App\Filament\Resources\VisitReasons\Pages\CreateVisitReason;
use App\Filament\Resources\VisitReasons\Pages\EditVisitReason;
use App\Filament\Resources\VisitReasons\Pages\ListVisitReasons;
use App\Models\User;
use App\Models\VisitReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff and admin can list visit reasons', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();
    $reasons = VisitReason::factory()->count(2)->create();

    $this->actingAs($user);

    Livewire::test(ListVisitReasons::class)
        ->assertCanSeeTableRecords($reasons);
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
]);

test('staff can create a visit reason', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateVisitReason::class)
        ->fillForm(['name' => 'General Checkup'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(VisitReason::class, ['name' => 'General Checkup']);
});

test('staff can edit a visit reason', function () {
    $staff = User::factory()->staff()->create();
    $reason = VisitReason::factory()->create(['name' => 'Old Name']);

    $this->actingAs($staff);

    Livewire::test(EditVisitReason::class, ['record' => $reason->getRouteKey()])
        ->fillForm(['name' => 'Updated Name'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(VisitReason::class, ['id' => $reason->id, 'name' => 'Updated Name']);
});

test('visit reason name is required', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateVisitReason::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

test('visit reason name must be unique', function () {
    $staff = User::factory()->staff()->create();
    VisitReason::factory()->create(['name' => 'Eye Exam']);

    $this->actingAs($staff);

    Livewire::test(CreateVisitReason::class)
        ->fillForm(['name' => 'Eye Exam'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});
