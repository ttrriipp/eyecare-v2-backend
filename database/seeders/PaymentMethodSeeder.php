<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        collect(['Cash', 'GCash', 'Bank Transfer', 'Credit Card', 'Check'])
            ->each(fn (string $name) => PaymentMethod::query()->firstOrCreate(['name' => $name]));
    }
}
