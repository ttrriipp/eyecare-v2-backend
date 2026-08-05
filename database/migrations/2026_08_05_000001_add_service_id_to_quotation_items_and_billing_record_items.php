<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->foreignId('service_id')->nullable()->after('lens_category_id')->constrained()->nullOnDelete();
        });

        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->foreignId('service_id')->nullable()->after('quotation_item_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
        });

        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
        });
    }
};
