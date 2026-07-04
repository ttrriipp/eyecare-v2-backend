<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['discount_type_id']);
            $table->dropColumn(['discount_type_id', 'discount_amount']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('discount_type_id')->nullable()->after('total_amount')->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_type_id');
        });
    }
};
