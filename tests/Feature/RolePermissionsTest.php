<?php

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Billings\Pages\EditBilling;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\LensCategories\LensCategoryResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VisitReasons\VisitReasonResource;
use App\Models\Billing;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// --- isAdmin() helper ---

test('isAdmin returns true for admin role', function () {
    $admin = User::factory()->admin()->create();
    expect($admin->isAdmin())->toBeTrue();
});

test('isAdmin returns false for staff role', function () {
    $staff = User::factory()->staff()->create();
    expect($staff->isAdmin())->toBeFalse();
});

test('optometrist capability requires the flag and an eligible clinic role', function (
    string $factoryState,
    bool $isOptometrist,
    bool $expected,
) {
    $user = User::factory()->{$factoryState}()->create([
        'is_optometrist' => $isOptometrist,
    ]);

    expect($user->hasOptometristCapability())->toBe($expected);
})->with([
    'flagged admin' => ['admin', true, true],
    'unflagged admin' => ['admin', false, false],
    'flagged staff' => ['staff', true, true],
    'unflagged staff' => ['staff', false, false],
    'flagged patient' => ['patient', true, false],
    'unflagged patient' => ['patient', false, false],
]);

test('optometrist scope returns only users with effective optometrist capability', function () {
    $adminOptometrist = User::factory()->admin()->create(['is_optometrist' => true]);
    $staffOptometrist = User::factory()->staff()->create(['is_optometrist' => true]);
    User::factory()->patient()->create(['is_optometrist' => true]);
    User::factory()->staff()->create(['is_optometrist' => false]);

    expect(User::query()->optometrists()->pluck('id')->all())
        ->toEqualCanonicalizing([$adminOptometrist->id, $staffOptometrist->id]);
});

// --- Admin-only resources: staff gets 403 ---

test('staff cannot access admin-only resources', function (string $url) {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $this->get($url)->assertForbidden();
})->with([
    'users' => [fn () => UserResource::getUrl('index')],
    'audit_logs' => [fn () => AuditLogResource::getUrl('index')],
    'brands' => [fn () => BrandResource::getUrl('index')],
    'lens_categories' => [fn () => LensCategoryResource::getUrl('index')],
    'visit_reasons' => [fn () => VisitReasonResource::getUrl('index')],
    'categories' => [fn () => ProductCategoryResource::getUrl('index')],
    'services' => [fn () => ServiceResource::getUrl('index')],
]);

test('admin can access all admin-only resources', function (string $url) {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->get($url)->assertSuccessful();
})->with([
    'audit_logs' => [fn () => AuditLogResource::getUrl('index')],
    'brands' => [fn () => BrandResource::getUrl('index')],
    'lens_categories' => [fn () => LensCategoryResource::getUrl('index')],
    'visit_reasons' => [fn () => VisitReasonResource::getUrl('index')],
    'categories' => [fn () => ProductCategoryResource::getUrl('index')],
    'services' => [fn () => ServiceResource::getUrl('index')],
]);

// --- Billing actions: void is admin-only; discount is handled in the edit form ---

test('staff cannot see void_billing or legacy apply_discount actions on billing edit', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create();

    $this->actingAs($staff);

    Livewire::test(EditBilling::class, ['record' => $billing->getRouteKey()])
        ->assertActionHidden('void_billing')
        ->assertActionDoesNotExist('apply_discount');
});

test('admin can see void_billing and legacy apply_discount action is removed on billing edit', function () {
    $admin = User::factory()->admin()->create();
    $billing = Billing::factory()->issued()->create();

    $this->actingAs($admin);

    Livewire::test(EditBilling::class, ['record' => $billing->getRouteKey()])
        ->assertActionVisible('void_billing')
        ->assertActionDoesNotExist('apply_discount');
});

// --- Order cancel: staff can cancel requested, not confirmed+ ---

test('staff can cancel a requested order', function () {
    $this->seed(OrderStatusSeeder::class);

    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create(['customer_id' => $customer->id, 'order_status_id' => $requestedStatus->id]);

    $this->actingAs($staff);

    Livewire::test(ListOrders::class)
        ->assertTableActionVisible('cancel', $order);
});

test('staff cannot cancel a confirmed order', function () {
    $this->seed(OrderStatusSeeder::class);

    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create(['customer_id' => $customer->id, 'order_status_id' => $confirmedStatus->id]);

    $this->actingAs($staff);

    Livewire::test(ListOrders::class)
        ->assertTableActionHidden('cancel', $order);
});

test('admin can cancel a confirmed order', function () {
    $this->seed(OrderStatusSeeder::class);

    $admin = User::factory()->admin()->create();
    $customer = User::factory()->customer()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create(['customer_id' => $customer->id, 'order_status_id' => $confirmedStatus->id]);

    $this->actingAs($admin);

    Livewire::test(ListOrders::class)
        ->assertTableActionVisible('cancel', $order);
});
