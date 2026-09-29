<?php

// WHAT: This controller handles everything related to authentication:
// registering a new user, logging in (issuing an API token), logging out
// (revoking the token), and returning the currently authenticated user.
// WHY it lives in the Api namespace: every endpoint here returns JSON only
// (no HTML views), so API-only controllers are grouped under App\Http\Controllers\Api.
// HOW IT CONNECTS: routes/api.php maps POST /api/register, /api/login,
// /api/logout and GET /api/user to the methods below. The React Login.jsx
// page calls these endpoints with axios (see src/api.js).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a brand new user and immediately log them in.
     *
     * WHY return a token here: the React app needs the token right away so
     * the user is not forced to log in again right after registering.
     */
    public function register(Request $request): JsonResponse
    {
        // validate() checks the incoming JSON and, on failure, automatically
        // returns a 422 JSON response with an "errors" object (because
        // bootstrap/app.php enables JSON responses for api/* routes).
        // 'confirmed' means the frontend must ALSO send `password_confirmation`.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // WHY Hash::make: never store plain-text passwords. Hash::make applies
        // bcrypt; later Hash::check() in login() compares a password to it.
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            // RBAC: every self-registered account is a powerless 'user'.
            // WHY hardcoded instead of read from input: $validated contains
            // ONLY the three fields validated above — a request like
            // {"role": "admin"} is silently ignored here, so nobody can
            // grant themselves admin at signup. Admins promote people later
            // through AdminUserController::updateRole().
            'role' => 'user',
        ]);

        // createToken() (from the HasApiTokens trait on User) stores a token
        // row in personal_access_tokens and returns an object whose
        // ->plainTextToken is the secret string React keeps in localStorage.
        $token = $user->createToken('api')->plainTextToken;

        // 201 = "Created" — REST convention for a resource that was just added.
        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Verify credentials and issue a fresh API token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Look up the user by email only — we check the password separately.
        $user = User::where('email', $credentials['email'])->first();

        // WHY a single combined check: if we said "user not found" first, an
        // attacker could discover which emails exist. One generic error for
        // both cases keeps that information private.
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            // WHY throw ValidationException: Laravel turns this into a 422 JSON
            // response like {"errors": {"email": ["These credentials..."]}},
            // which Login.jsx reads to display the message under the form.
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        // 200 (default) — the resource (session/token) already "exists".
        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Revoke the token used for THIS request (called on logout).
     *
     * WHY delete the token server-side: removing it from localStorage on the
     * frontend only hides it in the browser — deleting the row here ensures
     * the token stops working even if someone copied it earlier.
     */
    public function logout(Request $request): JsonResponse
    {
        // currentAccessToken() returns the PersonalAccessToken model that the
        // auth:sanctum middleware matched from the Authorization header.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Return the authenticated user (used by the frontend to know who is
     * logged in). The auth:sanctum middleware in routes/api.php guarantees
     * $request->user() is never null here.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
