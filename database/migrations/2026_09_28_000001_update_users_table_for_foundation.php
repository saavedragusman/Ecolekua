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
     * Adapts the starter-kit users table to the foundation schema (FND-007, FND-008):
     * split name, add status/forced-change flags, and drop the unused email verification
     * and remember-me columns (design.md Decision 13).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->default('')->after('id');
            $table->string('last_name', 100)->default('')->after('first_name');
            $table->boolean('is_active')->default(true)->after('password')->index();
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });

        // Only fictitious local data exists: the whole former name becomes the first name.
        DB::table('users')->update([
            'first_name' => DB::raw('LEFT(`name`, 100)'),
            'last_name' => '',
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->default(null)->change();
            $table->string('last_name', 100)->default(null)->change();
            $table->dropColumn(['name', 'email_verified_at', 'remember_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->rememberToken()->after('password');
        });

        DB::table('users')->update([
            'name' => DB::raw("TRIM(CONCAT(`first_name`, ' ', `last_name`))"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default(null)->change();
            $table->dropIndex(['is_active']);
            $table->dropColumn(['first_name', 'last_name', 'is_active', 'must_change_password']);
        });
    }
};
