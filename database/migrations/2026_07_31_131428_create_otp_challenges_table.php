<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose'); // OtpPurpose enum value
            $table->string('channel'); // email or phone
            $table->text('encrypted_destination');
            $table->string('destination_hash'); // blind index for lookups
            $table->string('code_digest'); // hashed OTP code
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('delivery_status')->default('pending'); // pending, sent, failed
            $table->timestamps();

            $table->index(['destination_hash', 'purpose', 'created_at']);
            $table->index(['user_id', 'purpose', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
