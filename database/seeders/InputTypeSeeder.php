<?php

namespace Database\Seeders;

use App\Enums\InputType as InputTypeEnum;
use App\Models\InputType;
use Illuminate\Database\Seeder;

class InputTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            InputTypeEnum::Text->value => ['name' => 'Text query', 'description' => 'User submitted a free-form text query'],
            InputTypeEnum::Image->value => ['name' => 'Product image', 'description' => 'User submitted a product image'],
        ];

        foreach ($rows as $slug => $attributes) {
            InputType::updateOrCreate(['slug' => $slug], $attributes + ['is_active' => true]);
        }
    }
}
