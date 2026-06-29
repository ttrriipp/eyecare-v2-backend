<?php

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
    $this->actingAs(User::factory()->admin()->create());
});

test('patients resource is globally searchable by name', function () {
    User::factory()->customer()->create(['name' => 'Maria Santos']);

    $results = PatientResource::getGlobalSearchResults('Maria')->collect();

    expect($results->count())->toBeGreaterThan(0);
});

test('orders resource is globally searchable by order number', function () {
    Order::factory()->create(['order_number' => 'ORD-2026-TESTXX']);

    $results = OrderResource::getGlobalSearchResults('TESTXX')->collect();

    expect($results->count())->toBeGreaterThan(0);
});

test('appointments resource is globally searchable by customer name', function () {
    $customer = User::factory()->customer()->create(['name' => 'Juan Reyes']);
    Appointment::factory()->create(['customer_id' => $customer->id]);

    $results = AppointmentResource::getGlobalSearchResults('Juan')->collect();

    expect($results->count())->toBeGreaterThan(0);
});

test('products resource is globally searchable by name', function () {
    $product = Product::factory()->create(['name' => 'Aviator Frame']);
    ProductVariant::factory()->for($product)->create();

    $results = ProductResource::getGlobalSearchResults('Aviator')->collect();

    expect($results->count())->toBeGreaterThan(0);
});
