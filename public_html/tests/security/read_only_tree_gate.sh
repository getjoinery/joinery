#!/bin/bash
# @joinery-test
# name: read_only_tree
# tier: deploy
# env: any
# needs: [host-converger]
# timeout: 180
#
# The read-only tree from the outside (specs/read_only_tree.md).
#
# One rule, both directions: every file the PHP pool executes or includes
# belongs to the tree's owner and is not writable by the pool, and every file
# the pool writes is data that nothing ever executes. This gate asks the
# machine, as the web user, whether that is actually true here — the unit tests
# read the code, and code that says the right thing is not the same as a box
# that is in the right state.
#
# tier: deploy because it must run as root (it drops to www-data to try the
# writes) and against a real installed site, not a fixture.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"     # public_html
SITE="$(dirname "$ROOT")"
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# Run one command as the web user. Everything this gate claims is a claim about
# what the POOL can do, so it has to be asked as the pool.
as_web() { setpriv --reuid=www-data --regid=www-data --clear-groups "$@" 2>/dev/null; }

if [ "$(id -u)" != "0" ]; then
    echo "  SKIP: this gate drops privileges to www-data and so must run as root"
    exit 0
fi

TREE_OWNER="$(stat -c %U "$ROOT")"

echo "== the pool cannot write anything it executes =="

# Not just the top of each directory: drift INSIDE the executable set is the
# whole reason for a gate. The ownership assertion keys on the directory, so a
# single stranger's file deeper in is exactly what it would not notice.
for d in "$ROOT" "$SITE/maintenance_scripts" "$SITE/vendor"; do
    [ -d "$d" ] || continue
    label="${d#$SITE/}"
    stray="$(find "$d" -path '*/.git' -prune -o \( -type f -o -type d \) \
        \( -user www-data -o -perm -g+w -o -perm -o+w \) -print 2>/dev/null | head -5)"
    chk "nothing under ${label} is www-data-owned or group/other-writable" \
        "$(test -z "$stray" && echo clean || echo "$stray")" "clean"
done

chk "the site directory itself is not the pool's" \
    "$(test "$(stat -c %U "$SITE")" != "www-data" && echo yes || echo no)" "yes"

for f in RELEASE_MANIFEST RELEASE_MANIFEST.sig; do
    [ -f "$SITE/$f" ] || continue
    chk "$f is not writable by the pool" \
        "$(as_web test -w "$SITE/$f" && echo writable || echo refused)" "refused"
done

probe="$ROOT/.s10_probe_$$"
as_web touch "$probe"
chk "the pool cannot create a file in public_html" \
    "$(test -e "$probe" && echo wrote || echo refused)" "refused"
rm -f "$probe"

probe="$ROOT/plugins/.s10_probe_$$"
as_web touch "$probe"
chk "nor in plugins/" "$(test -e "$probe" && echo wrote || echo refused)" "refused"
rm -f "$probe"

echo "== the config the pool includes is read-only to it =="

CFG="$SITE/config/Globalvars_site.php"
if [ -f "$CFG" ]; then
    chk "Globalvars_site.php is root:www-data" "$(stat -c '%U:%G' "$CFG")" "root:www-data"
    chk "and 0640" "$(stat -c %a "$CFG")" "640"
    chk "the pool can read it" "$(as_web test -r "$CFG" && echo yes || echo no)" "yes"
    chk "and cannot write it" "$(as_web test -w "$CFG" && echo writable || echo refused)" "refused"
    # Directory write is enough to unlink the file and leave a different one.
    probe="$SITE/config/.s10_probe_$$"
    as_web touch "$probe"
    chk "the pool cannot create a file in config/" \
        "$(test -e "$probe" && echo wrote || echo refused)" "refused"
    rm -f "$probe"
fi

echo "== the class map is data =="

chk "cache/class_map.php does not exist" \
    "$(test -e "$SITE/cache/class_map.php" && echo present || echo gone)" "gone"
if [ -f "$SITE/cache/class_map.json" ]; then
    chk "the map parses as JSON" \
        "$(php -r 'exit(is_array(json_decode(file_get_contents($argv[1]), true)) ? 0 : 1);' \
            "$SITE/cache/class_map.json" && echo yes || echo no)" "yes"
fi
chk "the autoloader never includes its cache" \
    "$(grep -cE '\b(include|require)(_once)?\s*\(\s*\$file' "$ROOT/includes/ClassAutoloader.php")" "0"

echo "== static_files holds data and serves none of it as code =="

# Inside a deploy's own verify step (upgrade.php sets this) the host installers
# have not run yet, so the vhost may still be the old render, and on a
# browser-upgrade box the converger is the process running the upgrade and
# cannot also carry a request. Those two sections are skipped there and proven
# by the next run of this gate by hand; everything else is valid at that point
# and a bad tree still rolls the deploy back.
IN_DEPLOY_VERIFY="${JOINERY_DEPLOY_VERIFY:-0}"

SF="$SITE/static_files"
if [ "$IN_DEPLOY_VERIFY" = "1" ]; then
    echo "  SKIP: static_files checks (inside a deploy's verify step; the vhost is rendered by the host installers after it)"
