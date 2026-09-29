<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WHAT: Creates the `categories` lookup table (Hardware, Software,
     * Network, ...). WHY a separate table instead of a plain string column
     * on tickets: a table lets admins rename/add categories in one place,
     * keeps spelling consistent (no "Hrdware" vs "Hardware"), and lets us
     * count tickets per category later for reports.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            // Category display name, e.g. "Hardware".
            // unique(): the DB itself rejects duplicates ("Hardware" twice)
            // even if validation in CategoryController were bypassed.
            $table->string('name')->unique();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     * WHY dropColumn for role-style FKs elsewhere: deleting a category that
     * tickets still point to is handled by the FK in the tickets migration
     * (nullOnDelete), so dropping this table is safe on its own.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
