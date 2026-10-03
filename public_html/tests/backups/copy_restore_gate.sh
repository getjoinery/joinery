#!/bin/bash
# @joinery-test
# name: copy_restore
# tier: db
# env: dev-only
# needs: []
# timeout: 300
# covers: [maintenance_scripts/sysadmin_tools/restore_chain.sh, includes/SiteCensus.php]
#
# A copy onto new hardware, end to end on throwaway projects and databases
# (specs/site_copy.md WP2 and WP3): a chain of a source project, restored with
# --adopt-secret-key into a target project whose config has another
# secret_box_key and another database password.
#
#   * The source's database is the test database's schema and settings, with
#     every sealed setting sealed again under the source's own fixture key and a
#     key canary added: dev's real key is never read.
#   * After the restore: the canary and every sealed value open with the key in
#     the target's config; the target keeps its own database password; and the
#     census of the target equals the source's, exactly (files and tables by
#     SiteCensus, sealed values opened by each side's own config key).
#   * A second full apply, after an edit and a new file on the target, removes
#     both, and the census still matches.
#
# Run unprivileged: as root the restore would take the real site's lock.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
passed=0; failed=0
pass() { echo "  PASS: $1"; passed=$((passed+1)); }
fail() { echo "  FAIL: $1"; [ -n "${2:-}" ] && echo "        $2"; failed=$((failed+1)); }
chk() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "got '$2', want '$3'"; fi; }
finish() { echo; echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"; [ $failed -eq 0 ]; exit $?; }

if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged - as root the restore would take the real site's lock"; finish
fi
if [ -z "${PGPASSWORD:-}" ] && [ -f "$ROOT/config/Globalvars_site.php" ]; then
    PGPASSWORD=$(grep "dbpassword.*=" "$ROOT/config/Globalvars_site.php" | head -1 | sed "s/.*'\(.*\)'.*/\1/")
    export PGPASSWORD
fi
if ! psql -U postgres -XtAc "SELECT 1" >/dev/null 2>&1; then
    echo "  SKIP: no postgres connection available"; finish
fi
TEMPLATE_DB="$(php -r 'require $argv[1]; echo Globalvars::get_instance()->get_setting("dbname") . "";' "$ROOT/public_html/includes/PathHelper.php" 2>/dev/null)"
TEMPLATE_DB="test_${TEMPLATE_DB}"
if ! psql -U postgres -d "$TEMPLATE_DB" -XtAc "SELECT 1" >/dev/null 2>&1; then
    echo "  SKIP: no test database ($TEMPLATE_DB) to take a schema from"; finish
fi

PROJ="jt_copy_$$"            # the site's name on both machines, and the target's database
SRC_DB="jt_copy_src_$$"      # the source's database
W="$(mktemp -d /tmp/jy_copy_gate_XXXXXX)"
cleanup() {
    dropdb -U postgres --if-exists "$SRC_DB" >/dev/null 2>&1
    dropdb -U postgres --if-exists "$PROJ" >/dev/null 2>&1
    rm -rf "${W:?}"
}
trap cleanup EXIT
S="$W/src/$PROJ"; T="$W/dst/$PROJ"

# helper.php: the PHP half. Keys come from files and are never printed.
cat > "$W/helper.php" <<'PHP'
<?php
require $argv[1];
function box_from_config($cfg) {
    $g = new class { public $settings = array(); function load($f) { include $f; } };
    $g->load($cfg);
    $rc = new ReflectionClass('SecretBox');
    $box = $rc->newInstanceWithoutConstructor();
    $p = $rc->getProperty('key'); $p->setAccessible(true);
    $p->setValue($box, base64_decode($g->settings['secret_box_key'], true));
    return array($box, $g->settings);
}
function pdo_for($db) {
    return new PDO('pgsql:host=localhost;dbname=' . $db, 'postgres', getenv('PGPASSWORD'),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
}
// Every sealed setting (the canary among them) opened with a config's key, and the canary itself.
function sealed($box, PDO $pdo) {
    $ok = 0; $dead = 0;
    foreach ($pdo->query("SELECT stg_value FROM stg_settings WHERE stg_value ~ '^v1\\.(sodium|aesgcm)\\.'")
             ->fetchAll(PDO::FETCH_COLUMN) as $blob) {
        if ($box->open($blob)['state'] === SecretBox::OPEN_OK) { $ok++; } else { $dead++; }
    }
    $c = $pdo->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
    $c->execute(array(SecretBox::CANARY_SETTING));
    $r = $box->open((string)$c->fetchColumn());
    $canary = ($r['state'] === SecretBox::OPEN_OK && $r['value'] === SecretBox::CANARY_PLAINTEXT) ? 'ok' : 'dead';
    return array('ok' => $ok, 'dead' => $dead, 'canary' => $canary);
}
switch ($argv[2]) {
    case 'seal':     // CONFIG DB: seal every sealed setting again under CONFIG's key, and a canary
        list($box) = box_from_config($argv[3]); $pdo = pdo_for($argv[4]);
        $u = $pdo->prepare('UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?');
        $n = 0;
        foreach ($pdo->query("SELECT stg_name FROM stg_settings WHERE stg_value ~ '^v1\\.(sodium|aesgcm)\\.'")
                 ->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $u->execute(array($box->encrypt('fixture ' . $name), $name)); $n++;
        }
        $pdo->prepare('DELETE FROM stg_settings WHERE stg_name = ?')->execute(array(SecretBox::CANARY_SETTING));
        $pdo->prepare('INSERT INTO stg_settings (stg_name, stg_value) VALUES (?, ?)')
            ->execute(array(SecretBox::CANARY_SETTING, $box->encrypt(SecretBox::CANARY_PLAINTEXT)));
        echo $n + 1, "\n";
        break;
    case 'sealed':   // CONFIG DB
        list($box) = box_from_config($argv[3]);
        echo json_encode(sealed($box, pdo_for($argv[4]))), "\n";
        break;
    case 'setting':  // CONFIG NAME, never the key
        if ($argv[4] === 'secret_box_key') { exit(2); }
        list(, $s) = box_from_config($argv[3]);
        echo $s[$argv[4]] ?? '(unset)', "\n";
        break;
    case 'census':   // SRC_DIR SRC_DB SRC_CONFIG DST_DIR DST_DB DST_CONFIG: compare exactly
        $side = function ($dir, $db, $cfg) {
            list($box) = box_from_config($cfg); $pdo = pdo_for($db); $s = sealed($box, $pdo);
            return array('version' => SiteCensus::VERSION, 'tables' => SiteCensus::tables($pdo),
                'files' => SiteCensus::files($dir),
                'secrets' => array('canary' => $s['canary'], 'present' => $s['ok'] + $s['dead'], 'dead' => $s['dead']),
                'offloaded' => array('total' => 0, 'sampled' => 0, 'answered' => 0, 'missing' => array(), 'error' => ''));
        };
        $src = $side($argv[3], $argv[4], $argv[5]);
        $dst = $side($argv[6], $argv[7], $argv[8]);
        $r = SiteCensus::compare($src, $dst, true);
        echo ($r['match'] ? 'MATCH' : 'DIFFER') . ' tables=' . count($src['tables']) . ' '
            . json_encode($r['differences']), "\n";
        break;
    case 'same_key': // CONFIG_A CONFIG_B: yes when both carry one key
        list($a) = box_from_config($argv[3]); list($b) = box_from_config($argv[4]);
        $rc = new ReflectionProperty('SecretBox', 'key'); $rc->setAccessible(true);
        echo hash_equals($rc->getValue($a), $rc->getValue($b)) ? 'yes' : 'no', "\n";
        break;
}
PHP
H() { php "$W/helper.php" "$ROOT/public_html/includes/PathHelper.php" "$@"; }

# config FILE DBNAME DBPASS: a site config with a fresh key of its own.
config() {
    mkdir -p "$(dirname "$1")"
    php -r '$k = base64_encode(random_bytes(32));
        file_put_contents($argv[1], "<?php\n\$this->settings[\"dbname\"] = " . var_export($argv[2], true) . ";\n"
            . "\$this->settings[\"dbpassword\"] = " . var_export($argv[3], true) . ";\n"
            . "\$this->settings[\"secret_box_key\"] = " . var_export($k, true) . ";\n");' "$1" "$2" "$3"
    chmod 640 "$1"
}

echo "== The source: a project with its own key, sealed values and files =="
createdb -U postgres "$SRC_DB" && pg_dump -U postgres "$TEMPLATE_DB" | psql -U postgres -q -d "$SRC_DB" >/dev/null 2>&1
config "$S/config/Globalvars_site.php" "$SRC_DB" "source-db-password"
echo "relay identity" > "$S/config/relay_pull_key"
mkdir -p "$S/public_html/theme/x" "$S/uploads" "$S/storage/mail" "$S/static_files" "$S/logs"
echo '<?php echo 1;' > "$S/public_html/index.php"; echo 'body{}' > "$S/public_html/theme/x/style.css"
head -c 5000 /dev/urandom > "$S/uploads/photo.jpg"; echo 'From: x' > "$S/storage/mail/1.eml"
echo 'fast' > "$S/static_files/a.txt"; echo 'source log' > "$S/logs/error.log"
SEALED="$(H seal "$S/config/Globalvars_site.php" "$SRC_DB")"
if [ "${SEALED:-0}" -ge 2 ]; then pass "the source's sealed settings and canary are sealed under its own key ($SEALED)"; else fail "could not seal the source's values" "$SEALED"; fi
chk "every sealed value opens on the source" "$(H sealed "$S/config/Globalvars_site.php" "$SRC_DB")" "{\"ok\":$SEALED,\"dead\":0,\"canary\":\"ok\"}"

echo "== A chain of it =="
mkdir -p "$W/arts"
php "$TOOLS/backup_envelope.php" mint --artifact chain --key-out "$W/chain.key" --sidecar-out "$W/envelope.json" >/dev/null 2>&1
out=$(bash "$TOOLS/backup_files.sh" site --project-dir "$S" --output-dir "$W/arts" --name files-0000 \
        --snar "$W/chain.snar" --key-file "$W/chain.key" 2>/dev/null)
F=$(echo "$out" | sed -n 's/^ARCHIVE=//p'); FB=$(echo "$out" | sed -n 's/^BYTES=//p'); FH=$(echo "$out" | sed -n 's/^SHA256=//p')
( cd "$W/arts" && bash "$TOOLS/backup_database.sh" --non-interactive --key-file "$W/chain.key" "$SRC_DB" >/dev/null 2>&1 )
D=$(ls "$W/arts/$SRC_DB"-*.sql.gz.enc 2>/dev/null | head -1)
if [ -n "$F" ] && [ -f "$F" ] && [ -n "$D" ]; then pass "the files archive and the database dump are made"; else fail "the chain could not be made" "$F / $D"; finish; fi
cat > "$W/arts/manifest.json" <<JSON
{"version": 1, "chain_id": "chain-20260928_120000", "slug": "$PROJ",
 "runs": [{"seq": 0, "level": 0, "time": "2026-09-28T12:00:00Z",
           "artifacts": {"files": {"name": "$(basename "$F")", "bytes": $FB, "sha256": "$FH"},
                         "db": {"name": "$(basename "$D")", "bytes": $(stat -c %s "$D"), "sha256": "$(sha256sum "$D" | cut -d' ' -f1)"}}}]}
JSON

# The target: its own config (another key, another password), its own files.
config "$T/config/Globalvars_site.php" "$PROJ" "target-db-password"
echo "target site key" > "$T/config/backup_site_key"
echo "only on the target" > "$T/target_only.txt"
chk "before the restore, the two configs carry different keys" "$(H same_key "$S/config/Globalvars_site.php" "$T/config/Globalvars_site.php")" "no"

restore() {
    bash "$TOOLS/restore_chain.sh" "$PROJ" --target-dir "$T" --artifacts "$W/arts" --key-file "$W/chain.key" \
        --force --adopt-secret-key --skip-ssl > "$W/log" 2>&1
}

echo "== Restored onto the target with --adopt-secret-key =="
if restore; then pass "the restore succeeds"; else fail "the restore failed" "$(grep -E 'ERROR|✗' "$W/log" | head -5)"; finish; fi
chk "the target's config now carries the source's key" "$(H same_key "$S/config/Globalvars_site.php" "$T/config/Globalvars_site.php")" "yes"
chk "the canary and every sealed value open with the target's own config" \
    "$(H sealed "$T/config/Globalvars_site.php" "$PROJ")" "{\"ok\":$SEALED,\"dead\":0,\"canary\":\"ok\"}"
chk "the target keeps its own database password" "$(H setting "$T/config/Globalvars_site.php" dbpassword)" "target-db-password"
chk "and its own database name" "$(H setting "$T/config/Globalvars_site.php" dbname)" "$PROJ"
chk "and its own backup_site_key" "$(cat "$T/config/backup_site_key")" "target site key"
C="$(H census "$S" "$SRC_DB" "$S/config/Globalvars_site.php" "$T" "$PROJ" "$T/config/Globalvars_site.php")"
case "$C" in MATCH*) pass "the census of the target equals the source's, exactly (${C%% \[*})" ;; *) fail "the census differs" "$C" ;; esac
if grep -q 'secret_box_key' "$W/log" && ! grep -qE "[A-Za-z0-9+/]{43}=" "$W/log"; then
    pass "the restore says what it did with the key, and prints none"
