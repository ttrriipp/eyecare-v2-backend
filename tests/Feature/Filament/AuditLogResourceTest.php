<?php

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff and admin can list audit logs', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $logs = AuditLog::factory()->count(3)->create();

    $this->actingAs($user);

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords($logs);
})->with(['staff', 'admin']);

test('audit logs table can be filtered by action', function () {
    $staff = User::factory()->staff()->create();

    $log = AuditLog::factory()->create(['action' => 'appointment.status_changed']);
    AuditLog::factory()->create(['action' => 'order.status_changed']);

    $this->actingAs($staff);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('action', 'appointment.status_changed')
        ->assertCanSeeTableRecords([$log]);
});

test('audit logs table can be filtered by subject type', function () {
    $staff = User::factory()->staff()->create();

    $apptLog = AuditLog::factory()->create(['subject_type' => 'App\\Models\\Appointment']);
    AuditLog::factory()->create(['subject_type' => 'App\\Models\\Order']);

    $this->actingAs($staff);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('subject_type', 'App\\Models\\Appointment')
        ->assertCanSeeTableRecords([$apptLog]);
});

test('staff can view an audit log record', function () {
    $staff = User::factory()->staff()->create();
    $log = AuditLog::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ViewAuditLog::class, ['record' => $log->getRouteKey()])
        ->assertSuccessful();
});

test('audit logs have no edit or create actions', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $pages = AuditLogResource::getPages();

    expect($pages)->toHaveKey('index')
        ->toHaveKey('view')
        ->not->toHaveKey('create')
        ->not->toHaveKey('edit');
});
