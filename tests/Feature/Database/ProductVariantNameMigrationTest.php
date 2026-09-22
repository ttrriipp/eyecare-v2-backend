<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('product variant name migration preserves rows while disambiguating duplicate names', function (): void {
    $connectionName = 'variant_name_migration_test';
    $originalDefaultConnection = config('database.default');

    config([
        "database.connections.{$connectionName}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
        'database.default' => $connectionName,
    ]);

    DB::purge($connectionName);

    try {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('name');
        });

        DB::table('product_variants')->insert([
            ['id' => 1, 'product_id' => 16, 'name' => 'Blue'],
            ['id' => 2, 'product_id' => 16, 'name' => ' Blue '],
            ['id' => 3, 'product_id' => 16, 'name' => 'Blue (2)'],
            ['id' => 4, 'product_id' => 16, 'name' => 'blue'],
            ['id' => 5, 'product_id' => 17, 'name' => 'Blue'],
        ]);

        $migration = require database_path('migrations/2026_09_15_120243_add_unique_variant_name_per_product.php');
        $migration->up();
        $migration->up();

        expect(DB::table('product_variants')->orderBy('id')->pluck('name')->all())
            ->toBe(['Blue', 'Blue (3)', 'Blue (2)', 'blue (4)', 'Blue'])
            ->and(DB::table('product_variants')->orderBy('id')->pluck('id')->all())
            ->toBe([1, 2, 3, 4, 5])
            ->and(Schema::hasIndex('product_variants', 'product_variants_product_id_name_unique'))
            ->toBeTrue();
    } finally {
        DB::disconnect($connectionName);
        DB::purge($connectionName);
        config(['database.default' => $originalDefaultConnection]);
    }
});
