/**
 * CacheCounty – Map Module
 * Manages the Leaflet map, GeoJSON layer and region styling.
 * Supports state (Bundesland) grouping and visibility toggling.
 */

const CacheMap = (() => {

  let map                = null;
  let geoLayer           = null;
  let countryOutlineLayer = null;      // always-visible country border
  let currentConfig      = null;       // active countryConfig
  let visitedCodes       = new Set();  // "CC-regioncode" strings
  let hiddenStates       = new Set();  // state_code values currently hidden
  let onRegionClick      = null;       // callback(regionData)

  // ── Style helpers ─────────────────────────────────────────────

  function getStyle(_feature, isVisited) {
    return {
      fillColor:   isVisited ? '#4a6741' : '#c8beaa',
      fillOpacity: isVisited ? 0.60 : 0.22,
      color:       isVisited ? '#2e4f28' : '#8a7055',
      weight:      1.2,
      opacity:     0.8,
    };
  }

  function hoverStyle(_feature, isVisited) {
    return {
      fillColor:   isVisited ? '#6a9463' : '#c45c2a',
      fillOpacity: isVisited ? 0.75 : 0.40,
      weight:      2,
      opacity:     1,
    };
  }

  function hiddenStyle() {
    return {
      fillOpacity: 0,
      opacity:     0,
    };
  }

  function isLayerHidden(feature) {
    if (!currentConfig?.state_code_property) return false;
    const stateCode = String(feature.properties[currentConfig.state_code_property] ?? '');
    return hiddenStates.has(stateCode);
  }

  // ── Init ──────────────────────────────────────────────────────

  function init(containerId) {
    map = L.map(containerId, {
      zoomControl:        true,
      attributionControl: true,
      minZoom: 5,
      maxZoom: 19,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      maxZoom: 19,
    }).addTo(map);

    map.setView([51.3, 10.4], 6);

    // Geocaching.com button below zoom controls
    const GcControl = L.Control.extend({
      options: { position: 'topleft' },
      onAdd() {
        const btn = L.DomUtil.create('a', 'leaflet-bar leaflet-control geocaching-btn');
        btn.href        = '#';
        btn.title       = 'Auf Geocaching.com ansehen';
        btn.target      = '_blank';
        btn.rel         = 'noopener';
        btn.innerHTML   = '<img src="https://www.geocaching.com/favicon.ico" width="19" height="19" alt="GC" style="display:block;margin:auto;">';
        btn.style.cssText = 'display:flex;align-items:center;justify-content:center;width:32px;height:32px;transition:background 0.15s,transform 0.1s;';
        L.DomEvent.on(btn, 'mouseover', () => { btn.style.background = '#f4f4f4'; btn.style.transform = 'scale(1.12)'; });
        L.DomEvent.on(btn, 'mouseout',  () => { btn.style.background = '';        btn.style.transform = ''; });
        L.DomEvent.on(btn, 'click', function (e) {
          L.DomEvent.preventDefault(e);
          const c   = map.getCenter();
          const lat = c.lat.toFixed(5);
          const lng = c.lng.toFixed(5);
          const z   = map.getZoom();
          window.open(`https://www.geocaching.com/map/#?ll=${lat},${lng}&z=${z}`, '_blank', 'noopener');
        });
        return btn;
      },
    });
    new GcControl().addTo(map);
  }

  // ── Load GeoJSON ──────────────────────────────────────────────

  /**
   * Loads and renders the GeoJSON for a country.
   * Returns a stateMap: { stateCode: { name, total, regions: [fullCode, …] } }
   */
  async function loadGeoJSON(url, countryConfig, visits, hiddenStateCodes, clickCallback) {
    onRegionClick = clickCallback;
    currentConfig = countryConfig;

    visitedCodes = new Set(visits.map(v => v.country_code + '-' + v.region_code));
    hiddenStates = new Set(hiddenStateCodes.map(String));

    if (geoLayer) {
      map.removeLayer(geoLayer);
      geoLayer = null;
    }

    if (countryOutlineLayer) {
      map.removeLayer(countryOutlineLayer);
      countryOutlineLayer = null;
    }

    let geojson;
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error('GeoJSON nicht erreichbar');
      geojson = await res.json();
    } catch (e) {
      console.error('GeoJSON Ladefehler:', e);
      return null;
    }

    // Build stateMap while iterating features
    const stateMap = {};

    geoLayer = L.geoJSON(geojson, {
      style: feature => {
        if (isLayerHidden(feature)) return hiddenStyle();
        const code    = feature.properties[countryConfig.region_code_property];
        const visited = visitedCodes.has(countryConfig.code + '-' + code);
        return getStyle(feature, visited);
      },

      onEachFeature: (feature, layer) => {
        const props      = feature.properties;
        const name       = props[countryConfig.region_name_property] || 'Unbekannt';
        const regionCode = props[countryConfig.region_code_property] || '';
        const fullCode   = countryConfig.code + '-' + regionCode;
        const stateName  = props[countryConfig.state_name_property]  || '';
        const stateCode  = String(props[countryConfig.state_code_property] ?? '');

        // Accumulate stateMap
        if (stateCode) {
          if (!stateMap[stateCode]) {
            stateMap[stateCode] = { name: stateName, code: stateCode, total: 0, regions: [] };
          }
          stateMap[stateCode].total++;
          stateMap[stateCode].regions.push(fullCode);
        }

        // Tooltip
        layer.bindTooltip(name, { sticky: true, direction: 'top', offset: [0, -4] });

        layer.on('mouseover', function () {
          if (isLayerHidden(feature)) return;
          this.setStyle(hoverStyle(feature, visitedCodes.has(fullCode)));
          this.bringToFront();
        });

        layer.on('mouseout', function () {
          if (isLayerHidden(feature)) return;
          this.setStyle(getStyle(feature, visitedCodes.has(fullCode)));
        });

        layer.on('click', function () {
          if (isLayerHidden(feature)) return;
          if (onRegionClick) {
            onRegionClick({
              countryCode: countryConfig.code,
              regionCode,
              fullCode,
              name,
              stateName,
              stateCode,
              properties: props,
            });
          }
        });
      },
    }).addTo(map);

    // Apply pointer-events:none for layers that are hidden at startup
    geoLayer.eachLayer(layer => {
      if (!layer.feature) return;
      if (isLayerHidden(layer.feature)) {
        const el = layer.getElement?.();
        if (el) el.style.pointerEvents = 'none';
      }
    });

    // Draw always-visible country outline (dissolve all districts into one shape)
    if (typeof turf !== 'undefined') {
      try {
        // Flatten MultiPolygon → individual Polygons (turf.dissolve only handles Polygon)
        const polygons = [];
        geojson.features.forEach(f => {
          const g = f.geometry;
          if (g.type === 'Polygon') {
            polygons.push(turf.polygon(g.coordinates, { _c: '1' }));
          } else if (g.type === 'MultiPolygon') {
            g.coordinates.forEach(coords => polygons.push(turf.polygon(coords, { _c: '1' })));
          }
        });
        const dissolved = turf.dissolve(turf.featureCollection(polygons), { propertyName: '_c' });
        countryOutlineLayer = L.geoJSON(dissolved, {
          style: { color: '#1a1a2e', weight: 2.5, opacity: 0.9, fillOpacity: 0 },
          interactive: false,
        }).addTo(map);
      } catch (e) {
        console.warn('Country outline konnte nicht berechnet werden:', e);
      }
    } else {
      console.warn('turf.js nicht geladen – country outline nicht verfügbar');
    }

    if (geoLayer.getLayers().length) {
      map.fitBounds(geoLayer.getBounds(), { padding: [24, 24] });
    }

    return stateMap;
  }

  // ── Toggle state visibility ───────────────────────────────────

  /**
   * Show or hide all regions belonging to a given stateCode.
   */
  function setStateVisibility(stateCode, visible) {
    if (!currentConfig || !geoLayer) return;

    const sc = String(stateCode);
    if (visible) hiddenStates.delete(sc);
    else         hiddenStates.add(sc);

    geoLayer.eachLayer(layer => {
      const feature = layer.feature;
      if (!feature) return;
      const layerStateCode = String(feature.properties[currentConfig.state_code_property] ?? '');
      if (layerStateCode !== sc) return;

      if (visible) {
        const code     = feature.properties[currentConfig.region_code_property];
        const fullCode = currentConfig.code + '-' + code;
        layer.setStyle(getStyle(feature, visitedCodes.has(fullCode)));
      } else {
        layer.setStyle(hiddenStyle());
      }

      const el = layer.getElement?.();
      if (el) el.style.pointerEvents = visible ? '' : 'none';
    });
  }

  // ── Mark single region visited/unvisited ──────────────────────

  function markVisited(fullCode, visited) {
    if (!geoLayer || !currentConfig) return;

    if (visited) visitedCodes.add(fullCode);
    else         visitedCodes.delete(fullCode);

    const [, rc] = fullCode.split('-');

    geoLayer.eachLayer(layer => {
      const props = layer.feature?.properties;
      if (!props) return;
      if (String(props[currentConfig.region_code_property]) !== rc) return;
      if (isLayerHidden(layer.feature)) return;
      layer.setStyle(getStyle(layer.feature, visited));
    });
  }

  // ── Public ────────────────────────────────────────────────────

  return { init, loadGeoJSON, setStateVisibility, markVisited };

})();
