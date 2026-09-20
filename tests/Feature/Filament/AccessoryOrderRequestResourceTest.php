<?php

use App\Filament\Resources\AccessoryOrderRequests\Pages\ViewAccessoryOrderRequest;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('staff can view the accessory order request details', function (): void {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->patient()->create();
    $product = Product::factory()->accessory()->create([
        'name' => 'Hydrating Eye Drops',
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'name' => '10 mL',
        'price' => 450,
    ]);
    $request = AccessoryOrderRequest::factory()->create([
        'request_number' => 'ORQ-2026-000099',
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'subtotal_amount' => 450,
    ]);

    AccessoryOrderRequestItem::factory()->create([
        'accessory_order_request_id' => $request->id,
        'product_variant_id' => $variant->id,
        'description' => 'Hydrating Eye Drops — 10 mL',
        'quantity' => 1,
        'unit_price' => 450,
        'amount' => 450,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAccessoryOrderRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Request details')
        ->assertSee('Order items')
        ->assertSee($request->request_number)
        ->assertSee($account->patient->full_name)
        ->assertSee('Pending')
        ->assertSee('Hydrating Eye Drops — 10 mL');
});
