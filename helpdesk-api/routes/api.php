<?php

// WHAT: The route table for everything under /api (registered automatically
// by bootstrap/app.php with the /api prefix).
// THREE LAYERS OF PROTECTION stack before any controller method runs:
//   1. PUBLIC group (register/login) — no token, else you could never get one.
//   2. auth:sanctum — validates the Authorization: Bearer <token> header,
//      fills $request->user(), and 401s missing/invalid tokens.
//   3. role:... (our EnsureRole alias, registered in bootstrap/app.php) —
//      blocks whole route groups by role, e.g. 'role:admin'.
// Layer 3 = route-level RBAC (the "front door"); per-row rules (does THIS
// user own THAT ticket) stay inside TicketController (the "safe").
//
// HOW IT CONNECTS TO REACT: every URL below is called from src/api.js
// consumers — Login.jsx (public two), Tickets.jsx (tickets + categories),
// Admin.jsx (categories writes + users).

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TicketController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// PUBLIC AUTH ROUTES (no token required) — called by React src/Login.jsx
// ---------------------------------------------------------------------------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// ---------------------------------------------------------------------------
// PROTECTED ROUTES — every route below requires a valid Bearer token
// ---------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // Who am I / end my session.
    // WHY a controller method instead of the old inline closure: routes
    // should only WIRE urls → methods; all auth logic lives in
    // AuthController (keeps the MVC split clean).
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // TICKET CRUD — open to all three roles. WHICH actions each role may
    // perform (the owner-vs-staff matrix) is enforced inside
    // TicketController, because those rules depend on each row's data.
    //   GET    /api/tickets          → index  (?scope=all = staff queue)
    //   POST   /api/tickets          → store
    //   GET    /api/tickets/{ticket} → show
    //   PATCH  /api/tickets/{ticket} → update
    //   DELETE /api/tickets/{ticket} → destroy
    Route::apiResource('tickets', TicketController::class);

    // CATEGORIES: readable by everyone logged-in (Tickets.jsx needs the
    // list for its create form), writable by ADMINS ONLY.
    // WHY not a single apiResource(): one resource = one middleware set,
    // but here index and the write actions have DIFFERENT role rules, so
    // we split the definition instead of forcing the wrong gate on one side.
    Route::get('/categories', [CategoryController::class, 'index']);

    // ---------------------------------------------------------------------------
    // ADMIN-ONLY GROUP — 'role:admin' is our EnsureRole middleware alias;
    // agents and plain users get 403 {"message": "..."} before any code below.
    // ---------------------------------------------------------------------------
    Route::middleware('role:admin')->group(function () {
        // Category management (used by Admin.jsx)
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::patch('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        // User & role management (used by Admin.jsx role table)
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::patch('/users/{user}/role', [AdminUserController::class, 'updateRole']);
    });
});