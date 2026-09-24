/**
 * Tests for public/app/js/outline.js
 */

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const source = readFileSync(resolve(__dirname, '../public/app/js/outline.js'), 'utf-8')
const CountryOutline = new Function(`${source}\nreturn CountryOutline;`)()

const square = (x, y, s = 1) => [[x, y], [x + s, y], [x + s, y + s], [x, y + s], [x, y]]
const polygon = (...rings) => ({ type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: rings } })
const multi = (...polys) => ({ type: 'Feature', properties: {}, geometry: { type: 'MultiPolygon', coordinates: polys } })

// Alle Kanten eines Linienzugs als richtungsunabhängige Schlüssel
const edgesOf = lines => new Set(lines.flatMap(line =>
  line.slice(1).map((p, i) => [line[i].join(','), p.join(',')].sort().join('|'))))

describe('CountryOutline.fromFeatures', () => {
  it('returns a MultiLineString feature', () => {
    const out = CountryOutline.fromFeatures([polygon(square(0, 0))])
    expect(out.type).toBe('Feature')
    expect(out.geometry.type).toBe('MultiLineString')
  })

  it('outlines a single polygon as one closed line', () => {
    const [line] = CountryOutline.fromFeatures([polygon(square(0, 0))]).geometry.coordinates
    expect(line).toHaveLength(5)
    expect(line[0]).toEqual(line[4])
  })

  it('drops the edge shared by two neighbours', () => {
    // Zwei Quadrate nebeneinander, gemeinsame Kante x = 1
    const lines = CountryOutline.fromFeatures([polygon(square(0, 0)), polygon(square(1, 0))]).geometry.coordinates
    const edges = edgesOf(lines)

    expect(edges.size).toBe(6)
    expect(edges.has('1,0|1,1')).toBe(false)
    expect(lines).toHaveLength(1)   // zu einer geschlossenen Linie verkettet
  })

  it('keeps separate islands as separate lines (MultiPolygon)', () => {
    const lines = CountryOutline.fromFeatures([multi([square(0, 0)], [square(5, 5)])]).geometry.coordinates
    expect(lines).toHaveLength(2)
  })

  it('drops the border of an enclave that belongs to another region', () => {
    // Große Fläche mit Loch, das Loch ist eine eigene Region (Enklave)
    const outer = square(0, 0, 3)
    const hole  = square(1, 1)
    const lines = CountryOutline.fromFeatures([polygon(outer, hole), polygon(hole)]).geometry.coordinates
    const edges = edgesOf(lines)

    expect(edges.size).toBe(4)          // nur der äußere Rand
    expect(edges.has('1,1|2,1')).toBe(false)
  })

  it('ignores duplicate consecutive points', () => {
    const ring = [[0, 0], [1, 0], [1, 0], [1, 1], [0, 1], [0, 0]]
    const edges = edgesOf(CountryOutline.fromFeatures([polygon(ring)]).geometry.coordinates)
    expect(edges.size).toBe(4)
  })

  it('handles features without geometry', () => {
    const out = CountryOutline.fromFeatures([{ type: 'Feature', properties: {}, geometry: null }])
    expect(out.geometry.coordinates).toEqual([])
  })
})
