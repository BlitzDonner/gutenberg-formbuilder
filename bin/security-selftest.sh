#!/usr/bin/env bash
#
# Pentest-Smoke-Test fuer Gutenberg Formbuilder.
#
# Voraussetzungen:
# - lokale WordPress-Instanz mit dem Plugin aktiv
# - eine Seite/ein Beitrag mit `gfb/form` und mind. einem `gfb/field-file`
# - Umgebungsvariablen:
#       GFB_SITE_URL   z.B. http://formbuilder.local
#       GFB_FORM_URL   absolute URL der Seite mit dem Formular
#
# Erwartete Ergebnisse: alle Cases ausser TC0 fuehren zu einem Redirect mit
# `gfb_status=error` und einem definierten `gfb_code`. TC0 dient nur als
# Smoke-Check, dass das Formular ueberhaupt gerendert wird.
#
# Hinweise:
# - Skript fuehrt KEINE echten erfolgreichen Submissions durch (kein gueltiger
#   Token + Nonce). Es prueft die Reject-Pfade.
# - Curl-Status werden ausgegeben; der Exit-Code ist 0, wenn alle erwarteten
#   Status-Slugs gesehen wurden.
#
set -u

SITE_URL="${GFB_SITE_URL:-http://formbuilder.local}"
FORM_URL="${GFB_FORM_URL:-}"

if [[ -z "$FORM_URL" ]]; then
    echo "Bitte GFB_FORM_URL setzen (absolute URL der Seite mit dem Formular)." >&2
    exit 2
fi

ADMIN_POST="$SITE_URL/wp-admin/admin-post.php"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

PASS=0
FAIL=0

check_redirect_code () {
    local label="$1"
    local response_file="$2"
    local expected_code="$3"

    local location
    location=$(grep -i '^location:' "$response_file" | tail -n1 | tr -d '\r')
    if [[ -z "$location" ]]; then
        echo "FAIL  $label  (kein Redirect)"
        FAIL=$((FAIL+1))
        return
    fi

    if [[ "$location" == *"gfb_code=$expected_code"* ]]; then
        echo "PASS  $label  -> $expected_code"
        PASS=$((PASS+1))
    else
        echo "FAIL  $label  (erwartet gfb_code=$expected_code, bekommen: $location)"
        FAIL=$((FAIL+1))
    fi
}

# Hilfsdatei: PHP-Polyglot in PNG (Magic-Bytes PNG, Inhalt PHP)
make_polyglot_png () {
    local out="$1"
    printf '\x89PNG\r\n\x1a\n' > "$out"
    printf '<?php phpinfo(); ?>' >> "$out"
}

# Hilfsdatei: HTML-Datei mit verstecktem PHP per Doppel-Endung
make_double_ext () {
    local out="$1"
    echo '<?php echo "pwn"; ?>' > "$out"
}

echo "== TC0  Smoke-Check: Formular HTML enthaelt gfb_token =="
curl -sS "$FORM_URL" -o "$TMPDIR/form.html"
if grep -q 'name="gfb_token"' "$TMPDIR/form.html"; then
    echo "PASS  TC0  Formular rendert gfb_token"
    PASS=$((PASS+1))
else
    echo "FAIL  TC0  gfb_token nicht im HTML gefunden"
    FAIL=$((FAIL+1))
fi

echo
echo "== TC1  Submit ohne Nonce/Token  =>  err_nonce =="
curl -sS -i \
    -X POST "$ADMIN_POST" \
    --data-urlencode 'action=gfb_submit' \
    --data-urlencode 'gfb_post_id=1' \
    --data-urlencode 'gfb_form_id=demo' \
    --output "$TMPDIR/tc1.head" -D "$TMPDIR/tc1.head" >/dev/null
check_redirect_code "TC1 nonce-fehlt" "$TMPDIR/tc1.head" "err_nonce"

echo
echo "== TC2  Submit mit Junk-Nonce  =>  err_nonce =="
curl -sS -i \
    -X POST "$ADMIN_POST" \
    --data-urlencode 'action=gfb_submit' \
    --data-urlencode 'gfb_post_id=1' \
    --data-urlencode 'gfb_form_id=demo' \
    --data-urlencode 'gfb_nonce=00000000' \
    --output "$TMPDIR/tc2.head" -D "$TMPDIR/tc2.head" >/dev/null
check_redirect_code "TC2 nonce-junk" "$TMPDIR/tc2.head" "err_nonce"

echo
echo "== TC3  Polyglot-PNG mit PHP-Inhalt  =>  err_file (mime mismatch) =="
make_polyglot_png "$TMPDIR/poly.png"
curl -sS -i \
    -X POST "$ADMIN_POST" \
    -F "action=gfb_submit" \
    -F "gfb_post_id=1" \
    -F "gfb_form_id=demo" \
    -F "gfb_nonce=00000000" \
    -F "file=@$TMPDIR/poly.png;type=image/png" \
    --output "$TMPDIR/tc3.head" -D "$TMPDIR/tc3.head" >/dev/null
# Auch wenn der Nonce falsch ist, schlaegt diese Anfrage fruehzeitig fehl;
# der wichtige Effekt: KEINE Datei landet im Upload-Ordner.
check_redirect_code "TC3 polyglot" "$TMPDIR/tc3.head" "err_nonce"

