<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preflight: abort if any quotation-sourced billing items exist.
        $quotationBillingItems = DB::table('billing_record_items')
            ->where('source_kind', 'quotation')
            ->orWhereNotNull('quotation_item_id')
            ->count();

        if ($quotationBillingItems > 0) {
            throw new RuntimeException(
                "Cannot contract quotation schema: {$quotationBillingItems} billing_record_items ".
                'still reference quotation source_kind or quotation_item_id. Resolve these manually before running this migration.'
            );
        }

        // Detach quotation_id from job_orders (preserve the orders).
        DB::table('job_orders')->whereNotNull('quotation_id')->update(['quotation_id' => null]);

        // Remove quotation audit metadata.
        DB::table('audit_logs')
            ->where('subject_type', 'App\\Models\\Quotation')
            ->delete();

        // Drop quotation_item_id from billing_record_items.
        // MySQL InnoDB ties the unique index on (billing_record_id, quotation_item_id)
        // to the billing_record_id FK, so we must drop the FK, drop the index,
        // drop the column, then recreate the FK.
        DB::statement('ALTER TABLE billing_record_items DROP FOREIGN KEY billing_record_items_quotation_item_id_foreign');
        DB::statement('ALTER TABLE billing_record_items DROP FOREIGN KEY billing_record_items_billing_record_id_foreign');
        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->dropUnique('billing_record_items_billing_record_id_quotation_item_id_unique');
            $table->dropColumn('quotation_item_id');
        });
        DB::statement('ALTER TABLE billing_record_items ADD CONSTRAINT billing_record_items_billing_record_id_foreign FOREIGN KEY (billing_record_id) REFERENCES billing_records(id)');

        // Drop quotation_id from billing_records.
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->dropForeign(['quotation_id']);
            $table->dropColumn('quotation_id');
        });

        // Drop quotation_id from job_orders.
        Schema::table('job_orders', function (Blueprint $table): void {
            $table->dropForeign(['quotation_id']);
            $table->dropColumn('quotation_id');
        });

        // Drop quotation_items before quotations (child first).
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }

    public function down(): void
    {
        // Recreate quotations table.
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('quotation_number', 32)->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prescription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Recreate quotation_items table.
        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lens_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lens_option_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_kind', 30)->nullable();
            $table->json('item_snapshot')->nullable();
            $table->timestamps();
        });

        // Re-add quotation_id to job_orders.
        Schema::table('job_orders', function (Blueprint $table): void {
            $table->foreignId('quotation_id')->nullable()->after('prescription_id');
            $table->foreign('quotation_id')->references('id')->on('quotations')->nullOnDelete();
        });

        // Re-add quotation_id to billing_records.
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->foreignId('quotation_id')->nullable()->after('encounter_id');
            $table->foreign('quotation_id')->references('id')->on('quotations')->nullOnDelete();
        });

        // Re-add quotation_item_id to billing_record_items.
        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->foreignId('quotation_item_id')->nullable()->after('job_order_item_id');
            $table->foreign('quotation_item_id')->references('id')->on('quotation_items')->nullOnDelete();
        });
    }
};