else
    fail "the restore log names no key step, or prints something shaped like a key" "$(grep -n 'secret_box_key' "$W/log" | head -3)"
fi

echo "== A second full apply discards what changed on the target =="
echo "edited on the target" > "$T/public_html/index.php"
echo "new on the target" > "$T/uploads/new.jpg"
psql -U postgres -d "$PROJ" -q -c "INSERT INTO stg_settings (stg_name, stg_value) VALUES ('copy_gate_probe_$$', 'x')" >/dev/null 2>&1
C="$(H census "$S" "$SRC_DB" "$S/config/Globalvars_site.php" "$T" "$PROJ" "$T/config/Globalvars_site.php")"
case "$C" in DIFFER*) pass "the census sees the edit, the new file and the new row" ;; *) fail "the census missed a change on the target" "$C" ;; esac
if restore; then pass "the second apply succeeds"; else fail "the second apply failed" "$(grep -E 'ERROR|✗' "$W/log" | head -5)"; fi
chk "the edit is gone" "$(cat "$T/public_html/index.php")" '<?php echo 1;'
chk "the new file is gone" "$([ -e "$T/uploads/new.jpg" ] && echo present || echo gone)" "gone"
chk "the new row is gone" "$(psql -U postgres -d "$PROJ" -XtAc "SELECT count(*) FROM stg_settings WHERE stg_name = 'copy_gate_probe_$$'")" "0"
C="$(H census "$S" "$SRC_DB" "$S/config/Globalvars_site.php" "$T" "$PROJ" "$T/config/Globalvars_site.php")"
case "$C" in MATCH*) pass "and the census matches again" ;; *) fail "the census differs after the second apply" "$C" ;; esac
chk "the key is still the source's" "$(H same_key "$S/config/Globalvars_site.php" "$T/config/Globalvars_site.php")" "yes"

finish
