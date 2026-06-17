<?php

use App\Models\DiscountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('discount_type_id')
                ->nullable()
                ->after('discount_amount')
                ->constrained('discount_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeignIdFor(DiscountType::class, 'discount_type_id');
        });

        Schema::dropIfExists('discount_types');
    }
};
