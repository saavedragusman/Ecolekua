<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Case-insensitive, accent-sensitive collation of name columns (DEC-PRD-45, design Decision 3).
     */
    private const NAME_COLLATION = 'utf8mb4_0900_as_ci';

    /**
     * Run the migrations.
     *
     * Products of spec 003 (design Decision 3): the product, the attributes it declares with their
     * role and allowed values, and its admitted detail locations and customizations. Everything
     * that belongs to a product cascades with it (`DeleteProduct` still checks, copies and audits
     * first); every foreign key toward catalog rows and toward another product restricts, so a
     * referenced category, attribute, value, location or service product cannot disappear.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->collation(self::NAME_COLLATION)->unique();
            $table->text('description')->nullable();
            $table->foreignId('product_category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('business_line', 20);
            $table->string('supply_mode', 30);
            // Default minimum stock: only in `stock_with_minimum`, mandatory there (DEC-PRD-46).
            $table->unsignedSmallInteger('min_stock_default')->nullable();
            // Stored flag; the effective custom color is computed (`Product::admitsCustomColor()`).
            $table->boolean('allows_custom_color')->default(false);
            $table->boolean('portal_visible')->default(true);
            $table->string('status', 20)->default('active');
            $table->string('image_display_path', 255)->nullable();
            $table->string('image_thumb_path', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'name']);
        });

        // Database guarantees of the supply mode rules (design Decision 3, MySQL 8.4 CHECK).
        DB::statement("ALTER TABLE `products` ADD CONSTRAINT `products_min_stock_only_with_minimum` CHECK (`min_stock_default` IS NULL OR `supply_mode` = 'stock_with_minimum')");
        DB::statement("ALTER TABLE `products` ADD CONSTRAINT `products_min_stock_required_with_minimum` CHECK (`supply_mode` <> 'stock_with_minimum' OR `min_stock_default` IS NOT NULL)");
        DB::statement("ALTER TABLE `products` ADD CONSTRAINT `products_custom_color_only_on_demand` CHECK (`allows_custom_color` = 0 OR `supply_mode` = 'on_demand')");

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('catalog_attribute_id')->constrained('catalog_attributes')->restrictOnDelete();
            $table->string('role', 10);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            // An attribute appears once in a product (E-06).
            $table->unique(['product_id', 'catalog_attribute_id']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->foreignId('product_attribute_id')->constrained('product_attributes')->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->restrictOnDelete();

            $table->primary(['product_attribute_id', 'attribute_value_id']);
        });

        Schema::create('product_detail_locations', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('detail_location_id')->constrained('detail_locations')->restrictOnDelete();

            $table->primary(['product_id', 'detail_location_id']);
        });

        Schema::create('product_customizations', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('service_product_id')->constrained('products')->restrictOnDelete();

            $table->primary(['product_id', 'service_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_customizations');
        Schema::dropIfExists('product_detail_locations');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('products');
    }
};
