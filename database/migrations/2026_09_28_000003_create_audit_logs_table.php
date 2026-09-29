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
     * Creates the append-only audit trail (FND-022..FND-024). Immutability is enforced
     * at the database level by two triggers; failing to create them must fail loudly.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at', 6);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_email')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();

            $table->index('created_at');
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_block_update BEFORE UPDATE ON audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_block_delete BEFORE DELETE ON audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'
            SQL);
    }

    /**
     * Reverse the migrations.
     *
     * The audit trail must never be dropped in production (FND-024).
     */
    public function down(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Refusing to drop the audit_logs table in production (FND-024).');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_block_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_block_delete');

        Schema::dropIfExists('audit_logs');
    }
};
