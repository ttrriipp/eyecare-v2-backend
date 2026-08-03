<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create billing_record_items table
        Schema::create('billing_record_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_record_id')->constrained('billing_records')->cascadeOnDelete();
            $table->string('item_type', 20); // product, service, legacy_other
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->foreignId('job_order_item_id')->nullable()->constrained('job_order_items')->nullOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('encounters')->nullOnDelete();
            $table->timestamps();
        });

        // 2. Add financial summary fields to billing_records
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->decimal('subtotal_amount', 12, 2)->default(0)->after('status');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal_amount');
        });

        // 3. Make job_order_id nullable and non-unique
        // Drop foreign key and unique constraint together
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->dropForeign(['job_order_id']);
            $table->dropUnique(['job_order_id']);
        });

        // Recreate as nullable with regular index
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->foreignId('job_order_id')->nullable()->change();
            $table->index('job_order_id');
        });

        // Restore foreign key
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->foreign('job_order_id')->references('id')->on('job_orders')->nullOnDelete();
        });

        // 4. Backfill billing_record_items from existing job_order_items
        DB::statement(<<<'SQL'
            INSERT INTO billing_record_items (billing_record_id, item_type, description, quantity, unit_price, amount, job_order_item_id, created_at, updated_at)
            SELECT
                br.id,
                COALESCE(joi.item_type, 'legacy_other'),
                joi.description,
                joi.quantity,
                joi.unit_price,
                joi.amount,
                joi.id,
                br.created_at,
                br.updated_at
            FROM billing_records br
            JOIN job_orders jo ON br.job_order_id = jo.id
            JOIN job_order_items joi ON joi.job_order_id = jo.id
            WHERE br.deleted_at IS NULL
        SQL);

        // 5. Copy subtotal and discount from quotation to billing_records
        DB::statement(<<<'SQL'
            UPDATE billing_records br
            JOIN job_orders jo ON br.job_order_id = jo.id
            JOIN quotations q ON jo.quotation_id = q.id
            SET
                br.subtotal_amount = COALESCE(q.subtotal, br.total_amount),
                br.discount_amount = COALESCE(q.discount_amount, 0)
            WHERE br.deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->dropColumn(['subtotal_amount', 'discount_amount']);
            $table->dropForeign(['job_order_id']);
            $table->dropIndex(['job_order_id']);
            $table->dropColumn('job_order_id');
        });

        Schema::table('billing_records', function (Blueprint $table): void {
            $table->foreignId('job_order_id')->unique()->constrained('job_orders')->cascadeOnDelete();
        });

        Schema::dropIfExists('billing_record_items');
    }
};
