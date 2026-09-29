<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WHAT: Full CRUD for tickets — now with simple RBAC ("policy-lite" style,
 * the same inline-abort pattern you already had, extended for roles).
 *
 * THE PERMISSION MATRIX enforced below (kept in ONE place so you can read
 * it like a spec — routes/api.php only adds auth:sanctum + route-level
 * role: middleware for category/user management):
 *
 *   ACTION     | user (requester)          | agent            | admin
 *   -----------+---------------------------+------------------+------------
 *   index      | own tickets               | own + ?scope=all | own + all
 *   show       | own                       | any              | any
 *   store      | own (status forced 'open')| same             | same
 *   update     | own AND only while 'open', | any ticket:      | any ticket
 *              | fields only (no status/   | incl. status +   |
 *              | assignment)               | assignment       |
 *   destroy    | own AND only while 'open' | NEVER (403)      | any
 *
 * HOW IT CONNECTS TO REACT: Tickets.jsx renders controls per role (status
 * dropdown & assign buttons only for staff, delete only when permitted) —
 * but the UI is convenience only; EVERY rule here is re-checked server-side
 * so a hand-crafted curl request hits the same 403s.
 */
class TicketController extends Controller
{
    /**
     * GET /api/tickets — list tickets for the logged-in user.
     *
     * TWO MODES chosen by a query string:
     *   /api/tickets            → MY tickets (every role) — the /tickets page
     *   /api/tickets?scope=all  → ALL tickets (staff only) — the /queue page
     * WHY scope in the URL instead of two endpoints: same controller method,
     * one obvious switch — fewer routes to learn, same behavior.
     *
     * WHY with([...]) eager loading: each ticket's badges (category name,
     * assignee name, requester name) come NESTED in this one response —
     * without it, React would render "null" badges or need N+1 extra calls.
     * (N+1 = 1 query for the list + 1 more PER ticket just to get its
     * category — a classic performance bug this prevents.)
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->query('scope') === 'all') {
            // Queue view: staff only. abort_unless = one-line if+abort.
            abort_unless(
                $request->user()->isStaff(),
                403,
                'Only staff can view all tickets.'
            );

            return response()->json(
                Ticket::with(['user', 'category', 'assignee'])->latest()->get()
            );
        }

        // Default: ONLY my tickets (query-level scoping — other users' rows
        // are never selected, so they cannot leak). Same as before RBAC,
        // just with relations attached for the badges.
        return response()->json(
            $request->user()->tickets()->with(['category', 'assignee'])->latest()->get()
        );
    }

    /**
     * POST /api/tickets — create a ticket (any role; customers are the
     * usual creators, staff can log tickets on someone's behalf).
     *
     * WHAT CHANGED vs the pre-RBAC version: `category_id` is now REQUIRED
     * (real helpdesks triage by category from the first second) and
     * `assigned_to` is NOT accepted — a fresh ticket always starts
     * UNASSIGNED, waiting in the queue for an agent to pick it up.
     * status is still forced server-side to 'open'.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['required', 'in:low,medium,high'],
            // exists:categories,id rejects ids of deleted categories with a
            // friendly 422 instead of an FK violation at insert time.
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            // NOTE what's ABSENT: status, assigned_to, user_id — unknown or
            // unvalidated keys never reach create(). Defense in depth:
            // validation strips them AND $fillable blocks them anyway.
        ]);

        // relationships()->create() stamps user_id = me automatically —
        // ownership still never comes from client input.
        $ticket = $request->user()->tickets()->create([
            ...$validated,      // title, description, priority, category_id
            'status' => 'open', // forced default — same as always
            // assigned_to omitted → NULL → sits in the queue.
        ]);

        // ->load('category') attaches the relation to THIS new row only so
        // the React list can append it with its badge already populated.
        return response()->json($ticket->load('category'), 201);
    }


    /**
     * GET /api/tickets/{ticket} — view one ticket.
     * ROUTE MODEL BINDING: Laravel sees {ticket}, fetches by id (or 404s)
     * and hands the row to this method — before any of our code runs.
     * WHO: the owner OR any staff member (agent must read tickets to work
     * them). Rule lives here (per-row), not in route middleware.
     */
    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeView($request, $ticket);

