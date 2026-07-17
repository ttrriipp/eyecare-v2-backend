<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('products')->where('product_type', 'general')->exists()) {
            throw new RuntimeException(
                'Legacy general products must be explicitly reclassified before product type expansion can be deployed.',
            );
        }
    }

    /**
     * This guard changes no schema or data, so there is nothing to reverse.
     */
    public function down(): void {}
};
