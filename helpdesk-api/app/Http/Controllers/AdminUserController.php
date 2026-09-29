<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WHAT: Admin-only user management: list everyone + change roles.
 * WHO CAN DO WHAT: routes/api.php wraps BOTH endpoints in
 * ->middleware('role:admin') — an agent or plain user gets 403 BEFORE
 * reaching this code, so no in-controller role check is needed here.
 * (Compare TicketController, where the rule varies per row and therefore
 * must be checked inside.)
 *
 * HOW IT CONNECTS: React Admin.jsx fetches GET /api/users to render the
 * role table, then sends PATCH /api/users/{id}/role when an admin picks a
 * new role from the dropdown — which triggers this controller.
 */
class AdminUserController extends Controller
{
    /**
     * GET /api/users — everyone, for the admin role table.
     * WHY an explicit column list: only the fields the table shows are
     * serialized. The password hash never leaves the server even without
     * the model's #[Hidden] attribute as a second line of defense.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            User::orderBy('name')->get(['id', 'name', 'email', 'role', 'created_at'])
        );
    }

    /**
     * PATCH /api/users/{user}/role — promote or demote a person.
     * WHY the target user is in the URL (the resource) and the new role in
     * the body (the action): REST-style, and impossible to fat-finger the
     * wrong account into a role change.
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        // GUARD: admins cannot demote THEMSELVES — one misclick and you've
        // locked yourself out of your own admin panel with no UI to undo
        // it. To leave admin, ask another admin.
        if ($user->id === $request->user()->id) {
            abort(403, 'You cannot change your own role.');
        }

        // in:... = whitelist; anything else ('superuser', '', null) → 422
        // JSON that Admin.jsx shows under the select.
        $validated = $request->validate([
            'role' => ['required', 'in:user,agent,admin'],
        ]);

        // Mass assignment succeeds because 'role' IS in User's Fillable
        // attribute. NOTE: register() hardcodes 'role' => 'user', so THIS
        // endpoint is the ONLY place in the entire app where a role changes.
        $user->update($validated);

        return response()->json($user);
    }
}