/**
 * CacheCounty – öffentliche Adressen der Karten- und Statistikseite
 *
 *   /                         Karte (eigene, falls eingeloggt), erstes Land
 *   /country/{cc}             dito, Land cc
 *   /map/{user}               Karte eines Nutzers, erstes Land
 *   /map/{user}/{cc}          Karte eines Nutzers, Land cc
 *   /stats/{user}[/{cc}]      Statistik eines Nutzers, optional mit Land
 *
 * Der Nutzername steht URL-kodiert in der Adresse (encodeURIComponent), der
 * Ländercode in Kleinbuchstaben. Beim Lesen wird dekodiert und der Code groß
 * geschrieben – so, wie er in countries.json steht.
 *
 * Die Rewrites dazu stehen in public/.htaccess und scripts/dev-router.php.
 * DOM-frei → getestet in tests/routes.test.js.
 */

const AppRoutes = (() => {

  const COUNTRY = /^[A-Za-z]{2}$/;

  function decode(segment) {
    try {
      return decodeURIComponent(segment);
    } catch (_) {
      return segment; // ungültige Kodierung: unverändert übernehmen
    }
  }

  const country = seg => (seg && COUNTRY.test(seg) ? seg.toUpperCase() : null);

  /**
   * Zerlegt einen Pfad in { page: 'map'|'stats', user, country }.
   * user und country sind null, wenn sie fehlen oder ungültig sind.
   */
  function parse(pathname) {
    const parts = pathname.split('/').filter(Boolean);
    const [first, second, third] = parts;

    if (first === 'stats') {
      return { page: 'stats', user: second ? decode(second) : null, country: country(third) };
    }
    if (first === 'map' && second) {
      return { page: 'map', user: decode(second), country: country(third) };
    }
    if (first === 'country') {
      return { page: 'map', user: null, country: country(second) };
    }
    return { page: 'map', user: null, country: null };   // /, /app/, /app/index.html
  }

  const countrySuffix = cc => (cc ? '/' + cc.toLowerCase() : '');

  /** Adresse der Karte – mit Nutzer /map/…, ohne Nutzer /country/… bzw. / */
  function mapPath(user, cc = null) {
    if (user) return '/map/' + encodeURIComponent(user) + countrySuffix(cc);
    return cc ? '/country/' + cc.toLowerCase() : '/';
  }

  /** Adresse der Statistik eines Nutzers */
  function statsPath(user, cc = null) {
    return '/stats/' + encodeURIComponent(user) + countrySuffix(cc);
  }

  return { parse, mapPath, statsPath };
})();
