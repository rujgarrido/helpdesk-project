// WHAT: One page that handles BOTH login and registering — a single form
// that toggles modes to keep the demo small.
// HOW IT CONNECTS TO THE API:
//   POST /api/register  (mode = register)  → AuthController@register
//   POST /api/login     (mode = login)     → AuthController@login
// Both return { user, token }; we store the token in localStorage and
// navigate to /tickets, where PrivateRoute (App.jsx) will now let us in.

import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from './api';

function Login() {
  // ---------------------------------------------------------------------------
  // STATE MANAGEMENT — useState is React's "component memory".
  // Each call returns [value, setter]. When a setter is called, React
  // RE-RENDERS the component so the DOM reflects the new value. That's the
  // whole React loop: state change → re-render → UI update.
  // ---------------------------------------------------------------------------

  // Which mode are we in? false = login, true = register.
  const [isRegister, setIsRegister] = useState(false);

  // All form inputs live in ONE object so a single handleChange covers
  // every field (each input's `name` attribute picks the key to update).
  const [form, setForm] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '', // Laravel's 'confirmed' rule compares these two
  });

  // Server-side error messages, shown as a banner above the form.
  const [error, setError] = useState('');

  // Disables the button while the request is in flight → prevents
  // double-submitting (which would create two accounts / two tokens).
  const [loading, setLoading] = useState(false);

  // useNavigate = programmatic navigation (the code version of <Link>).
  // We only call navigate('/tickets') AFTER saving the token, otherwise
  // PrivateRoute would bounce us straight back to "/".
  const navigate = useNavigate();

  // GENERIC change handler used by all inputs:
  // {...form} copies current values, [e.target.name] overwrites just the
  // field being typed in (name/email/password come from the input's name attr).
  const handleChange = (e) => {
    setForm({ ...form, [e.target.name]: e.target.value });
  };

  // ---------------------------------------------------------------------------
  // SUBMIT — runs when the form's submit button is pressed (onSubmit={...}).
  // Picks /register or /login depending on `isRegister`.
  // ---------------------------------------------------------------------------
  const handleSubmit = async (e) => {
    // STOP the browser's native form submission, which would reload the
    // page and throw away all React state. We handle sending ourselves.
    e.preventDefault();
    setError('');
    setLoading(true); // disables the button (see disabled={loading} below)

    try {
      // Laravel ignores extra keys on login, and register needs
      // name + password_confirmation (its 'confirmed' rule checks them).
      const endpoint = isRegister ? '/register' : '/login';
      const { data } = await api.post(endpoint, form);

      // THE TOKEN IS THE APP'S MASTER KEY. localStorage (not a plain JS
      // variable) because it survives page refreshes — a plain variable
      // would be wiped on reload and the user would be logged out instantly.
      localStorage.setItem('token', data.token);
      // Keep the user object too so the Tickets page could greet them.
      localStorage.setItem('user', JSON.stringify(data.user));

      // Redirect to the protected page — PrivateRoute in App.jsx will now
      // find the token and render <Tickets /> instead of bouncing back.
      navigate('/tickets');
    } catch (err) {
      // HOW ERRORS ARRIVE: Laravel validation failures are HTTP 422 with
      //   { errors: { email: ["msg"], password: ["msg"] } }
      // Object.values(...).flat() flattens to ["msg","msg"], join() one line.
      // Everything else (wrong password → 422 from AuthController, API down
      // → no response at all) falls back to a generic but honest message.
      const errors = err.response?.data?.errors;
      const message = errors
        ? Object.values(errors).flat().join(' ')
        : err.response?.data?.message ||
          'Something went wrong. Is the Laravel server running?';
      setError(message);
    } finally {
      // finally = always runs (success OR failure) → re-enable the button.
      setLoading(false);
    }
  };

  // ---------------------------------------------------------------------------
  // RENDER — the JSX the browser sees. Conditional rendering ({isRegister &&
  // ...}) adds/removes fields as the mode flips. "Controlled inputs" means
  // every input's value comes FROM state (value={form.x}) and changes only
  // through the handler (onChange) — React is the single source of truth.
  // ---------------------------------------------------------------------------
  return (
    <div className="page">
      <div className="card auth-card">
        <h1>🎫 Helpdesk</h1>
        <h2>{isRegister ? 'Create an account' : 'Sign in'}</h2>

        {/* Error banner — {error && ...} renders nothing when error is '' */}
        {error && <div className="alert">{error}</div>}

        {/* onSubmit fires for Enter key AND button click alike */}
        <form onSubmit={handleSubmit}>
          {/* Name field exists ONLY in register mode */}
          {isRegister && (
            <label>
              Name
              <input
                name="name"
                value={form.name}
                onChange={handleChange}
                placeholder="Jane Doe"
                required
              />
            </label>
          )}

          <label>
            Email
            <input
              type="email"
              name="email"
              value={form.email}
              onChange={handleChange}
              placeholder="you@example.com"
              required
            />
          </label>

          <label>
            Password
            <input
              type="password"
              name="password"
              value={form.password}
              onChange={handleChange}
              placeholder="••••••••"
              required
              minLength={8} // matches Laravel's min:8 rule — fail fast in the UI
            />
          </label>

          {/* Second password box for register — must equal the first
              (Laravel's `confirmed` rule looks for password_confirmation) */}
          {isRegister && (
            <label>
              Confirm password
              <input
                type="password"
                name="password_confirmation"
                value={form.password_confirmation}
                onChange={handleChange}
                placeholder="••••••••"
                required
              />
            </label>
          )}

          <button type="submit" disabled={loading}>
            {/* loading text gives feedback during the network round-trip */}
            {loading ? 'Please wait…' : isRegister ? 'Register' : 'Login'}
          </button>
        </form>

        {/* Toggle between modes. type="button" is CRITICAL: without it this
            would default to type="submit" and submit the form on click. */}
        <button
          type="button"
          className="link-btn"
          onClick={() => {
            setIsRegister(!isRegister); // flip the boolean → re-render
            setError(''); // clear old errors when switching modes
          }}
        >
          {isRegister
            ? 'Already have an account? Login'
            : 'Need an account? Register'}
        </button>
      </div>
    </div>
  );
}

export default Login;
