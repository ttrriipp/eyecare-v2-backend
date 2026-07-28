<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_record_id')->nullable()->constrained('billing_records')->nullOnDelete();
            $table->foreignId('dispensed_by')->constrained('users');
            $table->string('recipient_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('dispensed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensing_events');
    }
};
