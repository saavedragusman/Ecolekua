<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Case-insensitive, accent-sensitive collation of name and label columns (003 Decision 3).
     */
    private const NAME_COLLATION = 'utf8mb4_0900_as_ci';

    /**
     * Run the migrations.
     *
     * Pricing tables of spec 004 (design Decision 4): schemes and tiers, the scheme of a product, size
     * groups, combination prices, combo item prices and the single commercial parameters row. Money
     * columns are DECIMAL (DT-01) and every price is positive (DEC-COT-02). Rows that configure prices
     * cascade with the combination or component they price; the scheme of a product and the tiers of a
     * scheme restrict, so a referenced scheme cannot disappear. Rules the database cannot express
     * (a gapless tier set, the groups of a combination) live in the Actions.
     */
    public function up(): void
    {
        Schema::create('price_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->collation(self::NAME_COLLATION)->unique();
            $table->string('type', 10);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_scheme_id')->constrained('price_schemes')->restrictOnDelete();
            $table->string('label', 30)->collation(self::NAME_COLLATION);
            $table->unsignedInteger('min_quantity');
            $table->unsignedInteger('max_quantity')->nullable();
            $table->string('kind', 12);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['price_scheme_id', 'label']);
            $table->unique(['price_scheme_id', 'sort_order']);
        });

        DB::statement('ALTER TABLE `price_tiers` ADD CONSTRAINT `price_tiers_max_not_below_min` CHECK (`max_quantity` IS NULL OR `max_quantity` >= `min_quantity`)');

        // The scheme of a product (COT-002); combos are priced per component and have none.
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('price_scheme_id')->nullable()->after('status')->constrained('price_schemes')->restrictOnDelete();
        });

        Schema::create('size_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combination_id')->constrained('combinations')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });

        // `combination_id` is repeated so one size belongs to at most one group of a combination (COT-004).
        Schema::create('size_group_values', function (Blueprint $table) {
            $table->foreignId('size_group_id')->constrained('size_groups')->cascadeOnDelete();
            $table->foreignId('combination_id')->constrained('combinations')->cascadeOnDelete();
            $table->foreignId('size_value_id')->constrained('attribute_values')->restrictOnDelete();

            $table->primary(['size_group_id', 'size_value_id']);
            $table->unique(['combination_id', 'size_value_id']);
        });

        Schema::create('combination_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combination_id')->constrained('combinations')->cascadeOnDelete();
            $table->foreignId('price_tier_id')->constrained('price_tiers')->cascadeOnDelete();
            $table->foreignId('size_group_id')->nullable()->constrained('size_groups')->cascadeOnDelete();
            $table->decimal('unit_price', 10, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // NULL size groups would never collide in a unique index, so the key maps them to 0 (003 `size_key`
        // precedent). VIRTUAL, not STORED: MySQL rejects ON DELETE CASCADE on the base column of a STORED
        // generated column (error 1215), and a price must go with its size group.
        DB::statement('ALTER TABLE `combination_prices` ADD COLUMN `size_group_key` BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(`size_group_id`, 0)) VIRTUAL');
        DB::statement('ALTER TABLE `combination_prices` ADD UNIQUE `combination_prices_cell_unique` (`combination_id`, `price_tier_id`, `size_group_key`)');
        DB::statement('ALTER TABLE `combination_prices` ADD CONSTRAINT `combination_prices_price_positive` CHECK (`unit_price` > 0)');

        Schema::create('combo_item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_component_id')->unique()->constrained('combo_components')->cascadeOnDelete();
            $table->decimal('unit_price', 10, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE `combo_item_prices` ADD CONSTRAINT `combo_item_prices_price_positive` CHECK (`unit_price` > 0)');

        // One row, id 1 (COT-006): the editable advance percentage and quotation validity.
        Schema::create('commercial_parameters', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->decimal('advance_percentage', 5, 2);
            $table->unsignedSmallInteger('validity_days');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE `commercial_parameters` ADD CONSTRAINT `commercial_parameters_advance_range` CHECK (`advance_percentage` BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE `commercial_parameters` ADD CONSTRAINT `commercial_parameters_validity_minimum` CHECK (`validity_days` >= 1)');

        DB::table('commercial_parameters')->insert([
            'id' => 1,
            'advance_percentage' => '50.00',
            'validity_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_parameters');
        Schema::dropIfExists('combo_item_prices');
        Schema::dropIfExists('combination_prices');
        Schema::dropIfExists('size_group_values');
        Schema::dropIfExists('size_groups');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_scheme_id');
        });

        Schema::dropIfExists('price_tiers');
        Schema::dropIfExists('price_schemes');
    }
};
