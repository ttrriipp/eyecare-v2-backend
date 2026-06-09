<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('appointment_id')->nullable()->constrained();
            $table->foreignId('previous_prescription_id')->nullable()->constrained('prescriptions');
            $table->foreignId('created_by')->constrained('users');
            $table->decimal('od_sphere', 5, 2);
            $table->decimal('od_cylinder', 5, 2)->default(0);
            $table->unsignedSmallInteger('od_axis')->default(0);
            $table->decimal('od_add', 5, 2)->nullable();
            $table->decimal('od_prism', 5, 2)->nullable();
            $table->string('od_base', 20)->nullable();
            $table->decimal('os_sphere', 5, 2);
            $table->decimal('os_cylinder', 5, 2)->default(0);
            $table->unsignedSmallInteger('os_axis')->default(0);
            $table->decimal('os_add', 5, 2)->nullable();
            $table->decimal('os_prism', 5, 2)->nullable();
            $table->string('os_base', 20)->nullable();
            $table->decimal('pd', 5, 2);
            $table->date('prescribed_at');
            $table->date('expires_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
