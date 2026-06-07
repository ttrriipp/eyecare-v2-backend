<?php

namespace Database\Seeders;

use App\Models\PaymentStatus;
use Illuminate\Database\Seeder;

class PaymentStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            'posted',
            'voided',
            'reversed',
        ])->each(fn (string $name) => PaymentStatus::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