        return response()->json($ticket->load(['user', 'category', 'assignee']));
    }

    /**
     * PUT/PATCH /api/tickets/{ticket} — edit a ticket.
     *
     * THE CORE RBAC SPLIT: two completely different validation sets.
     *   • Staff (agent/admin): may touch everything except ownership.
     *   • Customer: own ticket, only while 'open', fields only — status
     *     and assignment keys are NOT in their validation array, so even a
     *     hand-crafted JSON body with "status": "closed" gets stripped
     *     (unknown/invalid keys never reach update()).
     * WHY customers can't change status: the agent owns progress in the
     * middle of the lifecycle; letting the requester flip their own ticket
     * to 'closed' mid-investigation is the classic helpdesk design mistake.
     * WHY customers can't edit once it leaves 'open': the agent is now
     * investigating based on the description as written — editing it
     * silently under them derails the work.
     */
    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();

        if ($user->isStaff()) {
            // STAFF may change anything EXCEPT ownership (user_id is never
            // validated here, and it's not in $fillable either — double
            // blocked against transferring a ticket to someone else).
            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['sometimes', 'string'],
                'priority' => ['sometimes', 'in:low,medium,high'],
                'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
                // 'sometimes' = only apply when the key was sent — a
                // status-only PATCH won't wipe the title.
                'status' => ['sometimes', 'in:open,in_progress,closed'],
                // The assignee must EXIST and be STAFF (agent or admin) —
                // assigning a ticket to another customer makes no sense.
                // nullable allows assigned_to: null = unassign/return to queue.
                'assigned_to' => [
                    'sometimes', 'nullable', 'integer',
                    Rule::exists('users', 'id')->whereIn('role', ['agent', 'admin']),
                ],
            ]);
        } else {
            // CUSTOMER: must own it (403 otherwise)...
            $this->authorizeOwnership($request, $ticket);
            // ...and it must still be untouched ('open'). Once staff pick
            // it up (in_progress) or close it, details are frozen.
            abort_unless(
                $ticket->status === 'open',
                403,
                'Only open tickets can be edited.'
            );

            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['sometimes', 'string'],
                'priority' => ['sometimes', 'in:low,medium,high'],
                'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
                // NO status, NO assigned_to in this array → customers can't
                // change them even by sending the keys (validation whitelist).
            ]);
        }

        // assigned_to is DELIBERATELY not in Ticket's $fillable — mass
        // assignment would silently DROP it (same trap as `status` in the
        // original build). So set it as a plain attribute first, then
        // mass-assign the rest. This line can only run after the staff-only
        // validation above already passed.
        if (array_key_exists('assigned_to', $validated)) {
            $ticket->assigned_to = $validated['assigned_to'];
            unset($validated['assigned_to']);
        }

        $ticket->update($validated); // remaining keys are all fillable

        return response()->json($ticket->load(['user', 'category', 'assignee']));
    }

    /**
     * DELETE /api/tickets/{ticket} — remove a ticket.
     *
     * REAL-WORLD NOTE: production helpdesks almost never hard-delete —
     * tickets are history/audit trail (kept via close/archive instead).
     * We keep delete for the learning exercise, but gate it realistically:
     *   • admin — may delete anything (spam, test rows).
     *   • agent — NEVER (close them instead; erasing a customer's report
     *     mid-dispute would destroy evidence).
     *   • customer — own ticket AND only while still 'open' (undoing your
     *     own fresh mistake is fine; erasing a closed/working ticket is not).
     */
    public function destroy(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $ticket->delete();

            // 204 = success with empty body; React just filters the row out.
            return response()->json(null, 204);
        }

        if ($user->isAgent()) {
            abort(403, 'Agents cannot delete tickets — close them instead.');
        }

        // Plain customer path: own ticket only...
        $this->authorizeOwnership($request, $ticket);
        // ...and only while still open.
        abort_unless(
            $ticket->status === 'open',
            403,
            'Only open tickets can be deleted.'
        );

        $ticket->delete();

        return response()->json(null, 204);
    }

    // -----------------------------------------------------------------------
    // SHARED AUTHORIZATION HELPERS ("policy-lite" — inline aborts instead of
    // full Policy classes; same pattern as the original build, extended).
    // -----------------------------------------------------------------------

    /**
     * MUTATION gate: the user must own this ticket (no staff exemption —
     * even admins go through ownership or their staff branch first; this
     * helper is only ever called on the non-staff path).
     *
     * WHY 403 (not 404): for a learning project "exists but not yours" is
     * the clearer message; production apps often 404 instead so attackers
     * can't confirm a ticket id exists.
     * strict !== avoids 5 == "5" coercion surprises between SQLite ints
     * and JSON numbers.
     */
    private function authorizeOwnership(Request $request, Ticket $ticket): void
    {
        if ($ticket->user_id !== $request->user()->id) {
            abort(403, 'You do not own this ticket.');
        }
    }

    /**
     * READ gate: owner OR any staff member.
     * WHY staff pass: agents need to READ every ticket to work the queue
     * (index?scope=all and show are the read side of that same rule).
     * Written as a positive allow-list — if nobody matches, we fall through
     * to the abort, so there is no "accidental default allow".
     */
    private function authorizeView(Request $request, Ticket $ticket): void
    {
        $user = $request->user();

        $isOwner = $ticket->user_id === $user->id;

        if (! $isOwner && ! $user->isStaff()) {
            abort(403, 'You do not own this ticket.');
        }
    }

}