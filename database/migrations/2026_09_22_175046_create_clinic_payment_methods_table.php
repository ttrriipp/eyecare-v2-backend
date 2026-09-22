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
        Schema::create('clinic_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('method', 32)->unique();
            $table->string('label', 80);
            $table->string('bank_name', 120)->nullable();
            $table->string('account_name', 160);
            $table->string('account_number', 120);
            $table->string('qr_image_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_payment_methods');
    }
};
