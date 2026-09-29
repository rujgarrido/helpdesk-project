<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WHAT: Creates the `tickets` table in SQLite.
     * WHY a separate migration file: migrations are version-controlled steps
     * for your database — `php artisan migrate` runs this once to create the
     * table, and `migrate:rollback` can drop it again via down() below.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            // id() = bigIncrements — an auto-incrementing primary key (ticket #1, #2...).
            $table->id();

            // WHICH USER OWNS THIS TICKET.
            // foreignId('user_id')->constrained() creates an integer column named
            // user_id AND a foreign key pointing to users.id, so SQLite refuses
            // to insert a ticket whose user_id doesn't exist in `users`.
            // cascadeOnDelete(): if a user is deleted, their tickets are deleted too.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Short summary of the problem, e.g. "Printer not working".
            // string() = VARCHAR(255) by default.
            $table->string('title');

            // Full details — text() allows long content (unbounded in SQLite).
            $table->text('description');

            // 'open' | 'in_progress' | 'closed'.
            // default('open'): every new ticket starts as "open", so the
            // controller can omit `status` when inserting and the DB still
            // has a valid value. Same idea for priority below.
            $table->string('status')->default('open');

            // 'low' | 'medium' | 'high' — default 'low'.
            $table->string('priority')->default('low');

            // created_at / updated_at — added automatically by $table->timestamps().
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     * WHAT: drops the table if we roll back. `dropIfExists` is safe to call
     * even when the table doesn't exist.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
