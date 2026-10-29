<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// HOW IT CONNECTS TO H1 (token expiry): config/sanctum.php sets
// 'expiration' => SANCTUM_EXPIRATION_MINUTES (default 1440 = 24 hours).
// Tokens expiring by GUARD RULE already fail auth immediately; this daily
// schedule only HOUSEKEEPES the database — purging already-expired token
// rows, so personal_access_tokens doesn't grow forever.
// REQUIREMENT: a host cron running `php artisan schedule:run` every minute.
// If the scheduler never runs, nothing breaks — the guard enforces expiry
// regardless; stale rows are just not purged.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
