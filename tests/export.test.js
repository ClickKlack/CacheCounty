/**
 * Tests for public/app/js/export.js
 *
 * export.js exposes `CacheExport` as a global via an IIFE.
 * We load it by evaluating the source in a context that provides
 * mocked browser globals (Blob, URL, document).
 */

import { describe, it, expect, vi } from 'vitest'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const exportSource = readFileSync(resolve(__dirname, '../public/app/js/export.js'), 'utf-8')

function buildExport() {
  const anchor = { href: '', download: '', click: vi.fn(), remove: vi.fn() }

  const ctx = {
    Blob: vi.fn(function Blob(parts, opts) { this.parts = parts; this.opts = opts }),
    URL: { createObjectURL: vi.fn().mockReturnValue('blob:mock'), revokeObjectURL: vi.fn() },
    document: {
      createElement: vi.fn().mockReturnValue(anchor),
      body: { appendChild: vi.fn() },
    },
    setTimeout: vi.fn(),
  }

  const factory = new Function(...Object.keys(ctx), `${exportSource}\nreturn CacheExport;`)
  const CacheExport = factory(...Object.values(ctx))

  return { CacheExport, ctx, anchor }
}

// ── Fixtures ──────────────────────────────────────────────────────────────

const DE = {
  code: 'DE',
  region_name_property: 'GEN',
  region_code_property: 'AGS',
  state_name_property:  'BL',
  state_code_property:  'BL_ID',
}

const AT = {
  code: 'AT',
  region_name_property: 'PB',
  region_code_property: 'PB_ID',
  state_name_property:  'BL',
  state_code_property:  'BL_ID',
}

const MULTI_GEOM = { type: 'MultiPolygon', coordinates: [[[[9, 54], [9, 55], [10, 55], [9, 54]]]] }

function feature(ags, gen, blId, bl, geometry = { type: 'Polygon', coordinates: [[[9, 54]]] }) {
  return { type: 'Feature', properties: { AGS: ags, GEN: gen, BL_ID: blId, BL: bl }, geometry }
}

// Schleswig-Holstein (01): 3 Kreise · Hamburg (02): 1 Kreis
const FEATURES = [
  feature('01001', 'Flensburg',     '01', 'Schleswig-Holstein'),
  feature('01054', 'Nordfriesland', '01', 'Schleswig-Holstein', MULTI_GEOM),
  feature('01057', 'Plön',          '01', 'Schleswig-Holstein'),
  feature('02000', 'Hamburg',       '02', 'Hamburg'),
]

function visits(entries) {
  return new Map(entries.map(([code, visitedAt]) => [code, { region_code: code, visited_at: visitedAt }]))
}

function build(CacheExport, mode, visitsByRegionCode = new Map(), opts = {}) {
  return CacheExport.buildFeatureCollection({
    features:      FEATURES,
    countryConfig: DE,
    stateCode:     '01',
    stateName:     'Schleswig-Holstein',
    visitsByRegionCode,
    mode,
    ...opts,
  })
}

// ── Filterung nach Bundesland ─────────────────────────────────────────────

describe('buildFeatureCollection – Bundesland-Filter', () => {
  it('liefert nur Features des gewählten Bundeslandes', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'all')
    expect(fc.type).toBe('FeatureCollection')
    expect(fc.features).toHaveLength(3)
    expect(fc.features.map(f => f.properties.region_code)).toEqual(['01001', '01054', '01057'])
  })

  it('schließt Features anderer Bundesländer aus', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'all')
    expect(fc.features.some(f => f.properties.region_code === '02000')).toBe(false)
  })
})

// ── mode: 'all' ───────────────────────────────────────────────────────────

