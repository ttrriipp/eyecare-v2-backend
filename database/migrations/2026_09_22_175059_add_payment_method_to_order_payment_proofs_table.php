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
        Schema::table('order_payment_proofs', function (Blueprint $table): void {
            $table->string('payment_method', 32)->default('gcash')->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_payment_proofs', function (Blueprint $table): void {
            $table->dropColumn('payment_method');
        });
    }
};
