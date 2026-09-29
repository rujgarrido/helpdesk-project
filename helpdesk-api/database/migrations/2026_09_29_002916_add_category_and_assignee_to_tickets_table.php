<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WHAT: Adds two nullable foreign keys to the existing `tickets` table:
     *   1. category_id — which category the ticket belongs to (required at
     *      the API level via validation, but the column is nullable so this
     *      migration can run on any pre-existing rows without failing).
     *   2. assigned_to — which STAFF member is handling it (nullable because
     *      a brand-new ticket starts unassigned, waiting in the queue).
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // 1. CATEGORY → categories.id.
            // nullOnDelete(): if an admin deletes a category, tickets don't
            // vanish — their category_id falls back to NULL (shown as
            // "Uncategorized" in the UI). Compare with user_id above, which
            // uses cascadeOnDelete because deleting a customer should remove
            // their tickets.
            $table->foreignId('category_id')
                ->nullable()
                ->after('priority')
                ->constrained()
                ->nullOnDelete();

            // 2. ASSIGNEE → users.id (the agent working the ticket).
            // Also nullOnDelete: if a staff member leaves the company the
            // ticket returns to the queue instead of disappearing.
            $table->foreignId('assigned_to')
                ->nullable()
                ->after('category_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     * dropConstrainedForeignId() drops BOTH the foreign key constraint AND
     * the column in one call — dropping only the column would leave an
     * orphaned constraint behind in the database.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
