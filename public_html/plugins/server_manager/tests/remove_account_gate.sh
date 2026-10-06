#!/bin/bash
# @joinery-test
# name: remove_account
# tier: db
# env: dev-only
# needs: []
# timeout: 120
#
# remove_account.sh is the tested host teardown that the Server Manager
# decommission_node job ships and runs to permanently delete a site. What makes
# it safe to drive from a job:
#
#   * "nothing to remove" is idempotent success — a re-run (or a teardown that
#     already happened) prints REMOVE_ACCOUNT_NOTHING and exits 0, never exit 1.
#   * a real removal prints REMOVE_ACCOUNT_OK and leaves no container, none of
#     the site's volumes, and no image behind.
#   * it touches nothing of another site on the same machine, however that
#     site is named (getjoinery_developers beside getjoinery, a <site>_test or
#     <site>-le-ssl site, a container whose ID starts with the site's name).
#
# The static section checks the marker contract and that the script's list of
# a site's volumes is install.sh's. The end-to-end section runs the whole
# script without root under a scratch root (REMOVE_ACCOUNT_ROOT) with stub
# docker, Apache, systemctl and dropdb, and asserts what each run removed and
# kept. The behavioral section builds a throwaway container + volume and
# removes it for real — that half needs root and a usable Docker, so on a box
# without them it is skipped with a logged reason rather than silently passing.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/remove_account.sh"

passed=0; failed=0
chk() { # chk "label" actual expected
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "== Static contract =="

if [ ! -f "$SCRIPT" ]; then
    echo "  FAIL: remove_account.sh not found at $SCRIPT"
    echo "RESULT: 0 passed, 1 failed"
    exit 1
fi

bash -n "$SCRIPT" 2>/dev/null && synok=yes || synok=no
chk "remove_account.sh is valid bash" "$synok" "yes"

grep -q "REMOVE_ACCOUNT_NOTHING" "$SCRIPT" && nothing=yes || nothing=no
chk "emits a REMOVE_ACCOUNT_NOTHING marker" "$nothing" "yes"
grep -q "REMOVE_ACCOUNT_OK" "$SCRIPT" && okmark=yes || okmark=no
chk "emits a REMOVE_ACCOUNT_OK marker on success" "$okmark" "yes"
grep -q 'grep[^|]*"\^\${SITE_NAME}' "$SCRIPT" && pfx=yes || pfx=no
chk "never picks anything by a site-name prefix" "$pfx" "no"

echo "== One list of a site's volumes =="

# install.sh's ALL_SITE_VOLUMES, _site_run_spec.sh's RUN_SPEC_VOLUMES and this
# script's SITE_VOLUME_SUFFIXES name the same volumes.
INSTALL_SH="$ROOT/maintenance_scripts/install_tools/install.sh"
RUN_SPEC_SH="$ROOT/maintenance_scripts/install_tools/_site_run_spec.sh"
norm() { tr ' ' '\n' | grep -v '^$' | sort | tr '\n' ' '; }
mine="$(sed -n 's/^SITE_VOLUME_SUFFIXES="\(.*\)"$/\1/p' "$SCRIPT" | norm)"
installs="$(sed -n '/^ALL_SITE_VOLUMES=(/,/^)/p' "$INSTALL_SH" | sed '1d;$d' | norm)"
runspec="$(sed -n '/^RUN_SPEC_VOLUMES=(/,/^)/p' "$RUN_SPEC_SH" | sed '1d;$d' | sed 's/"\([a-z_]*\):.*/\1/' | norm)"
[ -n "$mine" ] && n=yes || n=no
chk "SITE_VOLUME_SUFFIXES is set" "$n" "yes"
chk "matches install.sh ALL_SITE_VOLUMES" "$mine" "$installs"
chk "matches _site_run_spec.sh RUN_SPEC_VOLUMES" "$mine" "$runspec"

echo "== End to end under a scratch root =="

T="$(mktemp -d)"
BIN="$T/bin"; RUN="$T/run"; D="$T/docker"
mkdir -p "$BIN" "$RUN"
# The script under test runs from beside a stub remove_site_certificate.sh.
cp "$SCRIPT" "$RUN/remove_account.sh"
cat > "$RUN/remove_site_certificate.sh" <<'STUB'
#!/bin/bash
echo "{\"name\":\"$1\",\"letsencrypt\":\"removed\"}"
STUB

# Stub docker: state in $D/{containers,volumes,networks,images}, one name per
# line; every call is logged. stop/rm of a name that is not a container fails,
# as real docker does after its ID-prefix fallback finds nothing.
drop_line() { grep -vxF "$2" "$1" > "$1.new"; mv "$1.new" "$1"; }
cat > "$BIN/docker" <<STUB
#!/bin/bash
D="$D"
echo "\$*" >> "\$D/log"
has() { grep -qxF "\$2" "\$D/\$1"; }
drop() { grep -vxF "\$2" "\$D/\$1" > "\$D/\$1.new"; mv "\$D/\$1.new" "\$D/\$1"; }
case "\$1 \${2:-}" in
    "info "*)       [ -f "\$D/down" ] && exit 1; exit 0 ;;
    "ps -a")        cat "\$D/containers" ;;
    "volume ls")    cat "\$D/volumes" ;;
    "volume rm")    has volumes "\$3" || exit 1; grep -qxF "\$3" "\$D/in_use" && exit 1; drop volumes "\$3" ;;
    "network inspect") has networks "\$3" ;;
    "network rm")   has networks "\$3" && drop networks "\$3" ;;
    "images "*)     cat "\$D/images" ;;
    "rmi "*)        has images "\$2" && drop images "\$2" ;;
    "inspect -f")   case "\$3" in
                        *Config.Image*) cat "\$D/image_\$4" 2>/dev/null ;;
                        *Mounts*)       cat "\$D/mounts_\$4" 2>/dev/null ;;
                    esac ;;
    "stop "*)       has containers "\$2" ;;
    "rm "*)         has containers "\$2" && drop containers "\$2" ;;
    *)              exit 0 ;;
