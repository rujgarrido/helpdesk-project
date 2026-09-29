<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WHAT: CRUD for the categories lookup table.
 * WHO CAN DO WHAT (enforced in routes/api.php, not here):
 *   GET    /api/categories          → any logged-in user (the ticket form
 *                                      needs the dropdown list)
 *   POST   /api/categories          → admin only ('role:admin' middleware)
 *   PATCH  /api/categories/{id}     → admin only
 *   DELETE /api/categories/{id}     → admin only
 * WHY role checks live in the route file for THIS controller: the rule is
 * per-resource ("only admins write categories"), not per-row. Rules that
 * depend on a specific row's data (owner vs staff of ONE ticket) stay in
 * TicketController instead — front door in routes, safe in controller.
 *
 * HOW IT CONNECTS: React Admin.jsx calls these endpoints for the category
 * manager; Tickets.jsx calls index() to fill the create-ticket dropdown.
 */
class CategoryController extends Controller
{
    /**
     * GET /api/categories — full list for the create-ticket dropdown.
     * orderBy('name') so the <select> is alphabetical and predictable.
     * Eager count not needed: tickets() stays unloaded (lazy) to keep the
     * payload tiny — the form only needs id + name.
     */
    public function index(): JsonResponse
    {
        return response()->json(Category::orderBy('name')->get());
    }

    /**
     * POST /api/categories — create one (admin only).
     * WHY unique in validation when the DB also has a unique index:
     * validation returns a friendly 422 {errors: {name: [...]}} that
     * Admin.jsx can display — a raw DB constraint failure would surface as
     * an ugly 500 instead.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name'],
        ]);

        // 201 = created; the JSON body is the new row (with its id) so the
        // React list can append it without refetching.
        return response()->json(Category::create($validated), 201);
    }

    /**
     * PATCH /api/categories/{category} — rename (admin only).
     * HOW route model binding works: {category} in the route → Laravel
     * loads the row by id (or 404s) and passes it here.
     * WHY ->ignore($category): "unique except MYSELF" — renaming
     * "Hardware" to its own name must not collide with... "Hardware".
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('categories', 'name')->ignore($category),
            ],
        ]);

        $category->update($validated);

        return response()->json($category);
    }

    /**
     * DELETE /api/categories/{category} — remove (admin only).
     * WHY this is safe even while tickets reference the category: the FK
     * added in add_category_and_assignee migration is nullOnDelete, so
     * affected tickets keep existing with category_id = NULL, which the UI
     * renders as "Uncategorized". (Contrast: deleting a USER cascades their
     * tickets away.)
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $category->delete();

        return response()->json(null, 204); // 204 = deleted, no body
    }
}