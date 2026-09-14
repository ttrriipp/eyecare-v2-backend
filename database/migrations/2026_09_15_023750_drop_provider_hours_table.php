<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove provider-hour audit rows.
        DB::table('audit_logs')
            ->where('action', 'provider_hours.updated')
            ->delete();

        Schema::dropIfExists('provider_hours');
    }

    public function down(): void
    {
        Schema::create('provider_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'weekday']);
        });
    }
};
