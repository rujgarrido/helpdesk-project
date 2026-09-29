// WHAT: The application root — defines WHICH component renders for WHICH
// URL, and WHO may see each one (role guards = the frontend half of RBAC).
// HOW IT CONNECTS: main.jsx renders <App /> once; react-router-dom then
// reads window.location and keeps the UI in sync with the address bar
// (client-side routing — no full page reloads between pages).
// ROLE DATA SOURCE: Login.jsx stores the user object (incl. `role`) in
// localStorage at login; the guards below read it from there. The SERVER
// re-checks every rule anyway (role: middleware + controller checks) —
// these guards only spare users from seeing pages they can't use.

import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import Admin from './Admin.jsx';
import Login from './Login.jsx';
import Tickets from './Tickets.jsx';

/**
 * PrivateRoute — the frontend authorization GUARD (two levels now):
 *   1. token?      — logged in at all? no → redirect to "/".
 *   2. allowedRoles? — optionally restrict to certain roles; a user whose
 *      role isn't listed gets bounced to /tickets (their home base).
 * WHY both: level 1 = session check, level 2 = UX (no dead-end pages).
 * WHY this is NOT real security: localStorage is attacker-writable; the
 * real gate is the EnsureRole middleware + controller checks in Laravel.
 * Frontend guards = convenience; backend guards = enforcement.
 *
 * HOW IT CONNECTS: Login.jsx writes token+user on success; Tickets.jsx
 * removes both on logout — every route change re-reads them here.
 */
function PrivateRoute({ children, allowedRoles }) {
  const token = localStorage.getItem('token');

  // Level 1: no token → not logged in → login page. (`replace` keeps Back
  // from bouncing between "/" and the protected page.)
  if (!token) return <Navigate to="/" replace />;

  // Level 2: role gate. JSON.parse is wrapped in try/catch — a corrupted
  // localStorage entry must not crash the whole app (fail closed → login).
  if (allowedRoles) {
    let role; // assigned in BOTH try and catch below (no dead initializer)
    try {
      role = JSON.parse(localStorage.getItem('user'))?.role;
    } catch {
      role = null;
    }

    // Unknown/bad role or role not in the list → home for this user.
    if (!role || !allowedRoles.includes(role)) {
      return <Navigate to="/tickets" replace />;
    }
  }

  return children;
}

function App() {
  return (
    // BrowserRouter enables routing via the HTML5 History API (Vite serves
    // index.html for every path in dev, so refreshing /tickets still works).
    <BrowserRouter>
      <Routes>
        {/* "/" → Login page (public, no guard) */}
        <Route path="/" element={<Login />} />

        {/* "/tickets" → EVERY logged-in role. scope="mine" = tickets I
            created + tickets assigned to me; Tickets.jsx adapts its controls
            to the role (status dropdown & assign buttons only for staff). */}
        <Route
          path="/tickets"
          element={
            <PrivateRoute>
              <Tickets scope="mine" />
            </PrivateRoute>
          }
        />

        {/* "/queue" → the STAFF view: ALL tickets + triage controls.
            allowedRoles blocks plain customers client-side (server enforces
            the same rule via index()?scope=all + isStaff()). */}
        <Route
          path="/queue"
          element={
            <PrivateRoute allowedRoles={['agent', 'admin']}>
              <Tickets scope="all" />
            </PrivateRoute>
          }
        />

        {/* "/admin" → category manager + user role table, admin only */}
        <Route
          path="/admin"
          element={
            <PrivateRoute allowedRoles={['admin']}>
              <Admin />
            </PrivateRoute>
          }
        />

        {/* Unknown URLs: send to /tickets — PrivateRoute turns that into
            "/" for guests (→ login) and the home page for users, so nobody
            ever lands on a blank screen. */}
        <Route path="*" element={<Navigate to="/tickets" replace />} />
      </Routes>
    </BrowserRouter>
  );
}

export default App;