esac
STUB
for c in a2dissite apache2ctl; do printf '#!/bin/bash\necho "%s $*" >> "%s/log"\n' "$c" "$D" > "$BIN/$c"; done
cat > "$BIN/systemctl" <<STUB
#!/bin/bash
echo "systemctl \$*" >> "$D/log"
[ "\$1" = "is-enabled" ] && { [ -f "$D/enabled" ]; exit \$?; }
exit 0
STUB
printf '#!/bin/bash\necho "dropdb $*" >> "%s/log"\n' "$D" > "$BIN/dropdb"
chmod +x "$BIN"/* "$RUN"/*.sh

# A machine with no docker command at all: the stubs but docker, and links to
# only the tools the script uses, so a real Docker on this box is never reached.
NOD="$T/nodocker"; SYS="$T/sys"
mkdir -p "$NOD" "$SYS"
for c in a2dissite apache2ctl systemctl dropdb; do cp "$BIN/$c" "$NOD/$c"; done
for c in bash grep sed sort tr cut dirname rm mkdir cat ls mv tail head wc timeout touch cp env; do
    ln -s "$(command -v "$c")" "$SYS/$c"
done
run_nodocker() { PATH="$NOD:$SYS" REMOVE_ACCOUNT_ROOT="$T/fs" bash "$RUN/remove_account.sh" "$@" -y 2>&1; }

# A fresh machine: an empty Docker and an empty file tree.
reset() {
    rm -rf "${D:?}" "${T:?}/fs"
    mkdir -p "$D" "$T/fs/var/www/html" "$T/fs/etc/apache2/sites-available" "$T/fs/etc/joinery/sites" "$T/fs/root"
    for f in containers volumes networks images in_use log; do : > "$D/$f"; done
}
# A container site: container, its fifteen volumes, network, image, state dir,
# host-side logs folder and proxy vhost naming its certificate.
container_site() {
    local s="$1" v
    echo "$s" >> "$D/containers"
    echo "joinery-${s}" > "$D/image_${s}"
    : > "$D/mounts_${s}"
    for v in $mine; do echo "${s}_${v}" >> "$D/volumes"; printf 'volume:%s:/var/lib/docker/volumes/%s/_data ' "${s}_${v}" "${s}_${v}" >> "$D/mounts_${s}"; done
    echo "${s}_net" >> "$D/networks"
    echo "joinery-${s}:latest" >> "$D/images"
    mkdir -p "$T/fs/etc/joinery/sites/$s" "$T/fs/var/www/html/$s/logs"
    echo "network=${s}_net" > "$T/fs/etc/joinery/sites/$s/run_spec"
    printf 'SSLCertificateFile /etc/letsencrypt/live/%s.example/fullchain.pem\nErrorLog /var/www/html/%s/logs/proxy_error.log\n' "$s" "$s" \
        > "$T/fs/etc/apache2/sites-available/$s.conf"
}
in_use() { local v; for v in $mine; do echo "${1}_${v}" >> "$D/in_use"; done; }
run() { PATH="$BIN:$PATH" REMOVE_ACCOUNT_ROOT="$T/fs" bash "$RUN/remove_account.sh" "$@" -y 2>&1; }
logged() { grep -c -- "$1" "$D/log" | tr -d ' '; }
there() { [ -e "$T/fs$1" ] && echo yes || echo no; }

# --- A: getjoinery beside getjoinery_developers, getjoinery_orgs and a site
# --- named getjoinery_test, all running; getjoinery left a rebase behind.
reset
container_site getjoinery
for s in getjoinery_developers getjoinery_orgs getjoinery_test; do container_site "$s"; in_use "$s"; done
echo "getjoinery_postgres_pg16" >> "$D/volumes"
echo "joinery-getjoinery:pre-rebase-pg16" >> "$D/images"
mkdir -p "$T/fs/root/rebase/getjoinery" "$T/fs/root/rebase/getjoinery_orgs"
touch "$T/fs/etc/joinery/sites/getjoinery/held" "$T/fs/etc/joinery/sites/getjoinery/suspended"
cp "$T/fs/etc/apache2/sites-available/getjoinery.conf" "$T/fs/etc/apache2/sites-available/getjoinery.conf.before-render.1"
out="$(run getjoinery)"; rc=$?
chk "A: getjoinery removal verifies" "$(echo "$out" | grep -c '^DECOMMISSION_VERIFIED getjoinery$')" "1"
chk "A: exit 0" "$rc" "0"
chk "A: none of getjoinery's volumes left" "$(grep -cE '^getjoinery_[a-z_]+$' "$D/volumes" | tr -d ' ')" "45"
chk "A: getjoinery's own fifteen gone" "$(for v in $mine; do grep -xF "getjoinery_$v" "$D/volumes"; done | wc -l | tr -d ' ')" "0"
chk "A: the rebase's kept database gone" "$(grep -cxF getjoinery_postgres_pg16 "$D/volumes")" "0"
chk "A: no docker call names a sibling" "$(grep -cE 'getjoinery_(developers|orgs|test)' "$D/log")" "0"
chk "A: siblings' containers all there" "$(grep -cE '^getjoinery_(developers|orgs|test)$' "$D/containers")" "3"
chk "A: siblings' images all there" "$(grep -cE '^joinery-getjoinery_(developers|orgs|test):latest$' "$D/images")" "3"
chk "A: getjoinery's images gone" "$(grep -c '^joinery-getjoinery:' "$D/images")" "0"
chk "A: sibling getjoinery_test's host folder kept" "$(there /var/www/html/getjoinery_test/logs)" "yes"
chk "A: sibling vhosts kept" "$(ls "$T/fs/etc/apache2/sites-available" | grep -c '^getjoinery_')" "3"
chk "A: getjoinery's vhost and its backup gone" "$(ls "$T/fs/etc/apache2/sites-available" | grep -c '^getjoinery\.conf')" "0"
chk "A: no database dropped on a Docker host" "$(logged '^dropdb')" "0"
chk "A: rebase work dir gone" "$(there /root/rebase/getjoinery)" "no"
chk "A: a sibling's rebase work dir kept" "$(there /root/rebase/getjoinery_orgs)" "yes"
chk "A: held and suspended marks gone" "$(ls "$T/fs/etc/joinery/sites/getjoinery" | wc -l | tr -d ' ')" "0"
chk "A: sibling state kept" "$(there /etc/joinery/sites/getjoinery_orgs/run_spec)" "yes"
chk "A: its certificate handed to remove_site_certificate.sh" "$(echo "$out" | grep -c 'getjoinery.example.*removed')" "1"

# --- B: a hex name whose container is gone; only a volume is left. docker stop
# --- and rm would fall back to an ID prefix, so they must not be called.
reset
echo "cafe_code" >> "$D/volumes"
container_site cafe12
out="$(run cafe)"; rc=$?
chk "B: verifies" "$(echo "$out" | grep -c '^DECOMMISSION_VERIFIED cafe$')" "1"
chk "B: docker stop never called" "$(logged '^stop ')" "0"
chk "B: docker rm never called" "$(logged '^rm ')" "0"
chk "B: container cafe12 untouched" "$(grep -cxF cafe12 "$D/containers")" "1"

# --- C: Docker installed and enabled but not answering: refuse, touch nothing.
reset
container_site getjoinery
touch "$D/down" "$D/enabled"
out="$(run getjoinery)"; rc=$?
chk "C: refuses (exit 1)" "$rc" "1"
chk "C: no OK or VERIFIED marker" "$(echo "$out" | grep -cE 'REMOVE_ACCOUNT_OK|DECOMMISSION_VERIFIED')" "0"
chk "C: proxy vhost kept" "$(there /etc/apache2/sites-available/getjoinery.conf)" "yes"
chk "C: Apache never touched" "$(logged '^a2dissite')" "0"

# --- C2: Docker answers at the start and stops mid-run: the verdict fails.
reset
container_site getjoinery
cat > "$BIN/a2dissite" <<STUB
#!/bin/bash
touch "$D/down"
STUB
chmod +x "$BIN/a2dissite"
out="$(run getjoinery)"; rc=$?
chk "C2: cannot verify when Docker stops answering" "$(echo "$out" | grep -c '^DECOMMISSION_FAILED_VERIFY getjoinery$')" "1"
printf '#!/bin/bash\necho "a2dissite $*" >> "%s/log"\n' "$D" > "$BIN/a2dissite"

# --- D: a bare-metal site with its test twin, no Docker at all.
reset
mkdir -p "$T/fs/var/www/html/foo/public_html" "$T/fs/var/www/html/foo_test/public_html"
echo "DocumentRoot /var/www/html/foo/public_html" > "$T/fs/etc/apache2/sites-available/foo.conf"
out="$(run_nodocker foo)"; rc=$?
chk "D: verifies" "$(echo "$out" | grep -c '^DECOMMISSION_VERIFIED foo$')" "1"
chk "D: web root gone" "$(there /var/www/html/foo)" "no"
chk "D: test twin gone" "$(there /var/www/html/foo_test)" "no"
chk "D: both databases dropped" "$(grep -cE '^dropdb -U postgres foo(_test)?$' "$D/log")" "2"

# --- E: a bare-metal site whose <site>_test and <site>-le-ssl are sites of their own.
reset
mkdir -p "$T/fs/var/www/html/bar/public_html" "$T/fs/var/www/html/bar_test/public_html" "$T/fs/var/www/html/bar-le-ssl/public_html"
echo "DocumentRoot /var/www/html/bar/public_html" > "$T/fs/etc/apache2/sites-available/bar.conf"
echo "DocumentRoot /var/www/html/bar_test/public_html" > "$T/fs/etc/apache2/sites-available/bar_test.conf"
echo "DocumentRoot /var/www/html/bar-le-ssl/public_html" > "$T/fs/etc/apache2/sites-available/bar-le-ssl.conf"
out="$(run bar)"; rc=$?
chk "E: verifies" "$(echo "$out" | grep -c '^DECOMMISSION_VERIFIED bar$')" "1"
chk "E: site bar_test's files kept" "$(there /var/www/html/bar_test/public_html)" "yes"
chk "E: site bar_test's database kept" "$(logged '^dropdb -U postgres bar_test$')" "0"
chk "E: site bar-le-ssl's vhost kept" "$(there /etc/apache2/sites-available/bar-le-ssl.conf)" "yes"
chk "E: bar-le-ssl never disabled" "$(logged 'a2dissite bar-le-ssl')" "0"

# --- F: a re-run where only the vhost and a suspended mark are left.
reset
mkdir -p "$T/fs/etc/joinery/sites/baz"
touch "$T/fs/etc/joinery/sites/baz/suspended"
echo "x" > "$T/fs/etc/apache2/sites-available/baz.conf"
out="$(run baz)"
chk "F: suspended mark cleared on the bare path" "$(there /etc/joinery/sites/baz/suspended)" "no"

# --- F2: a quiet bare-metal site: held/ is _site_state.sh's folder of its cron files.
reset
mkdir -p "$T/fs/var/www/html/quiet/public_html" "$T/fs/etc/joinery/sites/quiet/held/cron.d"
echo "DocumentRoot /var/www/html/quiet/public_html" > "$T/fs/etc/apache2/sites-available/quiet.conf"
out="$(run_nodocker quiet)"; rc=$?
chk "F2: a quiet site's removal verifies" "$(echo "$out" | grep -c '^DECOMMISSION_VERIFIED quiet$')" "1"
out="$(run_nodocker quiet)"; rc=$?
chk "F2: and a re-run reports nothing to remove" "$(echo "$out" | grep -c '^REMOVE_ACCOUNT_NOTHING quiet$')|$rc" "1|0"

# --- F3: a Docker host re-run after the container went: only the proxy vhost is
# --- left. Still the proxy: no host database dropped, no test twin taken.
reset
container_site getjoinery_test
in_use getjoinery_test
mkdir -p "$T/fs/var/www/html/getjoinery/logs"
printf '<VirtualHost *:80>\n    ProxyPass / http://127.0.0.1:8080/\n</VirtualHost>\n' > "$T/fs/etc/apache2/sites-available/getjoinery.conf"
out="$(run getjoinery)"; rc=$?
chk "F3: verifies" "$(echo "$out" | grep -c '^DECOMMISSION_VERIFIED getjoinery$')" "1"
chk "F3: no database dropped" "$(logged '^dropdb')" "0"
chk "F3: says it removed the proxy" "$(echo "$out" | grep -c "Removing the container's proxy")" "1"

# --- C3: Docker's socket is there but no docker command on this PATH: refuse.
reset
container_site getjoinery
mkdir -p "$T/fs/var/run"
python3 -c "import socket,sys; s=socket.socket(socket.AF_UNIX); s.bind(sys.argv[1])" "$T/fs/var/run/docker.sock"
out="$(run_nodocker getjoinery)"; rc=$?
chk "C3: refuses (exit 1)" "$rc" "1"
chk "C3: proxy vhost kept" "$(there /etc/apache2/sites-available/getjoinery.conf)" "yes"

# --- I: a container install.sh did not make is refused before anything goes.
for case in image bind foreign rollback; do
    reset
    container_site handmade
    case "$case" in
        image)    echo "postgres:16" > "$D/image_handmade" ;;
        bind)     printf 'bind::/srv/handmade-data ' >> "$D/mounts_handmade" ;;
        foreign)  printf 'volume:getjoinery_orgs_postgres:/var/lib/docker/volumes/getjoinery_orgs_postgres/_data ' >> "$D/mounts_handmade" ;;
        rollback) echo "joinery-handmade:pre-rebase-pg16" > "$D/image_handmade" ;;
    esac
    out="$(run handmade)"; rc=$?
    if [ "$case" = rollback ]; then
        chk "I: a container on its rolled-back pre-rebase image is still ours" "$(echo "$out" | grep -c '^DECOMMISSION_VERIFIED handmade$')" "1"
    else
        chk "I: $case: refused (exit 1)" "$rc" "1"
        chk "I: $case: says it is not install.sh's" "$(echo "$out" | grep -c "is not one install.sh made")" "1"
        chk "I: $case: container, volumes and vhost untouched" \
            "$(grep -cxF handmade "$D/containers")|$(grep -c '^handmade_' "$D/volumes" | tr -d ' ')|$(there /etc/apache2/sites-available/handmade.conf)" "1|15|yes"
        chk "I: $case: nothing was stopped or removed" "$(grep -cE '^(stop|rm|volume rm|network rm|rmi) ' "$D/log")" "0"
    fi
done

# --- G: nothing left at all, with a stale held mark: idempotent and cleared.
reset
mkdir -p "$T/fs/etc/joinery/sites/qux"
touch "$T/fs/etc/joinery/sites/qux/held"
out="$(run qux)"; rc=$?
chk "G: REMOVE_ACCOUNT_NOTHING" "$(echo "$out" | grep -c '^REMOVE_ACCOUNT_NOTHING qux$')" "1"
chk "G: exit 0" "$rc" "0"
chk "G: held mark cleared" "$(there /etc/joinery/sites/qux/held)" "no"

# --- H: names that are not a site name are refused before anything is composed.
reset
for bad in '../foo' 'get.oinery' '' 'UPPER' '_lead'; do
    out="$(run "$bad")"; rc=$?
    chk "H: '$bad' refused" "$rc" "1"
done
chk "H: nothing was called" "$(wc -l < "$D/log" | tr -d ' ')" "0"

rm -rf "${T:?}"

echo "== Behavioral (root + Docker required) =="

can_docker=no
if [ "$(id -u)" = "0" ] && command -v docker >/dev/null 2>&1 && docker ps >/dev/null 2>&1; then
    can_docker=yes
fi

if [ "$can_docker" != "yes" ]; then
    echo "  SKIP: build-and-remove cycle needs root and a usable Docker daemon."
    echo "        Runs on a Docker host; this box has neither, so only the static"
    echo "        contract above was exercised. (Not counted as pass or fail.)"
else
    IMG="$(docker images --format '{{.Repository}}:{{.Tag}}' | grep -v '<none>' | head -1)"
    if [ -z "$IMG" ]; then
        echo "  SKIP: no local Docker image available to build a fixture container."
    else
        FIX="jygaterm_${RANDOM}_${RANDOM}"
        # Guard: never touch a real site name.
        if docker ps -a --format '{{.Names}}' | grep -qx "$FIX" || [ -d "/var/www/html/$FIX" ]; then
            echo "  FAIL: fixture name $FIX unexpectedly already exists; aborting"
            failed=$((failed+1))
        else
            # Shaped like one of install.sh's: its own image name, its own volume.
            docker volume create "${FIX}_postgres" >/dev/null 2>&1
            docker tag "$IMG" "joinery-${FIX}:latest" >/dev/null 2>&1
            docker run -d --name "$FIX" -v "${FIX}_postgres:/fixture" "joinery-${FIX}:latest" sh -c 'sleep 300' >/dev/null 2>&1 \
                || docker run -d --name "$FIX" -v "${FIX}_postgres:/fixture" "joinery-${FIX}:latest" >/dev/null 2>&1

            out="$(bash "$SCRIPT" "$FIX" -y 2>&1)"
            echo "$out" | grep -q "REMOVE_ACCOUNT_OK" && m=yes || m=no
            chk "removal prints REMOVE_ACCOUNT_OK" "$m" "yes"

            docker ps -a --format '{{.Names}}' | grep -qx "$FIX" && stillc=yes || stillc=no
            chk "container is gone after removal" "$stillc" "no"
            docker volume ls --format '{{.Name}}' | grep -q "^${FIX}_" && stillv=yes || stillv=no
            chk "the \${site}_ volume is gone after removal" "$stillv" "no"

            # Idempotent re-run: nothing left → REMOVE_ACCOUNT_NOTHING, exit 0.
            out2="$(bash "$SCRIPT" "$FIX" -y 2>&1)"; rc=$?
            echo "$out2" | grep -q "REMOVE_ACCOUNT_NOTHING" && n=yes || n=no
            chk "a second run reports REMOVE_ACCOUNT_NOTHING" "$n" "yes"
            chk "a second run exits 0 (idempotent)" "$rc" "0"

            # Belt-and-suspenders cleanup in case the script left anything.
            docker rm -f "$FIX" >/dev/null 2>&1 || true
            docker volume rm "${FIX}_postgres" >/dev/null 2>&1 || true
            docker rmi "joinery-${FIX}:latest" >/dev/null 2>&1 || true
        fi
    fi
fi

echo "RESULT: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
