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
     * Combos of spec 003 (design Decisions 3, 4 and 15): the combo, its components and the values each
     * component admits, plus the foreign key that ties `catalog_codes.combo_id` (created in the
     * combinations migration) to `combos`. The component product is restricted so a referenced product
     * cannot be deleted (E-28); the rules of the components live in the Actions.
     */
    public function up(): void
    {
        Schema::create('combos', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->collation(self::NAME_COLLATION)->unique();
            $table->boolean('portal_visible')->default(true);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('combo_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_id')->constrained('combos')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('sort_order');
            $table->timestamps();
        });

        // No rows for an attribute of a component means no restriction on it (DEC-PRD-44).
        Schema::create('combo_component_values', function (Blueprint $table) {
            $table->foreignId('combo_component_id')->constrained('combo_components')->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_attribute_id');
            $table->unsignedBigInteger('attribute_value_id');

            $table->primary(['combo_component_id', 'attribute_value_id']);
            $table->index(['catalog_attribute_id', 'attribute_value_id'], 'combo_component_values_attribute_value_index');
            // The value must belong to the stated attribute.
            $table->foreign(['attribute_value_id', 'catalog_attribute_id'], 'combo_component_values_value_attribute_foreign')
                ->references(['id', 'catalog_attribute_id'])
                ->on('attribute_values')
                ->restrictOnDelete();
        });

        Schema::table('catalog_codes', function (Blueprint $table) {
            $table->foreign('combo_id')->references('id')->on('combos')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_codes', function (Blueprint $table) {
            $table->dropForeign(['combo_id']);
        });

        Schema::dropIfExists('combo_component_values');
        Schema::dropIfExists('combo_components');
        Schema::dropIfExists('combos');
    }
};
