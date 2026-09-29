<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// WHY: Sanctum's HasApiTokens trait adds methods like createToken() and
// currentAccessToken() to the User model. Without it, calling
// $user->createToken() in AuthController would throw an error.
use Laravel\Sanctum\HasApiTokens;

// 'role' is fillable ONLY so AdminUserController can promote/demote with
// $user->update(['role' => ...]). register() never forwards a client-sent
// role — it builds the create() array itself, so nobody can self-promote.
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    // HOW IT CONNECTS: AuthController::login() and ::register() call
    // $user->createToken('api')->plainTextToken to issue a Bearer token.
    // The React app stores that token and sends it with every request,
    // which the auth:sanctum middleware then validates.
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * RELATIONSHIP: one user has MANY tickets.
     *
     * WHY: TicketController::index() calls $request->user()->tickets to fetch
     * only the logged-in user's tickets, and ::store() calls
     * $request->user()->tickets()->create([...]) which automatically stamps
     * the user_id foreign key for us — we never trust a user_id sent by the client.
     *
     * HOW IT WORKS: HasMany infers the foreign key from this model's name:
     * User -> user_id on the tickets table. In SQL:
     * SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC (with ->latest()).
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * ROLE HELPERS — one-line permission checks used by controllers
     * ("policy-lite" RBAC; the route-level twin is the EnsureRole middleware).
     * WHY methods instead of $user->role === 'admin' scattered everywhere:
     * add a 4th role later and you change exactly one line here.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isAgent(): bool
    {
        return $this->role === 'agent';
    }

    /** Staff = anyone who WORKS tickets (agent or admin). */
    public function isStaff(): bool
    {
        return $this->isAgent() || $this->isAdmin();
    }

    /**
     * RELATIONSHIP: one user (a staff member) has MANY assigned tickets.
     * WHY the explicit 2nd argument: hasMany infers the foreign key as
     * `user_id` (from this class's name), but our column is `assigned_to`
     * — Laravel cannot guess that, so we name it. Inverse of
     * Ticket::assignee(). SQL: SELECT * FROM tickets WHERE assigned_to = ?
     */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }
}
