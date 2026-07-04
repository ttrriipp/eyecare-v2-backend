<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Consolidates the `contact_lens` and `accessory` product types into `general`.
     *
     * Not reversible: once merged, the original distinction between `contact_lens`
     * and `accessory` is lost. A forward-fix migration would be required to split
     * them again, which would need manual reclassification by an admin.
     */
    public function up(): void
    {
        DB::table('products')
            ->whereIn('product_type', ['contact_lens', 'accessory'])
            ->update(['product_type' => 'general']);
    }

    public function down(): void
    {
        // Intentionally irreversible — see class docblock.
    }
};
