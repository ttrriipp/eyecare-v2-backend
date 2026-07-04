<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a nullable billing_id FK to orders so staff can pre-link a new order
     * to an existing billing (via the "Create Order" action on ViewBilling).
     * When set, GenerateBillingForOrder attaches items to this billing instead
     * of creating a new one.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('billing_id')->nullable()->after('prescription_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['billing_id']);
            $table->dropColumn('billing_id');
        });
    }
};
