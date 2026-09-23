/**
 * CacheCounty – GeoJSON-Export
 * Baut aus den geladenen GeoJSON-Features Download-Dateien für c:geo.
 * Bewusst frei von Leaflet-Abhängigkeiten, damit die Logik testbar bleibt.
 */

const CacheExport = (() => {

  // Farben aus der App-Palette (--moss / --rust in app.css),
  // als simplestyle-Properties, die c:geo beim Track-Import auswertet.
  const STYLE = {
    visited: {
      'fill':           '#4a6741',
      'fill-opacity':   0.4,
      'stroke':         '#2e4f28',
      'stroke-opacity': 0.9,
      'stroke-width':   2,
    },
    missing: {
      'fill':           '#c45c2a',
      'fill-opacity':   0.4,
      'stroke':         '#c45c2a',
      'stroke-opacity': 0.9,
      'stroke-width':   2,
    },
  };

  // ── Dateiname ─────────────────────────────────────────────────

  /**
   * Macht aus "Baden-Württemberg" ein dateinamentaugliches "baden-wuerttemberg".
   */
  function slugify(name) {
    return String(name ?? '')
      .toLowerCase()
      .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'region';
  }

  /**
   * mode: 'all' | 'missing'
   */
  function buildFilename(countryCode, stateName, mode) {
    const suffix = mode === 'missing' ? 'fehlend' : 'alle';
    return `cachecounty_${String(countryCode || 'XX').toUpperCase()}_${slugify(stateName)}_${suffix}.geojson`;
  }

  // ── FeatureCollection ─────────────────────────────────────────

  /**
   * Filtert die Features auf ein Bundesland und baut eine FeatureCollection.
   * Die Original-Geometrie wird per Referenz übernommen, nie verändert.
   *
   * @param {object}  opts
   * @param {Array}   opts.features            alle Features des aktuellen Landes
   * @param {object}  opts.countryConfig       Eintrag aus countries.json
   * @param {string}  opts.stateCode           state_code_property-Wert des Bundeslandes
   * @param {string} [opts.stateName]          Anzeigename (Fallback: aus dem ersten Feature)
   * @param {Map}     opts.visitsByRegionCode  region_code → visit-Objekt
   * @param {string}  opts.mode                'all' | 'missing'
   */
  function buildFeatureCollection({ features, countryConfig, stateCode, stateName, visitsByRegionCode, mode }) {
    const cfg      = countryConfig || {};
    const visits   = visitsByRegionCode || new Map();
    const onlyMiss = mode === 'missing';
    const sc       = String(stateCode);

    const out      = [];
    let   visited  = 0;
    let   total    = 0;
    let   label    = stateName;

    for (const feature of features || []) {
      const props = feature?.properties;
      if (!props) continue;
      if (String(props[cfg.state_code_property] ?? '') !== sc) continue;

      total++;
      if (!label) label = props[cfg.state_name_property] || '';

      const regionCode = String(props[cfg.region_code_property] ?? '');
      const visit      = visits.get(regionCode) || null;
      if (visit) visited++;

      if (onlyMiss && visit) continue;

      const style = visit ? STYLE.visited : STYLE.missing;

      const properties = {
        name:        props[cfg.region_name_property] || 'Unbekannt',
        region_code: regionCode,
        state:       props[cfg.state_name_property] || '',
        country:     cfg.code || '',
        visited:     !!visit,
      };
      if (visit?.visited_at) properties.visited_at = visit.visited_at;
      Object.assign(properties, style);

      out.push({
        type: 'Feature',
        properties,
        geometry: feature.geometry,
      });
    }

    const name = onlyMiss
      ? `CacheCounty – ${label || sc}: ${out.length} fehlende Landkreise`
      : `CacheCounty – ${label || sc} (${visited}/${total} gefunden)`;

    return { type: 'FeatureCollection', name, features: out };
  }

  // ── Download ──────────────────────────────────────────────────

  /**
   * Stößt den Browser-Download einer Textdatei an.
   */
  function triggerDownload(filename, text) {
    const blob = new Blob([text], { type: 'application/geo+json' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');

    a.href     = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();

    // Revoke erst nach dem Klick – Firefox bricht sonst ab.
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  // ── Public ────────────────────────────────────────────────────

  return { buildFeatureCollection, buildFilename, triggerDownload, slugify, STYLE };

})();