elif [ -d "$SF" ]; then
    DOMAIN="$(php -r 'require $argv[1]."/includes/PathHelper.php"; echo Globalvars::get_instance()->get_setting("webDir");' "$ROOT" 2>/dev/null)"
    if [ -n "$DOMAIN" ]; then
        probe="$SF/.s10_probe_$$.php"
        printf '<?php echo "EXECUTED-%s";\n' "$$" > "$probe"
        chown www-data:www-data "$probe" 2>/dev/null || true
        # By the site's own name, over https with -k. Not the loopback address:
        # Apache on a bare-metal box binds the public address, and a probe at
        # 127.0.0.1 got no connection at all (000), which is not an answer.
        # https rather than http because every site we install redirects port
        # 80, and a 301 is not an answer either.
        url="https://$DOMAIN/static_files/$(basename "$probe")"
        body="$(curl -sk --max-time 10 "$url" 2>/dev/null)"
        code="$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' "$url" 2>/dev/null)"
        chk "the probe reached the site (a connection, not 000)" \
            "$(test "$code" != "000" && echo reached || echo unreachable)" "reached"
        chk "a .php dropped into static_files is refused, not run" "$code" "403"
        chk "and none of it comes back" \
            "$(echo "$body" | grep -c "EXECUTED-$$")" "0"
        rm -f "$probe"

        # AllowOverride None: a .htaccess here must change nothing at all. The
        # probe is a rewrite rule, a FileInfo directive: the old vhost's
        # AllowOverride FileInfo obeys it (418), AllowOverride None ignores it.
        # (A `Require` line would not do: FileInfo does not permit it, so Apache
        # rejects the file with a 500, which is not the same as ignoring it.)
        ht="$SF/.htaccess"
        if [ ! -e "$ht" ]; then
            printf 'RewriteEngine On\nRewriteRule ^ - [R=418,L]\n' > "$ht"
            chown www-data:www-data "$ht" 2>/dev/null || true
            code="$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' \
                "https://$DOMAIN/static_files/" 2>/dev/null)"
            chk "a .htaccess written there changes nothing" \
                "$(case "$code" in 000) echo unreachable;; 418) echo obeyed;; *) echo ignored;; esac)" "ignored"
            rm -f "$ht"
        fi
    fi
fi

echo "== the runner refuses an installer it cannot attribute =="

FIX="$SITE/maintenance_scripts/install_tools/_plugin_installers_start.sh"
TMP="$(mktemp -d)"
mkdir -p "$TMP/public_html/plugins" "$TMP/config" "$TMP/cache" "$TMP/maintenance_scripts/install_tools"
printf '<?php\n' > "$TMP/config/Globalvars_site.php"
for h in _config_secrets.sh _tree_trust.sh; do
    cp "$SITE/maintenance_scripts/install_tools/$h" "$TMP/maintenance_scripts/install_tools/" 2>/dev/null || true
done
for f in install_agent.sh install_parser_jail.sh install_host_converger.sh render_vhost.sh; do
    printf '#!/bin/bash\nexit 0\n' > "$TMP/maintenance_scripts/install_tools/$f"
    chmod 755 "$TMP/maintenance_scripts/install_tools/$f"
done
chmod 775 "$TMP/maintenance_scripts/install_tools/install_parser_jail.sh"
cp "$FIX" "$TMP/maintenance_scripts/install_tools/"
out="$(bash "$TMP/maintenance_scripts/install_tools/_plugin_installers_start.sh" --site-root="$TMP" 2>&1)"
chk "a group-writable installer is refused" \
    "$(echo "$out" | grep -c 'installer refused.*install_parser_jail.sh')" "1"
chk "and the ones that are fine still run" \
    "$(echo "$out" | grep -c 'install_agent.sh: ok')" "1"
rm -rf "$TMP"

echo "== a submitted request is carried out =="

# The end-to-end promise: something the web side queued reaches root and comes
# back done, inside the converger's interval.
if [ "$IN_DEPLOY_VERIFY" = "1" ]; then
    echo "  SKIP: request round-trip (inside a deploy's verify step; the converger may be the process running this deploy)"
    req=""
    SKIP_REQUEST=1
else
    SKIP_REQUEST=0
fi
[ "$SKIP_REQUEST" = "0" ] && req="$(as_web php -r '
    require $argv[1]."/includes/PathHelper.php";
    echo RootRequest::submit("write_agent_files", array(), 0);
' "$ROOT" 2>/dev/null)"
if [ -n "$req" ]; then
    deadline=$(( $(date +%s) + 150 ))
    state="queued"
    while [ "$(date +%s)" -lt "$deadline" ]; do
        state="$(php -r '
            require $argv[1]."/includes/PathHelper.php";
            $s = RootRequest::status($argv[2]); echo $s["state"];
        ' "$ROOT" "$req" 2>/dev/null)"
        case "$state" in done|failed) break;; esac
        sleep 5
    done
    chk "a queued write_agent_files request reaches done" "$state" "done"
elif [ "$SKIP_REQUEST" = "0" ]; then
    chk "the pool can queue a root request" "no" "yes"
fi

echo
echo "read_only_tree gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