describe("buildFeatureCollection – mode 'all'", () => {
  it('färbt besuchte grün und unbesuchte rot', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'all', visits([['01001', '2024-06-01']]))

    const flensburg = fc.features.find(f => f.properties.region_code === '01001')
    const ploen     = fc.features.find(f => f.properties.region_code === '01057')

    expect(flensburg.properties.fill).toBe('#4a6741')
    expect(flensburg.properties.stroke).toBe('#2e4f28')
    expect(flensburg.properties.visited).toBe(true)

    expect(ploen.properties.fill).toBe('#c45c2a')
    expect(ploen.properties.stroke).toBe('#c45c2a')
    expect(ploen.properties.visited).toBe(false)
  })

  it('setzt gleiche Deckkraft und Randstärke für beide Zustände', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'all', visits([['01001', '2024-06-01']]))
    for (const f of fc.features) {
      expect(f.properties['fill-opacity']).toBe(0.4)
      expect(f.properties['stroke-width']).toBe(2)
      expect(f.properties['stroke-opacity']).toBe(0.9)
    }
  })

  it('nennt den Fundstand im name-Feld', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'all', visits([['01001', '2024-06-01']]))
    expect(fc.name).toBe('CacheCounty – Schleswig-Holstein (1/3 gefunden)')
  })
})

// ── mode: 'missing' ───────────────────────────────────────────────────────

describe("buildFeatureCollection – mode 'missing'", () => {
  it('enthält ausschließlich unbesuchte Landkreise', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'missing', visits([['01001', '2024-06-01']]))
    expect(fc.features).toHaveLength(2)
    expect(fc.features.map(f => f.properties.region_code)).toEqual(['01054', '01057'])
    expect(fc.features.every(f => f.properties.visited === false)).toBe(true)
  })

  it('färbt alle Features rot', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'missing', visits([['01001', '2024-06-01']]))
    expect(fc.features.every(f => f.properties.fill === '#c45c2a')).toBe(true)
  })

  it('liefert eine leere Feature-Liste, wenn alles besucht ist', () => {
    const { CacheExport } = buildExport()
    const all = visits([['01001', '2024-01-01'], ['01054', '2024-02-01'], ['01057', '2024-03-01']])
    const fc  = build(CacheExport, 'missing', all)
    expect(fc.features).toHaveLength(0)
    expect(fc.name).toBe('CacheCounty – Schleswig-Holstein: 0 fehlende Landkreise')
  })

  it('nennt die Anzahl der fehlenden im name-Feld', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'missing', visits([['01001', '2024-06-01']]))
    expect(fc.name).toBe('CacheCounty – Schleswig-Holstein: 2 fehlende Landkreise')
  })
})

// ── Geometrie ─────────────────────────────────────────────────────────────

describe('buildFeatureCollection – Geometrie', () => {
  it('übernimmt MultiPolygon-Geometrie unverändert per Referenz', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'all')
    const nf = fc.features.find(f => f.properties.region_code === '01054')
    expect(nf.geometry).toBe(MULTI_GEOM)
    expect(nf.geometry.type).toBe('MultiPolygon')
  })

  it('verändert die Original-Features nicht', () => {
    const { CacheExport } = buildExport()
    build(CacheExport, 'all', visits([['01001', '2024-06-01']]))
    expect(Object.keys(FEATURES[0].properties)).toEqual(['AGS', 'GEN', 'BL_ID', 'BL'])
  })
})

// ── Properties ────────────────────────────────────────────────────────────

describe('buildFeatureCollection – Properties', () => {
  it('übernimmt visited_at, wenn vorhanden', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'all', visits([['01001', '2024-06-01']]))
    const f  = fc.features.find(x => x.properties.region_code === '01001')
    expect(f.properties.visited_at).toBe('2024-06-01')
  })

  it('lässt visited_at weg, wenn der Besuch kein Datum hat', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'all', visits([['01001', null]]))
    const f  = fc.features.find(x => x.properties.region_code === '01001')
    expect(f.properties.visited).toBe(true)
    expect('visited_at' in f.properties).toBe(false)
  })

  it('setzt Name, Bundesland und Land', () => {
    const { CacheExport } = buildExport()
    const fc = build(CacheExport, 'all')
    const f  = fc.features[0]
    expect(f.properties.name).toBe('Flensburg')
    expect(f.properties.state).toBe('Schleswig-Holstein')
    expect(f.properties.country).toBe('DE')
  })
})

