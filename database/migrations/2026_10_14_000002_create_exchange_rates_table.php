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
     * Exchange rates of spec 004 (COT-030, design Decision 11): Bs per USD with 2 decimals (DEC-COT-60),
     * one row per fetch or manual entry, append-only. A manual entry supersedes the effective rate of its day by setting
     * `superseded_at`, which empties the generated `effective_date`; the unique key on that column keeps
     * exactly one effective rate per day while every superseded row stays in the history.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date');
            $table->decimal('value', 14, 2);
            $table->string('origin', 10);
            $table->dateTime('source_updated_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('exchange_rates')->restrictOnDelete();
            $table->date('effective_date')->nullable()->storedAs('IF(`superseded_at` IS NULL, `rate_date`, NULL)')->unique();
            $table->timestamp('created_at')->nullable();

            $table->index('rate_date');
        });

        DB::statement('ALTER TABLE `exchange_rates` ADD CONSTRAINT `exchange_rates_value_positive` CHECK (`value` > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
