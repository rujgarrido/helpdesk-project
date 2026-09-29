<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * WHAT: The starter categories every helpdesk needs on day one — these fill
 * the dropdown on the "New Ticket" form in Tickets.jsx.
 * WHY firstOrCreate instead of create: it's IDEMPOTENT — running the
 * seeder twice never crashes on the DB's unique index; existing rows are
 * simply found and skipped. (create() would throw on the second run.)
 * HOW TO RUN: php artisan db:seed --class=CategorySeeder
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate(['name' => X]) = "find by name, or insert it".
        $categories = ['Hardware', 'Software', 'Network', 'Access & Accounts', 'Other'];

        foreach ($categories as $name) {
            Category::firstOrCreate(['name' => $name]);
        }
    }
}