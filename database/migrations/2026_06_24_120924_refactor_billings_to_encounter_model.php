<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            // Add new direct FK columns (nullable initially for data migration)
            $table->foreignId('customer_id')->nullable()->after('id')->constrained('users');
            $table->foreignId('order_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->foreignId('discount_type_id')->nullable()->after('order_id')->constrained();
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_type_id');
            $table->decimal('subtotal', 10, 2)->default(0)->after('discount_amount');
        });

        // Migrate data: fill customer_id + order_id from existing billable references
        DB::statement("
            UPDATE billings b
            INNER JOIN orders o ON b.billable_type = 'App\\\\Models\\\\Order' AND b.billable_id = o.id
            SET b.customer_id = o.customer_id, b.order_id = o.id, b.subtotal = b.total_amount
        ");

        DB::statement("
            UPDATE billings b
            INNER JOIN service_records sr ON b.billable_type = 'App\\\\Models\\\\ServiceRecord' AND b.billable_id = sr.id
            SET b.customer_id = sr.customer_id, b.subtotal = b.total_amount
        ");

        // Make customer_id non-nullable now that data is populated
        Schema::table('billings', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable(false)->change();

            // Drop polymorphic columns
            $table->dropIndex(['billable_type', 'billable_id']);
            $table->dropColumn(['billable_type', 'billable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->string('billable_type')->nullable()->after('id');
            $table->unsignedBigInteger('billable_id')->nullable()->after('billable_type');
            $table->index(['billable_type', 'billable_id']);
        });

        DB::statement("
            UPDATE billings b
            INNER JOIN orders o ON b.order_id = o.id
            SET b.billable_type = 'App\\\\Models\\\\Order', b.billable_id = o.id
        ");

        Schema::table('billings', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['order_id']);
            $table->dropForeign(['discount_type_id']);
            $table->dropColumn(['customer_id', 'order_id', 'discount_type_id', 'discount_amount', 'subtotal']);
        });
    }
};
