/**
 * Tests for public/app/js/routes.js
 */

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const source = readFileSync(resolve(__dirname, '../public/app/js/routes.js'), 'utf-8')
const AppRoutes = new Function(`${source}\nreturn AppRoutes;`)()

describe('AppRoutes.parse', () => {
  it.each([
    ['/',                          { page: 'map',   user: null,          country: null }],
    ['/app/',                      { page: 'map',   user: null,          country: null }],
    ['/app/index.html',            { page: 'map',   user: null,          country: null }],
    ['/country/at',                { page: 'map',   user: null,          country: 'AT' }],
    ['/country/CH/',               { page: 'map',   user: null,          country: 'CH' }],
    ['/map/ClickKlack',            { page: 'map',   user: 'ClickKlack',  country: null }],
    ['/map/ClickKlack/',           { page: 'map',   user: 'ClickKlack',  country: null }],
    ['/map/ClickKlack/dk',         { page: 'map',   user: 'ClickKlack',  country: 'DK' }],
    ['/map/Ahnungslos*/at',        { page: 'map',   user: 'Ahnungslos*', country: 'AT' }],
    ['/map/Ahnungslos%2A',         { page: 'map',   user: 'Ahnungslos*', country: null }],
    ['/map/J%C3%B6rg%20S/de',      { page: 'map',   user: 'Jörg S',      country: 'DE' }],
    ['/stats/ClickKlack',          { page: 'stats', user: 'ClickKlack',  country: null }],
    ['/stats/ClickKlack/ch',       { page: 'stats', user: 'ClickKlack',  country: 'CH' }],
    ['/stats',                     { page: 'stats', user: null,          country: null }],
  ])('%s', (path, expected) => {
    expect(AppRoutes.parse(path)).toEqual(expected)
  })

  it('ignores invalid country segments', () => {
    expect(AppRoutes.parse('/map/ClickKlack/deu').country).toBeNull()
    expect(AppRoutes.parse('/map/ClickKlack/12').country).toBeNull()
    expect(AppRoutes.parse('/country/x').country).toBeNull()
  })

  it('keeps an invalid percent-encoding instead of throwing', () => {
    expect(AppRoutes.parse('/map/100%/de').user).toBe('100%')
  })
})

describe('AppRoutes.mapPath', () => {
  it('builds user map paths with and without country', () => {
    expect(AppRoutes.mapPath('ClickKlack')).toBe('/map/ClickKlack')
    expect(AppRoutes.mapPath('ClickKlack', 'AT')).toBe('/map/ClickKlack/at')
  })

  it('builds paths without user', () => {
    expect(AppRoutes.mapPath(null)).toBe('/')
    expect(AppRoutes.mapPath(null, 'CH')).toBe('/country/ch')
  })

  it('encodes the username like everywhere else', () => {
    expect(AppRoutes.mapPath('Jörg Schöne', 'DE')).toBe('/map/J%C3%B6rg%20Sch%C3%B6ne/de')
    expect(AppRoutes.mapPath('Ahnungslos*')).toBe('/map/Ahnungslos*')
  })
})

describe('AppRoutes.statsPath', () => {
  it('builds stats paths', () => {
    expect(AppRoutes.statsPath('ClickKlack')).toBe('/stats/ClickKlack')
    expect(AppRoutes.statsPath('ClickKlack', 'DK')).toBe('/stats/ClickKlack/dk')
  })
})

describe('round trip', () => {
  it.each([['Ahnungslos*', 'AT'], ['Jörg Schöne', null], ["O'Reilly (DE)", 'CH']])('%s / %s', (user, cc) => {
    expect(AppRoutes.parse(AppRoutes.mapPath(user, cc))).toEqual({ page: 'map', user, country: cc })
    expect(AppRoutes.parse(AppRoutes.statsPath(user, cc))).toEqual({ page: 'stats', user, country: cc })
  })
})
