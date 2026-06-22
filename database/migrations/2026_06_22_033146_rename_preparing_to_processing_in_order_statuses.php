<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_statuses')->where('name', 'preparing')->update(['name' => 'processing']);
    }

    public function down(): void
    {
        DB::table('order_statuses')->where('name', 'processing')->update(['name' => 'preparing']);
    }
};
