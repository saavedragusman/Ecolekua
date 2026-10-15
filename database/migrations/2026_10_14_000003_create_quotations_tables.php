<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Quotation tables of spec 004 (design Decision 4): the number sequence, quotations, their lines with
     * size rows, added customizations, combo components and negotiated prices. A draft keeps what the user
     * chose and a calculation cache; an issued quotation also keeps the frozen copy (Decision 18). Money is
     * DECIMAL (DT-01) and the rate has 2 decimals (DEC-COT-60). Every reference from quotation data to a
     * customer, product, combination, combo or service restricts deletes, because it is history (COT-024);
     * the children of a quotation go with it.
     */
    public function up(): void
    {
        // One row, id 1: the last number given; `QuotationNumber::next()` takes it under a row lock.
        Schema::create('quotation_sequences', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('last_number');
        });

        DB::table('quotation_sequences')->insert(['id' => 1, 'last_number' => 0]);

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->string('status', 12);
            $table->string('origin', 10);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->boolean('include_contact')->default(false);
            $table->string('prospect_name', 150)->nullable();
            $table->string('prospect_phone', 20)->nullable();
            $table->string('prospect_email', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('advisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('source_quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('agreed_advance', 14, 2)->nullable();
            $table->foreignId('agreed_advance_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('agreed_advance_at')->nullable();

            // Calculation cache of the last draft save (lists and filters, COT-020).
            $table->decimal('total', 14, 2)->nullable();
            $table->boolean('requires_advisor')->default(false);

            // Frozen copy at issue (COT-016).
            $table->json('pieces_by_line')->nullable();
            $table->decimal('advance_percentage', 5, 2)->nullable();
            $table->decimal('required_advance', 14, 2)->nullable();
            $table->json('advance_breakdown')->nullable();
            $table->decimal('rate_value', 14, 2)->nullable();
            $table->date('rate_date')->nullable();
            $table->string('rate_origin', 10)->nullable();
            $table->decimal('total_bs', 18, 2)->nullable();
            $table->decimal('advance_bs', 18, 2)->nullable();
            $table->json('advisor_reasons')->nullable();
            $table->json('recipient_snapshot')->nullable();
            $table->json('advisor_snapshot')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_on']);
        });

        DB::statement('ALTER TABLE `quotations` ADD CONSTRAINT `quotations_one_recipient` CHECK ((`customer_id` IS NULL) <> (`prospect_name` IS NULL))');

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 10);
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('combo_id')->nullable()->constrained('combos')->restrictOnDelete();
            $table->foreignId('combination_id')->nullable()->constrained('combinations')->restrictOnDelete();
            $table->json('selection');
            $table->unsignedInteger('combo_quantity')->nullable();
            $table->string('note', 500)->nullable();

            // Negotiated price (COT-012) and the basis it was negotiated for (DEC-COT-55).
            $table->foreignId('negotiated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('negotiated_at')->nullable();
            $table->boolean('negotiation_confirmed')->default(false);
            $table->foreignId('negotiated_combination_id')->nullable()->constrained('combinations')->restrictOnDelete();
            $table->json('negotiated_size_group_ids')->nullable();

            // Frozen copy at issue (COT-016).
            $table->string('code', 30)->nullable();
            $table->string('descriptive_name', 255)->nullable();
            $table->json('normalized_selection')->nullable();
            $table->string('tier_label', 30)->nullable();
            $table->string('price_origin', 12)->nullable();
            $table->decimal('subtotal', 14, 2)->nullable();
            $table->boolean('requires_advisor')->default(false);
            $table->string('advisor_reason', 150)->nullable();
            $table->timestamps();

            $table->unique(['quotation_id', 'position']);
        });

        DB::statement("ALTER TABLE `quotation_lines` ADD CONSTRAINT `quotation_lines_kind_target` CHECK ((`kind` = 'product' AND `product_id` IS NOT NULL AND `combo_id` IS NULL) OR (`kind` = 'combo' AND `combo_id` IS NOT NULL AND `product_id` IS NULL))");

        Schema::create('quotation_line_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_line_id')->constrained('quotation_lines')->cascadeOnDelete();
            $table->foreignId('size_value_id')->nullable()->constrained('attribute_values')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('size_group_label', 60)->nullable();
        });

        // A row without size would never collide in a unique index, so the key maps it to 0 (003 `size_key`
        // precedent). STORED is allowed here: the size reference restricts, it does not cascade.
        DB::statement('ALTER TABLE `quotation_line_rows` ADD COLUMN `size_key` BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(`size_value_id`, 0)) STORED');
        DB::statement('ALTER TABLE `quotation_line_rows` ADD UNIQUE `quotation_line_rows_size_unique` (`quotation_line_id`, `size_key`)');
        DB::statement('ALTER TABLE `quotation_line_rows` ADD CONSTRAINT `quotation_line_rows_quantity_minimum` CHECK (`quantity` >= 1)');

        Schema::create('quotation_line_customizations', function (Blueprint $table) {
            $table->foreignId('quotation_line_id')->constrained('quotation_lines')->cascadeOnDelete();
            $table->foreignId('service_product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity_per_garment');

            $table->primary(['quotation_line_id', 'service_product_id']);
        });

        Schema::create('quotation_line_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_line_id')->constrained('quotation_lines')->cascadeOnDelete();
            $table->foreignId('combo_component_id')->nullable()->constrained('combo_components')->nullOnDelete();
            $table->foreignId('combination_id')->nullable()->constrained('combinations')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->json('selection');

            // Frozen copy at issue (COT-016).
            $table->string('code', 30)->nullable();
            $table->string('descriptive_name', 255)->nullable();
            $table->json('normalized_selection')->nullable();
            $table->unsignedSmallInteger('quantity')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();

            $table->unique(['quotation_line_id', 'position']);
        });

        Schema::create('quotation_line_negotiated_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_line_id')->constrained('quotation_lines')->cascadeOnDelete();
            $table->foreignId('size_group_id')->nullable()->constrained('size_groups')->cascadeOnDelete();
            $table->decimal('unit_price', 10, 2);
        });

        // VIRTUAL, not STORED: MySQL rejects ON DELETE CASCADE on the base column of a STORED generated column
        // (error 1215), and a negotiated price goes with its size group (design change CD-02, as CD-01).
        DB::statement('ALTER TABLE `quotation_line_negotiated_prices` ADD COLUMN `size_group_key` BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(`size_group_id`, 0)) VIRTUAL');
        DB::statement('ALTER TABLE `quotation_line_negotiated_prices` ADD UNIQUE `quotation_negotiated_prices_group_unique` (`quotation_line_id`, `size_group_key`)');
        DB::statement('ALTER TABLE `quotation_line_negotiated_prices` ADD CONSTRAINT `quotation_negotiated_prices_price_positive` CHECK (`unit_price` > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_line_negotiated_prices');
        Schema::dropIfExists('quotation_line_components');
        Schema::dropIfExists('quotation_line_customizations');
        Schema::dropIfExists('quotation_line_rows');
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('quotation_sequences');
    }
};
