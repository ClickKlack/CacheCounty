/**
 * CacheCounty – Header-Menü (Anmeldestatus + Aktionen)
 *
 * Gemeinsam für Karte, Statistik und Admin. Auf dem Desktop stehen die Aktionen
 * nebeneinander im Header, unter 600 px klappen sie hinter einen ☰-Button
 * (Umschaltung rein per CSS, siehe .auth-menu in app.css).
 *
 * Nutzung:
 *   AuthMenu.render(container, {
 *     username: 'max' | null,
 *     items: [
 *       { label: 'Statistiken', href: '/stats/max' },
 *       { label: 'Abmelden', onClick: () => …, title: 'optional' },
 *     ],
 *   });
 *
 * buildHtml() ist DOM-frei und wird in tests/auth-menu.test.js geprüft.
 */

const AuthMenu = (() => {

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function userBlock(cssClass, username) {
    return `<div class="${cssClass}">
              <span class="auth-hint">Eingeloggt als</span>
              <span class="auth-name">${escHtml(username)}</span>
            </div>`;
  }

  function itemHtml(item, index) {
    const title = item.title ? ` title="${escHtml(item.title)}"` : '';
    if (item.href) {
      return `<a href="${escHtml(item.href)}" class="btn btn-ghost auth-menu-item"${title}
                 style="font-size:0.78rem;text-decoration:none">${escHtml(item.label)}</a>`;
    }
    return `<button type="button" class="btn btn-ghost auth-menu-item" data-menu-index="${index}"${title}
                    style="font-size:0.78rem">${escHtml(item.label)}</button>`;
  }

  /**
   * Header-HTML: Nutzername (nur Desktop), ☰-Button (nur Mobil) und das Menü.
   */
  function buildHtml({ username = null, items = [] } = {}) {
    return (username ? userBlock('auth-user', username) : '') +
      `<button id="auth-menu-toggle" class="auth-menu-toggle" type="button"
               aria-label="Menü" aria-expanded="false" aria-controls="auth-menu">☰</button>` +
      `<div id="auth-menu" class="auth-menu">` +
        (username ? userBlock('auth-menu-user', username) : '') +
        items.map(itemHtml).join('') +
      `</div>`;
  }

  // ── DOM ──────────────────────────────────────────────────────

  let globalListenersBound = false;

  function setOpen(open) {
    const menu = document.getElementById('auth-menu');
    if (!menu) return;
    menu.classList.toggle('open', open);
    document.getElementById('auth-menu-toggle')?.setAttribute('aria-expanded', String(open));
  }

  function close() { setOpen(false); }

  function bindGlobalListeners() {
    if (globalListenersBound) return;
    globalListenersBound = true;
    // Klick außerhalb und Escape schließen das Menü
    document.addEventListener('click', e => {
      if (!e.target.closest('#auth-menu, #auth-menu-toggle')) close();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  }

  function render(container, options) {
    container.innerHTML = buildHtml(options);
    bindGlobalListeners();

    container.querySelector('#auth-menu-toggle').addEventListener('click', () => {
      setOpen(!container.querySelector('#auth-menu').classList.contains('open'));
    });

    const items = options.items || [];
    container.querySelectorAll('[data-menu-index]').forEach(btn => {
      btn.addEventListener('click', () => {
        close();
        items[Number(btn.dataset.menuIndex)].onClick?.();
      });
    });
  }

  return { buildHtml, render, close };
})();
