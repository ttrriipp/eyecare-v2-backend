<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('id');
        });

        DB::table('users')
            ->select(['id', 'first_name', 'middle_name', 'last_name'])
            ->get()
            ->each(function (object $user): void {
                $name = implode(' ', array_filter([
                    $user->first_name,
                    $user->middle_name,
                    $user->last_name,
                ]));

                DB::table('users')->where('id', $user->id)->update([
                    'name' => $name !== '' ? $name : null,
                ]);
            });
    }
};
