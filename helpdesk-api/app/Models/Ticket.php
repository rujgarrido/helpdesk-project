<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
// BelongsTo is the type hint for "many-to-one" relationships
// (many tickets BELONG TO one user).
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WHAT: Represents one row of the `tickets` table.
 * WHY: Eloquent (Laravel's ORM) maps this class <-> table automatically:
 * class name "Ticket" -> table "tickets", and each row becomes an object.
 */
class Ticket extends Model
{
    /**
     * WHICH COLUMNS ARE MASS-ASSIGNABLE.
     * WHY: Laravel protects against "mass assignment attacks" — by default
     * create()/update() IGNORE any field not listed here. We deliberately
     * leave `user_id` OUT: ownership must only ever be set server-side from
     * the authenticated request, never from client JSON like {"user_id": 999}.
     * `status` IS listed because TicketController::update() explicitly
     * validates it (in:open,in_progress,closed) before calling update() —
     * without this entry Eloquent would silently DROP it and the React
     * status dropdown would appear to work but never save.
     *
     * HOW IT CONNECTS: TicketController::store() runs
     * $request->user()->tickets()->create($validated) — only keys listed
     * in $fillable actually get written to the database.
     */
    protected $fillable = [
        'title',
        'description',
        'priority',
        'status', // only ever mass-assigned after server-side validation
        // NEW: which category (validated with exists:categories,id before
        // it can ever reach create()/update()).
        'category_id',
        // NOTE: `assigned_to` is DELIBERATELY absent — assignment is a
        // staff-only power, so TicketController sets that attribute
        // DIRECTLY ($ticket->assigned_to = ...) after the role check,
        // never through mass assignment.
    ];

    /**
     * RELATIONSHIP: a ticket belongs to one user.
     *
     * WHY: lets us do $ticket->user (lazy-load the owner) and, more
     * importantly, lets the controller do $request->user()->tickets to fetch
     * ONLY the current user's tickets (the "index" query).
     *
     * HOW IT WORKS: BelongsTo infers the foreign key (`user_id`) and the
     * related key (`id`) from the model names: Ticket -> user_id, User -> id.
     * In SQL terms this generates: SELECT * FROM tickets WHERE user_id = ?
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * RELATIONSHIP: a ticket belongs to one category (or none → NULL,
     * shown as "Uncategorized" — possible after a category is deleted).
     * WHY a relation: the JSON then nests the whole category object
     * ({category: {id, name}}) so React renders a badge with NO extra
     * API call (enabled by eager loading ->with('category') in the controller).
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * RELATIONSHIP: a ticket belongs to ONE assignee (the agent handling
     * it) — or to NOBODY (null = unassigned, waiting in the queue).
     * WHY explicit FK: belongsTo would infer `user_id` from User::class,
     * but the column is `assigned_to`; the inverse is User::assignedTickets().
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
