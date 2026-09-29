// WHAT: The admin panel — two managers on one page:
//   1. CATEGORIES: add / rename / delete the category lookup table.
//   2. USERS: promote/demote roles (user ↔ agent ↔ admin).
// WHO SEES IT: only role=admin — blocked twice: PrivateRoute allowedRoles
// in App.jsx (UX) AND the role:admin middleware on every route in
// routes/api.php (enforcement). Hiding the nav link is a third, cosmetic.
//
// HOW IT CONNECTS TO THE API (via src/api.js + Bearer token):
//   GET    /api/categories        → list          (index; all roles can read)
//   POST   /api/categories        → add           (admin)
//   PATCH  /api/categories/{id}   → rename        (admin)
//   DELETE /api/categories/{id}   → delete        (admin)
//   GET    /api/users             → role table    (admin)
//   PATCH  /api/users/{id}/role   → change role   (admin)

import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from './api';

function Admin() {
  // DATA (fetched together on mount)
  const [categories, setCategories] = useState([]);
  const [users, setUsers] = useState([]);

  // NEW-CATEGORY input (the add form at the top of the category card).
  const [newCat, setNewCat] = useState('');

  // INLINE RENAME state: which row is being renamed and its draft value.
  // WHY only ONE rename at a time: with editId+editName as plain state,
  // clicking "Rename" on another row simply moves the editor — no need for
  // a per-row input component (keeps the demo small).
  const [editId, setEditId] = useState(null);
  const [editName, setEditName] = useState('');

  const [error, setError] = useState('');
  const navigate = useNavigate();

  // My own id/role (from localStorage, written by Login.jsx) — used to
  // disable the role dropdown on MY row (Laravel also 403s self-changes).
  let me = {};
  try {
    me = JSON.parse(localStorage.getItem('user')) || {};
  } catch {
    me = {};
  }

  // ---------------------------------------------------------------------------
  // READ — both lists on mount. WHY useEffect+[]: run once after render,
  // never inside rendering itself (React rule) and never in a loop.
  // Promise.all = fetch BOTH in parallel; if either fails we show one error
  // instead of firing a second request after the first one already broke.
  // ---------------------------------------------------------------------------
  useEffect(() => {
    const load = async () => {
      try {
        const [cats, usersRes] = await Promise.all([
          api.get('/categories'),
          api.get('/users'), // admin-only: role:admin middleware guards this
        ]);
        setCategories(cats.data);
        setUsers(usersRes.data);
      } catch (err) {
        // 403 here would mean the role changed since login — the axios
        // interceptor handles 401; this catch covers everything else.
        setError(err.response?.data?.message || 'Could not load admin data.');
      }
    };
    load();
  }, []);

  // ---------------------------------------------------------------------------
  // CATEGORY HANDLERS
  // ---------------------------------------------------------------------------

  // ADD — POST then append locally (same append-not-refetch trick as Tickets).
  const handleAddCategory = async (e) => {
    e.preventDefault(); // don't reload the page
    setError('');
    try {
      const { data } = await api.post('/categories', { name: newCat });
      setCategories([...categories, data]); // server returns the row w/ id
      setNewCat(''); // clear the input for the next one
    } catch (err) {
      const errors = err.response?.data?.errors;
      setError(
        errors
          ? Object.values(errors).flat().join(' ')
          : 'Could not add category.'
      );
    }
  };

  // RENAME — PATCH the row, then swap it into the list and close the editor.
  const handleRenameCategory = async (id) => {
    setError('');
    try {
      const { data } = await api.patch(`/categories/${id}`, { name: editName });
      setCategories(categories.map((c) => (c.id === id ? data : c)));
      setEditId(null); // exit rename mode
      setEditName('');
    } catch (err) {
      const errors = err.response?.data?.errors;
      setError(
        errors
          ? Object.values(errors).flat().join(' ')
          : 'Could not rename category.'
      );
    }
  };

  // DELETE — filter it out locally. WHY the server allows this even with
  // tickets attached: the category FK on tickets is nullOnDelete → those
  // tickets show "Uncategorized" instead of breaking (tickets migration).
  const handleDeleteCategory = async (id) => {
    setError('');
    try {
      await api.delete(`/categories/${id}`); // 204
      setCategories(categories.filter((c) => c.id !== id));
    } catch (err) {
      setError(err.response?.data?.message || 'Could not delete category.');
    }
  };

  // ---------------------------------------------------------------------------
  // ROLE HANDLER — the whole point of the RBAC panel.
  // Same endpoint for promote AND demote: PATCH /users/{id}/role {role}.
  // ---------------------------------------------------------------------------
  const handleRoleChange = async (user, role) => {
    setError('');
    try {
      const { data } = await api.patch(`/users/${user.id}/role`, { role });
      // Replace the row with the server's updated copy (it returns the full
      // user JSON, so the table shows exactly what was saved — or 403s).
      setUsers(users.map((u) => (u.id === user.id ? data : u)));
    } catch (err) {
      const errors = err.response?.data?.errors;
      setError(
        errors
          ? Object.values(errors).flat().join(' ')
          : 'Could not change role.'
      );
    }
  };

  // Same logout dance as Tickets.jsx (kept local per component rather than
  // extracted — one small duplicated block beats premature abstraction here).
  const handleLogout = async () => {
    try {
      await api.post('/logout');
    } catch {
      /* finish local logout regardless */
    }
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    navigate('/');
  };

  // ---------------------------------------------------------------------------
  // RENDER — same visual language as Tickets.jsx: .page > .topbar + .card
  // ---------------------------------------------------------------------------
  return (
    <div className="page">
      <header className="topbar">
        <h1>🛠 Admin Panel</h1>
        <nav className="nav">
          <Link to="/tickets">My Tickets</Link>
          <Link to="/queue">Queue</Link>
          <Link to="/admin" className="active">
            Admin
          </Link>
          <span className={`badge role-${me.role || 'admin'}`}>
            {me.role || 'admin'}
          </span>
          <button type="button" className="link-btn" onClick={handleLogout}>
            Logout
          </button>
        </nav>
      </header>

      {error && <div className="alert">{error}</div>}

      {/* ---------------- CATEGORY MANAGER (admin CRUD) ---------------- */}
      <div className="card">
        <h2>Categories ({categories.length})</h2>

        {/* ADD: small inline form — controlled input feeds handleAddCategory */}
        <form onSubmit={handleAddCategory} className="inline-form">
          <input
            value={newCat}
            onChange={(e) => setNewCat(e.target.value)}
            placeholder="New category name…"
            required
            maxLength={100}
          />
          <button type="submit">Add</button>
        </form>

        <ul className="cat-list">
          {categories.map((c) => (
            <li key={c.id} className="cat-row">
              {editId === c.id ? (
                // RENAME MODE: this row swaps its label for an input +
                // Save/Cancel. editId is the single source of "which row".
                <>
                  <input
                    className="inline-input"
                    value={editName}
                    onChange={(e) => setEditName(e.target.value)}
                    maxLength={100}
                    autoFocus // jump straight into typing
                  />
                  <button
                    type="button"
                    onClick={() => handleRenameCategory(c.id)}
                  >
                    Save
                  </button>
                  <button
                    type="button"
                    className="link-btn"
                    onClick={() => setEditId(null)} // cancel = close editor
                  >
                    Cancel
                  </button>
                </>
              ) : (
                <>
                  <span className="cat-name">{c.name}</span>
                  <button
                    type="button"
                    className="link-sm"
                    onClick={() => {
                      setEditId(c.id); // open the editor on THIS row
                      setEditName(c.name); // prefill with the current value
                    }}
                  >
                    Rename
                  </button>
                  <button
                    type="button"
                    className="danger"
                    onClick={() => handleDeleteCategory(c.id)}
                  >
                    Delete
                  </button>
                </>
              )}
            </li>
          ))}
        </ul>
      </div>

      {/* ---------------- USER ROLE MANAGER (admin only) ---------------- */}
      <div className="card">
        <h2>Users ({users.length})</h2>

        {/* A real <table> fits tabular data better than cards. WHO can
            change what: EVERY role dropdown here hits PATCH /users/{id}/role,
            which 403s for non-admins AND for self-changes (both enforced
            server-side — the disabled own-row below just mirrors it). */}
        <table className="table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Role</th>
            </tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id}>
                <td>{u.name}</td>
                <td className="muted">{u.email}</td>
                <td>
                  <select
                    value={u.role}
                    // Disabled on MY row: Laravel returns 403 for
                    // changing your own role — no point letting a click
                    // happen just to show an error.
                    disabled={u.id === me.id}
                    onChange={(e) => handleRoleChange(u, e.target.value)}
                  >
                    <option value="user">user</option>
                    <option value="agent">agent</option>
                    <option value="admin">admin</option>
                  </select>
                  {u.id === me.id && <span className="muted"> (you)</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        <p className="empty">
          Promote teammates to <strong>agent</strong> so they can work the
          Queue. Roles take effect on their next login (the stored user
          object refreshes then).
        </p>
      </div>
    </div>
  );
}

export default Admin;