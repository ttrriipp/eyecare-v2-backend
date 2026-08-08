<?php

use App\Actions\Users\SyncUserRoles;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->action = app(SyncUserRoles::class);
});

test('assigning admin only is valid', function () {
    $target = User::factory()->staff()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Admin], $actor);

    expect($target->fresh()->roles->pluck('name')->all())->toBe([Role::Admin]);
});

test('assigning optometrist only is valid', function () {
    $target = User::factory()->staff()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Optometrist], $actor);

    expect($target->fresh()->roles->pluck('name')->all())->toBe([Role::Optometrist]);
});

test('assigning staff only is valid', function () {
    $target = User::factory()->admin()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Staff], $actor);

    expect($target->fresh()->roles->pluck('name')->all())->toBe([Role::Staff]);
});

test('assigning admin plus optometrist is valid', function () {
    $target = User::factory()->staff()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Admin, Role::Optometrist], $actor);

    expect($target->fresh()->roles->pluck('name')->sort()->values()->all())
        ->toBe([Role::Admin, Role::Optometrist]);
});

test('assigning admin plus staff is rejected as redundant', function () {
    $target = User::factory()->staff()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Admin, Role::Staff], $actor);
})->throws(ValidationException::class);

test('assigning optometrist plus staff is rejected as redundant', function () {
    $target = User::factory()->staff()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Optometrist, Role::Staff], $actor);
})->throws(ValidationException::class);

test('assigning patient with a panel role is rejected', function () {
    $target = User::factory()->patient()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Admin, Role::Patient], $actor);
})->throws(ValidationException::class);

test('assigning empty role set is rejected', function () {
    $target = User::factory()->staff()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [], $actor);
})->throws(ValidationException::class);

test('self-role mutation is rejected', function () {
    $admin = User::factory()->admin()->create();

    $this->action->handle($admin, [Role::Staff], $admin);
})->throws(ValidationException::class);

test('removing the last active admin is rejected', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->admin()->create();

    // Deactivate the actor so the target is the last active admin.
    $actor->update(['is_active' => false]);

    $this->action->handle($admin, [Role::Staff], $actor->fresh());
})->throws(ValidationException::class);

test('removing admin is allowed when another active admin exists', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $this->action->handle($admin, [Role::Staff], $otherAdmin);

    expect($admin->fresh()->roles->pluck('name')->all())->toBe([Role::Staff]);
});

test('role change emits an audit log with old and new role names', function () {
    $target = User::factory()->staff()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Admin, Role::Optometrist], $actor);

    $auditLog = AuditLog::query()
        ->where('subject_type', (new User)->getMorphClass())
        ->where('subject_id', $target->id)
        ->where('action', 'user.role_changed')
        ->latest()
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['old_roles'])->toBe([Role::Staff])
        ->and($auditLog->metadata['new_roles'])->toBe([Role::Admin, Role::Optometrist]);
});

test('role change is immediately visible after sync', function () {
    $target = User::factory()->staff()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Optometrist], $actor);

    expect($target->fresh()->hasRole(Role::Optometrist))->toBeTrue()
        ->and($target->fresh()->hasRole(Role::Staff))->toBeFalse();
});

test('admin plus optometrist dual-role user can perform both admin and clinical checks', function () {
    $target = User::factory()->staff()->create();
    $actor = User::factory()->admin()->create();

    $this->action->handle($target, [Role::Admin, Role::Optometrist], $actor);

    $fresh = $target->fresh();
    expect($fresh->isAdmin())->toBeTrue()
        ->and($fresh->isOptometrist())->toBeTrue()
        ->and($fresh->hasPanelRole())->toBeTrue();
});
