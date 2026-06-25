<?php

use App\Filament\Resources\VisitReasons\Pages\CreateVisitReason;
use App\Filament\Resources\VisitReasons\Pages\EditVisitReason;
use App\Filament\Resources\VisitReasons\Pages\ListVisitReasons;
use App\Filament\Resources\VisitReasons\VisitReasonResource;
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
]);

test('admin can create a visit reason', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateVisitReason::class)
        ->fillForm(['name' => 'General Checkup'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(VisitReason::class, ['name' => 'General Checkup']);
});

test('admin can edit a visit reason', function () {
    $admin = User::factory()->admin()->create();
    $reason = VisitReason::factory()->create(['name' => 'Old Name']);

    $this->actingAs($admin);

    Livewire::test(EditVisitReason::class, ['record' => $reason->getRouteKey()])
        ->fillForm(['name' => 'Updated Name'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(VisitReason::class, ['id' => $reason->id, 'name' => 'Updated Name']);
});

test('visit reason name is required', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateVisitReason::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

test('visit reason name must be unique', function () {
    $admin = User::factory()->admin()->create();
    VisitReason::factory()->create(['name' => 'Eye Exam']);

    $this->actingAs($admin);

    Livewire::test(CreateVisitReason::class)
        ->fillForm(['name' => 'Eye Exam'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

test('staff cannot access visit reasons', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $this->get(VisitReasonResource::getUrl('index'))->assertForbidden();
});
