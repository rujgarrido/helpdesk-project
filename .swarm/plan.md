<!-- PLAN_HASH: 1rj7bvbhi7d7 -->
# Auth hardening: token expiry + throttled auth routes
Swarm: default
Phase: 1 [PENDING] | Updated: 2026-10-29T13:30:39.896Z

---
## Execution Profile
- Parallelization: enabled
- Max Concurrent Tasks: 10
- Council Parallel: yes
- Locked: no
- Auto Proceed: no
- Commit After Each Completed Task: no
- Planning Profile: balanced

---
## Phase 1: Token lifecycle & throttle hardening [PENDING]
- [ ] 1.1: H1: Enable Sanctum bearer-token expiration — config/sanctum.php: 'expiration' => max(1, (int) env('SANCTUM_EXPIRATION_MINUTES', 1440)) (fails closed: empty env can't disable expiry); add SANCTUM_EXPIRATION_MINUTES=1440 to .env.example; document fixed 24h-from-login semantics in config comments. Add scheduled pruning in routes/console.php: Schedule::command('sanctum:prune-expired --hours=24')->daily() (with Illuminate\Support\Facades\Schedule import) + a comment noting it needs host cron (schedule:run) and is housekeeping only — the guard enforces expiry regardless. [SMALL] (files_touched: ["helpdesk-api/.env.example","helpdesk-api/config/sanctum.php","helpdesk-api/routes/console.php"])
- [ ] 1.2: H2: Named rate limiters in AppServiceProvider::boot() — RateLimiter::for('login', fn) = Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()) plus Limit::perMinute(20)->by($request->ip()); RateLimiter::for('register', perMinute(5)->by(ip())) (string cast avoids pre-validation TypeError on array input). Routes: POST /api/login ->middleware('throttle:login'); POST /api/register ->middleware('throttle:register'); EXACTLY one argument each. [SMALL] (files_touched: ["helpdesk-api/app/Providers/AppServiceProvider.php","helpdesk-api/routes/api.php"])
- [ ] 1.3: TEST task (depends 1.1): tests/Feature/TokenExpirationTest.php — test class USES RefreshDatabase (phpunit.xml uses :memory: sqlite; no such table otherwise); build user via User::factory(); issue token with $user->createToken('api')->plainTextToken + $this->withHeader('Authorization', "Bearer $token") — NEVER Sanctum::actingAs (TransientToken bypasses expiry); $this->travel(1441)->minutes() then GET /api/user asserts 401 with JSON message 'Unauthenticated.'; also assert pre-travel 200 sanity. Verify: php artisan test --filter=TokenExpirationTest. [SMALL] (depends: 1.1) (files_touched: ["helpdesk-api/tests/Feature/TokenExpirationTest.php"])
- [ ] 1.4: TEST task (depends 1.2): tests/Feature/AuthRateLimitTest.php — test class USES RefreshDatabase; phpunit.xml sets CACHE_STORE=array (fresh per test). Post 6 rapid bad-credential login requests (same email+IP); assert response #6 (and any beyond) is 429; $this->travel(61)->seconds(); next bad login asserts 422 resumes (ArrayStore uses test time so travel decays the bucket). Also one register-side assertion: 6 rapid POST /api/register -> 429. Verify: php artisan test --filter=AuthRateLimitTest. [SMALL] (depends: 1.2) (files_touched: ["helpdesk-api/tests/Feature/AuthRateLimitTest.php"])
