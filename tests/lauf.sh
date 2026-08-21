#!/usr/bin/env bash
#
# Testreihe fuer «Blitz & Donner Formular».
#
#   ./tests/lauf.sh                 alle vier Umgebungen (alt, aktuell, kommend, umstieg)
#   ./tests/lauf.sh aktuell         nur die aktuelle WordPress-Fassung
#   ./tests/lauf.sh alt kommend     Auswahl
#   ./tests/lauf.sh --behalten      Container nach dem Lauf stehen lassen
#   ./tests/lauf.sh --echte-mails   am Schluss echte Mails ueber Hostpoint verschicken
#   ./tests/lauf.sh --ohne-browser  nur die Pruefungen ohne Browser
#
set -euo pipefail

HIER="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
WURZEL="$( dirname "$HIER" )"
export PLUGIN_DIR="$WURZEL"
export TESTS_DIR="$HIER"

BEHALTEN=0
ECHTE_MAILS=0
UMGEBUNGEN=()

for arg in "$@"; do
    case "$arg" in
        --behalten)    BEHALTEN=1 ;;
        --echte-mails) ECHTE_MAILS=1 ;;
        --ohne-browser) export GFB_OHNE_BROWSER=1 ;;
        --*)           echo "Unbekannte Option: $arg" >&2; exit 2 ;;
        *)             UMGEBUNGEN+=("$arg") ;;
    esac
done

if [[ ${#UMGEBUNGEN[@]} -eq 0 ]]; then
    UMGEBUNGEN=(alt aktuell kommend umstieg)
fi

# Kennung -> Abbild : WP-Port : Mailpit-Port
abbild_fuer() {
    case "$1" in
        alt)     echo "wordpress:6.6-php8.1-apache:8661:8761" ;;
        aktuell) echo "wordpress:7.1-php8.3-apache:8662:8762" ;;
        kommend) echo "wordpress:beta:8663:8763" ;;
        umstieg) echo "wordpress:7.1-php8.3-apache:8665:8765" ;;
        *)       echo "wordpress:$1:8664:8764" ;;
    esac
}

# Hauptschluessel fuer die Verschluesselung, frisch pro Lauf.
export GFB_MASTER_KEYS="1:$( openssl rand -base64 32 )"
export GFB_ACTIVE_KEY_ID=1

# Zwei Pakete fuer den Umstiegstest: die letzte Freigabe und der Arbeitsstand.
PAKETE="$( mktemp -d )"
export PAKETE_DIR="$PAKETE"
JETZIGE="$( grep -m1 '^ \* Version:' "$WURZEL/gutenberg-formbuilder.php" | sed 's/.*Version: *//' )"
VORVERSION="$( git -C "$WURZEL" tag --list 'v*' --sort=-v:refname \
    | grep -v "^v${JETZIGE}$" | head -1 )"
if [[ -z "$VORVERSION" ]]; then
    VORVERSION="$( git -C "$WURZEL" log --format=%H -n 1 --skip=1 -- gutenberg-formbuilder.php )"
fi
if ! git -C "$WURZEL" archive --format=zip --prefix=gutenberg-formbuilder/ \
        -o "$PAKETE/vorversion.zip" "$VORVERSION"; then
    echo "Warnung: Vorversion $VORVERSION liess sich nicht auspacken." >&2
fi
BAU="$( mktemp -d )"
rsync -a --exclude '.git' --exclude 'tests' --exclude 'graphify-out' --exclude 'node_modules' \
    "$WURZEL"/ "$BAU/gutenberg-formbuilder/"
( cd "$BAU" && zip -rq "$PAKETE/arbeitsstand.zip" gutenberg-formbuilder )
rm -rf "$BAU"
echo "▸ Pakete: Vorversion $VORVERSION, Arbeitsstand"

ZEITSTEMPEL="$( date +%Y-%m-%d_%H%M )"
AUSGABE="$HIER/berichte/$ZEITSTEMPEL"
mkdir -p "$AUSGABE"
export GFB_AUSGABE="$AUSGABE"

if [[ $ECHTE_MAILS -eq 1 ]]; then
    export GFB_SMTP_HOST="asmtp.mail.hostpoint.ch"
    export GFB_SMTP_USER="sgi@blitzdonner.ch"
    GFB_SMTP_PASS="$( security find-generic-password -s bd-mail-mcp -a sgi@blitzdonner.ch -w 2>/dev/null || true )"
    export GFB_SMTP_PASS
    if [[ -z "${GFB_SMTP_PASS}" ]]; then
        echo "Kein Postfachpasswort im Schluesselbund gefunden – echte Mails werden uebersprungen." >&2
        ECHTE_MAILS=0
    fi
fi
export GFB_ECHTE_MAILS="$ECHTE_MAILS"

aufraeumen() {
    if [[ $BEHALTEN -eq 1 ]]; then
        # Die Pakete bleiben liegen, solange die Container sie eingehängt haben.
        echo "Container bleiben stehen (--behalten). Pakete: $PAKETE"
        return
    fi
    rm -rf "$PAKETE" 2>/dev/null || true
    for kennung in "${UMGEBUNGEN[@]}"; do
        docker compose -p "gfbtest-$kennung" -f "$HIER/docker/compose.yml" down -v --remove-orphans >/dev/null 2>&1 || true
    done
}
trap aufraeumen EXIT

starte_umgebung() {
    local kennung="$1" spez abbild wp_port mp_port
    spez="$( abbild_fuer "$kennung" )"
    mp_port="${spez##*:}"; spez="${spez%:*}"
    wp_port="${spez##*:}"; abbild="${spez%:*}"

    echo "▸ $kennung – $abbild auf Port $wp_port"
    local plugin_dir="$PLUGIN_DIR" mount_ziel="/var/www/html/wp-content/plugins/gutenberg-formbuilder"
    if [[ "$kennung" == "umstieg" ]]; then
        # Kein Mount im Plugin-Ordner: WordPress muss ihn loeschen koennen,
        # sonst scheitert jedes Einspielen einer anderen Version.
        mount_ziel="/gfb-unbenutzt"
    fi
    WP_IMAGE="$abbild" WP_PORT="$wp_port" MAILPIT_PORT="$mp_port" \
        PLUGIN_DIR="$plugin_dir" PLUGIN_ZIEL="$mount_ziel" \
        docker compose -p "gfbtest-$kennung" -f "$HIER/docker/compose.yml" up -d --quiet-pull >/dev/null

    echo "$kennung|$abbild|$wp_port|$mp_port"
}

ZEILEN=()
for kennung in "${UMGEBUNGEN[@]}"; do
    ZEILEN+=( "$( starte_umgebung "$kennung" | tail -1 )" )
done

printf '%s\n' "${ZEILEN[@]}" > "$AUSGABE/umgebungen.txt"

if [[ ! -d "$HIER/node_modules" ]]; then
    echo "▸ Playwright fehlt, wird einmalig eingerichtet …"
    ( cd "$HIER" && npm install --silent && npx playwright install chromium >/dev/null )
fi

node "$HIER/laeufer/index.mjs" "$AUSGABE/umgebungen.txt"
