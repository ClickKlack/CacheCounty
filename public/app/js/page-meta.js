/**
 * CacheCounty – Seiten-Metadaten für Suchmaschinen und Link-Vorschauen
 *
 * /map/{user} und /stats/{user} werden aus derselben statischen HTML-Datei
 * ausgeliefert. Titel, Beschreibung und canonical-URL setzt deshalb das Skript
 * je Nutzer (Google wertet das nach dem Rendern aus). canonical sorgt dafür, dass
 * /, /app/ und /app/index.html als eine Seite gelten.
 *
 * Nutzung: PageMeta.set({ title, description, path })
 */

const PageMeta = (() => {

  // Setzt das Attribut; fehlt das Element, wird es nur mit create (Tag-Attribute) angelegt
  function setMeta(selector, attr, value, create = null) {
    let el = document.head.querySelector(selector);
    if (!el && create) {
      el = document.createElement('meta');
      Object.entries(create).forEach(([k, v]) => el.setAttribute(k, v));
      document.head.appendChild(el);
    }
    if (el) el.setAttribute(attr, value);
  }

  function set({ title, description, path }) {
    if (title) {
      document.title = title;
      setMeta('meta[property="og:title"]', 'content', title);
    }
    if (description) {
      setMeta('meta[name="description"]', 'content', description);
      setMeta('meta[property="og:description"]', 'content', description);
    }
    if (path) {
      const url = location.origin + path;
      let link = document.head.querySelector('link[rel="canonical"]');
      if (!link) {
        link = document.createElement('link');
        link.rel = 'canonical';
        document.head.appendChild(link);
      }
      link.href = url;
      setMeta('meta[property="og:url"]', 'content', url, { property: 'og:url' });
    }
  }

  return { set };
})();
