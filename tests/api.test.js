/**
 * Tests for app/js/api.js
 *
 * api.js exposes `Api` as a global via an IIFE.
 * We load it by evaluating the source in a context that provides
 * mocked `fetch` and `sessionStorage` globals.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const apiSource = readFileSync(resolve(__dirname, '../app/js/api.js'), 'utf-8')

function buildApi({ token = null, ok = true, responseBody = { data: { ok: true } } } = {}) {
  const fetchMock = vi.fn().mockResolvedValue({
    ok,
    json: () => Promise.resolve(responseBody),
  })

  const sessionStorageMock = {
    getItem: vi.fn().mockReturnValue(token),
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

// ── Authorization header ───────────────────────────────────────────────────

describe('Authorization header', () => {
  it('adds Bearer token from sessionStorage when present', async () => {
    const { Api, fetchMock } = buildApi({ token: 'my-secret-token' })
    await Api.getCountries()
    const { opts } = lastCall(fetchMock)
    expect(opts.headers['Authorization']).toBe('Bearer my-secret-token')
  })

  it('omits Authorization header when no token in sessionStorage', async () => {
    const { Api, fetchMock } = buildApi({ token: null })
    await Api.getCountries()
    const { opts } = lastCall(fetchMock)
    expect(opts.headers['Authorization']).toBeUndefined()
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
})
