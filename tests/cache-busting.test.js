/**
 * Eigene Skripte und Styles müssen in allen HTML-Dateien mit ?v=dev eingebunden sein.
 * Der Deploy ersetzt den Platzhalter durch den Commit (.github/workflows/deploy.yml),
 * damit Browser nach einem Deploy keine alten Dateien aus dem Cache mit neuem HTML
 * mischen. Ein neues Skript ohne Platzhalter würde genau diesen Fehler zurückbringen.
 */

import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const appDir = resolve(dirname(fileURLToPath(import.meta.url)), '../public/app')
const pages  = readdirSync(appDir).filter(f => f.endsWith('.html'))

describe.each(pages)('%s', page => {
  const html = readFileSync(resolve(appDir, page), 'utf-8')
  const own  = [...html.matchAll(/(?:src|href)="((?:js|css)\/[^"]+)"/g)].map(m => m[1])

  it('bindet eigene Skripte und Styles ein', () => {
    expect(own.length).toBeGreaterThan(0)
  })

  it('versieht jede eigene Datei mit ?v=dev', () => {
    expect(own.filter(url => !url.endsWith('?v=dev'))).toEqual([])
  })
})
