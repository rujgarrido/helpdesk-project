# 🎫 Helpdesk System

A full-stack helpdesk: customers create tickets, agents work them in a
queue, admins manage categories and roles. Built as a learning project —
**every file carries educational comments** explaining *what* the code does,
*why* it is written that way, and *how* the pieces connect.

```
Browser ──► helpdesk-web (React + Vite, :5173)
                │  axios + Bearer token (JSON over HTTP)
                ▼
            helpdesk-api (Laravel 13 + Sanctum, :8000)
                │  Eloquent (parameterized SQL)
                ▼
            SQLite (database/database.sqlite — gitignored)
```

## Tech stack

| Layer    | Tech |
|----------|------|
| Backend  | Laravel 13, Laravel Sanctum (token auth), Eloquent ORM, SQLite |
| Frontend | React 19, Vite 8, react-router-dom 7, Axios |
| Auth     | Bearer tokens in `Authorization` header, stored in `localStorage` |

## Features

- **Auth** — register / login / logout (Sanctum personal access tokens)
- **Tickets** — create, list, view, update, delete with `status`
  (`open → in_progress → closed`) and `priority` (`low/medium/high`)
- **Categories** — admin-managed lookup table, required on every ticket
- **Assignment** — agents claim tickets ("Assign to me") or return them to the queue
- **Simple RBAC** — three roles (below)

### Roles

| Role | Who | What they can do |
|------|-----|------------------|
| `user` | Customer / employee | Create tickets, view/edit **own** tickets (while `open`), delete own open tickets |
| `agent` | Support staff | Everything in the **Queue**: view all tickets, change status, assign/unassign. **Never** deletes |
| `admin` | Manager / IT lead | Everything + manage categories, promote/demote users, delete any ticket |

Server enforcement lives in two layers: route middleware
(`role:admin` → `app/Http/Middleware/EnsureRole.php`) and per-row checks
(`TicketController::authorizeOwnership/authorizeView`). The React UI only
*hides* controls — the API re-checks every rule (see the permission matrix
in `TicketController`'s docblock).

## Getting started

### Requirements
PHP 8.3+ · Composer · Node 20+ · npm

### Terminal 1 — API

```powershell
cd helpdesk-api
composer install            # first time only
copy .env.example .env      # first time only — .env is gitignored
php artisan key:generate    # first time only — creates a FRESH APP_KEY
php artisan migrate         # creates tables
php artisan db:seed         # seeds the 5 categories + a test@example.com user
php artisan serve           # http://127.0.0.1:8000
```

### Terminal 2 — Frontend

```powershell
cd helpdesk-web
npm install                 # first time only
npm run dev                 # http://localhost:5173
```

Open **http://localhost:5173** — register an account, you'll land on `/tickets`.

### Creating your first admin

Roles are never granted at registration (the API hardcodes `role=user`).
Promote yourself once via tinker:

```powershell
php artisan tinker --execute="App\Models\User::where('email','you@example.com')->update(['role'=>'admin']);"
```

Then log out/in and use the **Admin** tab to manage everyone else.

> **Dev demo accounts** (`password123`): `alice@hd.test` = admin,
> `bob@hd.test` = agent, `carol@hd.test` = user. They live only in your
> local (gitignored) SQLite file — fresh clones won't have them.

## Project structure

```
projects/
├── README.md            ← you are here
├── helpdesk-api/        Laravel JSON API
│   ├── app/Http/Controllers/     TicketController, CategoryController,
│   │                             AdminUserController, Api/AuthController
│   ├── app/Http/Middleware/      EnsureRole (role:admin gate)
│   ├── app/Models/               User, Ticket, Category
│   ├── database/migrations/      incl. role column, categories, FKs
│   ├── database/seeders/         CategorySeeder
│   └── routes/api.php            15 endpoints, 3 protection layers
└── helpdesk-web/        React SPA
    └── src/
        ├── api.js          axios instance + auth interceptors
        ├── App.jsx         routes + PrivateRoute (token & role guards)
        ├── Login.jsx       login/register toggle
        ├── Tickets.jsx     /tickets (mine) + /queue (all, staff)
        ├── Admin.jsx       categories + user roles (admin)
        └── index.css       all styling
```

## API overview

| Method & path | Who | Purpose |
|---|---|---|
| `POST /api/register` · `POST /api/login` | public | get a token |
| `GET /api/user` · `POST /api/logout` | any logged-in | session |
| `GET /api/tickets` | any | own tickets (`?scope=all` = staff only) |
| `POST /api/tickets` | any | create (category required, status forced `open`) |
| `GET/PATCH/DELETE /api/tickets/{id}` | owner or staff* | *role-split rules in controller |
| `GET /api/categories` | any logged-in | dropdown list |
| `POST/PATCH/DELETE /api/categories/{id}` | **admin** | category CRUD |
| `GET /api/users` · `PATCH /api/users/{id}/role` | **admin** | role management |

---

## 🔒 Security: keeping secrets out of git

**What never gets committed** (enforced by `.gitignore` — verified with
`git check-ignore`):

| File | Why | Rule |
|---|---|---|
| `helpdesk-api/.env` | real `APP_KEY`, DB path, mail creds | `.env` + `.env.*` |
| `helpdesk-api/database/*.sqlite` | password hashes + live API tokens | `database/.gitignore: *.sqlite*` |
| `helpdesk-web/.env*` | would hold `VITE_*` secrets | `.env` + `.env.*` |
| `vendor/`, `node_modules/`, `dist/` | build artifacts | standard rules |

`.env.example` **is** committed — it's a template with empty values
(`APP_KEY=` blank), so each clone generates its own key with
`php artisan key:generate`.

**Pre-push checklist**

```powershell
git status                                   # nothing sensitive staged
git check-ignore helpdesk-api/.env           # prints the matching rule
git grep -n "base64:" -- "*.php" "*.js"      # no key material in tracked files
```

Never `git add -f` an ignored file — the `-f` flag exists to bypass these
exact guards.

**If a secret ever IS pushed:** treat it as burned — rotate immediately.
For `APP_KEY`: `php artisan key:generate --force` (invalidates all
encrypted data + sessions), commit only the `.env.example` change, and
rewrite history (`git filter-repo`) for keys pushed long ago.

**Note:** demo passwords like `password123` in this README are local-dev
only. Never reuse them anywhere real.

