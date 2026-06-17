<?php

namespace Database\Seeders;

use App\Models\DiscountType;
use Illuminate\Database\Seeder;

class DiscountTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Senior Citizen', 'type' => 'percentage', 'value' => 20],
            ['name' => 'PWD',            'type' => 'percentage', 'value' => 20],
            ['name' => 'Loyalty',        'type' => 'percentage', 'value' => 10],
            ['name' => 'Custom',         'type' => 'fixed',      'value' => 0],
        ];

        foreach ($types as $data) {
            DiscountType::query()->firstOrCreate(
                ['name' => $data['name']],
                ['type' => $data['type'], 'value' => $data['value'], 'is_active' => true],
            );
        }
    }
}
