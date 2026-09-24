#!/usr/bin/env bash
# =============================================================================
#  CacheCounty – Entwicklungsumgebung starten
#
#    ./scripts/dev.sh              Frontend + API auf einem Port (Standard)
#    ./scripts/dev.sh api          nur die API, für VS Code Live Server
#    ./scripts/dev.sh status       Zustand aller Dienste anzeigen
#    ./scripts/dev.sh stop         den von dev.sh gestarteten PHP-Server beenden
#    ./scripts/dev.sh restart      stop + start
#
#  Optionen:
#    --no-services   MariaDB/Mailpit nicht automatisch starten
#    --foreground    PHP im Vordergrund laufen lassen (Strg+C beendet)
#    -h, --help      diese Hilfe
#
#  Konfiguration: scripts/dev.config.sh (wird beim ersten Start aus
#  dev.config.example.sh erzeugt und ist via .gitignore ausgeschlossen).
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_DIR="$ROOT/scripts/.run"
PID_FILE="$RUN_DIR/php.pid"
PORT_FILE="$RUN_DIR/php.port"
LOG_FILE="$RUN_DIR/php.log"


# ── Ausgabe ──────────────────────────────────────────────────────────────────

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET=$'\033[0m'; C_DIM=$'\033[2m';    C_BOLD=$'\033[1m'
    C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'; C_BLUE=$'\033[34m'
else
    C_RESET=''; C_DIM=''; C_BOLD=''; C_GREEN=''; C_YELLOW=''; C_RED=''; C_BLUE=''
fi

ok()    { printf '  %s✓%s %s\n' "$C_GREEN"  "$C_RESET" "$1"; }
warn()  { printf '  %s!%s %s\n' "$C_YELLOW" "$C_RESET" "$1"; }
fail()  { printf '  %s✗%s %s\n' "$C_RED"    "$C_RESET" "$1"; }
info()  { printf '  %s·%s %s\n' "$C_DIM"    "$C_RESET" "$1"; }
head1() { printf '\n%s%s%s\n' "$C_BOLD" "$1" "$C_RESET"; }
die()   { fail "$1"; [ $# -gt 1 ] && printf '\n%s\n' "$2"; exit 1; }


# ── Konfiguration laden ──────────────────────────────────────────────────────

CONFIG="$ROOT/scripts/dev.config.sh"
EXAMPLE="$ROOT/scripts/dev.config.example.sh"

if [ ! -f "$CONFIG" ]; then
    [ -f "$EXAMPLE" ] || die "Weder dev.config.sh noch dev.config.example.sh gefunden."
    cp "$EXAMPLE" "$CONFIG"
    printf '  %s·%s dev.config.sh aus der Vorlage erzeugt (nicht versioniert)\n' "$C_DIM" "$C_RESET"
fi

# shellcheck source=/dev/null
. "$CONFIG"

API_PORT="${API_PORT:-8081}"
FULL_PORT="${FULL_PORT:-8080}"
LIVE_SERVER_PORT="${LIVE_SERVER_PORT:-5500}"
MAILPIT_SMTP_PORT="${MAILPIT_SMTP_PORT:-1025}"
MAILPIT_UI_PORT="${MAILPIT_UI_PORT:-8025}"
BIND_HOST="${BIND_HOST:-localhost}"
PHP_BIN="${PHP_BIN:-php}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
MAILPIT_BIN="${MAILPIT_BIN:-mailpit}"
AUTOSTART_MARIADB="${AUTOSTART_MARIADB:-1}"
AUTOSTART_MAILPIT="${AUTOSTART_MAILPIT:-1}"
MARIADB_SERVICE="${MARIADB_SERVICE:-mariadb}"
SAMPLE_USER="${SAMPLE_USER:-ClickKlack}"


# ── Argumente ────────────────────────────────────────────────────────────────

COMMAND=start
MODE=full
SERVICES=1
FOREGROUND=0

for arg in "$@"; do
    case "$arg" in
        start|full)    COMMAND=start; MODE=full ;;
        api)           COMMAND=start; MODE=api  ;;
        stop)          COMMAND=stop    ;;
        status)        COMMAND=status  ;;
        restart)       COMMAND=restart ;;
        --no-services) SERVICES=0      ;;
        --foreground)  FOREGROUND=1    ;;
        -h|--help)
            # Kopfkommentar ausgeben: ab Zeile 2 alle Kommentarzeilen bis zur
            # ersten Nicht-Kommentarzeile, ohne die Trennlinien.
            awk 'NR==1 { next }
                 /^#/  { l = $0; sub(/^# ?/, "", l); if (l !~ /^=+$/) print l; next }
                       { exit }' "${BASH_SOURCE[0]}"
            exit 0 ;;
        *) die "Unbekannte Option: $arg" "Hilfe mit: ./scripts/dev.sh --help" ;;
    esac
