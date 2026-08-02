<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0. Backfill any remaining orphaned records (e.g., from seeders that ran after Phase A)
        $this->backfillOrphanedQuotationItems();
        $this->backfillOrphanedJobOrders();

        // 1. Reconciliation guards - verify all migrated data is valid
        $this->guardQuotationItemsHaveDirectQuotation();
        $this->guardJobOrdersHaveDirectQuotation();
        $this->guardNoOrphanedRevisions();

        // 2. Remove revision foreign key from quotation_items (nullable since Phase A)
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropForeign(['quotation_revision_id']);
            $table->dropColumn('quotation_revision_id');
        });

        // 3. Remove revision foreign key from job_orders (nullable since Phase A)
        Schema::table('job_orders', function (Blueprint $table): void {
            $table->dropForeign(['quotation_revision_id']);
            $table->dropColumn('quotation_revision_id');
        });

        // 4. Make quotation_id required on quotation_items
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->foreignId('quotation_id')->nullable(false)->change();
        });

        // 5. Drop the quotation_revisions table
        Schema::dropIfExists('quotation_revisions');
    }

    public function down(): void
    {
        // Recreate quotation_revisions table
        Schema::create('quotation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('presented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('presented_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        // Restore quotation_revision_id on quotation_items
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->foreignId('quotation_revision_id')->nullable()->after('quotation_id')->constrained('quotations')->cascadeOnDelete();
        });

        // Restore quotation_revision_id on job_orders
        Schema::table('job_orders', function (Blueprint $table): void {
            $table->foreignId('quotation_revision_id')->nullable()->after('quotation_id')->constrained('quotations')->nullOnDelete();
        });
    }

    /**
     * Backfill quotation_items that were created after the Phase A migration.
     */
    private function backfillOrphanedQuotationItems(): void
    {
        DB::statement(<<<'SQL'
            UPDATE quotation_items qi
            INNER JOIN quotation_revisions qr ON qi.quotation_revision_id = qr.id
            SET qi.quotation_id = qr.quotation_id
            WHERE qi.quotation_id IS NULL
        SQL);
    }

    /**
     * Backfill job_orders that were created after the Phase A migration.
     */
    private function backfillOrphanedJobOrders(): void
    {
        DB::statement(<<<'SQL'
            UPDATE job_orders jo
            INNER JOIN quotation_revisions qr ON jo.quotation_revision_id = qr.id
            SET jo.quotation_id = qr.quotation_id
            WHERE jo.quotation_id IS NULL AND jo.quotation_revision_id IS NOT NULL
        SQL);
    }

    /**
     * Guard: Every quotation_item must have a valid quotation_id.
     */
    private function guardQuotationItemsHaveDirectQuotation(): void
    {
        $orphans = DB::table('quotation_items')
            ->whereNull('quotation_id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Reconciliation failed: {$orphans} quotation_items have no direct quotation_id. ".
                'Backfill these records before running cleanup.'
            );
        }
    }

    /**
     * Guard: Every job_order with a quotation must have a valid quotation_id.
     */
    private function guardJobOrdersHaveDirectQuotation(): void
    {
        $orphans = DB::table('job_orders')
            ->whereNotNull('quotation_revision_id')
            ->whereNull('quotation_id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Reconciliation failed: {$orphans} job_orders have quotation_revision_id but no quotation_id. ".
                'Backfill these records before running cleanup.'
            );
        }
    }

    /**
     * Guard: No quotation_revisions should reference non-existent quotations.
     */
    private function guardNoOrphanedRevisions(): void
    {
        $orphans = DB::table('quotation_revisions')
            ->leftJoin('quotations', 'quotation_revisions.quotation_id', '=', 'quotations.id')
            ->whereNull('quotations.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Reconciliation failed: {$orphans} quotation_revisions reference non-existent quotations. ".
                'Clean these records before running cleanup.'
            );
        }
    }
};
