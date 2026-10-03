#!/bin/bash
# @joinery-test
# name: restore_adopt_key
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/sysadmin_tools/restore_chain.sh]
#
# restore_chain.sh --adopt-secret-key (specs/site_copy.md WP2, B21). A copy onto
# new hardware keeps this machine's own config/Globalvars_site.php and takes
# only the chain's secret_box_key into it, so every secret the source sealed
# opens here while the database password, the paths and the rest stay this
# machine's.
#
# Real tar and openssl on fixture chains, files only (--skip-database), into a
# scratch --target-dir. The config is read back the way Globalvars reads it: by
# including it inside an object, never by pattern.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
BACKUP="$TOOLS/backup_files.sh"
RESTORE="$TOOLS/restore_chain.sh"
ENVTOOL="$TOOLS/backup_envelope.php"
passed=0; failed=0
pass() { echo "  PASS: $1"; passed=$((passed+1)); }
fail() { echo "  FAIL: $1"; [ -n "${2:-}" ] && echo "        $2"; failed=$((failed+1)); }
chk() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "got '$2', want '$3'"; fi; }

if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged - as root the restore would take the real site's lock"
    echo; echo "RESULT: PASS 0 0"; exit 0
fi

W="$(mktemp -d /tmp/jy_adopt_gate_XXXXXX)"
trap 'rm -rf "${W:?}"' EXIT

php "$ENVTOOL" mint --artifact chain --key-out "$W/chain.key" --sidecar-out "$W/envelope.json" >/dev/null 2>&1
[ -s "$W/chain.key" ] || { fail "could not mint a chain key"; echo "RESULT: FAIL $passed $failed"; exit 1; }

key32() { head -c 32 /dev/urandom | base64 -w0; }
K_S="$(key32)"; K_T="$(key32)"; K_BAD="$(head -c 16 /dev/urandom | base64 -w0)"

# config FILE DBPASS KEY [closing]: a site config as the installer writes it
# (var_export literals). An empty KEY writes no key line.
config() {
    mkdir -p "$(dirname "$1")"
    {
        echo '<?php'
        echo "\$this->settings['dbname'] = 'site';"
        echo "\$this->settings['dbpassword'] = '$2';"
        echo "\$this->settings['webDir'] = 'copy.example.invalid';"
        [ -n "$3" ] && echo "\$this->settings['secret_box_key'] = '$3';"
        [ "${4:-}" = closing ] && echo '?>'
    } > "$1"
}

# chain NAME KEY: a one-run chain of a site whose config carries KEY ("" none,
# "-" no config file at all), in $W/NAME.
chain() {
    local src="$W/src_$1/site" arts="$W/$1"
    mkdir -p "$src/public_html" "$arts"
    echo "from the source" > "$src/public_html/page.txt"
    [ "$2" != "-" ] && config "$src/config/Globalvars_site.php" "source-db-password" "$2"
    local out f b h
    out=$(bash "$BACKUP" site --project-dir "$src" --output-dir "$arts" --name files-0000 \
            --snar "$W/$1.snar" --key-file "$W/chain.key" 2>/dev/null)
    f=$(echo "$out" | sed -n 's/^ARCHIVE=//p'); b=$(echo "$out" | sed -n 's/^BYTES=//p'); h=$(echo "$out" | sed -n 's/^SHA256=//p')
    cat > "$arts/manifest.json" <<JSON
{"version": 1, "chain_id": "chain-20260927_120000", "slug": "site",
 "runs": [{"seq": 0, "level": 0, "time": "2026-09-27T12:00:00Z",
           "artifacts": {"files": {"name": "$(basename "$f")", "bytes": $b, "sha256": "$h"}}}]}
JSON
}

# target KEY [closing]: this machine's own site at $W/out/site, with its own
# config (KEY "-": none), its own backup_site_key and a file of its own.
target() {
    rm -rf "${W:?}/out"; mkdir -p "$W/out/site/config"
    [ "$1" != "-" ] && { config "$W/out/site/config/Globalvars_site.php" "target-db-password" "$1" "${2:-}"; chmod 640 "$W/out/site/config/Globalvars_site.php"; }
    echo "target site key" > "$W/out/site/config/backup_site_key"
    echo "only on the target" > "$W/out/site/target_only.txt"
}

restore() {  # restore ARTS [flags...]
    local arts="$1"; shift
    bash "$RESTORE" site --target-dir "$W/out/site" --artifacts "$W/$arts" --key-file "$W/chain.key" \
        --force --skip-database "$@" > "$W/log" 2>&1
}

