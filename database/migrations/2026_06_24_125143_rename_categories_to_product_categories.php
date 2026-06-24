<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('categories', 'product_categories');
    }

    public function down(): void
    {
        Schema::rename('product_categories', 'categories');
    }
};
