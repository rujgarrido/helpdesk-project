<!--
AUTO-GENERATED EXPORT/CHECKPOINT SNAPSHOT — DO NOT EDIT
This file is NOT the live plan. It is a derived export artifact.
- The plan ledger is authoritative: SQLite after cutover, JSONL during file-shadow mode.
- .swarm/plan.json and .swarm/plan.md are derived projections.
Regenerated on: save_plan and phase_complete.
-->
# Auth hardening: token expiry + throttled auth routes
Swarm: default
Phase: 1 [PENDING] | Updated: 2026-10-31T10:19:44.738Z

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
- [ ] 1.1: H1: Enable Sanctum bearer-token expiration — config/sanctum.php: 'expiration' => max(1, (int) env('SANCTUM_EXPIRATION_MINUTES', 1440)) (fails closed: empty/0 env cannot disable expiry); .env.example documents SANCTUM_EXPIRATION_MINUTES=1440 (log: this file write is currently denied by runtime config-zone policy for architect — needs a coder pass or manual add); config comments document the fixed 24h-from-login semantics and config:cache deploy note. routes/console.php: Schedule::command('sanctum:prune-expired --hours=24')->daily() with Schedule facade import + cron/guard-enforces-anyway comments. IMPLEMENTED + reviewer APPROVED (task 1.1). [SMALL] (files_touched: ["helpdesk-api/.env.example","helpdesk-api/config/sanctum.php","helpdesk-api/routes/console.php"])
- [ ] 1.2: H2: Named rate limiters in AppServiceProvider::boot(). FINAL IMPLEMENTED FORM (do NOT use strtolower((string) ...) — that construct 500s on array input and is uncounted): $email = $request->input('email'); $email = is_string($email) ? strtolower($email) : ''; login limiter = Limit::perMinute(5)->by($email.'|'.ip()) + Limit::perMinute(20)->by(ip()); register limiter = perMinute(5)->by(ip()). Routes: POST /api/login ->middleware('throttle:login'); POST /api/register ->middleware('throttle:register'); EXACTLY one argument each. IMPLEMENTED + reviewer APPROVED after Tier-2 fix and live 500/429 regression probe. [SMALL] (files_touched: ["helpdesk-api/app/Providers/AppServiceProvider.php","helpdesk-api/routes/api.php"])
- [ ] 1.3: TEST task (depends 1.1): tests/Feature/TokenExpirationTest.php — test class USES RefreshDatabase (phpunit.xml uses :memory: sqlite; no such table otherwise); build user via User::factory(); issue token with $user->createToken('api')->plainTextToken + $this->withHeader('Authorization', "Bearer $token") — NEVER Sanctum::actingAs (TransientToken bypasses expiry); advance time by (int) config('sanctum.expiration') + 1 minutes (NOT hardcoded 1441) then GET /api/user asserts 401 with JSON message 'Unauthenticated.'; also assert pre-travel 200 sanity. Verify: php artisan test --filter=TokenExpirationTest. [SMALL] (depends: 1.1) (files_touched: ["helpdesk-api/tests/Feature/TokenExpirationTest.php"])
- [ ] 1.4: TEST task (depends 1.2): tests/Feature/AuthRateLimitTest.php — test class USES RefreshDatabase; phpunit.xml sets CACHE_STORE=array (fresh per test). Post 6 rapid bad-credential login requests (same email+IP); assert response #6 (and any beyond) is 429; $this->travel(61)->seconds(); next bad login asserts 422 resumes (ArrayStore uses test time so travel decays the bucket). Also one register-side assertion: 6 rapid POST /api/register -> 429. Verify: php artisan test --filter=AuthRateLimitTest. [SMALL] (depends: 1.2) (files_touched: ["helpdesk-api/tests/Feature/AuthRateLimitTest.php"])
