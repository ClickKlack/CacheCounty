# Selbst gehostete Fremdbibliotheken

Diese Dateien wurden früher per CDN (cdnjs, jsDelivr, Google Fonts) geladen. Turf.js entfiel
dabei ganz – den Landesumriss berechnet jetzt `js/outline.js`. Sie liegen
jetzt hier, damit die Seite keine Fremdserver mehr anfragt (Datenschutz, Ausfallsicherheit)
und eine strikte Content-Security-Policy (`script-src 'self'`) möglich ist.

**Nicht von Hand ändern.** Jede Datei ist unverändert aus dem genannten npm-Paket übernommen;
beim Download wurde die `integrity`-Prüfsumme des Pakets aus dem npm-Registry geprüft.

| Paket | Version | Lizenz | Verwendet |
|---|---|---|---|
| [leaflet](https://www.npmjs.com/package/leaflet) | 1.9.4 | BSD-2-Clause | `dist/leaflet.js`, `dist/leaflet.css`, `dist/images/*` |
| [chart.js](https://www.npmjs.com/package/chart.js) | 4.5.1 | MIT | `dist/chart.umd.min.js` |
| [@fontsource/playfair-display](https://www.npmjs.com/package/@fontsource/playfair-display) | 5.3.0 | OFL-1.1 | 600, 700, 600 italic – latin, latin-ext |
| [@fontsource/dm-sans](https://www.npmjs.com/package/@fontsource/dm-sans) | 5.3.0 | OFL-1.1 | 300, 400, 500 – latin, latin-ext |

`fonts/fonts.css` ist selbst geschrieben (nach dem Vorbild der fontsource-CSS-Dateien).

## Aktualisieren

1. Neue Version im npm-Registry nachschlagen, Tarball laden und gegen `dist.integrity`
   prüfen, z. B. `npm pack <paket>@<version>` (prüft die Integrität selbst).
2. Die oben genannten Dateien in ein **neues** Versionsverzeichnis kopieren
   (z. B. `chart.js/4.6.0/`), die Pfade in den HTML-Dateien anpassen, altes Verzeichnis löschen.
3. Tabelle unten neu erzeugen (`shasum -a 256`) und die Seiten mit offener Browser-Konsole
   durchklicken (CSP-Verstöße, fehlende Kacheln oder Schriften).

## SHA-256 der Dateien

| Datei | SHA-256 |
|---|---|
| `leaflet/1.9.4/leaflet.js` | db49d009c841f5ca34a888c96511ae936fd9f5533e90d8b2c4d57596f4e5641a |
| `leaflet/1.9.4/leaflet.css` | a7837102824184820dfa198d1ebcd109ff6d0ff9a2672a074b9a1b4d147d04c6 |
| `leaflet/1.9.4/images/layers.png` | 1dbbe9d028e292f36fcba8f8b3a28d5e8932754fc2215b9ac69e4cdecf5107c6 |
| `leaflet/1.9.4/images/layers-2x.png` | 066daca850d8ffbef007af00b06eac0015728dee279c51f3cb6c716df7c42edf |
| `leaflet/1.9.4/images/marker-icon.png` | 574c3a5cca85f4114085b6841596d62f00d7c892c7b03f28cbfa301deb1dc437 |
| `leaflet/1.9.4/images/marker-icon-2x.png` | 00179c4c1ee830d3a108412ae0d294f55776cfeb085c60129a39aa6fc4ae2528 |
| `leaflet/1.9.4/images/marker-shadow.png` | 264f5c640339f042dd729062cfc04c17f8ea0f29882b538e3848ed8f10edb4da |
| `chart.js/4.5.1/chart.umd.min.js` | 48444a82d4edcb5bec0f1965faacdde18d9c17db3063d042abada2f705c9f54a |
| `fonts/playfair-display/playfair-display-latin-ext-600-normal.woff2` | cf968562375eceed83b66f2b5445aa4ecdc4d5300de94930da06433877a975a1 |
| `fonts/playfair-display/playfair-display-latin-600-normal.woff2` | 5d2286941a6b02a29387efa94809a064f8917598eafa67b938261cf13bb887cd |
| `fonts/playfair-display/playfair-display-latin-ext-700-normal.woff2` | edb9f5d879b30c617698bcc288692c339d5a0a2464a5476f00025195e5cad166 |
| `fonts/playfair-display/playfair-display-latin-700-normal.woff2` | 28453852ea165c47b5a941be00e418402e1407002ed87507f062a1e316328fe6 |
| `fonts/playfair-display/playfair-display-latin-ext-600-italic.woff2` | 4f6a4778dcb42d51a922c9ee7ba5b85482473ce33e3d4eb9280912b24794b341 |
| `fonts/playfair-display/playfair-display-latin-600-italic.woff2` | 8176ed854ef40b2cdebbdb7a1fd9283b3dada1e87c8e89d003f3485fc3c7435b |
| `fonts/dm-sans/dm-sans-latin-ext-300-normal.woff2` | d45c7f5d73861db15ec16ba6c4a5e29fda548b32d382165a4afe3f9034ca13e2 |
| `fonts/dm-sans/dm-sans-latin-300-normal.woff2` | 80f13c410ec41f210a5553e7f420f8a51f459180019274df0b3faea314916f90 |
| `fonts/dm-sans/dm-sans-latin-ext-400-normal.woff2` | 962730c6ff7595f9499b0d963a3bccb2139d793f0fb31fbd87aed1881c020e0a |
| `fonts/dm-sans/dm-sans-latin-400-normal.woff2` | 4ab51eb2cd7305d177187908d6397474d4520663f6c6e572feb0a64f4fa80006 |
| `fonts/dm-sans/dm-sans-latin-ext-500-normal.woff2` | e0a1d21584ba00798a3dbe90ef5f8a162741d454f52ab66630f9dc41da372b3d |
| `fonts/dm-sans/dm-sans-latin-500-normal.woff2` | 19bf1984956517c35c2bd35b6cdedac12a21d6fcd3596c614ecdfb88b648909d |
