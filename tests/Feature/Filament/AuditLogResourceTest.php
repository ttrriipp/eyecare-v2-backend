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
})->with(['admin']);

test('audit logs table can be filtered by action', function () {
    $admin = User::factory()->admin()->create();

    $log = AuditLog::factory()->create(['action' => 'appointment.status_changed']);
    AuditLog::factory()->create(['action' => 'order.status_changed']);

    $this->actingAs($admin);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('action', 'appointment.status_changed')
        ->assertCanSeeTableRecords([$log]);
});

test('audit logs table can be filtered by subject type', function () {
    $admin = User::factory()->admin()->create();

    $apptLog = AuditLog::factory()->create(['subject_type' => 'App\\Models\\Appointment']);
    AuditLog::factory()->create(['subject_type' => 'App\\Models\\Order']);

    $this->actingAs($admin);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('subject_type', 'App\\Models\\Appointment')
        ->assertCanSeeTableRecords([$apptLog]);
});

test('admin can view an audit log record', function () {
    $admin = User::factory()->admin()->create();
    $log = AuditLog::factory()->create();

    $this->actingAs($admin);

    Livewire::test(ViewAuditLog::class, ['record' => $log->getRouteKey()])
        ->assertSuccessful();
});

test('staff cannot access audit logs', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $this->get(AuditLogResource::getUrl('index'))->assertForbidden();
});
