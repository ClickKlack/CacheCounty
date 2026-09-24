/**
 * Tests for public/app/js/auth-menu.js
 *
 * auth-menu.js exposes `AuthMenu` as a global via an IIFE. Only buildHtml()
 * is tested here – it is DOM-free; render() just assigns it and binds events.
 */

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const source = readFileSync(resolve(__dirname, '../public/app/js/auth-menu.js'), 'utf-8')

const AuthMenu = new Function(`${source}\nreturn AuthMenu;`)()

describe('AuthMenu.buildHtml', () => {
  it('renders toggle button and menu container', () => {
    const html = AuthMenu.buildHtml({ items: [] })
    expect(html).toContain('id="auth-menu-toggle"')
    expect(html).toContain('aria-controls="auth-menu"')
    expect(html).toContain('aria-expanded="false"')
    expect(html).toContain('id="auth-menu" class="auth-menu"')
  })

  it('shows the username twice when logged in: header (desktop) and menu (mobile)', () => {
    const html = AuthMenu.buildHtml({ username: 'max', items: [] })
    expect(html).toContain('class="auth-user"')
    expect(html).toContain('class="auth-menu-user"')
    expect(html.match(/>max</g)).toHaveLength(2)
  })

  it('omits user blocks when logged out', () => {
    const html = AuthMenu.buildHtml({ items: [{ label: 'Anmelden', onClick: () => {} }] })
    expect(html).not.toContain('auth-user')
    expect(html).not.toContain('auth-menu-user')
  })

  it('renders href items as links and onClick items as indexed buttons', () => {
    const html = AuthMenu.buildHtml({
      items: [
        { label: 'Statistiken', href: '/stats/max' },
        { label: 'Abmelden', onClick: () => {} },
      ],
    })
    expect(html).toMatch(/<a href="\/stats\/max"[^>]*>Statistiken<\/a>/)
    expect(html).toMatch(/<button[^>]*data-menu-index="1"[^>]*>Abmelden<\/button>/)
  })

  it('keeps the item order', () => {
    const html = AuthMenu.buildHtml({
      items: [{ label: 'Eins', href: '/1' }, { label: 'Zwei', href: '/2' }, { label: 'Drei', onClick: () => {} }],
    })
    expect(html.indexOf('Eins')).toBeLessThan(html.indexOf('Zwei'))
    expect(html.indexOf('Zwei')).toBeLessThan(html.indexOf('Drei'))
  })

  it('adds a title attribute when given', () => {
    const html = AuthMenu.buildHtml({ items: [{ label: 'X', title: 'Erklärung', onClick: () => {} }] })
    expect(html).toContain('title="Erklärung"')
  })

  it('escapes username, labels, titles and hrefs', () => {
    const html = AuthMenu.buildHtml({
      username: '<img src=x onerror=alert(1)>',
      items: [{ label: '<b>', title: '"quote\'', href: '/x?a="><script>' }],
    })
    expect(html).not.toContain('<img')
    expect(html).not.toContain('<b>')
    expect(html).not.toContain('<script>')
    expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;')
    expect(html).toContain('title="&quot;quote&#39;"')
  })
})
