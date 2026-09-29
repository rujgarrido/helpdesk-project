// WHAT: The workhorse page — renders TWO modes from ONE component:
//   scope="mine" (route /tickets) → "My Tickets": everything I created
//              plus tickets assigned to me, with modest controls.
//   scope="all"  (route /queue)   → "Ticket Queue" (staff only): EVERY
//              ticket, triage controls (status, assign) enabled.
// WHY one component twice: DRY — the rows, badges and handlers are
// identical; only the URL (and a few role checks) differ. Two separate
// pages would duplicate ~200 lines.
//
// HOW IT CONNECTS TO THE API (all via src/api.js, which attaches the
// Bearer token automatically):
//   GET    /api/categories          → create-form dropdown      (on mount)
//   GET    /api/tickets[?scope=all] → the list                  (on mount)
//   POST   /api/tickets             → create                    (form submit)
//   PATCH  /api/tickets/{id}        → status / assignment       (staff UI)
//   DELETE /api/tickets/{id}        → remove                    (delete btn)
//   POST   /api/logout              → revoke token              (logout)
// EVERY role rule here is cosmetic — the Laravel side (EnsureRole
// middleware + TicketController) enforces the same matrix server-side, so
// hiding a button is UX, not security.

import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from './api';

function Tickets({ scope = 'mine' }) {
  // The ticket array rendered as the list. Starts empty.
  const [tickets, setTickets] = useState([]);

  // The category dropdown options (fetched from GET /api/categories).
  const [categories, setCategories] = useState([]);

  // Fields of the "New Ticket" form. priority defaults to 'medium' and
  // category_id starts '' (empty) — the required attribute forces a pick.
  const [form, setForm] = useState({
    title: '',
    description: '',
    priority: 'medium',
    category_id: '',
  });

  const [error, setError] = useState('');
  const navigate = useNavigate();

  // WHO AM I — read once from localStorage (Login.jsx wrote it at login).
  // WHY here and not a /api/user fetch: zero round-trip; the tradeoff is a
  // stale role until next login (e.g. if an admin demotes you mid-session).
  // Acceptable for this app; a bigger one would re-fetch on mount.
  let me = {};
  try {
    me = JSON.parse(localStorage.getItem('user')) || {};
  } catch {
    me = {}; // corrupted entry → treat as anonymous, guards handle the rest
  }

  // Shorthand role flags — mirrors the helper methods on the PHP User model
  // (isAdmin/isAgent/isStaff), so the two halves read alike.
  const isStaff = me.role === 'agent' || me.role === 'admin';
  const isAdmin = me.role === 'admin';

  // ---------------------------------------------------------------------------
  // READ — fetch data when the component FIRST mounts.
  // WHY useEffect: it runs side effects (network calls) AFTER render — you
  // can't call an API in the middle of rendering a component.
  // WHY the empty array [] (dependencies): useEffect runs after EVERY render
  // by default; [] means "only on mount". Without it you'd loop forever:
  // fetch → setState → re-render → fetch → ...
  // ---------------------------------------------------------------------------
  useEffect(() => {
    // WHY a nested async function? useEffect's callback can't itself be
    // async (React would treat the returned Promise as a cleanup function),
    // so define an async helper and call it immediately.
    const load = async () => {
      try {
        // scope=all asks the API for EVERY ticket (server checks isStaff
        // and 403s if a customer sneaks in — PrivateRoute already blocks
        // that in the UI, this is defense in depth).
        const url = scope === 'all' ? '/tickets?scope=all' : '/tickets';
        const [ticketsRes, catsRes] = await Promise.all([
          api.get(url),
          api.get('/categories'),
        ]);

        setTickets(ticketsRes.data); // state update → React re-renders list
        setCategories(catsRes.data); // fills the category <select>
      } catch {
        // 401 is already handled globally by the interceptor in api.js.
        setError('Could not load data. Is the API running?');
      }
    };

    load();
  }, [scope]); // re-runs only if the mode (mine/all) changes — not per render

  // Generic form change handler (same pattern as Login.jsx): spread the old
  // state, overwrite the one key named by the input's name attribute.
  const handleChange = (e) =>
    setForm({ ...form, [e.target.name]: e.target.value });


  // ---------------------------------------------------------------------------
  // CREATE — POST a new ticket, then prepend it to the local list.
  // WHY prepend instead of refetching: the server just returned the saved
  // ticket (201 + JSON incl. nested category), so re-downloading the whole
  // list would waste a round-trip.
  // ---------------------------------------------------------------------------
  const handleCreate = async (e) => {
    e.preventDefault(); // stop the browser from reloading the page
    setError('');
    try {
      // NOTE: no `status` in the payload — Laravel forces 'open' server-side,
      // and no `assigned_to` either — new tickets wait unassigned in the queue.
      const { data } = await api.post('/tickets', {
        ...form,
        category_id: Number(form.category_id), // <select> values are strings;
      });                                      // the API validates integer
      setTickets([data, ...tickets]); // newest first, matches ->latest()
      // Reset the form (category back to '' → required again).
      setForm({ title: '', description: '', priority: 'medium', category_id: '' });
    } catch (err) {
      const errors = err.response?.data?.errors;
      setError(
        errors
          ? Object.values(errors).flat().join(' ')
          : 'Could not create ticket.'
      );
    }
  };

  // ---------------------------------------------------------------------------
  // UPDATE STATUS — PATCH just the `status` field (staff-only control; the
  // dropdown isn't even rendered for customers, and Laravel's update()
  // would strip the key from them anyway — UI + API double lock).
  // WHY PATCH (not PUT): PUT replaces the ENTIRE resource; PATCH = partial.
  // ---------------------------------------------------------------------------
  const handleStatusChange = async (ticket, status) => {
    setError('');
    try {
      const { data } = await api.patch(`/tickets/${ticket.id}`, { status });
      // Replace ONLY the changed ticket: map() builds a NEW array — React
      // compares state by reference; mutating in place wouldn't re-render.
      setTickets(tickets.map((t) => (t.id === ticket.id ? data : t)));
    } catch (err) {
      setError(err.response?.data?.message || 'Could not update status.');
    }
  };

  // ---------------------------------------------------------------------------
  // ASSIGN — staff claim work for themselves or hand it back to the queue.
  // assignedTo = my id ("Assign to me") or null (Unassign → back to queue).
  // WHY one handler for both: same endpoint, different payload value.
  // ---------------------------------------------------------------------------
  const handleAssign = async (ticket, assignedTo) => {
    setError('');
    try {
      const { data } = await api.patch(`/tickets/${ticket.id}`, {
        assigned_to: assignedTo, // null = unassign (Laravel: nullable rule)
      });
      setTickets(tickets.map((t) => (t.id === ticket.id ? data : t)));
    } catch (err) {
      setError(err.response?.data?.message || 'Could not update assignment.');
    }
  };

  // ---------------------------------------------------------------------------
  // DELETE — only rendered when allowed (admin: any; customer: own + open;
  // agent: never — enforced in TicketController regardless of what renders).
  // ---------------------------------------------------------------------------
  const handleDelete = async (id) => {
    setError('');
    try {
      await api.delete(`/tickets/${id}`); // 204 No Content on success
      // filter() keeps everything EXCEPT the deleted ticket.
      setTickets(tickets.filter((t) => t.id !== id));
    } catch (err) {
      setError(err.response?.data?.message || 'Could not delete ticket.');
    }
  };

  // ---------------------------------------------------------------------------
  // LOGOUT — two steps: kill the token SERVER-SIDE, then locally.
  // WHY both: localStorage.removeItem alone would leave a still-valid token
  // floating around; the API call revokes it so a stolen copy dies too.
  // ---------------------------------------------------------------------------
  const handleLogout = async () => {
    try {
      await api.post('/logout'); // revokes the token row in Laravel (Sanctum)
    } catch {
      // Even if the API call fails (server briefly down), still finish the
      // local logout — never trap the user inside a logged-in UI.
    }
    localStorage.removeItem('token'); // PrivateRoute checks THIS key
    localStorage.removeItem('user');
    navigate('/'); // redirect to the login page
  };


  // ---------------------------------------------------------------------------
  // RENDER — JSX maps STATE + ROLE to UI:
  //   scope/isStaff/isAdmin → which nav links & controls appear
  //   error        → alert banner
  //   form         → the empty "New Ticket" inputs
  //   tickets[]    → one <li> per ticket (list at the bottom)
  // ---------------------------------------------------------------------------
  return (
    <div className="page">
      {/* TOP BAR — title reflects the mode; nav + controls reflect the role */}
      <header className="topbar">
        <h1>{scope === 'all' ? '🎫 Ticket Queue' : '🎫 My Tickets'}</h1>

        <nav className="nav">
          {/* className='active' = current page (styled in index.css) */}
          <Link to="/tickets" className={scope !== 'all' ? 'active' : ''}>
            My Tickets
          </Link>
          {/* Staff-only links: hidden for customers here AND blocked again
              by PrivateRoute's allowedRoles (double UX guard). */}
          {isStaff && (
            <Link to="/queue" className={scope === 'all' ? 'active' : ''}>
              Queue
            </Link>
          )}
          {isAdmin && <Link to="/admin">Admin</Link>}

          {/* Who am I? Shows the current role — handy while testing RBAC */}
          <span className={`badge role-${me.role || 'user'}`}>
            {me.role || 'user'}
          </span>

          <button type="button" className="link-btn" onClick={handleLogout}>
            Logout
          </button>
        </nav>
      </header>

      {error && <div className="alert">{error}</div>}

      {/* ---------------- CREATE FORM (POST /api/tickets) ---------------- */}
      <div className="card">
        <h2>New Ticket</h2>
        <form onSubmit={handleCreate}>
          <label>
            Title
            <input
              name="title"
              value={form.title}
              onChange={handleChange}
              placeholder="Brief summary of the problem"
              required
            />
          </label>

          <label>
            Description
            <textarea
              name="description"
              value={form.description}
              onChange={handleChange}
              placeholder="Describe the issue in detail…"
              rows={3}
              required
            />
          </label>

          <div className="form-row">
            <label>
              Category
              {/* Controlled select: `value` mirrors state, so resetting
                  state after create() also resets the dropdown.
                  required + value='' forces the user to actually choose —
                  mirrors Laravel's required|exists:categories,id rules. */}
              <select
                name="category_id"
                value={form.category_id}
                onChange={handleChange}
                required
              >
                <option value="" disabled>
                  Choose…
                </option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </label>

            <label>
              Priority
              <select
                name="priority"
                value={form.priority}
                onChange={handleChange}
              >
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
              </select>
            </label>
          </div>

          <button type="submit">Create Ticket</button>
        </form>
      </div>

      {/* ---------------- LIST (GET /api/tickets result) ---------------- */}
      <div className="card">
        <h2>
          {scope === 'all' ? 'All Tickets' : 'My Tickets'} ({tickets.length})
        </h2>

        {/* Friendly empty state per mode */}
        {tickets.length === 0 ? (
          <p className="empty">
            {scope === 'all'
              ? 'Queue is clear — no tickets waiting.'
              : 'No tickets yet — create one above.'}
          </p>
        ) : (
          <ul className="ticket-list">
            {/* map() turns each ticket OBJECT into a JSX element.
                `key` gives React a stable identity per row so it updates
                only what changed instead of rebuilding the whole list. */}
            {tickets.map((t) => {
              // DELETE PERMISSION (mirror of TicketController::destroy):
              //   admin → anything; customer → own + open only; agent → never.
              // Purely cosmetic — the API re-checks — but it stops users
              // clicking buttons that would just fail.
              const canDelete =
                isAdmin || (!isStaff && t.user_id === me.id && t.status === 'open');

              return (
                <li key={t.id} className="ticket">
                  <div className="ticket-head">
                    <strong>{t.title}</strong>
                    <span className="badges">
                      {/* Category badge (null → "Uncategorized": possible if
                          an admin deleted the category, FK nullOnDelete) */}
                      <span className="badge category-badge">
                        {t.category?.name ?? 'Uncategorized'}
                      </span>
                      {/* Status badge: class "status-open" etc. → colored in
                          CSS. replace('_') → "in progress" for display */}
                      <span className={`badge status-${t.status}`}>
                        {t.status.replace('_', ' ')}
                      </span>
                    </span>
                  </div>

                  <p className="ticket-desc">{t.description}</p>

                  <div className="ticket-meta">
                    {/* Priority badge (priority-low / -medium / -high) */}
                    <span className={`badge priority-${t.priority}`}>
                      {t.priority}
                    </span>

                    {/* WHO REPORTED IT — only meaningful in the queue view */}
                    {scope === 'all' && (
                      <span className="badge requester">
                        via {t.user?.name ?? '?'}
                      </span>
                    )}

                    {/* ASSIGNMENT — staff-only cell. Assigned → show who +
                        a way back to the queue; unassigned → claim button. */}
                    {isStaff && (
                      <span className="assign-cell">
                        {t.assigned_to ? (
                          <>
                            <span className="badge assignee">
                              👤 {t.assignee?.name ?? 'someone'}
                            </span>
                            <button
                              type="button"
                              className="link-sm"
                              onClick={() => handleAssign(t, null)}
                            >
                              unassign
                            </button>
                          </>
                        ) : (
                          <button
                            type="button"
                            className="link-sm"
                            onClick={() => handleAssign(t, me.id)}
                          >
                            Assign to me
                          </button>
                        )}
                      </span>
                    )}

                    {/* STATUS DROPDOWN — staff-only. Customers already have
                        the status badge above (read-only), matching Laravel's
                        rule: requesters never move progress themselves. */}
                    {isStaff && (
                      <select
                        className="status-select"
                        value={t.status}
                        onChange={(e) => handleStatusChange(t, e.target.value)}
                      >
                        <option value="open">open</option>
                        <option value="in_progress">in_progress</option>
                        <option value="closed">closed</option>
                      </select>
                    )}

                    {/* DELETE — rendered only when canDelete (see above).
                        arrow fn DELAYS the call until click. */}
                    {canDelete && (
                      <button
                        type="button"
                        className="danger"
                        onClick={() => handleDelete(t.id)}
                      >
                        Delete
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}

export default Tickets;