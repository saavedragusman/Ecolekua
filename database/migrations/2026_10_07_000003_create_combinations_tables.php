<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Case-insensitive, accent-sensitive collation of code columns (DT-01, design Decision 4).
     */
    private const NAME_COLLATION = 'utf8mb4_0900_as_ci';

    /**
     * Run the migrations.
     *
     * Combinations of spec 003 (design Decisions 3, 4, 5 and 13): the commercial combinations of a
     * product with their axis values, the code registry shared with combos, the services a
     * combination includes and the minimum stock overrides. `catalog_codes.combo_id` is a plain
     * nullable unique column here: its foreign key to `combos` is added by the migration that creates
     * that table (Phase 13). The overlap rule itself lives in the Actions; the database only
     * guarantees exact duplicates through `active_signature`.
     */
    public function up(): void
    {
        Schema::create('combinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('active');
            // SHA-256 of the sorted `attributeId:valueId` axis pairs, written by the Actions.
            $table->char('axis_signature', 64);
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });

        // Only active combinations take part in the exact-duplicate guarantee (DEC-PRD-39).
        DB::statement("ALTER TABLE `combinations` ADD COLUMN `active_signature` CHAR(64) GENERATED ALWAYS AS (IF(`status` = 'active', `axis_signature`, NULL)) STORED AFTER `axis_signature`");
        DB::statement('ALTER TABLE `combinations` ADD UNIQUE `combinations_product_active_signature_unique` (`product_id`, `active_signature`)');

        Schema::create('catalog_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->collation(self::NAME_COLLATION)->unique();
            $table->foreignId('combination_id')->nullable()->unique()->constrained('combinations')->cascadeOnDelete();
            $table->unsignedBigInteger('combo_id')->nullable()->unique();
            $table->timestamps();
        });

        // A code belongs to exactly one combination or one combo (DEC-PRD-01, DEC-PRD-14, E-08).
        DB::statement('ALTER TABLE `catalog_codes` ADD CONSTRAINT `catalog_codes_exactly_one_owner` CHECK ((`combination_id` IS NULL) <> (`combo_id` IS NULL))');

        Schema::create('combination_values', function (Blueprint $table) {
            $table->foreignId('combination_id')->constrained('combinations')->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_attribute_id');
            $table->unsignedBigInteger('attribute_value_id');

            $table->primary(['combination_id', 'attribute_value_id']);
            $table->index(['catalog_attribute_id', 'attribute_value_id']);
            // The value must belong to the stated attribute.
            $table->foreign(['attribute_value_id', 'catalog_attribute_id'], 'combination_values_value_attribute_foreign')
                ->references(['id', 'catalog_attribute_id'])
                ->on('attribute_values')
                ->restrictOnDelete();
        });

        Schema::create('combination_customizations', function (Blueprint $table) {
            $table->foreignId('combination_id')->constrained('combinations')->cascadeOnDelete();
            $table->foreignId('service_product_id')->constrained('products')->restrictOnDelete();

            $table->primary(['combination_id', 'service_product_id']);
        });

        Schema::create('stock_minimum_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combination_id')->constrained('combinations')->cascadeOnDelete();
            $table->foreignId('size_value_id')->nullable()->constrained('attribute_values')->restrictOnDelete();
            $table->unsignedSmallInteger('minimum');
            $table->timestamps();
        });

        // NULL sizes would never collide in a unique index, so the key maps them to 0.
        DB::statement('ALTER TABLE `stock_minimum_overrides` ADD COLUMN `size_key` BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(`size_value_id`, 0)) STORED');
        DB::statement('ALTER TABLE `stock_minimum_overrides` ADD UNIQUE `stock_minimum_overrides_combination_size_unique` (`combination_id`, `size_key`)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_minimum_overrides');
        Schema::dropIfExists('combination_customizations');
        Schema::dropIfExists('combination_values');
        Schema::dropIfExists('catalog_codes');
        Schema::dropIfExists('combinations');
    }
};
