<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // FIX: Laravel's built-in default (set just before this callback
        // runs, see vendor .../ApplicationBuilder.php) is
        // redirectGuestsTo(fn () => route('login')). This is a JSON API
        // with NO route named 'login', so without the line below any
        // unauthenticated request WITHOUT an `Accept: application/json`
        // header made the auth middleware try to build that missing URL →
        // RouteNotFoundException → HTTP 500 instead of a proper 401.
        //
        // HOW THE FIX WORKS: redirectGuestsTo(null) means "never redirect
        // guests". Authenticate then throws AuthenticationException with no
        // redirect target, and because of shouldRenderJsonWhen() below
        // (api/* matches), Laravel answers clean 401 JSON
        // {"message":"Unauthenticated."} — which src/api.js reacts to by
        // clearing the token and sending the user back to the login page.
        $middleware->redirectGuestsTo(null);

        // REGISTER A ROUTE-MIDDLEWARE ALIAS so route files can write
        // ->middleware('role:admin') instead of the full class path.
        // HOW IT CONNECTS: routes/api.php uses 'role:admin' and
        // 'role:admin,agent' → EnsureRole receives those names as its
        // variadic $roles argument and 403s unless the user's role matches.
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
