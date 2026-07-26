<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop FK constraints that reference legacy tables
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['billing_id']);
            $table->dropForeign(['order_status_id']);
        });
        Schema::table('billings', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
        });
        Schema::table('feedback', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
        });

        // Drop legacy tables in reverse dependency order
        Schema::dropIfExists('payments');
        Schema::dropIfExists('billing_items');
        Schema::dropIfExists('service_records');
        Schema::dropIfExists('billings');
        Schema::dropIfExists('billing_statuses');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_statuses');
        Schema::dropIfExists('discount_types');
    }

    public function down(): void
    {
        // Legacy tables cannot be restored — this is a clean cutover
    }
};
