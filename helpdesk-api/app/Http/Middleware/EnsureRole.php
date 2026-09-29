<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WHAT: The RBAC gate. Blocks a request unless the authenticated user's
 * role is one of the roles named in the route definition.
 *
 * HOW IT CONNECTS (the full chain for `Route::...->middleware('role:admin')`):
 *   routes/api.php  →  'role' alias resolved in bootstrap/app.php  →  this
 *   class runs AFTER auth:sanctum filled $request->user()  →  abort(403)
 *   or $next($request) continues to the controller.
 *
 * The string after the colon becomes the variadic $roles argument:
 *   'role:admin'         → $roles = ['admin']
 *   'role:admin,agent'   → $roles = ['admin', 'agent']
 *
 * WHY middleware AND controller checks: middleware guards whole route groups
 * cheaply ("no admins past this point"); fine-grained per-record rules
 * (owner vs staff on ONE ticket) stay in TicketController's authorize
 * helpers. Both are needed — one is the front door, one is the safe.
 */
class EnsureRole
{
    /**
     * @param  string  ...$roles  roles allowed through, from the middleware
     *                             parameters (e.g. 'admin,agent')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // WHY ! $user too: auth:sanctum already ran, but if this middleware
        // were ever (mis)used alone, a null user must not slip through.
        // in_array(..., true) = STRICT comparison: 'admin' must match
        // exactly — no truthy/coercion surprises.
        if (! $user || ! in_array($user->role, $roles, true)) {
            // Same 403 style as TicketController::authorizeOwnership() —
            // Laravel renders {"message": "..."} JSON, React shows it.
            abort(403, 'Your role does not permit this action.');
        }

        return $next($request); // authorized → continue down the pipeline
    }
}