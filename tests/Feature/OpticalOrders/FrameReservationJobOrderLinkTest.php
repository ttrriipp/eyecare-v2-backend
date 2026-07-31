<?php

use App\Models\FrameReservation;
use App\Models\JobOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('job order can link to a frame reservation', function () {
    $reservation = FrameReservation::factory()->create();
    $jobOrder = JobOrder::factory()->create(['frame_reservation_id' => $reservation->id]);

    expect($jobOrder->frameReservation)->not->toBeNull()
        ->and($jobOrder->frameReservation->id)->toBe($reservation->id);
});

test('job order can exist without a frame reservation link', function () {
    $jobOrder = JobOrder::factory()->create(['frame_reservation_id' => null]);

    expect($jobOrder->frameReservation)->toBeNull();
});

test('frame reservation link is unique when set', function () {
    $reservation = FrameReservation::factory()->create();

    JobOrder::factory()->create(['frame_reservation_id' => $reservation->id]);

    expect(fn () => JobOrder::factory()->create(['frame_reservation_id' => $reservation->id]))
        ->toThrow(QueryException::class);
});

test('existing job orders remain valid without the link', function () {
    $jobOrder = JobOrder::factory()->create();

    expect($jobOrder->frame_reservation_id)->toBeNull()
        ->and($jobOrder->frameReservation)->toBeNull();
});
