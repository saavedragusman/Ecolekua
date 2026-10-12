<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
     * Catalog of spec 003 (design Decision 3): categories, attributes, attribute values, offered
     * colors of a fabric and detail locations. Catalog items are never deleted (no delete route);
     * every foreign key toward them restricts, so a referenced item cannot disappear. Later tables
     * (products, combinations, combos) must reference `attribute_values` and `catalog_attributes`
     * with `restrictOnDelete()`.
     */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->collation(self::NAME_COLLATION)->unique();
            $table->unsignedInteger('sort_order');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('catalog_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->collation(self::NAME_COLLATION)->unique();
            $table->string('presentation', 10);
            // A null use means "no special use"; MySQL allows many NULLs in a unique index, so only
            // the three real uses (fabric, size, gender) are constrained to one attribute each.
            $table->string('special_use', 10)->nullable()->unique();
            // At most one color-presentation attribute (DEC-PRD-38, E-58): the marker is 1 for it and
            // NULL for every other presentation.
            $table->tinyInteger('color_marker')->nullable()->storedAs("IF(`presentation` = 'color', 1, NULL)")->unique();
            $table->unsignedInteger('sort_order');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_attribute_id')->constrained('catalog_attributes')->restrictOnDelete();
            $table->string('name', 100)->collation(self::NAME_COLLATION);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('sort_order');
            $table->string('status', 20)->default('active');
            $table->char('tone', 7)->nullable();
            $table->string('svg_layer', 64)->nullable();
            $table->string('image_display_path', 255)->nullable();
            $table->string('image_thumb_path', 255)->nullable();
            $table->timestamps();

            $table->unique(['catalog_attribute_id', 'name']);
            // Target of the composite foreign keys that tie a value to its attribute (combination
            // and combo component values, later slices).
            $table->unique(['id', 'catalog_attribute_id']);
        });

        Schema::create('fabric_offered_colors', function (Blueprint $table) {
            $table->foreignId('fabric_value_id')->constrained('attribute_values')->restrictOnDelete();
            $table->foreignId('color_value_id')->constrained('attribute_values')->restrictOnDelete();

            $table->primary(['fabric_value_id', 'color_value_id']);
        });

        Schema::create('detail_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->collation(self::NAME_COLLATION)->unique();
            $table->string('status', 20)->default('active');
            $table->string('svg_layer', 64)->nullable();
            $table->string('image_display_path', 255)->nullable();
            $table->string('image_thumb_path', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detail_locations');
        Schema::dropIfExists('fabric_offered_colors');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('catalog_attributes');
        Schema::dropIfExists('product_categories');
    }
};
