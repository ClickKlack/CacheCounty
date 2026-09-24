/**
 * CacheCounty – Statistikseite
 * Lädt und rendert Benutzerstatistiken und die globale Rangliste.
 */

(() => {
  'use strict';

  // ── State ─────────────────────────────────────────────────────────────────

  const state = {
    username:       null,   // aus URL-Pfad
    session:        null,   // { username, is_admin } | null
    countries:      [],     // aus /api/countries
    currentCountry: null,   // aktuell gewähltes Land-Objekt
    statsData:      null,   // von /api/stats/{username}
    stateMap:       {},     // { stateCode: { name, total } } aus GeoJSON
    visits:         {},     // { countryCode: Set<regionCode> } lokal aufgebaut
    lbCountry:      null,   // null = Gesamt, string = country code
    timelineChart:  null,   // Chart.js-Instanz
  };

  // ── DOM-Elemente ───────────────────────────────────────────────────────────

  const els = {
    countrySelect:    document.getElementById('country-select'),
    authArea:         document.getElementById('auth-area'),
    heroAvatar:       document.getElementById('hero-avatar'),
    heroTitle:        document.getElementById('hero-title'),
    heroSub:          document.getElementById('hero-sub'),
    heroMapLink:      document.getElementById('hero-map-link'),
    countriesList:    document.getElementById('countries-list'),
    timelineChart:    document.getElementById('timeline-chart'),
    timelineHint:     document.getElementById('timeline-hint'),
    stateLabelHeading:      document.getElementById('state-label-heading'),
    statesCountryLabel:     document.getElementById('states-country-label'),
    milestonesCountryLabel: document.getElementById('milestones-country-label'),
    statesList:             document.getElementById('states-list'),
    milestonesGrid:         document.getElementById('milestones-grid'),
    lbTabsContainer:  document.querySelector('.leaderboard-tabs'),
    lbBody:           document.getElementById('leaderboard-body'),
    backdrop:         document.getElementById('backdrop'),
    loginDialog:      document.getElementById('login-dialog'),
    btnLogin:         document.getElementById('btn-login'),
    loginClose:       document.getElementById('login-close'),
    loginEmail:       document.getElementById('login-email'),
    btnSendLink:      document.getElementById('btn-send-link'),
    loginMsg:         document.getElementById('login-msg'),
  };

  // ── Init ──────────────────────────────────────────────────────────────────

  async function init() {
    state.username = parseUsername();
    if (!state.username) {
      showError('Kein Nutzer angegeben.');
      return;
    }

    setupAuthListeners();
    restoreSession();
    renderAuthArea();

    try {
      const [countries, statsData] = await Promise.all([
        Api.getCountries(),
        Api.getStats(state.username),
      ]);

      state.countries  = countries;
      state.statsData  = statsData;

      // Besuche nach Land strukturieren
      buildVisitsIndex(statsData);

      populateCountrySelect(countries);
      renderLeaderboardTabs(countries);

      renderHero(statsData.username);
      renderCountryComparison(countries, statsData.total_by_country);
      renderTimeline(statsData.timeline, countries);

      // Erstes Land laden (rendert auch Meilensteine)
      await switchCountry(countries[0]);

    } catch (err) {
      showError('Statistiken konnten nicht geladen werden: ' + err.message);
    }
  }

  // ── Hilfsfunktionen ────────────────────────────────────────────────────────

  function parseUsername() {
    const parts = location.pathname.split('/').filter(Boolean);
    // Erwartet: /stats/{username}
    if (parts[0] === 'stats' && parts[1]) return parts[1];
    return null;
  }

  function buildVisitsIndex(_statsData) {
    // Wir haben keine region_code-Liste in statsData – nur Aggregat.
    // Für die Bundesland-Sektion brauchen wir /api/map, ODER wir nutzen
    // den vorhandenen /api/map-Endpunkt für das aktuell gewählte Land.
    // Besuche werden lazy pro Land geladen (loadVisitsForCountry).
  }

  // ── Länder-Select ──────────────────────────────────────────────────────────

  function populateCountrySelect(countries) {
    els.countrySelect.innerHTML = countries
      .map(c => `<option value="${c.code}">${c.label}</option>`)
      .join('');
    els.countrySelect.addEventListener('change', () => {
      const c = state.countries.find(x => x.code === els.countrySelect.value);
      if (c) switchCountry(c);
    });
  }

  async function switchCountry(country) {
    state.currentCountry = country;
    els.countrySelect.value = country.code;

    // Bundesland-Label im Heading anpassen
    els.stateLabelHeading.textContent  = country.state_label || 'Region';
    els.statesCountryLabel.textContent = country.label;

    try {
      // GeoJSON laden für Regionenzählung
      const geojson = await fetchGeoJson(country.geojson);
      state.stateMap = buildStateMap(geojson, country);

      // Besuche für dieses Land laden (region_code-Level)
      const mapData = await Api.getMap(state.username, country.code);
      const visitedSet = new Set(mapData.visits.map(v => v.region_code));

      renderStateProgress(state.stateMap, visitedSet, country);

      // Meilensteine berechnen: Besuche nach effektivem Datum sortieren
      const total = Object.values(state.stateMap).reduce((s, st) => s + st.total, 0);
      const sortedVisits = [...mapData.visits]
        .map(v => ({ ...v, effectiveDate: v.visited_at || v.created_date }))
        .filter(v => v.effectiveDate)
        .sort((a, b) => a.effectiveDate.localeCompare(b.effectiveDate));

      renderMilestones(country, total, sortedVisits, state.statsData.first_visits, state.stateMap);
    } catch (err) {
      els.statesList.innerHTML = `<p class="stats-empty">Daten konnten nicht geladen werden.</p>`;
    }

    // Rangliste neu laden
    loadLeaderboard();
  }

  async function fetchGeoJson(path) {
    const res = await fetch('/' + path);
    if (!res.ok) throw new Error('GeoJSON nicht gefunden: ' + path);
    return res.json();
  }

  function buildStateMap(geojson, country) {
    const map = {};
    for (const f of geojson.features) {
      const sc = String(f.properties[country.state_code_property]);
      const sn = f.properties[country.state_name_property];
      if (!map[sc]) map[sc] = { name: sn, total: 0, regions: [] };
      map[sc].total++;
      map[sc].regions.push(f.properties[country.region_code_property]);
    }
    return map;
  }

  // ── Hero ───────────────────────────────────────────────────────────────────

  function renderHero(username) {
    const initials = username.slice(0, 2).toUpperCase();
    els.heroAvatar.textContent = initials;
    els.heroTitle.textContent  = username;
    els.heroSub.textContent    = 'Statistiken & Fortschritt';
    els.heroMapLink.href       = '/map/' + encodeURIComponent(username);
  }

  // ── Ländervergleich ────────────────────────────────────────────────────────

  function renderCountryComparison(countries, totalByCountry) {
    const byCode = Object.fromEntries(totalByCountry.map(t => [t.country_code, +t.visited]));

    if (countries.length === 0) {
      els.countriesList.innerHTML = '<p class="stats-empty">Keine Länder konfiguriert.</p>';
      return;
    }

    els.countriesList.innerHTML = countries.map(c => {
      const visited = byCode[c.code] || 0;
      return `
        <div class="country-progress-item" data-country="${c.code}">
          <div class="country-progress-header">
            <span class="country-progress-name">${c.label}</span>
            <span class="country-progress-count">${visited} besucht</span>
          </div>
          <div class="country-progress-bar-wrap">
            <div class="country-progress-bar">
              <div class="country-progress-fill" style="width:0%" data-visited="${visited}"></div>
            </div>
          </div>
        </div>`;
    }).join('');

    // Totals werden erst nach GeoJSON-Laden bekannt – Platzhalter bleiben bis dahin.
    // Wir laden die Totals für alle Länder parallel im Hintergrund.
    loadCountryTotals(countries, byCode);
  }

  async function loadCountryTotals(countries, byCode) {
    await Promise.allSettled(countries.map(async c => {
      try {
        const geojson = await fetchGeoJson(c.geojson);
        const total   = geojson.features.length;
        const visited = byCode[c.code] || 0;
        const pct     = total > 0 ? Math.round((visited / total) * 100) : 0;

        const item = els.countriesList.querySelector(`[data-country="${c.code}"]`);
        if (!item) return;
        item.querySelector('.country-progress-count').textContent =
          `${visited} / ${total} · ${pct} %`;
        item.querySelector('.country-progress-fill').style.width = pct + '%';
      } catch { /* ignorieren */ }
    }));
  }

  // ── Zeitverlauf ────────────────────────────────────────────────────────────

  function renderTimeline(timeline, countries) {
    if (!timeline || timeline.length === 0) {
      els.timelineHint.textContent = 'Noch keine Besuche eingetragen.';
      return;
    }

    // Alle Monate zwischen erstem und letztem Eintrag auffüllen
    const allMonths = buildMonthRange(timeline);

    // Pro Land kumulierte Summe berechnen
    const countryColors = {
      DE: { border: '#4a6741', bg: 'rgba(74,103,65,0.15)' },
      AT: { border: '#c45c2a', bg: 'rgba(196,92,42,0.12)' },
    };
    const defaultColors = [
      { border: '#5c4a30', bg: 'rgba(92,74,48,0.12)' },
      { border: '#8a7055', bg: 'rgba(138,112,85,0.10)' },
    ];

    const usedCountries = [...new Set(timeline.map(t => t.country_code))];

    const datasets = usedCountries.map((code, idx) => {
      const col = countryColors[code] || defaultColors[idx % defaultColors.length];
      const countryLabel = countries.find(c => c.code === code)?.label || code;

      // Monatliche Counts für dieses Land
      const monthCounts = {};
      timeline.filter(t => t.country_code === code).forEach(t => {
        monthCounts[t.month_key] = +t.count;
      });

      // Kumulierte Werte
      let running = 0;
      const data = allMonths.map(m => {
        running += monthCounts[m] || 0;
        return running;
      });

      return {
        label:           countryLabel,
        data,
        borderColor:     col.border,
        backgroundColor: col.bg,
        fill:            true,
        tension:         0.35,
        pointRadius:     2,
        pointHoverRadius:5,
      };
    });

    // Beschriftung: nur jeden 3. Monat anzeigen um Überlappung zu vermeiden
    const labels = allMonths.map((m, i) => {
      if (allMonths.length <= 12 || i % 3 === 0) return formatYearMonth(m);
      return '';
    });

    if (state.timelineChart) state.timelineChart.destroy();

    state.timelineChart = new Chart(els.timelineChart, {
      type: 'line',
      data: { labels, datasets },
      options: {
        responsive:          true,
        maintainAspectRatio: true,
        interaction:         { mode: 'index', intersect: false },
        plugins: {
          legend: {
            display: usedCountries.length > 1,
            labels:  { font: { family: 'DM Sans' }, color: '#3a2e1f' },
          },
          tooltip: {
            callbacks: {
              title: (items) => {
                const idx = items[0].dataIndex;
                return formatYearMonth(allMonths[idx]);
              },
              label: (item) => ` ${item.dataset.label}: ${item.raw} Regionen`,
            },
          },
        },
        scales: {
          x: {
            ticks: { font: { family: 'DM Sans', size: 11 }, color: '#8a7055' },
            grid:  { color: 'rgba(58,46,31,0.06)' },
          },
          y: {
            beginAtZero: true,
            ticks: { font: { family: 'DM Sans', size: 11 }, color: '#8a7055', precision: 0 },
            grid:  { color: 'rgba(58,46,31,0.06)' },
          },
        },
      },
    });

    const hasFallback = /* Hinweis wenn visited_at fehlt */ true;
    if (hasFallback) {
      els.timelineHint.textContent =
        'Besuche ohne eingetragenes Datum werden anhand des Eintrags-Zeitpunkts angezeigt.';
    }
  }

  function buildMonthRange(timeline) {
    const months = [...new Set(timeline.map(t => t.month_key))].sort();
    if (months.length === 0) return [];

    const result = [];
    let [y, m] = months[0].split('-').map(Number);
    const [ey, em] = months[months.length - 1].split('-').map(Number);

    while (y < ey || (y === ey && m <= em)) {
      result.push(`${y}-${String(m).padStart(2, '0')}`);
      m++;
      if (m > 12) { m = 1; y++; }
    }
    return result;
  }

  function formatYearMonth(ym) {
    const [y, m] = ym.split('-');
    return new Date(+y, +m - 1).toLocaleDateString('de-DE', { month: 'short', year: '2-digit' });
  }

  // ── Bundesland-Fortschritt ─────────────────────────────────────────────────

  function renderStateProgress(stateMap, visitedSet) {
    const entries = Object.entries(stateMap)
      .map(([sc, st]) => {
        const visited = st.regions.filter(rc => visitedSet.has(String(rc))).length;
        const rate    = st.total > 0 ? visited / st.total : 0;
        return { sc, st, visited, rate };
      })
      .sort((a, b) => b.rate - a.rate);

    if (entries.length === 0) {
      els.statesList.innerHTML = '<p class="stats-empty">Keine Regionen gefunden.</p>';
      return;
    }

    els.statesList.innerHTML = entries.map(({ st, visited }) => {
      const pct = st.total > 0 ? Math.round((visited / st.total) * 100) : 0;
      return `
        <div class="state-progress-item">
          <div class="state-progress-meta">
            <span class="state-progress-name">${st.name}</span>
            <span class="state-progress-count">${visited} / ${st.total}</span>
          </div>
          <div class="state-progress-bar-wrap">
            <div class="state-progress-bar">
              <div class="state-progress-fill" style="width:${pct}%"></div>
            </div>
            <span class="state-progress-pct">${pct} %</span>
          </div>
        </div>`;
    }).join('');
  }

  // ── Meilensteine ──────────────────────────────────────────────────────────

  function renderMilestones(country, total, sortedVisits, firstVisits, stateMap) {
    els.milestonesCountryLabel.textContent = country.label;

    const thresholds = [10, 25, 50, 75, 100];
    const firstVisit = firstVisits.find(f => f.country_code === country.code);
    const cards      = [];

    // Erster Besuch
    cards.push(renderMilestoneCard(
      !!firstVisit,
      'Erster Besuch',
      firstVisit ? 'erreicht am' : 'noch nicht erreicht',
      firstVisit ? formatDate(firstVisit.first_date) : '–',
    ));

    // Prozent-Meilensteine mit Datum
    for (const pct of thresholds) {
      const needed  = Math.ceil(total * pct / 100);
      const reached = sortedVisits.length >= needed;
      const label   = reached ? 'erreicht am' : 'noch nicht erreicht';
      const value   = reached
        ? formatDate(sortedVisits[needed - 1].effectiveDate)
        : `${sortedVisits.length}\u202f/\u202f${needed} nötig`;

      cards.push(renderMilestoneCard(reached, `${pct}\u202f%`, label, value));
    }

    // Alle Bundesländer mind. einmal besucht
    const stateLabel = country.state_label_plural || country.state_label || 'Regionen';
    const regionToState = {};
    for (const [sc, st] of Object.entries(stateMap)) {
      for (const rc of st.regions) regionToState[String(rc)] = sc;
    }
    const firstPerState = {};
    for (const v of sortedVisits) {
      const sc = regionToState[String(v.region_code)];
      if (sc && !firstPerState[sc]) firstPerState[sc] = v.effectiveDate;
    }
    const stateCount    = Object.keys(stateMap).length;
    const statesVisited = Object.keys(firstPerState).length;
    const allReached    = statesVisited >= stateCount && stateCount > 0;
    const allDate       = allReached
      ? Object.values(firstPerState).sort().at(-1)
      : null;
    const allValue      = allReached
      ? formatDate(allDate)
      : `${statesVisited}\u202f/\u202f${stateCount} ${stateLabel}`;

    cards.push(renderMilestoneCard(
      allReached,
      `Alle ${stateLabel}`,
      allReached ? 'erreicht am' : 'noch nicht erreicht',
      allValue,
    ));

    els.milestonesGrid.innerHTML = cards.join('');
  }

  function renderMilestoneCard(reached, title, label, value) {
    const star = reached ? '★' : '☆';
    return `
      <div class="milestone-card ${reached ? 'milestone-reached' : ''}">
        <div class="milestone-icon">${star}</div>
        <div class="milestone-title">${title}</div>
        <div class="milestone-label">${label}</div>
        <div class="milestone-value">${value || '–'}</div>
      </div>`;
  }

  function formatDate(dateStr) {
    if (!dateStr) return '–';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: 'long', year: 'numeric' });
  }

  // ── Rangliste ─────────────────────────────────────────────────────────────

  async function loadLeaderboard() {
    try {
      const data = await Api.getLeaderboard(state.lbCountry);
      renderLeaderboard(data.rankings);
    } catch {
      els.lbBody.innerHTML = '<tr><td colspan="3" class="stats-empty">Rangliste nicht verfügbar.</td></tr>';
    }
  }

  function renderLeaderboard(rankings) {
    if (!rankings || rankings.length === 0) {
      els.lbBody.innerHTML = '<tr><td colspan="3" class="stats-empty">Noch keine Einträge.</td></tr>';
      return;
    }

    els.lbBody.innerHTML = rankings.map(r => {
      const isSelf = r.username === state.username;
      const rankIcon = r.rank === 1 ? '🥇' : r.rank === 2 ? '🥈' : r.rank === 3 ? '🥉' : r.rank;
      return `
        <tr class="${isSelf ? 'lb-self' : ''}">
          <td class="lb-rank">${rankIcon}</td>
          <td class="lb-user">
            <a href="/map/${encodeURIComponent(r.username)}">${r.username}</a>
          </td>
          <td class="lb-count">${r.visited}</td>
        </tr>`;
    }).join('');
  }

  // ── Auth ──────────────────────────────────────────────────────────────────

  function restoreSession() {
    const token = sessionStorage.getItem('cc_token');
    const uname = sessionStorage.getItem('cc_username');
    const admin = sessionStorage.getItem('cc_is_admin');
    if (token && uname) {
      state.session = { username: uname, is_admin: admin === '1', token };
    }
  }

  function renderAuthArea() {
    if (state.session) {
      els.authArea.innerHTML = `
        <div class="auth-user">
          <span class="auth-hint">Angemeldet als</span>
          <span class="auth-name">${state.session.username}</span>
        </div>
        <button id="btn-logout" class="btn btn-ghost">Abmelden</button>`;
      document.getElementById('btn-logout').addEventListener('click', logout);
    } else {
      els.authArea.innerHTML = `<button id="btn-login" class="btn btn-ghost">Anmelden</button>`;
      document.getElementById('btn-login').addEventListener('click', openLoginDialog);
    }
  }

  async function logout() {
    try { await Api.logout(); } catch { /* ignorieren */ }
    sessionStorage.removeItem('cc_token');
    sessionStorage.removeItem('cc_username');
    sessionStorage.removeItem('cc_is_admin');
    state.session = null;
    renderAuthArea();
  }

  function setupAuthListeners() {
    els.backdrop.addEventListener('click', closeLoginDialog);
    els.loginClose.addEventListener('click', closeLoginDialog);
    els.btnSendLink.addEventListener('click', sendMagicLink);
  }

  function openLoginDialog() {
    els.backdrop.classList.remove('hidden');
    els.loginDialog.classList.remove('hidden');
    els.loginEmail.focus();
  }

  function closeLoginDialog() {
    els.backdrop.classList.add('hidden');
    els.loginDialog.classList.add('hidden');
    els.loginMsg.style.display = 'none';
    els.loginEmail.value = '';
  }

  async function sendMagicLink() {
    const email = els.loginEmail.value.trim();
    if (!email) return;
    els.btnSendLink.disabled = true;
    try {
      await Api.sendMagicLink(email);
      els.loginMsg.style.display = '';
      els.loginMsg.textContent   = 'Link wurde gesendet – bitte E-Mail prüfen.';
    } catch (err) {
      els.loginMsg.style.display = '';
      els.loginMsg.textContent   = err.message;
      els.loginMsg.style.color   = 'var(--rust)';
    } finally {
      els.btnSendLink.disabled = false;
    }
  }

  // ── Tab-Umschalter für Rangliste ──────────────────────────────────────────

  function renderLeaderboardTabs(countries) {
    // Ländertabs dynamisch nach dem "Gesamt"-Tab einfügen
    countries.forEach(c => {
      const btn = document.createElement('button');
      btn.className    = 'lb-tab';
      btn.dataset.country = c.code;
      btn.textContent  = c.label;
      els.lbTabsContainer.appendChild(btn);
    });

    // Event-Delegation für alle Tabs (inkl. "Gesamt")
    els.lbTabsContainer.addEventListener('click', e => {
      const tab = e.target.closest('.lb-tab');
      if (!tab) return;
      els.lbTabsContainer.querySelectorAll('.lb-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      state.lbCountry = tab.dataset.country || null;
      loadLeaderboard();
    });
  }

  // ── Fehleranzeige ─────────────────────────────────────────────────────────

  function showError(msg) {
    document.getElementById('stats-main').innerHTML =
      `<div class="stats-error"><p>${msg}</p><a href="/" class="btn btn-ghost">Zur Startseite</a></div>`;
  }

  // ── Start ─────────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', init);

})();
