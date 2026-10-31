<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// WHAT: Plan task 1.3 (H1 hardening) — a bearer token issued through
// POST /api/login (or /api/register) must keep working BEFORE
// config('sanctum.expiration') minutes elapse and stop working AFTER it.
// Guard.php:128 enforces created_at->gt(now()->subMinutes(expiration)) on
// the bearer path, ANDed with the per-token expires_at column.
//
// HOW: every request here uses a REAL PersonalAccessToken row
// ($user->createToken('api')->plainTextToken) sent as an Authorization:
// Bearer header. NEVER Sanctum::actingAs(): that injects a TransientToken,
// which carries no created_at and sails straight past the expiry check —
// the whole test would be theater.
//
// WHY the travel windows come from config('sanctum.expiration') instead of
// hardcoded 1440/1441: the config clamps to max(1, (int) env(...)), so an
// operator override of SANCTUM_EXPIRATION_MINUTES would silently break
// hardcoded numbers. Deriving from config keeps these tests describing the
// same contract (valid inside the window, 401 outside it) under any config.
//
// DB SETUP (required by the assertions below): RefreshDatabase runs the
// migrations against the :memory: sqlite database from phpunit.xml — without
// it there are no `users` / `personal_access_tokens` tables and the factory
// + createToken() calls would fail before any HTTP assertion runs.
class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * WHAT: fails-fast precondition — a just-issued token authenticates
     * against GET /api/user, so any 401 in the tests below can only come
     * from the expiry guard, not from a broken auth path.
     * HOW: create a factory user, issue a real token, send it as a Bearer
     * header, and pin the response to the exact user JSON (status + body).
     */
    public function test_fresh_token_authenticates_against_user_endpoint(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $user->id,
            'email' => $user->email,
        ]);
    }

    /**
     * WHAT: a token OLDER than the configured window is rejected with a
     * clean 401 JSON body (which the SPA interceptor reacts to by clearing
     * localStorage and bouncing back to the login page).
     * HOW: travel past expiration + 1 minute so created_at is strictly
     * older than now()->subMinutes(expiration) inside Sanctum's Guard —
     * then assert the exact 401 status and the exact JSON message.
     * WHY +1 minute: the guard uses gt() (strict), so "expiration" exactly
     * would be a knife-edge; +1 gives a whole margin of safety on both the
     * second-precision created_at column and the strict comparison.
     */
    public function test_token_is_rejected_after_configured_expiration_window(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $expirationMinutes = (int) config('sanctum.expiration');
        $this->travel($expirationMinutes + 1)->minutes();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * WHAT: boundary sanity — a token that is still INSIDE the window
     * (5 minutes before the edge) keeps working. Together with the 401 test
     * above this brackets the boundary, proving the rejection is caused by
     * elapsed time and not by something that always fails.
     * HOW: travel to expiration - 5 minutes and assert 200 + the exact user
     * identity again.
     */
    public function test_token_still_valid_inside_configured_expiration_window(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $expirationMinutes = (int) config('sanctum.expiration');
        $this->travel($expirationMinutes - 5)->minutes();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $user->id,
            'email' => $user->email,
        ]);
    }
}
