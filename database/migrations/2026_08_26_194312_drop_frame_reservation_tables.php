<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $reservationCount = DB::table('frame_reservations')->count();
        $itemCount = DB::table('frame_reservation_items')->count();

        if ($reservationCount > 0 || $itemCount > 0) {
            throw new \RuntimeException(
                "Cannot drop reservation tables: {$reservationCount} reservations and {$itemCount} items remain. ".
                'Run saved-frames:migrate-reservations --execute first.'
            );
        }

        Schema::dropIfExists('frame_reservation_items');
        Schema::dropIfExists('frame_reservations');
    }

    public function down(): void
    {
        Schema::create('frame_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->text('staff_notes')->nullable();
            $table->timestamps();

            $table->unique('appointment_id');
        });

        Schema::create('frame_reservation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('frame_reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }
};
