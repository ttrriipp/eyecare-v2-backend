<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->text('od_sphere')->nullable()->change();
            $table->text('od_cylinder')->nullable()->change();
            $table->text('od_axis')->nullable()->change();
            $table->text('od_add')->nullable()->change();
            $table->text('od_prism')->nullable()->change();
            $table->text('od_base')->nullable()->change();
            $table->text('os_sphere')->nullable()->change();
            $table->text('os_cylinder')->nullable()->change();
            $table->text('os_axis')->nullable()->change();
            $table->text('os_add')->nullable()->change();
            $table->text('os_prism')->nullable()->change();
            $table->text('os_base')->nullable()->change();
            $table->text('pd')->nullable()->change();
            $table->text('notes')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->decimal('od_sphere', 5, 2)->nullable()->change();
            $table->decimal('od_cylinder', 5, 2)->nullable()->change();
            $table->unsignedSmallInteger('od_axis')->nullable()->change();
            $table->decimal('od_add', 5, 2)->nullable()->change();
            $table->decimal('od_prism', 5, 2)->nullable()->change();
            $table->string('od_base', 20)->nullable()->change();
            $table->decimal('os_sphere', 5, 2)->nullable()->change();
            $table->decimal('os_cylinder', 5, 2)->nullable()->change();
            $table->unsignedSmallInteger('os_axis')->nullable()->change();
            $table->decimal('os_add', 5, 2)->nullable()->change();
            $table->decimal('os_prism', 5, 2)->nullable()->change();
            $table->string('os_base', 20)->nullable()->change();
            $table->decimal('pd', 5, 2)->nullable()->change();
            $table->text('notes')->nullable()->change();
        });
    }
};
