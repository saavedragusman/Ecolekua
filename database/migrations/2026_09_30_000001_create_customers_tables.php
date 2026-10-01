<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Customer registry of spec 002 (design.md Decision 2). Contact and address are one-to-one
     * parts of the customer and go away with it; the advisor and the creator are users, who are
     * never deleted, so those keys restrict. Future history tables (quotations, orders, payments)
     * must reference `customers.id` with `restrictOnDelete()`.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name', 200);
            $table->string('document_type', 20)->nullable();
            $table->string('document_number', 30)->nullable();
            $table->string('phone', 16)->index();
            $table->string('email', 255)->nullable();
            $table->unsignedTinyInteger('birthday_day')->nullable();
            $table->unsignedTinyInteger('birthday_month')->nullable();
            $table->unsignedTinyInteger('anniversary_day')->nullable();
            $table->unsignedTinyInteger('anniversary_month')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('advisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['document_type', 'document_number'], 'customers_document_unique');
            $table->index(['status', 'name']);
        });

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('position', 100)->nullable();
            $table->string('phone', 16);
            $table->string('email', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('line', 255);
            $table->string('city', 100);
            $table->string('state', 40);
            $table->string('reference', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customers');
    }
};
