/**
 * CacheCounty – API Client
 * Thin wrapper around fetch() for the PHP REST API.
 */

const API_BASE = '/api'; // adjust if API lives on a subdomain

const Api = (() => {

  async function request(method, path, body = null) {
    const headers = { 'Content-Type': 'application/json' };

    // Session token from cookie is sent automatically via credentials.
    // For non-cookie environments the token is stored in sessionStorage.
    const token = sessionStorage.getItem('cc_token');
    if (token) headers['Authorization'] = 'Bearer ' + token;

    const opts = {
      method,
      headers,
      credentials: 'include',
    };

    if (body !== null) opts.body = JSON.stringify(body);

    const res  = await fetch(API_BASE + path, opts);
    const data = await res.json();

    if (!res.ok) {
      throw new Error(data.error || 'Unbekannter Fehler');
    }

    return data.data ?? data;
  }

  return {
    // ── Auth ──────────────────────────────────────────────
    sendMagicLink: (email)  => request('POST', '/auth/magic-link', { email }),
    verifyToken:   (token)  => request('GET',  '/auth/verify?token=' + encodeURIComponent(token)),
    me:            ()       => request('GET',  '/auth/me'),
    logout:        ()       => request('POST', '/auth/logout'),

    // ── Public ────────────────────────────────────────────
    getCountries:  ()                    => request('GET', '/countries'),
    getMap:        (username, country)   => {
      const qs = country ? '?country=' + encodeURIComponent(country) : '';
      return request('GET', '/map/' + encodeURIComponent(username) + qs);
    },

    // ── Visits ────────────────────────────────────────────
    addVisit:    (code, payload) => request('POST',   '/regions/' + encodeURIComponent(code) + '/visit', payload),
    updateVisit: (code, payload) => request('PUT',    '/regions/' + encodeURIComponent(code) + '/visit', payload),
    removeVisit: (code)          => request('DELETE', '/regions/' + encodeURIComponent(code) + '/visit'),

    // ── Admin – Users ──────────────────────────────────────
    listUsers:      ()            => request('GET',    '/admin/users'),
    createUser:     (payload)     => request('POST',   '/admin/users', payload),
    updateUser:     (id, payload) => request('PATCH',  '/admin/users/' + id, payload),
    deleteUser:     (id)          => request('DELETE', '/admin/users/' + id),

    // ── Admin – Sessions ───────────────────────────────────
    listSessions:   ()            => request('GET',    '/admin/sessions'),
    deleteSession:  (token)       => request('DELETE', '/admin/sessions/' + encodeURIComponent(token)),

    // ── Stats ─────────────────────────────────────────────
    getStats:       (username)    => request('GET', '/stats/' + encodeURIComponent(username)),
    getLeaderboard: (country)     => {
      const qs = country ? '?country=' + encodeURIComponent(country) : '';
      return request('GET', '/leaderboard' + qs);
    },
  };
})();
