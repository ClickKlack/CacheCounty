#!/usr/bin/env bash
# =============================================================================
#  CacheCounty – Konfiguration der lokalen Entwicklungsumgebung
# =============================================================================
#
#  Diese Datei ist die VERSIONIERTE Vorlage. Beim ersten Start legt dev.sh
#  automatisch eine Kopie als scripts/dev.config.sh an – die ist via .gitignore
#  ausgeschlossen und gehört dir. Passe dort an, was von den Defaults abweicht.
#
#  Datenbank-Zugangsdaten stehen hier BEWUSST NICHT: die leben ausschließlich
#  in api/config/database.local.php, das die Anwendung selbst liest. dev.sh
#  greift für seinen Verbindungstest auf dieselbe Datei zu – eine Quelle,
#  keine Kopien, die auseinanderlaufen können.
#
# =============================================================================


# ── Ports ────────────────────────────────────────────────────────────────────

# PHP-Server im Modus "api" (nur die API, Frontend kommt vom Live Server).
# Muss zum Proxy-Ziel in .vscode/settings.json passen.
API_PORT=8081

# PHP-Server im Modus "full" (liefert API und Frontend, inkl. /map/{username}).
FULL_PORT=8080

# VS Code Live Server – wird nur geprüft und angezeigt, nicht gestartet.
LIVE_SERVER_PORT=5500

# Mailpit: SMTP-Eingang und Weboberfläche.
MAILPIT_SMTP_PORT=1025
MAILPIT_UI_PORT=8025


# ── Bind-Adresse ─────────────────────────────────────────────────────────────

# "localhost" lässt PHP unter macOS auf [::1] (IPv6) lauschen – damit kommt der
# Live-Server-Proxy nachweislich zurecht. Falls ein Client nur IPv4 spricht,
# hier auf 127.0.0.1 umstellen.
BIND_HOST=localhost


# ── Programme (leer = aus dem PATH) ──────────────────────────────────────────

PHP_BIN=
MYSQL_BIN=
MAILPIT_BIN=


# ── Hintergrunddienste ───────────────────────────────────────────────────────

# 1 = dev.sh startet den Dienst bei Bedarf, 0 = nur prüfen und warnen.
AUTOSTART_MARIADB=1
AUTOSTART_MAILPIT=1

# Name des Homebrew-Services für die Datenbank.
MARIADB_SERVICE=mariadb


# ── Sonstiges ────────────────────────────────────────────────────────────────

# Beispiel-Username für die Hinweise am Ende der Ausgabe.
SAMPLE_USER=ClickKlack
