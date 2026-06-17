<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movement_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('inventory_movement_type_id')->nullable()->constrained('inventory_movement_types');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('type')->nullable();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['inventory_movement_type_id']);
            $table->dropColumn('inventory_movement_type_id');
        });

        Schema::dropIfExists('inventory_movement_types');
    }
};
