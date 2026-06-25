<?php

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Billings\Pages\ViewBilling;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\LensTypes\LensTypeResource;
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

// --- Admin-only resources: staff gets 403 ---

test('staff cannot access admin-only resources', function (string $url) {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $this->get($url)->assertForbidden();
})->with([
    'users' => [fn () => UserResource::getUrl('index')],
    'audit_logs' => [fn () => AuditLogResource::getUrl('index')],
    'brands' => [fn () => BrandResource::getUrl('index')],
    'lens_types' => [fn () => LensTypeResource::getUrl('index')],
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
    'lens_types' => [fn () => LensTypeResource::getUrl('index')],
    'visit_reasons' => [fn () => VisitReasonResource::getUrl('index')],
    'categories' => [fn () => ProductCategoryResource::getUrl('index')],
    'services' => [fn () => ServiceResource::getUrl('index')],
]);

// --- Billing actions: void and apply_discount admin-only ---

test('staff cannot see void_billing or apply_discount actions on billing view', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create();

    $this->actingAs($staff);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->assertActionHidden('void_billing')
        ->assertActionHidden('apply_discount');
});

test('admin can see void_billing and apply_discount actions on billing view', function () {
    $admin = User::factory()->admin()->create();
    $billing = Billing::factory()->issued()->create();

    $this->actingAs($admin);

    Livewire::test(ViewBilling::class, ['record' => $billing->getRouteKey()])
        ->assertActionVisible('void_billing')
        ->assertActionVisible('apply_discount');
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
