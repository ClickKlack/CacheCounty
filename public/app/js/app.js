/**
 * CacheCounty – App Controller
 * Orchestrates auth, country switching, state panel, dialogs and visit CRUD.
 */

document.addEventListener('DOMContentLoaded', () => {

  // ── State ─────────────────────────────────────────────────────
  const state = {
    session:        null,   // { username, is_admin } | null – immer vom Server (/me, /verify)
    countries:      [],     // full country configs from API
    currentCountry: null,   // active country config object
    pageUser:       null,   // username from URL /map/{username}
    visits:         [],     // visits array from API
    stateMap:       {},     // { stateCode: { name, code, total, regions[] } }
    hiddenStates:   new Set(), // stateCodes currently hidden
    activeRegion:   null,   // region currently shown in dialog
  };

  // ── DOM refs ──────────────────────────────────────────────────
  const $ = id => document.getElementById(id);

  const els = {
    countrySelect:  $('country-select'),
    statsBar:       $('stats-bar'),
    statsText:      $('stats-text'),
    statsFill:      $('stats-fill'),
    authArea:       $('auth-area'),
    ownerBadge:     $('owner-badge'),
    ownerName:      $('owner-name'),
    ownerAvatar:    $('owner-avatar'),
    mapLoader:      $('map-loader'),
    mapContainer:   $('map-container'),

    // State panel
    statePanel:       $('state-panel'),
    statePanelToggle: $('state-panel-toggle'),
    statePanelTitle:  $('state-panel-title'),
    stateList:        $('state-list'),
    btnStatesAll:     $('btn-states-all'),
    btnStatesNone:    $('btn-states-none'),

    // Region dialog
    regionDialog:   $('region-dialog'),
    dialogClose:    $('dialog-close'),
    dialogBadge:    $('dialog-badge'),
    dialogName:     $('dialog-name'),
    dialogSub:      $('dialog-sub'),
    statusPill:     $('status-pill'),
    visitInfo:      $('visit-info'),
    infoDate:       $('info-date'),
    infoNotes:      $('info-notes'),
    visitForm:      $('visit-form'),
    formDate:       $('form-date'),
    formNotes:      $('form-notes'),
    btnToggle:      $('btn-toggle-visit'),
    btnSave:        $('btn-save-visit'),

    // Login dialog
    loginDialog:    $('login-dialog'),
    loginClose:     $('login-close'),
    loginEmail:     $('login-email'),
    btnSendMagic:   $('btn-send-magic'),
    loginMsg:       $('login-msg'),
    backdrop:       $('backdrop'),
  };

  // ── Utilities ─────────────────────────────────────────────────

  function getPageUsername() {
    const match = location.pathname.match(/^\/map\/([^/]+)/);
    return match ? decodeURIComponent(match[1]) : null;
  }

  function isOwner() {
    if (!state.session) return false;
    if (!state.pageUser) return true; // eigene Karte, kein User in der URL
    return state.session.username.toLowerCase() === state.pageUser.toLowerCase();
  }

  function findVisit(fullCode) {
    const [cc, rc] = fullCode.split('-');
    return state.visits.find(v => v.country_code === cc && v.region_code === rc) || null;
  }

  function formatDate(dateStr) {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function showLoader() { els.mapLoader.classList.remove('hidden'); }
  function hideLoader() { els.mapLoader.classList.add('hidden'); }

  // ── localStorage helpers ──────────────────────────────────────

  function lsKey() {
    const u = state.pageUser || state.session?.username || 'anon';
    const c = state.currentCountry?.code || 'XX';
    return `cc_states_${u}_${c}`;
  }

  function saveHiddenStates() {
    try {
      localStorage.setItem(lsKey(), JSON.stringify([...state.hiddenStates]));
    } catch (_) {}
  }

  function loadHiddenStates() {
    try {
      const raw = localStorage.getItem(lsKey());
      return raw ? new Set(JSON.parse(raw)) : new Set();
    } catch (_) {
      return new Set();
    }
  }

  // ── Auth ──────────────────────────────────────────────────────

  async function checkMagicLinkToken() {
    const params = new URLSearchParams(location.search);
    const token  = params.get('token');
    if (!token) return;
    history.replaceState({}, '', location.pathname);
    try {
      // Der Server setzt das HttpOnly-Cookie; hier kommen nur Name und Rolle an
      state.session = await Api.verifyToken(token);
    } catch (e) {
      showToast('Der Login-Link ist ungültig oder abgelaufen.', 'error');
    }
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

  // Aufgerufen von api.js bei 401: Session abgelaufen oder anderswo beendet
  function onSessionLost() {
    if (!state.session) return;
    state.session = null;
    renderAuthArea();
    renderOwnerBadge();
    renderStatePanel();   // entfernt die Download-Buttons des Besitzers
    closeDialog();
    showToast('Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.', 'error');
  }

  // Header-Aktionen über das gemeinsame Menü (auth-menu.js)
  function renderAuthArea() {
    const statsUser = state.pageUser || state.session?.username;
    const statsItem = statsUser
      ? [{ label: 'Statistiken', href: '/stats/' + encodeURIComponent(statsUser) }]
      : [];

    if (state.session) {
      AuthMenu.render(els.authArea, {
        username: state.session.username,
        items: [
          ...(state.session.is_admin ? [{ label: 'Admin', href: 'admin.html' }] : []),
          ...statsItem,
          { label: 'Abmelden', onClick: () => logout(false) },
          { label: 'Überall abmelden', title: 'Beendet die Anmeldung auf allen Geräten und Browsern',
            onClick: () => { if (confirm('Auf allen Geräten und Browsern abmelden?')) logout(true); } },
        ],
      });
    } else {
      AuthMenu.render(els.authArea, {
        items: [...statsItem, { label: 'Anmelden', onClick: () => openDialog('login') }],
      });
    }
  }

  // everywhere = true beendet alle Sessions des Nutzers, nicht nur die aktuelle
  async function logout(everywhere) {
    try { await (everywhere ? Api.logoutAll() : Api.logout()); } catch (_) {}
    state.session = null;
    renderAuthArea();
    renderOwnerBadge();
    renderStatePanel();   // entfernt die Download-Buttons des Besitzers
    closeDialog();
  }

  // Titel, Beschreibung und canonical-URL je Karte (page-meta.js)
  function updatePageMeta() {
    const user = state.pageUser;
    if (!user) {
      PageMeta.set({ path: '/' });
      return;
    }
    const heading = `Karte von ${user}`;
    $('page-title').textContent = heading;
    PageMeta.set({
      title:       `${heading} – CacheCounty`,
      description: `Welche Landkreise, Bezirke und Kommunen hat ${user} schon besucht? ` +
                   `Die Geocaching-Karte von ${user} bei CacheCounty.`,
      path:        '/map/' + encodeURIComponent(user),
    });
  }

  // ── Owner badge ───────────────────────────────────────────────

  function renderOwnerBadge() {
    const owner = state.pageUser || state.session?.username;
    if (!owner) { els.ownerBadge.classList.add('hidden'); return; }
    els.ownerName.textContent   = owner;
    els.ownerAvatar.textContent = owner.charAt(0).toUpperCase();
    els.ownerBadge.classList.remove('hidden');
  }

  // ── Stats bar ─────────────────────────────────────────────────

  function renderStats() {
    if (!state.currentCountry) return;
    const cc      = state.currentCountry.code;
    const visited = state.visits.filter(v => v.country_code === cc).length;
    const total   = Object.values(state.stateMap).reduce((s, st) => s + st.total, 0);

    if (total > 0) {
      const pct = Math.round((visited / total) * 100);
      els.statsText.textContent  = `${visited} von ${total} Regionen besucht · ${pct} %`;
      els.statsFill.style.width  = pct + '%';
      els.statsBar.classList.remove('hidden');
      els.mapContainer.classList.add('has-stats');
    } else {
      els.statsBar.classList.add('hidden');
      els.mapContainer.classList.remove('has-stats');
    }
  }

  // ── State Panel ───────────────────────────────────────────────

  function renderStatePanel() {
    const cc      = state.currentCountry?.code || '';
    const label   = state.currentCountry?.state_label || 'Regionen';

    els.statePanelTitle.textContent = label;
    els.statePanelToggle.setAttribute('data-label', label);

    const entries = Object.values(state.stateMap)
      .sort((a, b) => a.name.localeCompare(b.name, 'de'));

    const visitedByState = {};
    state.visits
      .filter(v => v.country_code === cc)
      .forEach(v => {
        // find which state this region belongs to
        for (const [sc, st] of Object.entries(state.stateMap)) {
          const fullCode = cc + '-' + v.region_code;
          if (st.regions.includes(fullCode)) {
            visitedByState[sc] = (visitedByState[sc] || 0) + 1;
            break;
          }
        }
      });

    const owner = isOwner();

    els.stateList.innerHTML = entries.map(st => {
      const visited  = visitedByState[st.code] || 0;
      const missing  = st.total - visited;
      const pct      = st.total > 0 ? Math.round((visited / st.total) * 100) : 0;
      const checked  = !state.hiddenStates.has(st.code);
      const dimmed   = !checked ? 'dimmed' : '';
      const name     = escHtml(st.name);
      const code     = escHtml(st.code);

      // Downloads nur für den eingeloggten Besitzer
      const downloads = owner
        ? `<span class="state-item-dl-group">
             <button class="state-item-dl" data-dl-state="${code}" data-dl-mode="all"
                     title="Alle ${st.total} Landkreise – gefundene grün, fehlende rot"
                     aria-label="Alle Landkreise von ${name} als GeoJSON herunterladen">&#10515;</button>` +
          (missing > 0
            ? `<button class="state-item-dl state-item-dl--missing" data-dl-state="${code}" data-dl-mode="missing"
                     title="Nur die ${missing} fehlenden Landkreise"
                     aria-label="Fehlende Landkreise von ${name} als GeoJSON herunterladen">&#10515;</button>`
            : '') +
          `</span>`
        : '';

      return `
        <li class="state-item ${dimmed}" data-state-code="${code}">
          <div class="state-checkbox ${checked ? 'checked' : ''}"></div>
          <div class="state-item-info">
            <div class="state-item-name">${name}</div>
            <div class="state-item-bar-wrap">
              <div class="state-item-bar">
                <div class="state-item-bar-fill" style="width:${pct}%"></div>
              </div>
              <span class="state-item-count">${visited}&thinsp;/&thinsp;${st.total}</span>
            </div>
          </div>
          ${downloads}
        </li>`;
    }).join('');

    // Click handler per item – Download-Buttons dürfen nicht durchschlagen
    els.stateList.querySelectorAll('.state-item').forEach(item => {
      item.addEventListener('click', e => {
        if (e.target.closest('.state-item-dl')) return;
        toggleState(item.dataset.stateCode);
      });
    });

    els.stateList.querySelectorAll('.state-item-dl').forEach(btn => {
      btn.addEventListener('click', () =>
        downloadStateGeoJSON(btn.dataset.dlState, btn.dataset.dlMode));
    });
  }

  // ── GeoJSON-Export ────────────────────────────────────────────

  /**
   * Lädt die Landkreise eines Bundeslandes als GeoJSON herunter.
   * mode: 'all' (alle, farbcodiert) | 'missing' (nur nicht besuchte)
   */
  function downloadStateGeoJSON(stateCode, mode) {
    const country = state.currentCountry;
    const st      = state.stateMap[stateCode];

    if (!country || !st) {
      showToast('Bundesland nicht gefunden.', 'error');
      return;
    }

    try {
      const visitsByRegionCode = new Map(
        state.visits
          .filter(v => v.country_code === country.code)
          .map(v => [String(v.region_code), v])
      );

      const fc = CacheExport.buildFeatureCollection({
        features:      CacheMap.getFeatures(),
        countryConfig: country,
        stateCode,
        stateName:     st.name,
        visitsByRegionCode,
        mode,
      });

      if (!fc.features.length) {
        showToast('Keine Landkreise zum Exportieren.', 'error');
        return;
      }

      CacheExport.triggerDownload(
        CacheExport.buildFilename(country.code, st.name, mode),
        JSON.stringify(fc)
      );
      showToast(`${fc.features.length} Landkreise heruntergeladen.`);
    } catch (e) {
      console.error('Export fehlgeschlagen:', e);
      showToast('Export fehlgeschlagen: ' + e.message, 'error');
    }
  }

  function toggleState(stateCode, forceVisible) {
    const visible = forceVisible !== undefined
      ? forceVisible
      : state.hiddenStates.has(stateCode);

    if (visible) state.hiddenStates.delete(stateCode);
    else          state.hiddenStates.add(stateCode);

    CacheMap.setStateVisibility(stateCode, visible);
    saveHiddenStates();
    renderStatePanel();
  }

  function setAllStates(visible) {
    Object.keys(state.stateMap).forEach(sc => {
      if (visible) state.hiddenStates.delete(sc);
      else          state.hiddenStates.add(sc);
      CacheMap.setStateVisibility(sc, visible);
    });
    saveHiddenStates();
    renderStatePanel();
  }

  // Panel toggle (collapse/expand)
  els.statePanelToggle.addEventListener('click', () => {
    els.statePanel.classList.toggle('collapsed');
  });

  els.btnStatesAll.addEventListener('click',  () => setAllStates(true));
  els.btnStatesNone.addEventListener('click', () => setAllStates(false));

  // ── Countries ─────────────────────────────────────────────────

  async function loadCountries() {
    try {
      state.countries = await Api.getCountries();
    } catch (e) {
      console.error('Länder nicht ladbar:', e);
      state.countries = [{ code: 'DE', label: 'Deutschland', state_label: 'Bundesland',
        geojson: 'data/de_landkreise.geojson', region_name_property: 'GEN',
        region_code_property: 'AGS', state_name_property: 'BL', state_code_property: 'BL_ID' }];
    }

    els.countrySelect.innerHTML = state.countries
      .map(c => `<option value="${escHtml(c.code)}">${escHtml(c.label)}</option>`)
      .join('');

    await switchCountry(state.countries[0]?.code || 'DE');
  }

  async function switchCountry(code) {
    const country = state.countries.find(c => c.code === code);
    if (!country) return;
    state.currentCountry = country;

    showLoader();

    const username = state.pageUser || state.session?.username;
    state.visits = [];
    if (username) {
      try {
        const data = await Api.getMap(username, code);
        state.visits = data.visits || [];
      } catch (e) {
        console.warn('Visits nicht ladbar:', e);
      }
    }

    // Load hidden states from localStorage for this user+country combo
    state.hiddenStates = loadHiddenStates();

    const geojsonFile = country.geojson?.split('/').pop() || (code.toLowerCase() + '.geojson');
    const geojsonUrl  = '../data/' + geojsonFile;

    const stateMap = await CacheMap.loadGeoJSON(
      geojsonUrl,
      country,
      state.visits,
      [...state.hiddenStates],
      onRegionClick
    );

    state.stateMap = stateMap || {};

    hideLoader();
    renderStats();
    renderStatePanel();
  }

  els.countrySelect.addEventListener('change', e => switchCountry(e.target.value));

  // ── Region dialog ─────────────────────────────────────────────

  function onRegionClick(regionData) {
    state.activeRegion = regionData;
    const visit   = findVisit(regionData.fullCode);
    const visited = !!visit;

    els.dialogBadge.textContent = regionData.countryCode;
    els.dialogName.textContent  = regionData.name;
    els.dialogSub.textContent   = regionData.stateName || '';

    els.statusPill.textContent = visited ? 'Besucht' : 'Nicht besucht';
    els.statusPill.classList.toggle('visited', visited);

    if (visited) {
      els.infoDate.textContent  = formatDate(visit.visited_at);
      els.infoNotes.textContent = visit.notes || '—';
      els.visitInfo.classList.remove('hidden');
    } else {
      els.visitInfo.classList.add('hidden');
    }

    if (isOwner()) {
      els.formDate.value  = visit?.visited_at || '';
      els.formNotes.value = visit?.notes      || '';
      els.btnToggle.textContent = visited ? 'Besuch entfernen' : 'Als besucht markieren';
      els.btnToggle.className   = 'btn ' + (visited ? 'btn-danger' : 'btn-primary');
      els.btnSave.classList.toggle('hidden', !visited);
      els.visitForm.classList.remove('hidden');
    } else {
      els.visitForm.classList.add('hidden');
    }

    openDialog('region');
  }

  els.btnToggle.addEventListener('click', async () => {
    if (!state.activeRegion) return;
    const { fullCode, countryCode, regionCode, name } = state.activeRegion;
    const visited = !!findVisit(fullCode);
    els.btnToggle.disabled = true;
    try {
      if (visited) {
        await Api.removeVisit(fullCode);
        state.visits = state.visits.filter(
          v => !(v.country_code === countryCode && v.region_code === regionCode));
        CacheMap.markVisited(fullCode, false);
      } else {
        await Api.addVisit(fullCode, {
          region_name: name,
          visited_at:  els.formDate.value  || null,
          notes:       els.formNotes.value || null,
        });
        state.visits.push({
          country_code: countryCode, region_code: regionCode,
          region_name: name,
          visited_at:  els.formDate.value  || null,
          notes:       els.formNotes.value || null,
        });
        CacheMap.markVisited(fullCode, true);
      }
      renderStats();
      renderStatePanel();
      onRegionClick(state.activeRegion);
    } catch (e) {
      if (e.status !== 401) alert('Fehler: ' + e.message);   // 401 meldet onSessionLost()
    } finally {
      els.btnToggle.disabled = false;
    }
  });

  els.btnSave.addEventListener('click', async () => {
    if (!state.activeRegion) return;
    const { fullCode } = state.activeRegion;
    els.btnSave.disabled = true;
    try {
      await Api.updateVisit(fullCode, {
        visited_at: els.formDate.value  || null,
        notes:      els.formNotes.value || null,
      });
      const v = findVisit(fullCode);
      if (v) { v.visited_at = els.formDate.value || null; v.notes = els.formNotes.value || null; }
      renderStatePanel();
      onRegionClick(state.activeRegion);
    } catch (e) {
      if (e.status !== 401) alert('Fehler: ' + e.message);   // 401 meldet onSessionLost()
    } finally {
      els.btnSave.disabled = false;
    }
  });

  // ── Login dialog ──────────────────────────────────────────────

  els.btnSendMagic.addEventListener('click', async () => {
    const email = els.loginEmail.value.trim();
    if (!email) return;
    els.btnSendMagic.disabled = true;
    els.loginMsg.classList.add('hidden');
    try {
      await Api.sendMagicLink(email);
      els.loginMsg.className   = 'login-msg';
      els.loginMsg.textContent = '✓ Login-Link wurde gesendet. Bitte prüfe dein Postfach.';
      els.loginMsg.classList.remove('hidden');
      els.loginEmail.value = '';
    } catch (e) {
      els.loginMsg.className   = 'login-msg error';
      els.loginMsg.textContent = '✕ ' + Api.magicLinkErrorText(e);
      els.loginMsg.classList.remove('hidden');
    } finally {
      els.btnSendMagic.disabled = false;
    }
  });

  els.loginEmail.addEventListener('keydown', e => { if (e.key === 'Enter') els.btnSendMagic.click(); });

  // ── Dialog management ─────────────────────────────────────────

  function openDialog(which) {
    els.backdrop.classList.remove('hidden');
    els.regionDialog.classList.toggle('hidden', which !== 'region');
    els.loginDialog.classList.toggle('hidden',  which !== 'login');
  }

  function closeDialog() {
    els.backdrop.classList.add('hidden');
    els.regionDialog.classList.add('hidden');
    els.loginDialog.classList.add('hidden');
    state.activeRegion = null;
  }

  els.dialogClose.addEventListener('click', closeDialog);
  els.loginClose.addEventListener('click',  closeDialog);
  els.backdrop.addEventListener('click',    closeDialog);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDialog(); });

  // ── Boot ──────────────────────────────────────────────────────

  async function boot() {
    Api.setUnauthorizedHandler(onSessionLost);
    await checkMagicLinkToken();

    // Anmeldestatus und Rolle kommen immer vom Server – nie aus dem Browser-Speicher
    if (!state.session) {
      try {
        state.session = await Api.me();
      } catch (_) { /* nicht eingeloggt */ }
    }

    state.pageUser = getPageUsername();
    updatePageMeta();
    renderAuthArea();
    renderOwnerBadge();
    CacheMap.init('map');
    fetch('version.json').then(r => r.ok ? r.json() : null).then(v => {
      if (!v) return;
      const el = document.querySelector('.leaflet-control-attribution');
      if (el) el.insertAdjacentHTML('beforeend', ` · Build&nbsp;<span title="${v.built}">${v.commit}</span>`);
    }).catch(() => {});
    await loadCountries();
  }

  boot().catch(console.error);

});
