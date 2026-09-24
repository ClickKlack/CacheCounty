/**
 * CacheCounty – API Client
 * Thin wrapper around fetch() for the PHP REST API.
 */

const API_BASE = '/api'; // adjust if API lives on a subdomain

const Api = (() => {

  // Die Session steckt ausschließlich im HttpOnly-Cookie cc_session – JavaScript
  // sieht den Token nie. Frühere Versionen spiegelten ihn in sessionStorage;
  // diese Reste werden beim Laden entfernt.
  try {
    ['cc_token', 'cc_username', 'cc_admin', 'cc_is_admin'].forEach(k => sessionStorage.removeItem(k));
  } catch (_) { /* sessionStorage nicht verfügbar – nichts zu tun */ }

  // Wird bei 401 aufgerufen (Session abgelaufen oder beendet), damit die Seite
  // auf „abgemeldet" umschalten kann. /auth/me und /auth/verify sind ausgenommen:
  // Dort ist 401 eine normale Antwort („nicht eingeloggt" bzw. „Link ungültig").
  let unauthorizedHandler = null;
  const NO_UNAUTHORIZED_HANDLER = ['/auth/me', '/auth/verify'];

  async function request(method, path, body = null) {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
    };

    if (body !== null) opts.body = JSON.stringify(body);

    const res = await fetch(API_BASE + path, opts);

    let data;
    try {
      data = await res.json();
    } catch (_) {
      // Keine JSON-Antwort – meist eine HTML-Fehlerseite, etwa wenn der
      // PHP-Server hinter dem Dev-Proxy nicht läuft oder mod_rewrite fehlt.
      const err = new Error(`Die API hat kein JSON geliefert (HTTP ${res.status}). Läuft der PHP-Server?`);
      err.status = res.status;
      throw err;
    }

    if (!res.ok) {
      if (res.status === 401 && unauthorizedHandler && !NO_UNAUTHORIZED_HANDLER.includes(path)) {
        unauthorizedHandler();
      }
      const err = new Error(data.error || 'Unbekannter Fehler');
      err.status = res.status;
      throw err;
    }

    return data.data ?? data;
  }

  return {
    setUnauthorizedHandler: (fn) => { unauthorizedHandler = fn; },

    // ── Auth ──────────────────────────────────────────────
    sendMagicLink: (email)  => request('POST', '/auth/magic-link', { email }),
    verifyToken:   (token)  => request('POST', '/auth/verify', { token }),
    me:            ()       => request('GET',  '/auth/me'),
    logout:        ()       => request('POST', '/auth/logout'),
    logoutAll:     ()       => request('POST', '/auth/logout-all'),

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
