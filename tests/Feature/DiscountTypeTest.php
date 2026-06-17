<?php

use App\Models\DiscountType;
use App\Models\Order;
use Database\Seeders\DiscountTypeSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Seeder ───────────────────────────────────────────────────────────────────

test('discount type seeder creates the four predefined types', function () {
    $this->seed(DiscountTypeSeeder::class);

    expect(DiscountType::query()->count())->toBe(4);

    $this->assertDatabaseHas(DiscountType::class, ['name' => 'Senior Citizen', 'type' => 'percentage', 'value' => '20.00']);
    $this->assertDatabaseHas(DiscountType::class, ['name' => 'PWD',            'type' => 'percentage', 'value' => '20.00']);
    $this->assertDatabaseHas(DiscountType::class, ['name' => 'Loyalty',        'type' => 'percentage', 'value' => '10.00']);
    $this->assertDatabaseHas(DiscountType::class, ['name' => 'Custom',         'type' => 'fixed',      'value' => '0.00']);
});

test('discount type seeder is idempotent', function () {
    $this->seed(DiscountTypeSeeder::class);
    $this->seed(DiscountTypeSeeder::class);

    expect(DiscountType::query()->count())->toBe(4);
});

// ─── Model & Factory ─────────────────────────────────────────────────────────

test('discount type factory creates a valid record', function () {
    $discountType = DiscountType::factory()->percentage(20)->create(['name' => 'Test']);

    expect($discountType->type)->toBe('percentage')
        ->and($discountType->value)->toBe('20.00')
        ->and($discountType->is_active)->toBeTrue();
});

test('order has nullable discountType relationship', function () {
    $this->seed(OrderStatusSeeder::class);

    $order = Order::factory()->create(['discount_type_id' => null]);

    expect($order->discountType)->toBeNull();
});

test('order discountType relationship returns the linked discount type', function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(DiscountTypeSeeder::class);

    $discountType = DiscountType::query()->where('name', 'Senior Citizen')->first();
    $order = Order::factory()->create(['discount_type_id' => $discountType->id]);

    expect($order->discountType->name)->toBe('Senior Citizen')
        ->and($order->discountType->type)->toBe('percentage');
});
