<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add direct commercial fields to quotations
        Schema::table('quotations', function (Blueprint $table): void {
            $table->decimal('subtotal', 12, 2)->default(0)->after('valid_until');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal');
            $table->decimal('total', 12, 2)->default(0)->after('discount_amount');
            $table->foreignId('presented_by')->nullable()->after('total')->constrained('users')->nullOnDelete();
            $table->timestamp('presented_at')->nullable()->after('presented_by');
            $table->foreignId('confirmed_by')->nullable()->after('presented_at')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
        });

        // 2. Add direct quotation_id to quotation_items
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->foreignId('quotation_id')->nullable()->after('id')->constrained('quotations')->cascadeOnDelete();
        });

        // 3. Add direct quotation_id to job_orders
        Schema::table('job_orders', function (Blueprint $table): void {
            $table->foreignId('quotation_id')->nullable()->after('prescription_id')->constrained('quotations')->nullOnDelete();
        });

        // 4. Add payment_due_date to billing_records
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->date('payment_due_date')->nullable()->after('void_reason');
        });

        // 5. Backfill quotations from highest revision
        $this->backfillQuotationTotals();

        // 6. Backfill quotation_items.quotation_id
        $this->backfillQuotationItemDirectKeys();

        // 7. Backfill job_orders.quotation_id
        $this->backfillJobOrderDirectKeys();

        // 8. Add indexes for direct lookups
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->index('quotation_id');
        });

        Schema::table('job_orders', function (Blueprint $table): void {
            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table): void {
            $table->dropForeign(['quotation_id']);
            $table->dropIndex(['quotation_id']);
            $table->dropColumn('quotation_id');
        });

        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropForeign(['quotation_id']);
            $table->dropIndex(['quotation_id']);
            $table->dropColumn('quotation_id');
        });

        Schema::table('billing_records', function (Blueprint $table): void {
            $table->dropColumn('payment_due_date');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropForeign(['presented_by']);
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn([
                'subtotal',
                'discount_amount',
                'total',
                'presented_by',
                'presented_at',
                'confirmed_by',
                'confirmed_at',
            ]);
        });
    }

    private function backfillQuotationTotals(): void
    {
        // Copy totals from the highest revision number for each quotation
        DB::statement(<<<'SQL'
            UPDATE quotations q
            INNER JOIN (
                SELECT
                    qr.quotation_id,
                    qr.subtotal,
                    qr.discount_amount,
                    qr.total,
                    qr.presented_by,
                    qr.presented_at,
                    qr.accepted_by,
                    qr.accepted_at
                FROM quotation_revisions qr
                INNER JOIN (
                    SELECT quotation_id, MAX(revision_number) AS max_rev
                    FROM quotation_revisions
                    GROUP BY quotation_id
                ) latest ON qr.quotation_id = latest.quotation_id
                    AND qr.revision_number = latest.max_rev
            ) source ON q.id = source.quotation_id
            SET
                q.subtotal = source.subtotal,
                q.discount_amount = source.discount_amount,
                q.total = source.total,
                q.presented_by = source.presented_by,
                q.presented_at = source.presented_at,
                q.confirmed_by = source.accepted_by,
                q.confirmed_at = source.accepted_at
        SQL);
    }

    private function backfillQuotationItemDirectKeys(): void
    {
        // Link items to their quotation through the revision
        DB::statement(<<<'SQL'
            UPDATE quotation_items qi
            INNER JOIN quotation_revisions qr ON qi.quotation_revision_id = qr.id
            SET qi.quotation_id = qr.quotation_id
        SQL);
    }

    private function backfillJobOrderDirectKeys(): void
    {
        // Link job orders to their quotation through the revision
        DB::statement(<<<'SQL'
            UPDATE job_orders jo
            INNER JOIN quotation_revisions qr ON jo.quotation_revision_id = qr.id
            SET jo.quotation_id = qr.quotation_id
        SQL);
    }
};
