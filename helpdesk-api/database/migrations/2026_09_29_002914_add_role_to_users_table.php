<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WHAT: Adds the `role` column that powers our simple RBAC
     * (Role-Based Access Control): 'user' | 'agent' | 'admin'.
     * WHY additive (Schema::table on an existing table) instead of editing
     * the original users migration: once a migration has run, changing it
     * makes your history lie. New columns always arrive via NEW migrations
     * so `php artisan migrate` stays idempotent (safe to re-run).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // string() = VARCHAR(255). default('user') means EVERY new
            // account starts powerless — register() never accepts a role
            // from the client; admins promote people afterwards.
            // WHY NOT a DB enum: SQLite has no native enum type, and
            // 'user,agent,admin' is enforced by validation (in:user,agent,admin)
            // in the AdminUserController instead — one place to change.
            $table->string('role')->default('user');
        });
    }

    /**
     * Reverse the migrations.
     * dropColumn removes just this column (the table stays intact).
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