# setting NAME: read the restored config the way Globalvars does.
setting() {
    php -r 'class G { public $settings = array(); function load($f) { include $f; } }
            $g = new G; $g->load($argv[1]); echo $g->settings[$argv[2]] ?? "(unset)";' \
        "$W/out/site/config/Globalvars_site.php" "$1" 2>/dev/null
}

chain good "$K_S"
chain nokey ""
chain badkey "$K_BAD"
chain noconfig "-"

echo "== The chain's key, this machine's everything else =="
target "$K_T"
if restore good --adopt-secret-key; then pass "a restore with --adopt-secret-key succeeds"; else fail "the restore failed" "$(tail -5 "$W/log")"; fi
chk "secret_box_key is the source's, so what it sealed opens here" "$(setting secret_box_key)" "$K_S"
chk "the database password is this machine's" "$(setting dbpassword)" "target-db-password"
chk "the domain is this machine's config's" "$(setting webDir)" "copy.example.invalid"
if php -l "$W/out/site/config/Globalvars_site.php" >/dev/null 2>&1; then pass "the config parses"; else fail "the config does not parse"; fi
chk "the config keeps its mode" "$(stat -c %a "$W/out/site/config/Globalvars_site.php")" "640"
chk "backup_site_key is this machine's" "$(cat "$W/out/site/config/backup_site_key")" "target site key"
chk "the source's files arrived" "$(cat "$W/out/site/public_html/page.txt" 2>/dev/null)" "from the source"
chk "nothing is left beside the config" "$(ls "$W/out/site/config" | grep -c adopt)" "0"
if grep -qF -- "$K_S" "$W/log" || grep -qF -- "$K_T" "$W/log"; then fail "a key was printed"; else pass "no key is printed"; fi

echo "== Without the flag, this machine's key stays (the in-place restore) =="
target "$K_T"
restore good
chk "secret_box_key is this machine's own" "$(setting secret_box_key)" "$K_T"

echo "== Adopting a key it already has changes nothing =="
target "$K_S"
before="$(sha256sum < "$W/out/site/config/Globalvars_site.php")"
restore good --adopt-secret-key
chk "the config is byte for byte unchanged" "$(sha256sum < "$W/out/site/config/Globalvars_site.php")" "$before"
if grep -q 'already the chain' "$W/log"; then pass "and says so"; else fail "no word that the key was already there" "$(tail -3 "$W/log")"; fi

echo "== A config with no key line, and a closing tag, gets one before the tag =="
target "" closing
if restore good --adopt-secret-key; then pass "the restore succeeds"; else fail "the restore failed" "$(tail -5 "$W/log")"; fi
chk "secret_box_key is the source's" "$(setting secret_box_key)" "$K_S"
chk "the closing tag is still the last line" "$(tail -1 "$W/out/site/config/Globalvars_site.php")" "?>"
chk "the database password is this machine's" "$(setting dbpassword)" "target-db-password"

echo "== Refusals =="
for c in nokey:"names no secret_box_key" badkey:"not 32 base64-encoded bytes" noconfig:"carries no config/Globalvars_site.php"; do
    name="${c%%:*}"; why="${c#*:}"
    target "$K_T"
    if restore "$name" --adopt-secret-key; then
        fail "$name: the restore succeeded"
    else
        pass "$name: the restore fails"
    fi
    if grep -q "$why" "$W/log"; then pass "$name: and says $why"; else fail "$name: the refusal does not say why" "$(grep ERROR "$W/log" | head -2)"; fi
    chk "$name: this machine's key and password are left as they were" "$(setting secret_box_key)/$(setting dbpassword)" "$K_T/target-db-password"
done

target "-"
if restore good --adopt-secret-key; then fail "a machine with no config of its own was restored onto"; else pass "a machine with no config of its own is refused"; fi
if grep -q 'nothing was restored' "$W/log"; then pass "and says nothing was restored"; else fail "the refusal does not say nothing was restored" "$(grep ERROR "$W/log" | head -2)"; fi
chk "before any write: its own file is still there, the source's is not" \
    "$(cat "$W/out/site/target_only.txt" 2>/dev/null)|$([ -e "$W/out/site/public_html/page.txt" ] && echo arrived || echo absent)" \
    "only on the target|absent"

echo "== --skip-ssl reaches the reconcile =="
if grep -q 'RECONCILE_ARGS+=(--skip-ssl)' "$RESTORE" && grep -q -- '--skip-ssl)       SKIP_SSL=true' "$RESTORE"; then
    pass "--skip-ssl is passed to reconcile_site.sh"
else
    fail "--skip-ssl is not passed to reconcile_site.sh"
fi

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
