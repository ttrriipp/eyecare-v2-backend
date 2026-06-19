<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('lens_type_id')->nullable()->change();
            $table->string('lens_type_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('lens_type_id')->nullable(false)->change();
            $table->string('lens_type_name')->nullable(false)->change();
        });
    }
};
