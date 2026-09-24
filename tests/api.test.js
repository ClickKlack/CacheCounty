/**
 * Tests for public/app/js/api.js
 *
 * api.js exposes `Api` as a global via an IIFE.
 * We load it by evaluating the source in a context that provides
 * mocked `fetch` and `sessionStorage` globals (the latter only for the legacy cleanup).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const apiSource = readFileSync(resolve(__dirname, '../public/app/js/api.js'), 'utf-8')

function buildApi({ ok = true, status = 200, responseBody = { data: { ok: true } }, jsonThrows = false } = {}) {
  const fetchMock = vi.fn().mockResolvedValue({
    ok,
    status,
    json: () => jsonThrows
      ? Promise.reject(new SyntaxError('Unexpected token < in JSON at position 0'))
      : Promise.resolve(responseBody),
  })

  const sessionStorageMock = {
    removeItem: vi.fn(),
  }

  const ctx = {
    fetch: fetchMock,
    sessionStorage: sessionStorageMock,
  }

  // Evaluate the IIFE in a scope where fetch and sessionStorage are defined
  const factory = new Function(...Object.keys(ctx), `${apiSource}\nreturn Api;`)
  const Api = factory(...Object.values(ctx))

  return { Api, fetchMock, sessionStorageMock }
}

// ── Helper to extract the request options from the mock ────────────────────

function lastCall(fetchMock) {
  const [url, opts] = fetchMock.mock.calls.at(-1)
  return { url, opts }
}

// ── Auth ──────────────────────────────────────────────────────────────────

describe('Api.sendMagicLink', () => {
  it('sends POST to /api/auth/magic-link with email', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.sendMagicLink('test@example.com')
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/auth/magic-link')
    expect(opts.method).toBe('POST')
    expect(JSON.parse(opts.body)).toEqual({ email: 'test@example.com' })
  })
})

describe('Api.me', () => {
  it('sends GET to /api/auth/me', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.me()
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/auth/me')
    expect(opts.method).toBe('GET')
    expect(opts.body).toBeUndefined()
  })
})

describe('Api.logout', () => {
  it('sends POST to /api/auth/logout', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.logout()
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/auth/logout')
    expect(opts.method).toBe('POST')
  })
})

describe('Api.logoutAll', () => {
  it('sends POST to /api/auth/logout-all', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.logoutAll()
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/auth/logout-all')
    expect(opts.method).toBe('POST')
  })
})

// ── Public ────────────────────────────────────────────────────────────────

describe('Api.getCountries', () => {
  it('sends GET to /api/countries', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getCountries()
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/countries')
    expect(opts.method).toBe('GET')
  })
})

describe('Api.getMap', () => {
  it('builds correct URL with country query parameter', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getMap('MaxMustermann', 'DE')
    const { url } = lastCall(fetchMock)
    expect(url).toBe('/api/map/MaxMustermann?country=DE')
  })

  it('omits query string when country is null', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getMap('MaxMustermann', null)
    const { url } = lastCall(fetchMock)
    expect(url).toBe('/api/map/MaxMustermann')
  })

  it('URL-encodes special characters in username', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getMap('Max Mustermann', 'DE')
    const { url } = lastCall(fetchMock)
    expect(url).toContain('Max%20Mustermann')
  })
})

// ── Stats ─────────────────────────────────────────────────────────────────

describe('Api.getStats', () => {
  it('sends GET to /api/stats/{username}', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getStats('ClickKlack')
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/stats/ClickKlack')
    expect(opts.method).toBe('GET')
  })

  it('URL-encodes special characters in username', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getStats('Max Mustermann')
    const { url } = lastCall(fetchMock)
    expect(url).toContain('Max%20Mustermann')
  })
})

describe('Api.getLeaderboard', () => {
  it('sends GET to /api/leaderboard without query string when no country given', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getLeaderboard(null)
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/leaderboard')
    expect(opts.method).toBe('GET')
  })

  it('appends ?country=DE when country is provided', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getLeaderboard('DE')
    const { url } = lastCall(fetchMock)
    expect(url).toBe('/api/leaderboard?country=DE')
  })

  it('URL-encodes the country code', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getLeaderboard('Ö+X')
    const { url } = lastCall(fetchMock)
    expect(url).toContain('%C3%96%2BX')
  })
})

// ── Visits ────────────────────────────────────────────────────────────────

describe('Api.addVisit', () => {
  it('sends POST to /api/regions/{code}/visit', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.addVisit('DE-09162', { visited_at: '2024-06-01', notes: 'Schön!' })
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/regions/DE-09162/visit')
    expect(opts.method).toBe('POST')
    expect(JSON.parse(opts.body)).toMatchObject({ visited_at: '2024-06-01' })
  })
})

describe('Api.updateVisit', () => {
  it('sends PUT to /api/regions/{code}/visit', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.updateVisit('DE-09162', { notes: 'Aktualisiert' })
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/regions/DE-09162/visit')
    expect(opts.method).toBe('PUT')
  })
})

describe('Api.removeVisit', () => {
  it('sends DELETE to /api/regions/{code}/visit', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.removeVisit('DE-09162')
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/regions/DE-09162/visit')
    expect(opts.method).toBe('DELETE')
    expect(opts.body).toBeUndefined()
  })
})

// ── Admin ─────────────────────────────────────────────────────────────────

describe('Api.createUser', () => {
  it('sends POST to /api/admin/users', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.createUser({ username: 'newuser', email: 'new@example.com' })
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/admin/users')
    expect(opts.method).toBe('POST')
  })
})

describe('Api.deleteUser', () => {
  it('sends DELETE to /api/admin/users/{id}', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.deleteUser(42)
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/admin/users/42')
    expect(opts.method).toBe('DELETE')
  })
})

describe('Api.deleteSession', () => {
  it('URL-encodes session token', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.deleteSession('tok/en+special')
    const { url } = lastCall(fetchMock)
    expect(url).toContain('tok%2Fen%2Bspecial')
  })
})

// ── Cookie-Authentifizierung ───────────────────────────────────────────────

describe('Cookie authentication', () => {
  it('never sends an Authorization header', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.getCountries()
    const { opts } = lastCall(fetchMock)
    expect(opts.headers['Authorization']).toBeUndefined()
  })

  it('sends the session cookie for same-origin requests only', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.me()
    expect(lastCall(fetchMock).opts.credentials).toBe('same-origin')
  })

  it('removes legacy session data from sessionStorage on load', () => {
    const { sessionStorageMock } = buildApi()
    const removed = sessionStorageMock.removeItem.mock.calls.map(c => c[0])
    expect(removed).toEqual(expect.arrayContaining(['cc_token', 'cc_username', 'cc_admin', 'cc_is_admin']))
  })
})

describe('Api.verifyToken', () => {
  it('sends POST to /api/auth/verify with the token in the body, not the URL', async () => {
    const { Api, fetchMock } = buildApi()
    await Api.verifyToken('abc123')
    const { url, opts } = lastCall(fetchMock)
    expect(url).toBe('/api/auth/verify')
    expect(opts.method).toBe('POST')
    expect(JSON.parse(opts.body)).toEqual({ token: 'abc123' })
  })
})

// ── 401-Behandlung ─────────────────────────────────────────────────────────

describe('Unauthorized handler', () => {
  const unauthorized = { ok: false, status: 401, responseBody: { success: false, error: 'Unauthorized' } }

  it('is called on 401 for regular requests', async () => {
    const { Api } = buildApi(unauthorized)
    const handler = vi.fn()
    Api.setUnauthorizedHandler(handler)
    await expect(Api.addVisit('DE-09162', {})).rejects.toThrow('Unauthorized')
    expect(handler).toHaveBeenCalledOnce()
  })

  it('is not called for /auth/me (401 just means "not logged in")', async () => {
    const { Api } = buildApi(unauthorized)
    const handler = vi.fn()
    Api.setUnauthorizedHandler(handler)
    await expect(Api.me()).rejects.toThrow()
    expect(handler).not.toHaveBeenCalled()
  })

  it('is not called for /auth/verify (401 means "link invalid")', async () => {
    const { Api } = buildApi(unauthorized)
    const handler = vi.fn()
    Api.setUnauthorizedHandler(handler)
    await expect(Api.verifyToken('x')).rejects.toThrow()
    expect(handler).not.toHaveBeenCalled()
  })

  it('is not called for other error codes', async () => {
    const { Api } = buildApi({ ok: false, status: 403, responseBody: { error: 'Forbidden' } })
    const handler = vi.fn()
    Api.setUnauthorizedHandler(handler)
    await expect(Api.listUsers()).rejects.toThrow('Forbidden')
    expect(handler).not.toHaveBeenCalled()
  })
})

// ── Error handling ─────────────────────────────────────────────────────────

describe('Error handling', () => {
  it('throws Error with message from API error response', async () => {
    const { Api } = buildApi({
      ok: false,
      responseBody: { success: false, error: 'Unauthorized' },
    })
    await expect(Api.me()).rejects.toThrow('Unauthorized')
  })

  it('throws generic message when error field is missing', async () => {
    const { Api } = buildApi({
      ok: false,
      responseBody: {},
    })
    await expect(Api.me()).rejects.toThrow('Unbekannter Fehler')
  })

  it('exposes the HTTP status on the error', async () => {
    const { Api } = buildApi({ ok: false, status: 429, responseBody: { error: 'Too many requests.' } })
    await expect(Api.sendMagicLink('a@b.de')).rejects.toMatchObject({ status: 429 })
  })

  // Tritt auf, wenn hinter dem Dev-Proxy kein PHP-Server läuft und
  // stattdessen eine HTML-Fehlerseite zurückkommt.
  it('gives an actionable message when the response is not JSON', async () => {
    const { Api } = buildApi({ ok: false, status: 502, jsonThrows: true })
    await expect(Api.me()).rejects.toThrow('Die API hat kein JSON geliefert (HTTP 502)')
  })

  it('does not leak the raw SyntaxError on a non-JSON response', async () => {
    const { Api } = buildApi({ ok: true, status: 200, jsonThrows: true })
    await expect(Api.me()).rejects.toThrow(/Läuft der PHP-Server\?/)
  })
})
