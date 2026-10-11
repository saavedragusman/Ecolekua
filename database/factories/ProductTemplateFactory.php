<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A general template with only the `cuerpo` layer. The file is not written: tests that serve it
 * store their own bytes. All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<ProductTemplate>
 */
class ProductTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_value_id' => null,
            'svg_path' => 'catalog/templates/0/'.fake()->unique()->uuid().'.svg',
            'original_name' => 'plantilla.svg',
            'layers' => ['cuerpo'],
        ];
    }
}
