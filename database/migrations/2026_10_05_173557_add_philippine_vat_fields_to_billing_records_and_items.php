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
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->unsignedTinyInteger('vat_calculation_version')->nullable()->after('discount_amount');
            $table->string('discount_type', 24)->nullable()->after('vat_calculation_version');
            $table->boolean('discount_eligibility_verified')->default(false)->after('discount_type');
            $table->decimal('vatable_sales_amount', 12, 2)->default(0)->after('discount_type');
            $table->decimal('vat_amount', 12, 2)->default(0)->after('vatable_sales_amount');
            $table->decimal('vat_exempt_sales_amount', 12, 2)->default(0)->after('vat_amount');
            $table->decimal('zero_rated_sales_amount', 12, 2)->default(0)->after('vat_exempt_sales_amount');
            $table->decimal('vat_exemption_amount', 12, 2)->default(0)->after('zero_rated_sales_amount');
        });

        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->string('vat_treatment', 16)->nullable()->after('amount');
            $table->boolean('statutory_discount_eligible')->default(false)->after('vat_treatment');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('vat_treatment', 16)->default('vatable');
            $table->boolean('statutory_discount_eligible')->default(false);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->string('vat_treatment', 16)->default('vatable');
            $table->boolean('statutory_discount_eligible')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->dropColumn(['vat_treatment', 'statutory_discount_eligible']);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['vat_treatment', 'statutory_discount_eligible']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['vat_treatment', 'statutory_discount_eligible']);
        });

        Schema::table('billing_records', function (Blueprint $table): void {
            $table->dropColumn([
                'vat_calculation_version',
                'discount_type',
                'discount_eligibility_verified',
                'vatable_sales_amount',
                'vat_amount',
                'vat_exempt_sales_amount',
                'zero_rated_sales_amount',
                'vat_exemption_amount',
            ]);
        });
    }
};
