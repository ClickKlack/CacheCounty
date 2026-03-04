/**
 * CacheCounty – Admin Panel
 */

document.addEventListener('DOMContentLoaded', () => {

  // ── State ─────────────────────────────────────────────────────
  const state = {
    session: null,
    users:   [],
  };

  const $ = id => document.getElementById(id);

  const els = {
    authArea:            $('auth-area'),
    tableBody:           $('user-table-body'),
    sessionTableBody:    $('session-table-body'),
    btnNewUser:          $('btn-new-user'),
    btnRefreshSessions:  $('btn-refresh-sessions'),
    userDialog:          $('user-dialog'),
    dialogClose:         $('dialog-close'),
    formUsername:        $('form-username'),
    formEmail:           $('form-email'),
    formIsAdmin:         $('form-is-admin'),
    btnCreate:           $('btn-create'),
    formMsg:             $('form-msg'),
    backdrop:            $('backdrop'),
  };

  // ── Utilities ─────────────────────────────────────────────────

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function formatDate(dateStr) {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.slice(0, 10).split('-');
    return `${d}.${m}.${y}`;
  }

  function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('toast-visible'));
    setTimeout(() => {
      toast.classList.remove('toast-visible');
      toast.addEventListener('transitionend', () => toast.remove());
    }, 4000);
  }

  // ── Auth ──────────────────────────────────────────────────────

  function restoreSession() {
    const token    = sessionStorage.getItem('cc_token');
    const username = sessionStorage.getItem('cc_username');
    const isAdmin  = sessionStorage.getItem('cc_admin') === '1';
    if (token && username) state.session = { token, username, is_admin: isAdmin };
  }

  function renderAuthArea() {
    els.authArea.innerHTML =
      `<div class="auth-user">
         <span class="auth-hint">Eingeloggt als</span>
         <span class="auth-name">${escHtml(state.session.username)}</span>
       </div>
       <button id="btn-logout" class="btn btn-ghost" style="font-size:0.78rem">Abmelden</button>`;
    $('btn-logout').addEventListener('click', logout);
  }

  async function logout() {
    try { await Api.logout(); } catch (_) {}
    sessionStorage.clear();
    location.href = 'index.html';
  }

  // ── User table ────────────────────────────────────────────────

  async function loadUsers() {
    try {
      state.users = await Api.listUsers();
      renderTable();
    } catch (e) {
      showToast('Fehler beim Laden: ' + e.message, 'error');
    }
  }

  function renderTable() {
    if (!state.users.length) {
      els.tableBody.innerHTML = `<tr><td colspan="5" class="table-empty">Keine Benutzer vorhanden.</td></tr>`;
      return;
    }

    els.tableBody.innerHTML = state.users.map(u => {
      const initials  = u.username.charAt(0).toUpperCase();
      const isSelf    = state.session?.username === u.username;
      const adminBadge = u.is_admin
        ? `<span class="badge badge-admin">Admin</span>` : '';
      const statusBadge = u.is_active
        ? `<span class="badge badge-active">Aktiv</span>`
        : `<span class="badge badge-inactive">Inaktiv</span>`;

      const actions = isSelf
        ? `<span class="cell-self">(du)</span>`
        : `<button class="action-btn" data-action="toggle-active"
              data-id="${u.id}" data-value="${u.is_active ? '0' : '1'}"
              title="${u.is_active ? 'Deaktivieren' : 'Aktivieren'}">
              ${u.is_active ? '⏸' : '▶'}
           </button>
           <button class="action-btn" data-action="toggle-admin"
              data-id="${u.id}" data-value="${u.is_admin ? '0' : '1'}"
              title="${u.is_admin ? 'Admin-Rechte entziehen' : 'Zum Admin machen'}">
              ${u.is_admin ? '★' : '☆'}
           </button>
           <button class="action-btn action-btn--danger" data-action="delete"
              data-id="${u.id}" data-name="${escHtml(u.username)}"
              title="Löschen">✕</button>`;

      return `
        <tr class="${!u.is_active ? 'row-inactive' : ''}">
          <td>
            <div class="user-cell">
              <div class="user-avatar">${escHtml(initials)}</div>
              <div class="user-cell-info">
                <a href="/map/${escHtml(u.username)}" target="_blank" class="user-link">${escHtml(u.username)}</a>
                ${adminBadge}
              </div>
            </div>
          </td>
          <td class="col-email cell-muted">${escHtml(u.email)}</td>
          <td>${statusBadge}</td>
          <td class="col-date cell-muted">${formatDate(u.created_at)}</td>
          <td class="col-actions">${actions}</td>
        </tr>`;
    }).join('');

    els.tableBody.querySelectorAll('[data-action]').forEach(btn => {
      btn.addEventListener('click', handleAction);
    });
  }

  async function handleAction(e) {
    const btn    = e.currentTarget;
    const action = btn.dataset.action;
    const id     = Number(btn.dataset.id);
    btn.disabled = true;

    try {
      if (action === 'toggle-active') {
        const active = btn.dataset.value === '1';
        await Api.updateUser(id, { is_active: active });
        const u = state.users.find(u => u.id === id);
        if (u) u.is_active = active ? 1 : 0;
        showToast(active ? 'User aktiviert.' : 'User deaktiviert.');
        renderTable();

      } else if (action === 'toggle-admin') {
        const isAdmin = btn.dataset.value === '1';
        await Api.updateUser(id, { is_admin: isAdmin });
        const u = state.users.find(u => u.id === id);
        if (u) u.is_admin = isAdmin ? 1 : 0;
        showToast(isAdmin ? 'Admin-Rechte vergeben.' : 'Admin-Rechte entzogen.');
        renderTable();

      } else if (action === 'delete') {
        const name = btn.dataset.name;
        if (!confirm(`User „${name}" wirklich löschen?\nAlle Besuche werden ebenfalls gelöscht.`)) {
          btn.disabled = false;
          return;
        }
        await Api.deleteUser(id);
        state.users = state.users.filter(u => u.id !== id);
        showToast(`User „${name}" gelöscht.`);
        renderTable();
      }
    } catch (e) {
      showToast(e.message, 'error');
      btn.disabled = false;
    }
  }

  // ── Sessions ──────────────────────────────────────────────────

  async function loadSessions() {
    try {
      const sessions = await Api.listSessions();
      renderSessions(sessions);
    } catch (e) {
      showToast('Fehler beim Laden der Sessions: ' + e.message, 'error');
    }
  }

  function parseBrowser(ua) {
    if (!ua) return '—';
    if (ua.includes('Firefox/'))       return 'Firefox';
    if (ua.includes('Edg/'))           return 'Edge';
    if (ua.includes('Chrome/'))        return 'Chrome';
    if (ua.includes('Safari/'))        return 'Safari';
    if (ua.includes('curl/'))          return 'curl';
    return ua.slice(0, 40);
  }

  function renderSessions(sessions) {
    if (!sessions.length) {
      els.sessionTableBody.innerHTML = `<tr><td colspan="6" class="table-empty">Keine aktiven Sessions.</td></tr>`;
      return;
    }

    els.sessionTableBody.innerHTML = sessions.map(s => {
      const initials     = s.username.charAt(0).toUpperCase();
      const browser      = parseBrowser(s.user_agent);
      const lastSeen     = s.last_seen_at ? formatDate(s.last_seen_at) : '—';
      const currentBadge = s.is_current
        ? `<span class="badge badge-active" style="margin-left:6px">Aktuell</span>` : '';
      const deleteBtn = s.is_current
        ? `<span class="cell-self" title="Eigene Session">—</span>`
        : `<button class="action-btn action-btn--danger" data-action="delete-session"
              data-token="${escHtml(s.id)}" title="Session beenden">✕</button>`;

      return `
        <tr>
          <td>
            <div class="user-cell">
              <div class="user-avatar">${escHtml(initials)}</div>
              <div class="user-cell-info">
                <span class="user-link">${escHtml(s.username)}</span>
                ${currentBadge}
              </div>
            </div>
          </td>
          <td class="col-email cell-muted">${escHtml(s.ip_address || '—')}</td>
          <td class="cell-muted">${escHtml(browser)}</td>
          <td class="col-date cell-muted">${formatDate(s.created_at)}</td>
          <td class="col-date cell-muted">${lastSeen}</td>
          <td class="col-actions">${deleteBtn}</td>
        </tr>`;
    }).join('');

    els.sessionTableBody.querySelectorAll('[data-action="delete-session"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          await Api.deleteSession(btn.dataset.token);
          showToast('Session beendet.');
          await loadSessions();
        } catch (e) {
          showToast(e.message, 'error');
          btn.disabled = false;
        }
      });
    });
  }

  els.btnRefreshSessions.addEventListener('click', loadSessions);

  // ── Create User Dialog ────────────────────────────────────────

  function openDialog() {
    els.formUsername.value  = '';
    els.formEmail.value     = '';
    els.formIsAdmin.checked = false;
    els.formMsg.classList.add('hidden');
    els.backdrop.classList.remove('hidden');
    els.userDialog.classList.remove('hidden');
    els.formUsername.focus();
  }

  function closeDialog() {
    els.backdrop.classList.add('hidden');
    els.userDialog.classList.add('hidden');
  }

  els.btnNewUser.addEventListener('click', openDialog);
  els.dialogClose.addEventListener('click', closeDialog);
  els.backdrop.addEventListener('click', closeDialog);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDialog(); });

  els.btnCreate.addEventListener('click', async () => {
    const username = els.formUsername.value.trim();
    const email    = els.formEmail.value.trim();
    const isAdmin  = els.formIsAdmin.checked;

    if (!username || !email) {
      showFormMsg('Bitte Username und E-Mail-Adresse eingeben.', 'error');
      return;
    }

    els.btnCreate.disabled = true;
    els.formMsg.classList.add('hidden');
    try {
      await Api.createUser({ username, email, is_admin: isAdmin });
      closeDialog();
      showToast(`User „${username}" wurde angelegt.`);
      await loadUsers();
    } catch (e) {
      showFormMsg(e.message, 'error');
    } finally {
      els.btnCreate.disabled = false;
    }
  });

  els.formUsername.addEventListener('keydown', e => { if (e.key === 'Enter') els.formEmail.focus(); });
  els.formEmail.addEventListener('keydown',    e => { if (e.key === 'Enter') els.btnCreate.click(); });

  function showFormMsg(msg, type) {
    els.formMsg.textContent = msg;
    els.formMsg.className   = 'login-msg' + (type === 'error' ? ' error' : '');
    els.formMsg.classList.remove('hidden');
  }

  // ── Boot ──────────────────────────────────────────────────────

  async function boot() {
    restoreSession();

    if (!state.session) {
      try {
        state.session = await Api.me();
      } catch (_) {}
    }

    if (!state.session?.is_admin) {
      location.href = 'index.html';
      return;
    }

    renderAuthArea();
    await Promise.all([loadUsers(), loadSessions()]);
  }

  boot().catch(console.error);

});