// ── Österreich-Mapping ────────────────────────────────────────────────────

describe('buildFeatureCollection – AT-Mapping', () => {
  const AT_FEATURES = [
    { type: 'Feature', properties: { PB_ID: '101', PB: 'Eisenstadt (Stadt)', BL_ID: '1', BL: 'Burgenland' }, geometry: { type: 'Polygon', coordinates: [[[16, 47]]] } },
    { type: 'Feature', properties: { PB_ID: '102', PB: 'Rust (Stadt)',       BL_ID: '1', BL: 'Burgenland' }, geometry: { type: 'Polygon', coordinates: [[[16, 47]]] } },
    { type: 'Feature', properties: { PB_ID: '201', PB: 'Klagenfurt',         BL_ID: '2', BL: 'Kärnten'    }, geometry: { type: 'Polygon', coordinates: [[[14, 46]]] } },
  ]

  it('nutzt PB/PB_ID statt GEN/AGS', () => {
    const { CacheExport } = buildExport()
    const fc = CacheExport.buildFeatureCollection({
      features: AT_FEATURES,
      countryConfig: AT,
      stateCode: '1',
      stateName: 'Burgenland',
      visitsByRegionCode: visits([['101', '2024-05-01']]),
      mode: 'missing',
    })
    expect(fc.features).toHaveLength(1)
    expect(fc.features[0].properties.name).toBe('Rust (Stadt)')
    expect(fc.features[0].properties.region_code).toBe('102')
    expect(fc.features[0].properties.country).toBe('AT')
  })
})

// ── buildFilename ─────────────────────────────────────────────────────────

describe('buildFilename', () => {
  it('sluggt Umlaute korrekt', () => {
    const { CacheExport } = buildExport()
    expect(CacheExport.buildFilename('DE', 'Baden-Württemberg', 'all'))
      .toBe('cachecounty_DE_baden-wuerttemberg_alle.geojson')
  })

  it('unterscheidet alle und fehlend', () => {
    const { CacheExport } = buildExport()
    expect(CacheExport.buildFilename('DE', 'Schleswig-Holstein', 'missing'))
      .toBe('cachecounty_DE_schleswig-holstein_fehlend.geojson')
    expect(CacheExport.buildFilename('DE', 'Schleswig-Holstein', 'all'))
      .toBe('cachecounty_DE_schleswig-holstein_alle.geojson')
  })

  it('behandelt Klammern und Leerzeichen', () => {
    const { CacheExport } = buildExport()
    expect(CacheExport.buildFilename('AT', 'Eisenstadt (Stadt)', 'all'))
      .toBe('cachecounty_AT_eisenstadt-stadt_alle.geojson')
  })

  it('fällt auf einen Platzhalter zurück, wenn der Name leer ist', () => {
    const { CacheExport } = buildExport()
    expect(CacheExport.buildFilename('DE', '', 'all'))
      .toBe('cachecounty_DE_region_alle.geojson')
  })
})

// ── triggerDownload ───────────────────────────────────────────────────────

describe('triggerDownload', () => {
  it('erzeugt einen Blob und klickt den Anchor mit dem Dateinamen', () => {
    const { CacheExport, ctx, anchor } = buildExport()
    CacheExport.triggerDownload('test.geojson', '{"a":1}')

    expect(ctx.Blob).toHaveBeenCalledWith(['{"a":1}'], { type: 'application/geo+json' })
    expect(ctx.URL.createObjectURL).toHaveBeenCalled()
    expect(anchor.download).toBe('test.geojson')
    expect(anchor.href).toBe('blob:mock')
    expect(anchor.click).toHaveBeenCalled()
    expect(anchor.remove).toHaveBeenCalled()
  })
})
