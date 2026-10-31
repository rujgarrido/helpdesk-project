<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ---------------------------------------------------------------
        // H2 HARDENING (auth-route throttling): bootstrap/app.php never
        // calls ->throttleApi(), so the 'api' middleware group has NO default
        // rate limit — POST /api/login and /api/register were completely
        // unthrottled. These NAMED limiters are attached in routes/api.php
        // via ->middleware('throttle:login') / ('throttle:register').

        // LOGIN: primary key = email|IP so DIFFERENT users behind one NAT
        // don't share a bucket (good for offices) while one attacker guessing
        // one account still trips the 5/min. The is_string() guard is NOT
        // cosmetic: a request like {email: [x]} must fall into the shared
        // ''|ip bucket and be COUNTED — casting an array emits E_WARNING and
        // strtolower(array) throws TypeError; Laravel converts any warning
        // raised on a public route into ErrorException => HTTP 500, and it
        // would happen INSIDE the limiter BEFORE the hit() loop, so those
        // requests would be unthrottled 500s. Non-string input → one shared
        // bucket, still counted. A secondary IP bucket caps total flood
        // from a single address at 20/min (NOTE: that bucket IS shared by
        // all users of one NAT — that is its job).
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? strtolower($email) : ''; // non-string → shared bucket, still counted
            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // REGISTER: no email key (the point is stopping mass account
        // creation), so a plain per-IP 5/min bucket.
        RateLimiter::for('register', function (Request $request): array {
            return [
                Limit::perMinute(5)->by($request->ip()),
            ];
        });

        // NOTES for whoever maintains this:
        // - 429 responses render as JSON on api/* (bootstrap/app.php
        //   shouldRenderJsonWhen) and include a Retry-After header; the
        //   React Login.jsx error message falls back to data.message, so a
        //   flood surfaces as "Too Many Attempts." — no frontend change
        //   required.
        // - Throttle runs BEFORE controller validation, so invalid requests
        //   still consume a hit (intentional anti-enumeration).
        // - If deployed behind a proxy/load balancer, configure
        //   trustProxies() or ALL clients will share the proxy's IP bucket.
        // - Limiter hit-counters live in the default cache store.
    }
}