done

[ "$MODE" = api ] && PORT="$API_PORT" || PORT="$FULL_PORT"


# ── Helfer ───────────────────────────────────────────────────────────────────

# Findet nichts auf dem Port, liefert lsof Exit 1 – mit 'pipefail' würde das
# unter 'set -e' das Skript beenden. Ein leerer Rückgabewert ist hier aber ein
# gültiges Ergebnis, deshalb der Abschluss mit 'true'.
port_pid() { lsof -nP -iTCP:"$1" -sTCP:LISTEN -t 2>/dev/null | head -1 || true; }

# PID des von uns gestarteten PHP-Servers, sofern er noch lebt
own_pid() {
    [ -f "$PID_FILE" ] || return 1
    local pid; pid="$(cat "$PID_FILE" 2>/dev/null)" || return 1
    [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null || return 1
    printf '%s' "$pid"
}

wait_for_port() {
    local port="$1" tries="${2:-50}"
    for _ in $(seq 1 "$tries"); do
        [ -n "$(port_pid "$port")" ] && return 0
        sleep 0.2
    done
    return 1
}

# Verbindungstest gegen die Konfiguration, die auch die Anwendung nutzt.
# Gibt bei Erfolg die Anzahl der Nutzer aus, sonst die PDO-Fehlermeldung auf stderr.
db_probe() {
    (cd "$ROOT" && "$PHP_BIN" -r '
        $f = "api/config/database.local.php";
        if (!file_exists($f)) { fwrite(STDERR, "CONFIG_MISSING"); exit(2); }
        $c = require $f;
        try {
            $pdo = new PDO(
                sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $c["host"] ?? "", $c["name"] ?? ""),
                $c["user"] ?? "", $c["pass"] ?? "",
                [PDO::ATTR_TIMEOUT => 4, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            echo (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage());
            exit(1);
        }
    ' 2>"$RUN_DIR/db.err")
}

active_users() {
    (cd "$ROOT" && "$PHP_BIN" -r '
        $c = require "api/config/database.local.php";
        try {
            $pdo = new PDO(
                sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $c["host"], $c["name"]),
                $c["user"], $c["pass"], [PDO::ATTR_TIMEOUT => 4]
            );
            foreach ($pdo->query("SELECT username, email, is_admin FROM users WHERE is_active = 1 ORDER BY is_admin DESC, username") as $r) {
                printf("%s|%s|%s\n", $r["username"], $r["email"], $r["is_admin"] ? "Admin" : "");
            }
        } catch (Throwable $e) { /* still */ }
    ' 2>/dev/null)
}


# ── Kommando: stop ───────────────────────────────────────────────────────────

do_stop() {
    head1 "PHP-Server beenden"
    local pid
    if pid="$(own_pid)"; then
        # Sicherheitsnetz: PIDs werden wiederverwendet. Nur beenden, wenn dort
        # tatsächlich noch ein PHP-Prozess sitzt.
        if ! ps -o command= -p "$pid" 2>/dev/null | grep -q '[p]hp'; then
            rm -f "$PID_FILE" "$PORT_FILE"
            warn "PID $pid gehört keinem PHP-Prozess mehr – nichts beendet."
            return 0
        fi
        kill "$pid" 2>/dev/null || true
        for _ in $(seq 1 25); do kill -0 "$pid" 2>/dev/null || break; sleep 0.2; done
        kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null || true
        rm -f "$PID_FILE" "$PORT_FILE"
        ok "beendet (PID $pid)"
    else
        rm -f "$PID_FILE" "$PORT_FILE"
        info "Kein von dev.sh gestarteter Server aktiv."
        for p in "$FULL_PORT" "$API_PORT"; do
            local foreign; foreign="$(port_pid "$p")"
            [ -n "$foreign" ] && warn "Port $p ist von PID $foreign belegt – nicht von dev.sh gestartet, bleibt unangetastet."
        done
    fi
    # Ohne dieses return liefert die Schleife oben eine 1, wenn kein Port belegt
    # ist – 'set -e' würde 'restart' dann nach dem Stoppen abbrechen.
    return 0
}


# ── Kommando: status ─────────────────────────────────────────────────────────

svc_line() {
    local label="$1" port="$2" hint="${3:-}"
    local pid; pid="$(port_pid "$port")"
    # %-22s zaehlt Bytes, nicht Zeichen – Labels deshalb bei ASCII halten,
    # sonst verrutscht die Spalte bei Umlauten oder Gedankenstrichen.
    if [ -n "$pid" ]; then
        ok "$(printf '%-22s Port %-5s PID %s' "$label" "$port" "$pid")"
    else
        warn "$(printf '%-22s Port %-5s --  %s' "$label" "$port" "$hint")"
    fi
}

do_status() {
    head1 "Dienste"
    svc_line "MariaDB"            3306                  "nicht erreichbar"
    svc_line "Mailpit (SMTP)"     "$MAILPIT_SMTP_PORT"  "Magic-Link-Mails gehen ins Leere"
    svc_line "Mailpit (Web)"      "$MAILPIT_UI_PORT"    ""
    svc_line "PHP (Modus full)"   "$FULL_PORT"          "gestoppt"
    svc_line "PHP (Modus api)"    "$API_PORT"           "gestoppt"
    svc_line "VS Code Live Server" "$LIVE_SERVER_PORT"  "nicht gestartet (optional)"

    head1 "Datenbank"
    local count
    if count="$(db_probe)"; then
        ok "Verbindung steht – $count Nutzer in der Tabelle"
    else
        local err; err="$(cat "$RUN_DIR/db.err" 2>/dev/null || true)"
        [ "$err" = CONFIG_MISSING ] \
            && fail "api/config/database.local.php fehlt" \
            || fail "keine Verbindung: $err"
    fi
}


# ── Preflight ────────────────────────────────────────────────────────────────

preflight() {
    head1 "Voraussetzungen"

    command -v "$PHP_BIN" >/dev/null 2>&1 \
        || die "PHP nicht gefunden ($PHP_BIN)" "Installieren mit: brew install php"

    local ver; ver="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
    if "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
        ok "PHP $ver"
    else
        die "PHP $ver ist zu alt – benötigt wird 8.3 oder neuer."
    fi

    [ -f "$ROOT/api/vendor/autoload.php" ] \
        && ok "Composer-Abhängigkeiten installiert" \
        || die "api/vendor/ fehlt" "Nachholen mit: cd api && composer install --optimize-autoloader"

    # Lokale Konfigurationsdateien – bei Bedarf aus den Templates erzeugen
    local created=0
    for name in database app; do
        local local_file="$ROOT/api/config/$name.local.php"
        if [ ! -f "$local_file" ]; then
            cp "$ROOT/api/config/$name.php" "$local_file"
            warn "api/config/$name.local.php aus der Vorlage erzeugt – bitte Werte eintragen"
            created=1
        fi
    done
    [ "$created" -eq 0 ] && ok "Lokale Konfiguration vorhanden"

    # GeoJSON – nicht im Repository, muss manuell abgelegt werden
    local missing_geo=""
    while IFS= read -r file; do
        [ -z "$file" ] && continue
        [ -f "$ROOT/public/$file" ] || missing_geo="$missing_geo public/$file"
    done < <(cd "$ROOT" && "$PHP_BIN" -r '
        foreach (json_decode(file_get_contents("config/countries.json"), true) ?: [] as $c) {
            echo $c["geojson"] ?? "", "\n";
        }' 2>/dev/null || true)

    if [ -z "$missing_geo" ]; then
        ok "GeoJSON-Dateien vorhanden"
    else
        warn "GeoJSON fehlt:$missing_geo – Karte bleibt leer (Dateien sind nicht im Repo)"
    fi
}


# ── Dienste ──────────────────────────────────────────────────────────────────

start_services() {
    head1 "Hintergrunddienste"

    # ── MariaDB ──
    if [ -n "$(port_pid 3306)" ]; then
        ok "MariaDB läuft"
    elif [ "$AUTOSTART_MARIADB" = 1 ] && command -v brew >/dev/null 2>&1; then
        info "MariaDB wird gestartet …"
        brew services start "$MARIADB_SERVICE" >/dev/null 2>&1 || true
        wait_for_port 3306 60 && ok "MariaDB gestartet" || fail "MariaDB startet nicht"
    else
        fail "MariaDB läuft nicht (Autostart aus)"
    fi

    # ── Datenbankverbindung ──
    local count
    if count="$(db_probe)"; then
        ok "Datenbankverbindung steht – $count Nutzer"
    else
        local err; err="$(cat "$RUN_DIR/db.err" 2>/dev/null || true)"
        fail "Datenbankverbindung fehlgeschlagen"
        printf '\n    %s\n' "$err"
        case "$err" in
            *1698*)
                cat <<'HINT'

    Fehler 1698 heißt: der Account authentifiziert sich über den Unix-Socket.
    Über TCP kommt man damit nicht hinein. In api/config/database.local.php
    muss 'host' deshalb auf 'localhost' stehen – nicht auf 127.0.0.1, denn
    nur bei 'localhost' nutzt PHP den Socket.
HINT
                ;;
            *"Unknown database"*)
                cat <<HINT

    Die Datenbank existiert nicht. Anlegen und Schema einspielen:
      $MYSQL_BIN -e "CREATE DATABASE CacheCounty CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
      $MYSQL_BIN CacheCounty < database.sql
HINT
                ;;
            CONFIG_MISSING)
                printf '\n    api/config/database.local.php anlegen (Vorlage: database.php).\n'
                ;;
        esac
        printf '\n'
        exit 1
    fi

    # ── Mailpit ──
    if [ -n "$(port_pid "$MAILPIT_SMTP_PORT")" ]; then
        ok "Mailpit läuft – Oberfläche: http://localhost:$MAILPIT_UI_PORT"
    elif [ "$AUTOSTART_MAILPIT" = 1 ] && command -v "$MAILPIT_BIN" >/dev/null 2>&1; then
        info "Mailpit wird gestartet …"
        nohup "$MAILPIT_BIN" \
            --smtp "0.0.0.0:$MAILPIT_SMTP_PORT" \
            --listen "0.0.0.0:$MAILPIT_UI_PORT" \
            > "$RUN_DIR/mailpit.log" 2>&1 &
        wait_for_port "$MAILPIT_SMTP_PORT" 40 \
            && ok "Mailpit gestartet – Oberfläche: http://localhost:$MAILPIT_UI_PORT" \
            || fail "Mailpit startet nicht (siehe scripts/.run/mailpit.log)"
    else
        warn "Mailpit läuft nicht – Magic-Link-Mails können nicht zugestellt werden"
        command -v "$MAILPIT_BIN" >/dev/null 2>&1 || info "Installieren mit: brew install mailpit"
    fi
}


# ── PHP-Server ───────────────────────────────────────────────────────────────

start_php() {
    head1 "PHP-Server (Modus: $MODE)"

    local existing; existing="$(port_pid "$PORT")"
    if [ -n "$existing" ]; then
        local mine; mine="$(own_pid || true)"
        if [ -n "$mine" ] && [ "$mine" = "$existing" ]; then
            ok "läuft bereits auf Port $PORT (PID $existing)"
        else
            warn "Port $PORT ist bereits von PID $existing belegt – kein zweiter Server gestartet"
            info "Fremden Prozess beenden mit: kill $existing"
        fi
        printf '%s' "$PORT" > "$PORT_FILE"
        return 0
    fi

    if [ "$FOREGROUND" = 1 ]; then
        summary
        head1 "PHP läuft im Vordergrund – Strg+C beendet"
        exec "$PHP_BIN" -S "$BIND_HOST:$PORT" -t "$ROOT/public" "$ROOT/scripts/dev-router.php"
    fi

    # 'exec' ersetzt die Subshell durch PHP – sonst bliebe ein Bash-Prozess
    # als Elternteil stehen und $! zeigte auf ihn statt auf den Server.
    ( cd "$ROOT" && exec nohup "$PHP_BIN" -S "$BIND_HOST:$PORT" -t public scripts/dev-router.php \
        > "$LOG_FILE" 2>&1 ) &

    if wait_for_port "$PORT" 50; then
        # PID aus dem Port ableiten statt aus $!: zuverlässig unabhängig davon,
        # wie viele Prozesse zwischen Subshell, nohup und php liegen.
        local pid; pid="$(port_pid "$PORT")"
        printf '%s' "$pid"  > "$PID_FILE"
        printf '%s' "$PORT" > "$PORT_FILE"
        ok "gestartet auf $BIND_HOST:$PORT (PID $pid)"
        info "Log: scripts/.run/php.log"
    else
        fail "Start fehlgeschlagen – Log:"
        sed 's/^/      /' "$LOG_FILE" | tail -10
        rm -f "$PID_FILE"
        exit 1
    fi
}


# ── Abschluss ────────────────────────────────────────────────────────────────

summary() {
    head1 "Bereit"

    if [ "$MODE" = api ]; then
        printf '  %sAPI%s          http://localhost:%s/api/countries\n' "$C_BLUE" "$C_RESET" "$PORT"
        printf '  %sFrontend%s     VS Code Live Server starten → http://localhost:%s/app/\n' \
               "$C_BLUE" "$C_RESET" "$LIVE_SERVER_PORT"
        printf '  %sMailpit%s      http://localhost:%s\n' "$C_BLUE" "$C_RESET" "$MAILPIT_UI_PORT"
        printf '\n'
        info "Live Server proxyt /api auf Port $API_PORT – dieser Server bedient ihn."
        info "Achtung: /map/{username} kennt Live Server nicht, nur /app/."
    else
        printf '  %sApp%s          http://localhost:%s/\n'               "$C_BLUE" "$C_RESET" "$PORT"
        printf '  %sKarte%s        http://localhost:%s/map/%s\n'         "$C_BLUE" "$C_RESET" "$PORT" "$SAMPLE_USER"
        printf '  %sStatistik%s    http://localhost:%s/stats/%s\n'       "$C_BLUE" "$C_RESET" "$PORT" "$SAMPLE_USER"
        printf '  %sAdmin%s        http://localhost:%s/app/admin.html\n' "$C_BLUE" "$C_RESET" "$PORT"
        printf '  %sMailpit%s      http://localhost:%s\n'                "$C_BLUE" "$C_RESET" "$MAILPIT_UI_PORT"
    fi

    # Login-Hilfe: welche Adressen sind überhaupt registriert?
    local users; users="$(active_users || true)"
    if [ -n "$users" ]; then
        head1 "Login (Magic Link landet in Mailpit)"
        while IFS='|' read -r username email admin; do
            [ -z "$username" ] && continue
            printf '  %-14s %-34s %s\n' "$username" "$email" "$admin"
        done <<< "$users"
        printf '\n'
        info "Unbekannte Adressen melden trotzdem Erfolg (Schutz vor E-Mail-Enumeration)"
        info "– es kommt dann nur keine Mail an."
    fi

    printf '\n  %sBeenden:%s ./scripts/dev.sh stop\n\n' "$C_DIM" "$C_RESET"
}


# ── Ablauf ───────────────────────────────────────────────────────────────────

mkdir -p "$RUN_DIR"

printf '\n%s┌─ CacheCounty · Entwicklungsumgebung ─┐%s\n' "$C_BOLD" "$C_RESET"

case "$COMMAND" in
    stop)    do_stop; printf '\n' ;;
    status)  do_status; printf '\n' ;;
    restart) do_stop; preflight; [ "$SERVICES" = 1 ] && start_services; start_php; summary ;;
    start)
        preflight
        [ "$SERVICES" = 1 ] && start_services || info "Dienste übersprungen (--no-services)"
        start_php
        summary
        ;;
esac
