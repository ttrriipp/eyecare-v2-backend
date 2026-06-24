<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_channels');
    }

    public function down(): void
    {
        Schema::create('notification_channels', function ($table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('notification_templates', function ($table) {
            $table->id();
            $table->timestamps();
        });
    }
};