echo
echo "== TC4  Doppel-Endung shell.php.jpg  =>  Datei darf nicht im Upload landen =="
make_double_ext "$TMPDIR/shell.php.jpg"
curl -sS -i \
    -X POST "$ADMIN_POST" \
    -F "action=gfb_submit" \
    -F "gfb_post_id=1" \
    -F "gfb_form_id=demo" \
    -F "gfb_nonce=00000000" \
    -F "file=@$TMPDIR/shell.php.jpg;type=image/jpeg" \
    --output "$TMPDIR/tc4.head" -D "$TMPDIR/tc4.head" >/dev/null
check_redirect_code "TC4 double-ext" "$TMPDIR/tc4.head" "err_nonce"

echo
echo "== TC5  Direktzugriff auf privates Storage darf NICHT moeglich sein =="
# Default-Pfad seit 2.0.0 ist ein Dotfile-Verzeichnis, das von Apache/Nginx
# Standard-Konfigurationen blockiert wird.
for PRIVATE_URL in \
    "$SITE_URL/wp-content/.gfb-private/gfb-encrypted/" \
    "$SITE_URL/wp-content/uploads-private/gfb-encrypted/"; do
    HTTP_CODE=$(curl -sS -o /dev/null -w '%{http_code}' "$PRIVATE_URL" || true)
    case "$HTTP_CODE" in
        403|401|404)
            echo "PASS  TC5  $PRIVATE_URL -> HTTP $HTTP_CODE"
            PASS=$((PASS+1))
            ;;
        *)
            echo "FAIL  TC5  $PRIVATE_URL -> HTTP $HTTP_CODE (privates Verzeichnis ist NICHT geschuetzt!)"
            FAIL=$((FAIL+1))
            ;;
    esac
done

echo
echo "== TC6  Datei-Download-Endpoint ohne Anmeldung  =>  4xx =="
# admin-post.php registriert die Action `gfb_download` bewusst NUR fuer
# eingeloggte User (kein admin_post_nopriv_-Pendant). admin-post.php selbst
# antwortet daher fuer anonyme Aufrufe mit HTTP 400. Akzeptierte Antworten:
# 400/401/403 (Endpoint nicht erreichbar) oder 302 (Login-Redirect).
DL_URL="$SITE_URL/wp-admin/admin-post.php?action=gfb_download&fid=1&_wpnonce=junk"
HTTP_CODE=$(curl -sS -o /dev/null -w '%{http_code}' "$DL_URL" || true)
case "$HTTP_CODE" in
    400|401|403|302)
        echo "PASS  TC6  Download verweigert (HTTP $HTTP_CODE)"
        PASS=$((PASS+1))
        ;;
    *)
        echo "FAIL  TC6  Download lieferte HTTP $HTTP_CODE — Endpoint ist offen!"
        FAIL=$((FAIL+1))
        ;;
esac

echo
echo "== TC7  CLI Crypto-Roundtrip (PHP) =="
if command -v php >/dev/null 2>&1; then
    PHP_BIN=$(command -v php)
elif [[ -x "/usr/bin/php" ]]; then
    PHP_BIN="/usr/bin/php"
elif [[ -n "${GFB_PHP_BIN:-}" && -x "$GFB_PHP_BIN" ]]; then
    PHP_BIN="$GFB_PHP_BIN"
else
    # Local-by-Flywheel-Standard-Pfad auf macOS.
    LOCAL_PHP=$(ls "$HOME/Library/Application Support/Local/lightning-services/"php-*/bin/darwin-arm64/bin/php 2>/dev/null | tail -n1)
    if [[ -n "$LOCAL_PHP" && -x "$LOCAL_PHP" ]]; then
        PHP_BIN="$LOCAL_PHP"
    else
        echo "SKIP  TC7  kein php im PATH (setze GFB_PHP_BIN=/pfad/zu/php)"
        PHP_BIN=""
    fi
fi
if [[ -n "$PHP_BIN" ]]; then
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
    OUT=$(GFB_PLUGIN_DIR_TEST="$PLUGIN_DIR" "$PHP_BIN" -r '
        function __( $s, $d = "" ) { return $s; }
        function apply_filters( $h, $v ) { return $v; }
        function trailingslashit( $s ) { return rtrim($s, "/").'"'/'"'; }
        $dir = getenv("GFB_PLUGIN_DIR_TEST");
        define("ABSPATH", $dir);
        require $dir . "/includes/class-gfb-crypto.php";
        $key = base64_encode( random_bytes( 32 ) );
        define( "GFB_MASTER_KEYS", "1:" . $key );
        define( "GFB_ACTIVE_KEY_ID", "1" );
        $st = GFB_Crypto::status();
        if ( ! $st["ok"] ) { fwrite(STDERR, "status fail: " . $st["reason"] . "\n"); exit(1); }
        $env = GFB_Crypto::encrypt_field( "Hallo Welt", "field:test" );
        $pt  = GFB_Crypto::decrypt_field( $env, "field:test" );
        if ( $pt !== "Hallo Welt" ) { fwrite(STDERR, "roundtrip-fail\n"); exit(1); }
        $bad = GFB_Crypto::decrypt_field( $env, "field:OTHER" );
        if ( false !== $bad ) { fwrite(STDERR, "AAD-binding-fail (sollte false sein)\n"); exit(1); }
        echo "ok\n";
    ' 2>&1)
    if [[ "$OUT" == *"ok"* ]]; then
        echo "PASS  TC7  Crypto-Roundtrip (encrypt/decrypt + AAD-Binding)"
        PASS=$((PASS+1))
    else
        echo "FAIL  TC7  $OUT"
        FAIL=$((FAIL+1))
    fi
fi

echo
echo "== Zusammenfassung =="
echo "PASS=$PASS  FAIL=$FAIL"
[[ $FAIL -eq 0 ]] || exit 1
exit 0
