/**
 * CacheCounty – Landesumriss aus Regionsflächen
 *
 * Grenzen zwischen zwei Regionen kommen in den Daten genau zweimal vor (einmal je
 * Nachbar), die Außengrenze des Landes nur einmal. Behält man die einmaligen Kanten
 * und verkettet sie zu Linien, erhält man den Umriss – in einem Durchlauf, ohne
 * Polygon-Verschmelzung (früher turf.dissolve, das per new Function gegen die CSP
 * verstieß bzw. in Turf 7 viermal langsamer ist).
 *
 * Voraussetzung: Benachbarte Flächen teilen exakt dieselben Eckpunkte (topologisch
 * saubere Daten, wie bei den amtlichen Grenzdatensätzen). Sonst erscheinen auch
 * Binnengrenzen im Umriss. Neue GeoJSON-Dateien deshalb vorher prüfen (CLAUDE.md).
 *
 * Leaflet-frei und DOM-frei → getestet in tests/outline.test.js.
 */

const CountryOutline = (() => {

  const pointKey = p => p[0] + ',' + p[1];
  const edgeKey  = (ka, kb) => (ka < kb ? ka + '|' + kb : kb + '|' + ka);

  function ringsOf(geometry) {
    if (!geometry) return [];
    if (geometry.type === 'Polygon')      return geometry.coordinates;
    if (geometry.type === 'MultiPolygon') return geometry.coordinates.flat();
    return [];
  }

  /**
   * Liefert den Umriss aller Flächen als GeoJSON-Feature (MultiLineString).
   */
  function fromFeatures(features) {
    // 1) Kanten zählen (Richtung egal)
    const edges = new Map();
    for (const f of features) {
      for (const ring of ringsOf(f.geometry)) {
        for (let i = 0; i < ring.length - 1; i++) {
          const ka = pointKey(ring[i]), kb = pointKey(ring[i + 1]);
          if (ka === kb) continue; // doppelte Punkte ignorieren
          const k = edgeKey(ka, kb);
          const e = edges.get(k);
          if (e) e.count++;
          else edges.set(k, { a: ring[i], b: ring[i + 1], ka, kb, count: 1 });
        }
      }
    }

    // 2) Nur einmal vorkommende Kanten gehören zum Umriss → Nachbarschaftsliste
    const neighbours = new Map();
    const points     = new Map();
    const link = (from, to) => {
      if (!neighbours.has(from)) neighbours.set(from, []);
      neighbours.get(from).push(to);
    };
    for (const e of edges.values()) {
      if (e.count !== 1) continue;
      link(e.ka, e.kb);
      link(e.kb, e.ka);
      points.set(e.ka, e.a);
      points.set(e.kb, e.b);
    }

    // 3) Kanten zu möglichst langen Linien verketten
    const used  = new Set();
    const lines = [];
    for (const [start, next] of neighbours) {
      for (const first of next) {
        if (used.has(edgeKey(start, first))) continue;

        const line = [points.get(start)];
        let current = first;
        used.add(edgeKey(start, current));

        for (;;) {
          line.push(points.get(current));
          const cont = neighbours.get(current).find(n => !used.has(edgeKey(current, n)));
          if (cont === undefined) break;
          used.add(edgeKey(current, cont));
          current = cont;
        }
        lines.push(line);
      }
    }

    return {
      type: 'Feature',
      properties: {},
      geometry: { type: 'MultiLineString', coordinates: lines },
    };
  }

  return { fromFeatures };
})();
