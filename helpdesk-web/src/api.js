// WHAT: The single place where ALL HTTP requests to the Laravel API are
// configured. Every component imports this `api` instance instead of using
// fetch() or a raw axios call.
// WHY: base URL, auth headers and error handling are defined ONCE here.
// If the API moves to another server later, you change one line — not every
// component.

import axios from 'axios';

// axios.create() returns a CUSTOM axios instance (a configured copy of the
// default axios). baseURL means component calls can be short:
//   api.get('/tickets')  →  http://127.0.0.1:8000/api/tickets
const api = axios.create({
  // WHY 127.0.0.1 and not localhost: both work, but 127.0.0.1 avoids
  // possible IPv6 (::1) mismatches between Windows/PHP and Vite.
  baseURL: 'http://127.0.0.1:8000/api',
  headers: {
    // Tell Laravel we always want JSON back (its error responses are JSON too).
    Accept: 'application/json',
  },
});

// ---------------------------------------------------------------------------
// REQUEST INTERCEPTOR — runs BEFORE every request leaves the browser.
// HOW INTERCEPTORS WORK: axios keeps a pipeline of "hooks". The request
// hook receives the outgoing request config (url, method, headers...) and
// MUST return it (or a modified copy) — returning anything else, or throwing,
// cancels the request. Think of it as a postal worker who stamps every
// envelope with the right return address before it is sent.
//
// WHY we need it: every protected Laravel route (auth:sanctum) expects the
// header `Authorization: Bearer <token>`. Without this hook you'd have to
// remember to add that header to EVERY api.get/post/patch/delete call in
// every component — one forgotten line = mysterious 401 errors.
// ---------------------------------------------------------------------------
api.interceptors.request.use((config) => {
  // The token was saved by Login.jsx on successful login/register.
  const token = localStorage.getItem('token');

  if (token) {
    // Template literal builds e.g. "Bearer 3|abc123..."
    config.headers.Authorization = `Bearer ${token}`;
  }
  // If no token: send the request anyway (works for /login, /register).
  // Laravel will answer 401 on protected routes, handled below.

  return config; // ← required: the (possibly modified) config continues on its way
});

// ---------------------------------------------------------------------------
// RESPONSE INTERCEPTOR — runs on EVERY response (success or failure).
// WHY: a central place to react to "session expired" (401) so individual
// components don't each need the same cleanup code.
// ---------------------------------------------------------------------------
api.interceptors.response.use(
  (response) => response, // success → pass through untouched

  (error) => {
    // error.response is undefined for network errors (API not running),
    // hence the optional chaining ?.
    if (error.response?.status === 401) {
      // Token is invalid/expired/revoked → clean it up.
      localStorage.removeItem('token');
      localStorage.removeItem('user');

      // Full page reload to "/" (the Login route). window.location is a
      // blunt instrument but here it's ideal: it resets ALL React state and
      // guarantees PrivateRoute re-evaluates from a clean slate.
      if (window.location.pathname !== '/') {
        window.location.assign('/');
      }
    }

    // IMPORTANT: rethrow the error so the caller's .catch() still runs
    // (e.g. Login.jsx showing "wrong credentials").
    return Promise.reject(error);
  }
);

export default api;
