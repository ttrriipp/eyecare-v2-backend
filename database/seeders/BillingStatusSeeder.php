<?php

namespace Database\Seeders;

use App\Models\BillingStatus;
use Illuminate\Database\Seeder;

class BillingStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            'issued',
            'partially_paid',
            'paid',
            'voided',
        ])->each(fn (string $name) => BillingStatus::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
