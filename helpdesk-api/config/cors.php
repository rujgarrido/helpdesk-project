<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // WHICH URLS MAY CALL THIS API FROM A BROWSER.
    // WHY CORS exists: the React app runs on http://localhost:5173 (Vite)
    // while this API runs on http://127.0.0.1:8000 — a DIFFERENT origin.
    // Browsers block cross-origin fetches unless the SERVER replies with an
    // Access-Control-Allow-Origin header matching the page's origin, which
    // this config generates. Without it you'd see "CORS policy error" in the
    // browser console and every axios request would fail.
    // WHY not '*': we list our exact dev origin instead of a wildcard —
    // safer and enough for this project. (Add 'http://127.0.0.1:5173' too if
    // you open the frontend via 127.0.0.1 instead of localhost.)
    'allowed_origins' => ['http://localhost:5173'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
