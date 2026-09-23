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
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->string('delivery_state', 40)->nullable()->after('provider_status');
            $table->unsignedSmallInteger('send_attempt_count')->default(0)->after('delivery_state');
            $table->timestamp('send_attempted_at')->nullable()->after('send_attempt_count');
            $table->string('provider_message_id')->nullable()->after('provider_reference');
            $table->timestamp('provider_status_updated_at')->nullable()->after('provider_accepted_at');
            $table->timestamp('provider_status_checked_at')->nullable()->after('provider_status_updated_at');
            $table->string('provider_last_event_id')->nullable()->after('provider_status_checked_at');
            $table->index(
                ['provider_name', 'delivery_state', 'provider_status_checked_at'],
                'sms_notifications_textbee_sync_index',
            );
            $table->index('provider_message_id', 'sms_notifications_provider_message_id_index');
        });

        Schema::create('sms_provider_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('idempotency_key', 191);
            $table->string('event_name', 80);
            $table->foreignId('sms_notification_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_reference')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('provider_status', 80)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['provider', 'idempotency_key'], 'sms_provider_webhook_events_idempotency_unique');
            $table->index(['provider', 'provider_reference'], 'sms_provider_webhook_events_reference_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_provider_webhook_events');

        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->dropIndex('sms_notifications_textbee_sync_index');
            $table->dropIndex('sms_notifications_provider_message_id_index');
            $table->dropColumn([
                'delivery_state',
                'send_attempt_count',
                'send_attempted_at',
                'provider_message_id',
                'provider_status_updated_at',
                'provider_status_checked_at',
                'provider_last_event_id',
            ]);
        });
    }
};
