<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_type_visit_reason_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_type_id')
                ->constrained('appointment_types', 'id', 'atvrp_appointment_type_id_foreign')
                ->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(
                ['appointment_type_id', 'is_active', 'sort_order'],
                'atvrp_active_order_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_type_visit_reason_presets');
    }
};
