<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// WHAT: Plan task 1.4 (H2 hardening) — POST /api/login and POST /api/register
// must be throttled by the NAMED limiters defined in AppServiceProvider::boot():
//   login:    Limit::perMinute(5)->by(email.'|'.ip)  +  Limit::perMinute(20)->by(ip)
//   register: Limit::perMinute(5)->by(ip)
// attached in routes/api.php via ->middleware('throttle:login' / 'throttle:register').
//
// HOW: real HTTP requests via postJson — no throttle-header guessing, no
// RateLimiter fakes. Tests run from 127.0.0.1, so every request below shares
// ONE IP bucket; that is exactly what the register limiter keys on, and for
// login the per-email bucket is the one that trips first (5 < 20). We assert
// only STATUS CODES — never Retry-After header values, which are incidental.
//
// WHY the sequences are deterministic: ThrottleRequests::handleRequest checks
// ALL limits first (6th request → attempts 5 >= 5 → 429) and only then hits
// every limit, so requests 1-5 always reach the controller. The throttle runs
// BEFORE controller validation, so throttled requests still consume a hit.
//
// DB SETUP (required by the assertions below): RefreshDatabase migrates the
// :memory: sqlite database from phpunit.xml — the factory user in the login
// test and the five register() inserts both need the `users` table. CACHE_STORE
// is `array`, rebuilt per test, so hit counters never leak between tests.
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * WHAT: bad logins against one email are cut off after 5 per minute
     * (HTTP 429 on the 6th attempt), and the bucket decays after the
     * 60-second window so a legitimate retry works again afterwards.
     * HOW: six postJson /api/login calls with a factory user's real email
     * and a WRONG password — 422 "credentials do not match" from
     * AuthController::login for the first five (throttle passes them), 429
     * from the login limiter on the sixth. Then travel past the 60s decay
     * (ArrayStore expiry is Carbon-based, so travel really empties the
     * bucket) and assert the login is served as 422 again, not 429.
     */
    public function test_login_is_throttled_after_five_bad_attempts_and_recovers_after_decay(): void
    {
        $user = User::factory()->create();

        $statuses = [];
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $statuses[] = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'not-the-factory-password',
            ])->getStatusCode();
        }

        // Attempts 1-5 clear the limiter and hit the controller (wrong
        // password → 422); attempt 6 trips 'throttle:login' → 429.
        $this->assertSame(
            [422, 422, 422, 422, 422, 429],
            $statuses,
            'Expected 422 for the first five bad logins and 429 on the sixth.'
        );

        // Past the 60s decay window the bucket is empty again → the very
        // same bad login is handled by the controller (422), not the limiter.
        $this->travel(61)->seconds();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'not-the-factory-password',
        ])->assertStatus(422);
    }

    /**
     * WHAT: mass account creation from one IP is cut off after 5 per minute
     * (HTTP 429 on the 6th request), and the throttled request never reaches
     * the controller, so no sixth user row is created.
     * HOW: six postJson /api/register calls with SIX DISTINCT valid emails —
     * distinct so the unique:users,email validation passes for requests 1-5
     * and cannot be the reason for any 4xx. The register limiter keys by IP
     * only, so consecutive requests from this one test trip it. Assert the
     * exact 201/429 sequence plus the row count as a state-change check.
     */
    public function test_register_is_throttled_after_five_accounts_from_one_ip(): void
    {
        $statuses = [];
        for ($i = 1; $i <= 6; $i++) {
            $statuses[] = $this->postJson('/api/register', [
                'name' => "Rate Limit Test {$i}",
                'email' => "rate-limit-test-{$i}@example.com",
                'password' => 'super-secret-password',
                'password_confirmation' => 'super-secret-password',
            ])->getStatusCode();
        }

        // Requests 1-5 create the accounts (201 Created); request 6 is
        // rejected by 'throttle:register' BEFORE the controller runs.
        $this->assertSame(
            [201, 201, 201, 201, 201, 429],
            $statuses,
            'Expected 201 for the first five registrations and 429 on the sixth.'
        );

        // STATE CHANGE: exactly five rows — the throttled sixth request must
        // never reach User::create (that is the point of throttling signup).
        $this->assertSame(5, User::count());
    }
}
