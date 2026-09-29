<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WHAT: One row of the `categories` lookup table (Hardware, Software, ...).
 * WHY a model: admins manage categories through CategoryController with the
 * same Eloquent flow as tickets — create()/update()/delete() with mass
 * assignment protection and zero raw SQL.
 */
class Category extends Model
{
    /**
     * Only `name` is mass-assignable — CategoryController::store() calls
     * Category::create($validated) where $validated contains ONLY the
     * validated name (required, max length, unique).
     */
    protected $fillable = ['name'];

    /**
     * RELATIONSHIP: one category has MANY tickets.
     * (Inverse of Ticket::category().) HOW IT CONNECTS: when an admin
     * deletes a category, the tickets table FK (nullOnDelete) nulls out
     * category_id; this relation is what future reports will use to count
     * "how many Hardware tickets this week?".
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}