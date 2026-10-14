<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SVG templates of a product (PRD-020, design Decisions 3 and 18). `variant_value_id` is null for
     * the general template or the gender-use value the template is for (DEC-PRD-49); `variant_key`
     * turns the null into 0 so the unique index allows one general template and one per variant.
     * `layers` is the sorted list of `<g id>` read from the sanitized file.
     */
    public function up(): void
    {
        Schema::create('product_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_value_id')->nullable()->constrained('attribute_values')->restrictOnDelete();
            $table->unsignedBigInteger('variant_key')->storedAs('IFNULL(`variant_value_id`, 0)');
            $table->string('svg_path', 255);
            $table->string('original_name', 255);
            $table->json('layers');
            $table->timestamps();

            $table->unique(['product_id', 'variant_key'], 'product_templates_product_variant_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_templates');
    }
};
