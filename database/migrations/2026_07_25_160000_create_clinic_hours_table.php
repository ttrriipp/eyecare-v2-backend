<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_hours', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('weekday')->unique();
            $table->time('open_time');
            $table->time('close_time');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_hours');
    }
};
