<?php

use App\Actions\Reservations\ConvertFrameReservationToJobOrder;
use App\Enums\JobOrderStatus;
use App\Enums\ReservationStatus;
use App\Enums\TransactionItemType;
use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

test('converting a requested reservation commits inventory normally', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $reservation->items()->create(['product_variant_id' => $variant->id]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 2500,
        'amount' => 2500,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
    ]);

    app(ConvertFrameReservationToJobOrder::class)->handle($reservation, $jobOrder);

    expect($variant->fresh()->stock_quantity)->toBe(9)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Converted)
        ->and($jobOrder->fresh()->frame_reservation_id)->toBe($reservation->id);
});

test('converting a prepared reservation transfers the existing allocation with no net stock change', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 9]); // already decremented by "prepare"
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Prepared]);
    $reservation->items()->create(['product_variant_id' => $variant->id]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    app(ConvertFrameReservationToJobOrder::class)->handle($reservation, $jobOrder);

    expect($variant->fresh()->stock_quantity)->toBe(9)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Converted)
        ->and(InventoryMovement::where('product_variant_id', $variant->id)->count())->toBe(2);
});

test('converting a tried-on reservation also transfers the existing allocation with no net stock change', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 9]);
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::TriedOn]);
    $reservation->items()->create(['product_variant_id' => $variant->id]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    app(ConvertFrameReservationToJobOrder::class)->handle($reservation, $jobOrder);

    expect($variant->fresh()->stock_quantity)->toBe(9)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Converted);
});

test('a converted reservation cannot be converted again', function () {
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Converted]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    app(ConvertFrameReservationToJobOrder::class)->handle($reservation, $jobOrder);
})->throws(ValidationException::class, 'Only requested, prepared, or tried-on');

test('a released reservation cannot be converted', function () {
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Released]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    app(ConvertFrameReservationToJobOrder::class)->handle($reservation, $jobOrder);
})->throws(ValidationException::class, 'Only requested, prepared, or tried-on');
