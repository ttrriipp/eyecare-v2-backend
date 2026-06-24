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
            $table->string('billable_type')->nullable()->after('id');
            $table->unsignedBigInteger('billable_id')->nullable()->after('billable_type');
            $table->index(['billable_type', 'billable_id']);
        });

        // Migrate existing order billings to the polymorphic columns
        DB::table('billings')
            ->whereNotNull('order_id')
            ->update([
                'billable_type' => 'App\\Models\\Order',
                'billable_id' => DB::raw('order_id'),
            ]);

        Schema::table('billings', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropUnique(['order_id']);
            $table->dropColumn('order_id');

            // Make morph columns non-nullable now that data is migrated
            $table->string('billable_type')->nullable(false)->change();
            $table->unsignedBigInteger('billable_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('id');
        });

        DB::table('billings')
            ->where('billable_type', 'App\\Models\\Order')
            ->update(['order_id' => DB::raw('billable_id')]);

        Schema::table('billings', function (Blueprint $table) {
            $table->dropIndex(['billable_type', 'billable_id']);
            $table->dropColumn(['billable_type', 'billable_id']);
        });
    }
};
