#!/bin/bash
# @joinery-test
# name: site_refresh
# tier: safe
# env: any
# needs: []
# timeout: 300
# covers: [maintenance_scripts/install_tools/site_refresh.sh, maintenance_scripts/install_tools/_site_run_spec.sh, maintenance_scripts/sysadmin_tools/recreate_site.sh, maintenance_scripts/install_tools/Dockerfile.template, maintenance_scripts/install_tools/Dockerfile.base]
#
# A site container's OS is replaced when Ubuntu ships a fix
# (specs/multi_tenant_host_hardening.md D9). recreate_site.sh replaces one
# site's container: build first, then stop, remove, run from the run spec, wait
# for health and an answer, and put the previous image back when the new one
# does not come up. site_refresh.sh decides when, nightly: a throwaway
# container of the base says what apt would install, a security fix builds the
# base fresh, and every site whose image does not carry the base's id is
# replaced one at a time; a rollback holds the cycle. This gate drives both
# against a stand-in engine that keeps images, tags, labels and containers in
# files, as an unprivileged user under JOINERY_SITE_STATE_ROOT.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/install_tools"
RECREATE="$ROOT/maintenance_scripts/sysadmin_tools/recreate_site.sh"
REFRESH="$TOOLS/site_refresh.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged; as root the fixture root is ignored and the host's own paths would be used"
    echo "RESULT: 0 passed, 0 failed"; exit 0
fi

export JOINERY_SITE_STATE_ROOT="$T/root" JOINERY_SITE_ENGINE_KIND=podman STUB="$T/s"
export RECREATE_WAIT_SECONDS=3 SITE_REFRESH_MIN_FREE_BYTES=1 BUSY_WAIT_SECONDS=0 RECREATE_LOCK_WAIT=1
# As systemd sets it for every unit's processes: the nightly service and the
# host agent run these scripts as units.
export INVOCATION_ID=gate0000000000000000000000000000
R="$T/root"
mkdir -p "$T/bin" "$STUB" "$R/etc/joinery/host_restrictions" "$R/etc/containers"
echo '{"defaultAction":"SCMP_ACT_ERRNO"}' > "$R/etc/containers/joinery-site-seccomp.json"
BASE_HASH="$(bash -c ". '$TOOLS/install.sh'; compute_install_sh_hash")"
BASE_VERSION="$(bash -c ". '$TOOLS/install.sh'; echo \$BASE_IMAGE_VERSION")"

# --- the stand-in engine -------------------------------------------------------
# Images are folders named by id (label, hash, fail); tags are files naming an
# id; containers are folders (image, state, env, port). Every call is logged,
# and what matters at the moment of a call (the replacing mark at a stop, the
# env file's mode at a run, an inherited lock descriptor) is recorded.
cat > "$T/bin/podman" <<'STUB'
#!/bin/bash
S="$STUB"
echo "$*" >> "$S/calls"
tagf() { local t="${1#localhost/}"; [[ "$t" == *:* ]] || t="${t}:latest"; echo "$S/tags/${t//\//_}"; }
resolve() { local f; f="$(tagf "$1")"; if [ -f "$f" ]; then cat "$f"; elif [ -d "$S/images/$1" ]; then echo "$1"; else return 1; fi; }
newid() { local n; n=$(( $(cat "$S/n" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$S/n"; printf '%012x%052x\n' "$n" "$n"; }
mkdir -p "$S/tags" "$S/images" "$S/containers"
case "$1 $2" in "run -d"|start*) for fd in 7 8; do [ -e "/proc/$$/fd/$fd" ] && echo "$1 inherited fd $fd" >> "$S/fdleak"; done
    [ -n "${INVOCATION_ID:-}" ] && echo "$1 saw INVOCATION_ID (its monitor would stay in the calling unit)" >> "$S/fdleak" ;; esac
case "$1" in
  inspect)
    shift
    if [ "$1" = "-f" ]; then f="$2"; c="$3"; else f=""; c="$1"; fi
    d="$S/containers/$c"; [ -d "$d" ] || exit 1
    case "$f" in
      "") echo "{}" ;;
      # Docker's health: unhealthy for an image that fails, none for one
      # built before the health check.
      *State.Health*) id="$(cat "$d/image")"
        if [ -f "$S/fail_all" ] || [ -f "$S/images/$id/fail" ]; then echo unhealthy
        elif [ -f "$S/images/$id/nohealth" ]; then echo none; else echo healthy; fi ;;
      # The container's start command's second word: the joinery start, or a
      # site made before it (cmd1).
      *Config.Cmd*) cat "$d/cmd1" 2>/dev/null || echo /usr/local/sbin/joinery-site-start ;;
      # What run_spec_adopt reads of a container Docker made before run specs.
      *HostConfig.Privileged*) cat "$d/hostconfig" 2>/dev/null || echo "false|bridge||0|0|0||0|0|0|0||unless-stopped|${c}|" ;;
      *PortBindings*) cat "$d/ports" 2>/dev/null ;;
      *.Mounts*) cat "$d/mounts" 2>/dev/null ;;
      *NetworkSettings.Networks*) echo "bridge " ;;
      *State.Running*) [ "$(cat "$d/state")" = running ] && echo true || echo false ;;
      *State.Status*) cat "$d/state" ;;
      # As a real engine call may: stdin read, where a test asks for it.
      *Config.Env*) [ -f "$S/inspect_reads_stdin" ] && timeout 1 cat > /dev/null 2>&1; cat "$d/env" ;;
      *joinery.seccomp*) cat "$d/seccomp" 2>/dev/null ;;
      *.Image*) cat "$d/image" ;;
      *) echo "" ;;
    esac ;;
  image)
    shift
    case "$1" in
      inspect)
        shift; f=""; [ "$1" = "-f" ] && { f="$2"; shift 2; }
        id="$(resolve "$1")" || exit 1
        case "$f" in
          "") echo "[{}]" ;;
          *.Id*) echo "$id" ;;
          *joinery.base_id*) cat "$S/images/$id/label" 2>/dev/null ;;
          *joinery.install_sh_hash*) cat "$S/images/$id/hash" 2>/dev/null ;;
          *Healthcheck*) [ -f "$S/images/$id/nohc" ] || echo CMD-SHELL ;;
          *Created*) echo "2026-10-14 10:00:00 +0000 UTC" ;;
        esac ;;
      prune) echo pruned >> "$S/pruned" ;;
    esac ;;
  volume)
    shift; [ "$1" = inspect ] || exit 0; shift
    f=""; [ "$1" = "-f" ] && { f="$2"; shift 2; }
    [ -d "$S/volumes/$1" ] || exit 1
    [ -n "$f" ] && echo "$S/volumes/$1" || echo "{}" ;;
  build)
    shift
    [ -f "$S/build_fail" ] && { echo "build: a step failed"; exit 1; }
    echo "$*" >> "$S/build_args"
    [ -f "$S/build_leaves" ] && { echo "wc$(date +%N) x-working-container" >> "$S/external"; echo "wd$(date +%N) x-working-container-1" >> "$S/external"; }
    [ -f "$S/build_sleep" ] && sleep "$(cat "$S/build_sleep")"
    [ -f "$S/build_touch" ] && touch "$(cat "$S/build_touch")"
    tags=(); label=""; hash=""; ctx=""; file=""; prev=""
    for a in "$@"; do
      case "$prev" in -t) tags+=("$a") ;; --label) label="${a#joinery.base_id=}" ;; --build-arg) case "$a" in INSTALL_SH_HASH=*) hash="${a#*=}" ;; JOINERY_DECLARED_APT=*) echo "${a#*=}" > "$S/declared_arg" ;; esac ;; -f) file="$a" ;; esac
      prev="$a"; ctx="$a"
    done
    id="$(newid)"; mkdir -p "$S/images/$id"
    [ -n "$label" ] && echo "$label" > "$S/images/$id/label"
    [ -n "$hash" ] && echo "$hash" > "$S/images/$id/hash"
    [ -f "$S/next_build_fails" ] && { touch "$S/images/$id/fail"; rm -f "$S/next_build_fails"; }
    [ -f "$S/build_nohc" ] && [ -z "$file" ] && touch "$S/images/$id/nohc"
    # A fresh base takes every fix that was pending.
    case "$file" in *Dockerfile.base) [ -f "$S/build_keeps_apt" ] || : > "$S/apt" ;; esac
    [ -z "$file" ] && { (cd "$ctx" && find . -type f | LC_ALL=C sort | tr '\n' ' ') > "$S/context_listing"
        cp "$ctx/maintenance_scripts/install_tools/_site_start.sh" "$S/context_start" 2>/dev/null; }
    for t in "${tags[@]}"; do echo "$id" > "$(tagf "$t")"; done
    echo "built $id" ;;
  tag) [ -f "$S/tag_fails" ] && [[ "$2" == *:next && "$3" != *:* ]] && exit 1
       id="$(resolve "$2")" || exit 1; echo "$id" > "$(tagf "$3")" ;;
  rmi) rm -f "$(tagf "$2")" ;;
  stop) d="$S/containers/${@: -1}"; [ -d "$d" ] && echo exited > "$d/state"
        [ -f "$S/arm_fail_all" ] && touch "$S/fail_all"
        [ -f "$JOINERY_SITE_STATE_ROOT/etc/joinery/sites/${@: -1}/replacing" ] && echo marked >> "$S/mark_at_stop" ;;
  rm) if [ "$3" = "--storage" ]; then grep -v "^$4 " "$S/external" > "$S/external.n"; mv "$S/external.n" "$S/external"; exit 0; fi
      [ -f "$S/rm_fails" ] && exit 1
      rm -rf "$S/containers/${@: -1}" ;;
  start) d="$S/containers/$2"; [ -d "$d" ] && echo running > "$d/state" ;;
  run)
    shift
    # As some real engine call does: whatever is on stdin is read.
    timeout 1 cat > /dev/null 2>&1
    if [ "$1" = "--rm" ]; then
      case "$*" in
        *"--entrypoint ls"*) cat "$S/pgmajor" 2>/dev/null || echo 18 ;;
        *"--entrypoint bash"*) echo "$*" > "$S/check_args"; [ -f "$S/check_fail" ] && exit 3; cat "$S/apt" 2>/dev/null ;;
      esac
      exit 0
    fi
    [ -f "$S/fail_all" ] && [ -f "$S/arm_run_fail" ] && exit 125
    name=""; envf=""; prev=""; img=""; filter=""
    for a in "$@"; do case "$prev" in --name) name="$a" ;; --env-file) envf="$a" ;; --label) case "$a" in joinery.seccomp=*) filter="${a#*=}" ;; esac ;; esac; prev="$a"; img="$a"; done
    id="$(resolve "$img")" || exit 125
    d="$S/containers/$name"; mkdir -p "$d"; echo "$id" > "$d/image"; echo running > "$d/state"
    rm -f "$d/seccomp"; [ -n "$filter" ] && echo "$filter" > "$d/seccomp"
    cp "$envf" "$d/env"; stat -c %a "$envf" > "$S/envmode"
    echo "$name" >> "$S/runs" ;;
  healthcheck) id="$(cat "$S/containers/$3/image" 2>/dev/null)" || exit 1; [ -f "$S/fail_all" ] && exit 1; [ -f "$S/images/$id/fail" ] && exit 1; exit 0 ;;
  logs) echo "the new container's last words" ;;
  ps)
    if [ "$3" = "--external" ]; then cat "$S/external" 2>/dev/null; exit 0; fi
    for d in "$S"/containers/*/; do [ -d "$d" ] || continue
      if [ "$2" = "-a" ] || [ "$(cat "$d/state")" = running ]; then basename "$d"; fi; done ;;
  info) case "$*" in *GraphRoot*) echo "$S" ;; *) echo 4 ;; esac ;;
  exec)
    case "$3" in
      pgrep) [ -f "$S/pgrep_rc" ] && exit "$(cat "$S/pgrep_rc")"
             if [ -f "$S/busy_left.$2" ]; then n="$(cat "$S/busy_left.$2")"; [ "$n" -gt 0 ] && { echo $((n - 1)) > "$S/busy_left.$2"; echo "9 php utils/process_scheduled_tasks.php"; exit 0; }; fi
             [ -f "$S/busy.$2" ] && { cat "$S/busy.$2"; exit 0; }; exit 1 ;;
      php) cat "$S/declared" 2>/dev/null ;;
      bash) cat "$S/mfdir.$2" 2>/dev/null ;;
    esac ;;
  cp)
    src="${2#*:}"; [ -f "$S/fs$src" ] || exit 1; cp "$S/fs$src" "$3" ;;
  network) exit 1 ;;
esac
exit 0
STUB
chmod 755 "$T/bin/podman"
cat > "$T/bin/curl" <<'STUB'
#!/bin/bash
cat "$STUB/curl_code" 2>/dev/null || printf 302
STUB
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
echo "$*" >> "$STUB/systemctl"
case "$1" in is-enabled|is-active) [ -f "$STUB/timer_on" ] ;; enable) touch "$STUB/timer_on" ;; disable) rm -f "$STUB/timer_on" ;;
  show) [ -f "$STUB/service_running" ] && echo activating || echo inactive ;;
  start) if [ -f "$STUB/start_fails" ]; then exit 1; fi
         if [ -s "$STUB/start_fails_left" ]; then n="$(cat "$STUB/start_fails_left")"; echo $((n - 1)) > "$STUB/start_fails_left"; if [ "$n" -gt 0 ]; then exit 1; fi; fi ;; esac
STUB
chmod 755 "$T/bin/curl" "$T/bin/systemctl"
export PATH="$T/bin:$PATH"

# --- the fixture: a base, and sites made from it ---------------------------------
img() {  # ID-NUMBER LABEL -> id
    local id; id="$(printf '%012x%052x' "$1" "$1")"; mkdir -p "$STUB/images/$id"; [ -n "$2" ] && echo "$2" > "$STUB/images/$id/label"; echo "$id"
}
site() {  # NAME IDBLOCK PORT IMAGE-ID
    local n="$1" v
    mkdir -p "$R/etc/joinery/sites/$n" "$STUB/containers/$n"
    { printf 'spec_version=2\nhostname=%s\nrestart=always\nmemory=384m\ncpus=\npids_limit=512\nidblock=%s\n' "$n" "$2"
      printf 'publish=127.0.0.1:%s:80\npublish=127.0.0.1:%s:5432\n' "$3" "$(( $3 + 1000 ))"
      for v in code scripts postgres config agent; do printf 'volume=%s_%s:/x/%s\n' "$n" "$v" "$v"; done; } > "$R/etc/joinery/sites/$n/run_spec"
    for v in code scripts postgres config agent; do mkdir -p "$STUB/volumes/${n}_${v}"; done
    echo 0.8.503 > "$STUB/volumes/${n}_code/VERSION"
    mkdir -p "$STUB/volumes/${n}_postgres/18/main"; echo 18 > "$STUB/volumes/${n}_postgres/18/main/PG_VERSION"
    echo "$4" > "$STUB/containers/$n/image"; echo running > "$STUB/containers/$n/state"
    sha256sum "$R/etc/containers/joinery-site-seccomp.json" | cut -d' ' -f1 > "$STUB/containers/$n/seccomp"
    printf 'SITENAME=%s\nDOMAIN_NAME=%s.example.com\nPOSTGRES_PASSWORD=pw-%s\n' "$n" "$n" "$n" > "$STUB/containers/$n/env"
    mkdir -p "$STUB/fs/var/www/html/$n"
    echo "manifest $n" > "$STUB/fs/var/www/html/$n/RELEASE_MANIFEST"; echo "sig $n" > "$STUB/fs/var/www/html/$n/RELEASE_MANIFEST.sig"
    mkdir -p "$STUB/fs/var/www/html/$n/config/release_manifest"
    echo "kept manifest $n" > "$STUB/fs/var/www/html/$n/config/release_manifest/RELEASE_MANIFEST"
    echo "kept sig $n" > "$STUB/fs/var/www/html/$n/config/release_manifest/RELEASE_MANIFEST.sig"
    echo "/var/www/html/$n" > "$STUB/mfdir.$n"
}
reset() {
    rm -rf "$STUB" "$R"
    mkdir -p "$STUB/tags" "$R/etc/joinery/host_restrictions" "$R/etc/containers"; : > "$STUB/calls"
    echo '{"defaultAction":"SCMP_ACT_ERRNO"}' > "$R/etc/containers/joinery-site-seccomp.json"
    echo 100 > "$STUB/n"
    BASE="$(img 1 "")"; echo "$BASE_HASH" > "$STUB/images/$BASE/hash"
    echo "$BASE" > "$STUB/tags/joinery-base:${BASE_VERSION}"; echo "$BASE" > "$STUB/tags/joinery-base:latest"
    OLD_A="$(img 2 "$BASE")"; echo "$OLD_A" > "$STUB/tags/joinery-sa:latest"
    OLD_B="$(img 3 "")"; echo "$OLD_B" > "$STUB/tags/joinery-sb:latest"
    site sa 0 8080 "$OLD_A"; site sb 1 8081 "$OLD_B"
    printf 'php8.5-apcu|php-apcu\nlibjpeg-turbo-progs|libjpeg-turbo-progs\n' > "$STUB/declared"
}
tagid() { cat "$STUB/tags/$1" 2>/dev/null; }
cimg() { cat "$STUB/containers/$1/image" 2>/dev/null; }
rec() { bash "$RECREATE" "$@" > "$T/out" 2>&1; echo $?; }
last() { tail -n 1 "$T/out"; }
outcome() { last | sed -n 's/.*"outcome":"\([a-z_]*\)".*/\1/p'; }
nothing_stopped() { grep -cE '^(stop |rm |run -d )' "$STUB/calls"; }

echo "=== recreate_site: what is refused, with nothing stopped ==="
reset
chk "a name that is not a site's: refused" "$(rec '../x'; )|$(outcome)" "1|refused"
chk "not root and no test root: refused" "$(JOINERY_SITE_STATE_ROOT= bash "$RECREATE" sa > "$T/out" 2>&1; echo $?)|$(grep -m1 -c 'run as root' "$T/out")" "1|1"
mv "$R/etc/joinery/sites/sa/run_spec" "$T/spec.aside"
chk "no run spec: refused" "$(rec sa)|$(grep -m1 -c 'has no run spec' "$T/out")" "1|1"
mv "$T/spec.aside" "$R/etc/joinery/sites/sa/run_spec"
echo copy > "$R/etc/joinery/sites/sa/quiet"
chk "a quiet site (a dormant copy, a frozen source): refused before anything is built" "$(rec sa)|$(grep -m1 -c 'is quiet' "$T/out")|$(grep -c '^build' "$STUB/calls")" "1|1|0"
rm "$R/etc/joinery/sites/sa/quiet"; echo restart=always > "$R/etc/joinery/sites/sa/held"
chk "a held site: refused" "$(rec sa)|$(grep -m1 -c 'held stopped' "$T/out")" "1|1"
rm "$R/etc/joinery/sites/sa/held"; mkdir -p "$R/root/rebase/sa"; printf 'stage=prepared\n' > "$R/root/rebase/sa/state"
chk "a site being rebased: refused" "$(rec sa)|$(grep -m1 -c 'being rebased' "$T/out")" "1|1"
printf 'stage=prepared\nstage=finished\n' > "$R/root/rebase/sa/state"
echo exited > "$STUB/containers/sa/state"
chk "a rebase finished does not refuse; a site not running does" "$(rec sa)|$(grep -m1 -c 'not running' "$T/out")" "1|1"
echo running > "$STUB/containers/sa/state"; sed -i 's/^SITENAME=sa/SITENAME=other/' "$STUB/containers/sa/env"
chk "a container whose SITENAME is not its name: refused" "$(rec sa)|$(grep -m1 -c "is not that site's own" "$T/out")" "1|1"
sed -i 's/^SITENAME=other/SITENAME=sa/' "$STUB/containers/sa/env"; mv "$STUB/volumes/sa_agent" "$T/vol.aside"
chk "a volume its spec mounts is missing (a run would make it empty): refused" "$(rec sa)|$(grep -m1 -c 'sa_agent' "$T/out")" "1|1"
mv "$T/vol.aside" "$STUB/volumes/sa_agent"; rm "$STUB/volumes/sa_code/VERSION"
chk "a code volume with no release: refused" "$(rec sa)|$(grep -m1 -c 'holds no release' "$T/out")" "1|1"
echo 0.8.503 > "$STUB/volumes/sa_code/VERSION"; rm "$STUB/tags/joinery-base:${BASE_VERSION}"
chk "no base image: refused" "$(rec sa)|$(grep -m1 -c "no joinery-base:${BASE_VERSION}" "$T/out")" "1|1"
echo "$BASE" > "$STUB/tags/joinery-base:${BASE_VERSION}"; echo 19 > "$STUB/pgmajor"
chk "a base whose PostgreSQL cannot open the database: refused, naming the rebase" "$(rec sa)|$(grep -m1 -c 'rebase_site_container' "$T/out")" "1|1"
rm "$STUB/pgmajor"; echo "4242 php /var/www/html/sa/public_html/utils/upgrade.php" > "$STUB/busy.sa"
chk "busy with an upgrade: refused as busy (2), before anything is built" "$(rec sa)|$(outcome)|$(grep -m1 -c 'upgrade.php' "$T/out")|$(grep -c '^build' "$STUB/calls")" "2|refused|1|0"
rm "$STUB/busy.sa"; echo 2 > "$STUB/pgrep_rc"
chk "busy cannot be told (pgrep does not run): refused, never taken for idle" "$(rec sa)|$(grep -m1 -c 'could not be told' "$T/out")" "1|1"
rm "$STUB/pgrep_rc"; printf 'php8.5-apcu|php-apcu\n--allow-unauthenticated\n' > "$STUB/declared"
chk "a declared package that is not a package name: refused" "$(rec sa)|$(grep -m1 -c 'not a package name' "$T/out")" "1|1"
printf 'php8.5-apcu|php-apcu\n' > "$STUB/declared"; touch "$STUB/build_fail"
chk "an image that does not build: refused" "$(rec sa)|$(grep -m1 -c 'did not build' "$T/out")" "1|1"
rm "$STUB/build_fail"
chk "none of these stopped, removed or ran a container" "$(nothing_stopped)" "0"
chk "  and the site runs its own image still" "$(cimg sa)|$(cat "$STUB/containers/sa/state")" "$OLD_A|running"
chk "  and no image is left tagged next" "$(ls "$STUB/tags" | grep -m1 -c ':next')" "0"
chk "  and no mark is left" "$(ls "$R/etc/joinery/sites/sa/")" "run_spec"
exec 9>>"$R/run/joinery/recreate.sa.lock"; flock 9
chk "a second replacement of the same site while one runs: refused" "$(rec sa)|$(grep -m1 -c 'under way' "$T/out")" "1|1"
exec 9>&-

echo "=== recreate_site: a replacement ==="
reset
echo "$(img 50 "")" > /dev/null
mkdir -p "$R/var/lib/joinery/site-refresh/held" "$R/var/tmp/joinery-recreate-sa.STALE" "$R/run/joinery"
printf 'outcome=rolled_back\nbase=%s\n' "${BASE:0:12}" > "$R/var/lib/joinery/site-refresh/held/sa"
printf 'outcome=rolled_back\nbase=%s\n' "${BASE:0:12}" > "$R/var/lib/joinery/site-refresh/held/sb"
echo 'POSTGRES_PASSWORD=stale' > "$R/run/joinery/recreate.sa.env"
echo 'POSTGRES_PASSWORD=stale' > "$R/etc/joinery/sites/sa/recreate.env"
: > "$STUB/calls"
chk "replaced: exit 0" "$(rec sa)|$(outcome)" "0|replaced"
NEW="$(tagid joinery-sa:latest)"
chk "the image was built before anything stopped" "$(awk '/^build/ && !b { b = NR } /^stop/ && !s { s = NR } END { print (b && s && b < s) ? "yes" : "no" }' "$STUB/calls")" "yes"
chk "the new image carries the base's id" "$(cat "$STUB/images/$NEW/label")" "$BASE"
chk "the site runs the new image" "$(cimg sa)|$(cat "$STUB/containers/sa/state")" "$NEW|running"
chk "the previous image is kept as joinery-sa:previous" "$(tagid joinery-sa:previous)" "$OLD_A"
chk "no image is left tagged next" "$(ls "$STUB/tags" | grep -m1 -c ':next')" "0"
chk "the container is run with its own environment, from a file only root can read" "$(grep -m1 -c '^POSTGRES_PASSWORD=pw-sa$' "$STUB/containers/sa/env")|$(cat "$STUB/envmode")" "1|600"
chk "  and the file is gone after" "$(ls "$R/etc/joinery/sites/sa/" | grep -c '\.env')" "0"
chk "the site was marked as being replaced when it stopped, and the mark is gone after" "$(cat "$STUB/mark_at_stop")|$(ls "$R/etc/joinery/sites/sa/")" "marked|run_spec"
chk "no lock descriptor reached the engine (a container monitor would hold it for good)" "$(cat "$STUB/fdleak" 2>/dev/null)" ""
chk "the build context: this tree's Dockerfile, start command and vhost template, the site's manifest, and no code" \
    "$(cat "$STUB/context_listing")" "./Dockerfile ./maintenance_scripts/install_tools/_site_start.sh ./maintenance_scripts/install_tools/default_virtualhost.conf ./sa/RELEASE_MANIFEST ./sa/RELEASE_MANIFEST.sig "
chk "  the start command is this tree's own, not the site's" "$(cmp -s "$TOOLS/_site_start.sh" "$STUB/context_start" && echo same)" "same"
chk "the extension packages the site declared, as a build argument" "$(cat "$STUB/declared_arg")" "php8.5-apcu|php-apcu libjpeg-turbo-progs|libjpeg-turbo-progs"
chk "seconds down recorded" "$(last | grep -cE '"down_seconds":[0-9]+\.[0-9]')" "1"
chk "this site's hold is lifted by its replacement; another site's stays" "$([ -f "$R/var/lib/joinery/site-refresh/held/sa" ] && echo kept || echo lifted)|$([ -f "$R/var/lib/joinery/site-refresh/held/sb" ] && echo kept || echo lifted)" "lifted|kept"
chk "what a killed run left (its environment file, its working folder) is gone" "$(ls "$R/run/joinery/" "$R/etc/joinery/sites/sa/" | grep -c '\.env')|$(ls "$R/var/tmp/" | grep -c STALE)" "0|0"
rm -f "$R/var/lib/joinery/site-refresh/held/sb"
chk "untagged images are pruned" "$(wc -l < "$STUB/pruned")" "1"
chk "the other site was not touched" "$(cimg sb)" "$OLD_B"
echo "/var/www/html/sb/config/release_manifest" > "$STUB/mfdir.sb"
chk "the code matches only the kept copy (the root holds an older release's): the kept copy is carried" \
    "$(rec sb)|$(grep -m1 -c "the one at its root is another release's" "$T/out")" "0|1"
echo "/var/www/html/sb" > "$STUB/mfdir.sb"
rm -f "$STUB/fs/var/www/html/sb/RELEASE_MANIFEST" "$STUB/fs/var/www/html/sb/RELEASE_MANIFEST.sig"
chk "a site with no manifest at its root is replaced, saying so" "$(rec sb)|$(grep -m1 -c 'no signed release manifest' "$T/out")" "0|1"

echo "=== recreate_site: back onto the previous image ==="
reset
touch "$STUB/next_build_fails"
chk "a new container that does not come up: rolled back (3)" "$(rec sa)|$(outcome)" "3|rolled_back"
FAILED_ID="$(tagid joinery-sa:failed)"
chk "the site runs its previous image again" "$(cimg sa)|$(cat "$STUB/containers/sa/state")|$(tagid joinery-sa:latest)" "$OLD_A|running|$OLD_A"
chk "the image that failed is kept as joinery-sa:failed" "$([ -f "$STUB/images/$FAILED_ID/fail" ] && echo yes)" "yes"
chk "its last words are kept" "$(grep -c "last words" "$R/var/log/joinery/recreate/sa.failed.log")" "1"
chk "  and the mark is gone" "$(ls "$R/etc/joinery/sites/sa/")" "run_spec"
chk "  and the site holds itself, with this run's reason and base" \
    "$(sed -n 's/^outcome=//p;s/^base=//p' "$R/var/lib/joinery/site-refresh/held/sa" | tr '\n' ' ')|$(grep -c '^reason=the new container' "$R/var/lib/joinery/site-refresh/held/sa")" "rolled_back ${BASE:0:12} |1"
reset
touch "$STUB/arm_fail_all"
chk "neither image comes up after the swap: down (4)" "$(rec sa)|$(outcome)" "4|down"
reset
touch "$STUB/arm_fail_all" "$STUB/arm_run_fail"
chk "left down with no container (the engine refuses both runs): down, and its mark and environment file are kept" \
    "$(rec sa)|$(outcome)|$([ -d "$STUB/containers/sa" ] && echo container || echo none)|$(ls "$R/etc/joinery/sites/sa/" | tr '\n' ' ')" "4|down|none|recreate.env replacing run_spec "
rm -f "$STUB/arm_fail_all" "$STUB/arm_run_fail" "$STUB/fail_all"
chk "  so the next run (the Replace button) runs it again on its previous image, and lifts its down hold" \
    "$(rec sa)|$(outcome)|$(cimg sa)|$([ -f "$R/var/lib/joinery/site-refresh/held/sa" ] && echo held || echo lifted)" "0|restored|$OLD_A|lifted"
rm -rf "$STUB/containers/sb"
chk "a site with no container and nothing left to run it with: refused, naming install.sh site" "$(rec sb)|$(grep -m1 -c 'install.sh site makes it again' "$T/out")" "1|1"
reset
touch "$STUB/tag_fails"
chk "the new image cannot be tagged to run: rolled back, never reported as replaced on the old image" \
    "$(rec sa)|$(outcome)|$(grep -m1 -c 'could not be tagged' "$T/out")|$(cimg sa)" "3|rolled_back|1|$OLD_A"

echo "=== recreate_site: what reviewer2's review added ==="
reset
echo 500 > "$STUB/curl_code"; : > "$STUB/calls"
chk "a site that does not answer now: refused before anything is built (its failure is not the image's)" \
    "$(rec sa)|$(grep -m1 -c 'does not answer now' "$T/out")|$(grep -c '^build' "$STUB/calls")|$(nothing_stopped)" "1|1|0|0"
rm "$STUB/curl_code"
printf 'php8.5-apcu|php-apcu\napache2-\n' > "$STUB/declared"
chk "a declared package ending in - (apt: remove it): refused" "$(rec sa)|$(grep -m1 -c 'not a package name' "$T/out")" "1|1"
printf 'php8.5-apcu|php-apcu\nlibstdc++6|g++\n' > "$STUB/declared"
chk "a name ending in + is a package's (g++)" "$(rec sb)|$(outcome)" "0|replaced"
reset
echo 2 > "$STUB/busy_left.sa"
chk "a scheduled task running is waited out (here two asks), then the site is replaced" "$(BUSY_WAIT_SECONDS=30 rec sa)|$(outcome)" "0|replaced"
reset
echo 99 > "$STUB/busy_left.sa"; : > "$STUB/calls"
chk "  one still running past the wait: busy (2), nothing built" "$(BUSY_WAIT_SECONDS=6 rec sa)|$(grep -m1 -c 'process_scheduled_tasks' "$T/out")|$(grep -c '^build' "$STUB/calls")" "2|1|0"
chk "  the site's own scheduled tasks (its backups run inside them) count as busy" "$(grep -c 'process_scheduled_tasks)' "$RECREATE")" "1"
reset
echo "$R/etc/joinery/sites/sa/quiet" > "$STUB/build_touch"; : > "$STUB/calls"
chk "a site made quiet while its image built: refused, nothing stopped, the image dropped" \
    "$(rec sa)|$(grep -m1 -c 'became quiet' "$T/out")|$(nothing_stopped)|$(ls "$STUB/tags" | grep -c ':next')" "1|1|0|0"
reset
echo 30 > "$STUB/build_sleep"; touch "$STUB/build_leaves"; echo "wcother y-working-container" > "$STUB/external"
T0=$(date +%s)
chk "a build past its bound is stopped and refused" "$(RECREATE_BUILD_SECONDS=1 rec sa)|$(grep -m1 -c 'did not build' "$T/out")" "1|1"
chk "  its working containers removed (a second one buildah suffixes -1 too); one another build made before it kept" "$(cat "$STUB/external" | tr '\n' ' ')" "wcother y-working-container "
chk "  within its bound and the ten seconds' grace, and nothing of it left running" "$(( $(date +%s) - T0 < 20 ))|$(pgrep -f 'sleep 30$' -u "$(id -u)" -P 1 | wc -l)" "1|0"
reset
NEWBAD="$(img 60 "$BASE")"; touch "$STUB/images/$NEWBAD/fail"
echo "$OLD_A" > "$STUB/tags/joinery-sa:previous"; echo "$NEWBAD" > "$STUB/tags/joinery-sa:latest"
echo "$NEWBAD" > "$STUB/containers/sa/image"; echo exited > "$STUB/containers/sa/state"
date -u > "$R/etc/joinery/sites/sa/replacing"; touch -d '3 hours ago' "$R/etc/joinery/sites/sa/replacing"
chk "a replacement killed part way (its mark left, the site stopped): put back on its previous image, no build" \
    "$(rec sa)|$(outcome)|$(cimg sa)|$(cat "$STUB/containers/sa/state")|$(grep -c '^build' "$STUB/calls")" "0|restored|$OLD_A|running|0"
chk "  and its mark is gone" "$(ls "$R/etc/joinery/sites/sa/")" "run_spec"
echo "$NEWBAD" > "$STUB/containers/sa/image"; echo "$NEWBAD" > "$STUB/tags/joinery-sa:latest"; date -u > "$R/etc/joinery/sites/sa/replacing"
chk "an interrupted replacement left running a new image that does not answer: put back too" \
    "$(rec sa)|$(outcome)|$(cimg sa)" "0|restored|$OLD_A"
rm -rf "$STUB/containers/sa"; date -u > "$R/etc/joinery/sites/sa/replacing"
printf 'SITENAME=sa\nDOMAIN_NAME=sa.example.com\nPOSTGRES_PASSWORD=pw-sa\n' > "$R/etc/joinery/sites/sa/recreate.env"
chk "killed between remove and run (no container): run again from the environment file it left" \
    "$(rec sa)|$(outcome)|$(cimg sa)|$(grep -c '^POSTGRES_PASSWORD=pw-sa$' "$STUB/containers/sa/env")" "0|restored|$OLD_A|1"
date -u > "$R/etc/joinery/sites/sa/replacing"; : > "$STUB/calls"
chk "an interrupted replacement whose site answers: its mark is cleared, and it is replaced as any other" \
    "$(rec sa)|$(outcome)|$(grep -c '^build' "$STUB/calls")|$(grep -c 'its mark is cleared' "$T/out")" "0|replaced|1|1"
echo exited > "$STUB/containers/sa/state"
chk "a stopped site with no mark is not a replacement to complete: refused" "$(rec sa)|$(grep -m1 -c 'not running' "$T/out")" "1|1"
reset
touch "$STUB/next_build_fails"
RECREATE_WAIT_SECONDS=60 bash "$RECREATE" sa > "$T/term.out" 2>&1 &
RPID=$!
for i in $(seq 1 100); do [ -f "$R/etc/joinery/sites/sa/replacing" ] && grep -q '^run -d' "$STUB/calls" && break; sleep 0.2; done
sleep 1; kill -TERM "$RPID"; wait "$RPID" 2> /dev/null
chk "a run stopped (TERM) in the swap keeps its mark and its environment file" \
    "$([ -f "$R/etc/joinery/sites/sa/replacing" ] && echo mark)|$([ -f "$R/etc/joinery/sites/sa/recreate.env" ] && echo env)" "mark|env"
chk "  and the next run (waiting out the stopped run's last command) completes it onto the previous image" "$(RECREATE_LOCK_WAIT=15 rec sa)|$(outcome)|$(cimg sa)|$(ls "$R/etc/joinery/sites/sa/")" "0|restored|$OLD_A|run_spec"

echo "=== site_refresh check ==="
reset
srun() { bash "$REFRESH" "$@" > "$T/rout" 2>&1; echo $?; }
st() { sed -n "s/^$1=//p" "$R/var/lib/joinery/site-refresh/state"; }
printf '%s\n' 'Inst tzdata [2026c] (2026e Ubuntu:26.04/resolute-updates [all])' \
    'Inst curl [8.18.0-1ubuntu2.7] (8.18.0-1ubuntu2.8 Ubuntu:26.04/resolute-security [amd64]) []' \
    'Inst apache2 [2.4.66-2ubuntu2.5] (2.4.66-2ubuntu2.6 Ubuntu:26.04/resolute-updates, Ubuntu:26.04/resolute-security [amd64])' > "$STUB/apt"
chk "check: exit 0" "$(srun check)" "0"
chk "  every update and the security fixes counted, the fixes named" "$(st pending)|$(st pending_security)|$(st security_packages)" "3|2|curl apache2"
chk "  a security fix makes the base due" "$(st base_due)|$(st check)" "fix|ok"
chk "  sb's image does not carry the base's id: 1 of 2 sites due" "$(st sites_due)|$(st sites)" "1|2"
chk "  the check changed nothing" "$(nothing_stopped)|$(grep -m1 -c '^build' "$STUB/calls")" "0|0"
OK_AT="$(st checked_ok_at)"
touch "$STUB/check_fail"; sleep 1
chk "a check that fails: recorded, the last good check's time kept" "$(srun check)|$(st check)|$(st checked_ok_at)" "1|failed|$OK_AT"
rm "$STUB/check_fail"
grep -v security "$STUB/apt" > "$STUB/apt.n"; mv "$STUB/apt.n" "$STUB/apt"
chk "updates but no security fix: the base is not due" "$(srun check)|$(st base_due)|$(st pending_security)" "0|no|0"
echo 0000 > "$STUB/images/$BASE/hash"
chk "a base this tree would build differently is due" "$(srun check)|$(st base_due)" "0|setup_changed"

echo "=== site_refresh run ==="
reset
: > "$STUB/apt"; : > "$STUB/calls"
chk "nothing pending: no base is built; the one site not on this base is replaced" "$(srun run)|$(st run)|$(grep -c -- '-f .*Dockerfile.base' "$STUB/calls")" "0|replaced|0"
chk "  sb is on the base now, sa never stopped" "$(cat "$STUB/images/$(cimg sb)/label")|$(cimg sa)" "$BASE|$OLD_A"
chk "  and the run says what it replaced" "$(st replaced | sed 's/:[0-9.]*//g')|$(st sites_due)" "sb|0"
: > "$STUB/calls"
chk "a second night with nothing pending does nothing" "$(srun run)|$(st run)|$(nothing_stopped)|$(grep -m1 -c '^build' "$STUB/calls")" "0|nothing_due|0|0"
echo 'Inst curl [1] (2 Ubuntu:26.04/resolute-security [amd64])' > "$STUB/apt"; : > "$STUB/calls"
chk "a security fix: the base is built fresh, then every site replaced" "$(srun run)|$(st run)|$(grep -c 'building the base fresh (fix: 1' "$T/rout")" "0|replaced|1"
chk "  the new base is checked in turn: the state says what it waits for (a fresh build took the fix)" "$(st base_due)|$(st pending_security)" "no|0"
NB="$(tagid joinery-base:${BASE_VERSION})"
chk "  the base built with a fresh pull and no cache, before any site stopped" \
    "$(grep -c -- 'build -q --format docker --no-cache --hooks-dir /usr/share/containers/oci/hooks.d --hooks-dir /etc/containers/oci/hooks.d --pull=always --memory 2g -f .*Dockerfile.base' "$STUB/calls")|$(awk '/Dockerfile.base/ && !b { b = NR } /^stop/ && !s { s = NR } END { print (b < s) ? "first" : "after" }' "$STUB/calls")" "1|first"
chk "  both sites on the new base" "$(cat "$STUB/images/$(cimg sa)/label")|$(cat "$STUB/images/$(cimg sb)/label")" "$NB|$NB"
chk "  one at a time: stop, run, stop, run" "$(grep -oE '^(stop|run -d) ' "$STUB/calls" | tr -d '\n')" "stop run -d stop run -d "
chk "no lock descriptor reached the engine from the nightly run either" "$(cat "$STUB/fdleak" 2>/dev/null)" ""

echo "=== site_refresh: a rollback holds that site; a second, or a site left down, stops the run ==="
reset
: > "$STUB/apt"; rm -f "$STUB/images/$OLD_A/label"   # both sites due, no fix pending
touch "$STUB/next_build_fails"                       # the first site image built fails to come up
chk "one site rolled back: held, and the run goes on with the other" "$(srun run)|$(st run)|$(ls "$R/var/lib/joinery/site-refresh/held")|$(cat "$STUB/images/$(cimg sb)/label")" "0|incomplete|sa|$BASE"
chk "  the hold names the outcome and the base" "$(sed -n 's/^outcome=//p;s/^base=//p' "$R/var/lib/joinery/site-refresh/held/sa" | tr '\n' ' ')" "rolled_back ${BASE:0:12} "
: > "$STUB/calls"
chk "the next night: the held site is not due and nothing is done" "$(srun run)|$(st sites_due)|$(nothing_stopped)" "0|0|0"
chk "clear SITE lifts that hold, and the next run replaces it" "$(bash "$REFRESH" clear sa)|$(srun run)|$(st run)|$(st sites_due)" "site_refresh: sa's hold is lifted; the next run replaces it|0|replaced|0"
reset
echo 'Inst curl [1] (2 Ubuntu:26.04/resolute-security [amd64])' > "$STUB/apt"
# Every site image built from here fails to come up.
touch "$STUB/sites_fail"
sed -i 's|\[ -f "$S/next_build_fails" \] \&\& { touch "$S/images/$id/fail"; rm -f "$S/next_build_fails"; }|[ -f "$S/next_build_fails" ] \&\& { touch "$S/images/$id/fail"; rm -f "$S/next_build_fails"; }; [ -f "$S/sites_fail" ] \&\& [ -z "$file" ] \&\& touch "$S/images/$id/fail"|' "$T/bin/podman"
chk "a second site rolled back on the same base: the run stops" "$(srun run)|$(st run)|$(ls "$R/var/lib/joinery/site-refresh/held" | tr '\n' ' ')" "0|held|sa sb "
NB="$(tagid joinery-base:${BASE_VERSION})"
chk "  both holds name the new base; both sites run their old images" \
    "$(cat "$R"/var/lib/joinery/site-refresh/held/* | sed -n 's/^base=//p' | sort -u)|$(cimg sa)|$(cimg sb)" "${NB:0:12}|$OLD_A|$OLD_B"
rm "$STUB/sites_fail"; : > "$STUB/calls"; : > "$STUB/apt"
chk "the next night, on the same base: held, nothing done" "$(srun run)|$(st run)|$(nothing_stopped)|$(grep -m1 -c '^build' "$STUB/calls")" "0|held|0|0"
echo 'Inst curl [1] (3 Ubuntu:26.04/resolute-security [amd64])' > "$STUB/apt"
chk "a later fix builds a new base, which lifts the holds, and every site is replaced" "$(srun run)|$(st run)|$(ls "$R/var/lib/joinery/site-refresh/held" | wc -l)|$(st sites_due)" "0|replaced|0|0"
reset
: > "$STUB/apt"; rm -f "$STUB/images/$OLD_A/label"; touch "$STUB/arm_fail_all"
chk "a site left down stops the run at once" "$(srun run)|$(st run)|$(sed -n 's/^outcome=//p' "$R/var/lib/joinery/site-refresh/held/sa")|$(cimg sb)" "0|held|down|$OLD_B"
rm -f "$STUB/arm_fail_all" "$STUB/fail_all"; echo exited > "$STUB/containers/sa/state"   # down: not running
echo 'Inst curl [1] (2 Ubuntu:26.04/resolute-security [amd64])' > "$STUB/apt"
chk "a new base: a site left down stays held (it is still down), and the run goes on with the others" \
    "$(srun run)|$(sed -n 's/^outcome=//p' "$R/var/lib/joinery/site-refresh/held/sa")|$(cat "$STUB/images/$(cimg sb)/label")" "0|down|$(tagid joinery-base:${BASE_VERSION})"

echo "=== site_refresh: a site made under another system call filter is due (WP3) ==="
reset
: > "$STUB/apt"; echo "$BASE" > "$STUB/images/$OLD_B/label"      # both sites on the base
FILTER_OLD="$(cat "$STUB/containers/sa/seccomp")"
chk "both sites on the base and the host's filter: nothing due" "$(srun check)|$(st sites_due)" "0|0"
echo '{"defaultAction":"SCMP_ACT_ERRNO","architectures":[]}' > "$R/etc/containers/joinery-site-seccomp.json"
FILTER_NEW="$(sha256sum "$R/etc/containers/joinery-site-seccomp.json" | cut -d' ' -f1)"
chk "the host's filter changes: both sites are due, though on the current base" "$(srun check)|$(st sites_due)|$(st base_due)" "0|2|no"
: > "$STUB/calls"
chk "  the run replaces both, on the same base, saying why" \
    "$(srun run)|$(st run)|$(grep -c 'replacing s[ab] (an older system call filter)' "$T/rout")|$(grep -c -- 'Dockerfile.base' "$STUB/calls")" "0|replaced|2|0"
chk "  each new container carries the host's filter" "$(cat "$STUB/containers/sa/seccomp")|$(cat "$STUB/containers/sb/seccomp")" "$FILTER_NEW|$FILTER_NEW"
chk "  the next night: nothing due" "$(srun run)|$(st run)|$(st sites_due)" "0|nothing_due|0"
rm -f "$STUB/containers/sb/seccomp"
chk "a container made before the label: due" "$(srun check)|$(st sites_due)" "0|1"
chk "the run arguments carry the filter's hash as joinery.seccomp" \
    "$(bash -c ". '$TOOLS/_site_run_spec.sh'; run_spec_args sa" 2>/dev/null | tr '\0' '\n' | grep -A1 -x -- '--label' | tail -n1)" "joinery.seccomp=$FILTER_NEW"
[ "$FILTER_OLD" != "$FILTER_NEW" ] || chk "the two filters differ" "same" "different"

echo "=== site_refresh: the check ==="
reset
echo 'Inst libfoo-security-dev [1] (2 Ubuntu:26.04/resolute-updates [amd64])' > "$STUB/apt"
chk "a package named for security, from -updates, is no fix" "$(srun check)|$(st pending_security)|$(st base_due)" "0|0|no"
chk "the check container holds only apt's file capabilities, gains none, and counts a full upgrade" \
    "$(grep -c -- '--cap-drop=ALL --cap-add=CHOWN --cap-add=DAC_OVERRIDE --cap-add=FOWNER --security-opt=no-new-privileges' "$STUB/check_args")|$(grep -c 'full-upgrade' "$STUB/check_args")" "1|1"
chk "  each capability its own flag: Docker refuses a comma list (box proof, exit 125)" "$(grep -c -- '--cap-add=[A-Z_]*,' "$STUB/check_args")" "0"
touch "$STUB/check_fail"; : > "$STUB/calls"
chk "a check that fails still replaces the sites already due on the current base, and builds nothing" \
    "$(srun run)|$(st check)|$(cat "$STUB/images/$(cimg sb)/label")|$(grep -c -- 'Dockerfile.base' "$STUB/calls")" "0|failed|$BASE|0"
rm "$STUB/check_fail"
echo 'Inst curl [1] (2 Ubuntu:26.04/resolute-security [amd64])' > "$STUB/apt"; : > "$STUB/build_args"
srun run > /dev/null
chk "the base builds under a memory limit" "$(grep -c -- 'Dockerfile.base' "$STUB/build_args")|$(grep 'Dockerfile.base' "$STUB/build_args" | grep -c -- '--memory 2g')" "1|1"
reset
: > "$STUB/apt"; echo 30 > "$STUB/build_sleep"
chk "one site past its slot: stopped, passed over as timed out, and the run goes on" \
    "$(SITE_REFRESH_SITE_TIMEOUT=2 srun run)|$(st passed_over)|$(st run)" "0|sb:timed_out|incomplete"
rm "$STUB/build_sleep"

echo "=== site_refresh: what is passed over ==="
reset
: > "$STUB/apt"
echo "77 pg_dump sb" > "$STUB/busy.sb"
chk "a busy site is passed over and stays due" "$(srun run)|$(st run)|$(st passed_over)|$(st sites_due)" "0|incomplete|sb:busy|1"
rm "$STUB/busy.sb"; echo copy > "$R/etc/joinery/sites/sb/quiet"
chk "a quiet site is never due" "$(srun run)|$(st run)|$(st sites_due)|$(cimg sb)" "0|nothing_due|0|$OLD_B"
rm "$R/etc/joinery/sites/sb/quiet"; echo restart=always > "$R/etc/joinery/sites/sb/held"
chk "a held site is never due" "$(srun run)|$(st sites_due)|$(cimg sb)" "0|0|$OLD_B"
rm "$R/etc/joinery/sites/sb/held"
echo 'Inst curl [1] (2 Ubuntu:26.04/resolute-security [amd64])' > "$STUB/apt"; : > "$STUB/calls"
chk "too little room for a fresh base: nothing built or stopped" \
    "$(SITE_REFRESH_MIN_FREE_BYTES=999999999999999 bash "$REFRESH" run > "$T/rout" 2>&1; echo $?)|$(st run)|$(grep -m1 -c '^build' "$STUB/calls")|$(nothing_stopped)" "0|base_failed|0|0"
chk "a second run while one runs: it says so and does nothing" \
    "$(exec 9>>"$R/run/joinery/site-refresh.lock"; flock 9; bash "$REFRESH" run 2>&1 | grep -m1 -c 'under way')" "1"

echo "=== site_refresh install ==="
TR="$T/tree"; mkdir -p "$TR/maintenance_scripts"
cp -r "$ROOT/maintenance_scripts/install_tools" "$ROOT/maintenance_scripts/sysadmin_tools" "$TR/maintenance_scripts/"
chmod -R go-w "$TR"
rm -rf "$R/etc/systemd" "$R/var/lib/joinery/site-refresh"; : > "$STUB/systemctl"; rm -f "$STUB/timer_on"
chk "install: writes the service and the timer, enables it, and records when" \
    "$(bash "$TR/maintenance_scripts/install_tools/site_refresh.sh" install > "$T/iout" 2>&1; echo $?)|$(ls "$R/etc/systemd/system" | tr '\n' ' ')|$(grep -m1 -c 'enable --now joinery-site-refresh.timer' "$STUB/systemctl")|$(grep -m1 -c '^installed_at=' "$R/var/lib/joinery/site-refresh/state")" \
    "0|joinery-site-refresh.service joinery-site-refresh.timer |1|1"
chk "  the service runs this tree's script; the timer is nightly, spread, and not caught up at boot" \
    "$(grep -c "^ExecStart=/bin/bash $TR/maintenance_scripts/install_tools/site_refresh.sh run$" "$R/etc/systemd/system/joinery-site-refresh.service")|$(grep -cE '^(OnCalendar=\*-\*-\* 01:30 UTC|RandomizedDelaySec=30m|Persistent=false)$' "$R/etc/systemd/system/joinery-site-refresh.timer")" "1|3"
chk "  the service sets no Nice: the containers it starts run at normal priority" "$(grep -m1 -c '^Nice=' "$R/etc/systemd/system/joinery-site-refresh.service")" "0"
: > "$STUB/systemctl"
chk "a second install: current, nothing reloaded" "$(bash "$TR/maintenance_scripts/install_tools/site_refresh.sh" install)|$(grep -c daemon-reload "$STUB/systemctl")" "site_refresh: the nightly replacement timer is current|0"
printf 'checked_at=x\n' > "$R/var/lib/joinery/site-refresh/state"
bash "$TR/maintenance_scripts/install_tools/site_refresh.sh" install > /dev/null 2>&1
chk "a state from before the timer gains when it was installed" "$(grep -c '^installed_at=..*' "$R/var/lib/joinery/site-refresh/state")|$(grep -c '^checked_at=x' "$R/var/lib/joinery/site-refresh/state")" "1|1"
for f in _host_files.sh _site_start.sh default_virtualhost.conf; do
    chmod g+w "$TR/maintenance_scripts/install_tools/$f"
    chk "  $f writable by another account: refused" "$(bash "$TR/maintenance_scripts/install_tools/site_refresh.sh" install 2>&1 | grep -c "not root's alone")" "1"
    chmod g-w "$TR/maintenance_scripts/install_tools/$f"
done
chmod g+w "$TR/maintenance_scripts/sysadmin_tools/recreate_site.sh"; : > "$STUB/systemctl"; touch "$STUB/timer_on"
chk "a tree another account can write: refused (the timer runs it as root), and a timer already on is stopped" \
    "$(bash "$TR/maintenance_scripts/install_tools/site_refresh.sh" install 2>&1 | grep -c "not root's alone")|$(grep -c 'disable --now joinery-site-refresh.timer' "$STUB/systemctl")" "1|1"

echo "=== Docker: a site made before run specs (fleet_security_patching WP4, B10) ==="
# docker-prod's six: no run spec, an image with no health check and no base id,
# Docker's own record the only account of how each is run.
export JOINERY_SITE_ENGINE_KIND=docker
ln -sf "$T/bin/podman" "$T/bin/docker"
dreset() {
    reset
    rm -f "$R/etc/joinery/sites/sa/run_spec" "$STUB/images/$OLD_A/label"; touch "$STUB/images/$OLD_A/nohealth"
    echo "-c" > "$STUB/containers/sa/cmd1"
    printf '127.0.0.1|8080|80/tcp\n127.0.0.1|9080|5432/tcp\n' > "$STUB/containers/sa/ports"
    for v in code scripts postgres config agent; do printf 'volume|sa_%s|/x/%s|true|/srv/sa_%s\n' "$v" "$v" "$v"; done > "$STUB/containers/sa/mounts"
    mkdir -p "$STUB/containers/tool"; echo "$OLD_B" > "$STUB/containers/tool/image"; echo running > "$STUB/containers/tool/state"
    printf 'SITENAME=sb\n' > "$STUB/containers/tool/env"
}
dreset
: > "$STUB/apt"; touch "$STUB/inspect_reads_stdin"
chk "check: a site with no run spec is counted and due; a container whose SITENAME is not its name is not a site" \
    "$(srun check)|$(st sites)|$(st sites_due)" "0|2|2"
rm "$STUB/inspect_reads_stdin"; : > "$STUB/calls"
chk "  the check wrote no run spec" "$(ls "$R/etc/joinery/sites/sa/")" ""
chk "the run: both sites replaced on the base, no base built" "$(srun run)|$(st run)|$(st sites_due)|$(grep -c -- 'Dockerfile.base' "$STUB/calls")" "0|replaced|0|0"
chk "  sa's run spec was recorded from its container first, saying so" \
    "$(grep -c "sa: recreate_site: recorded sa's run spec from its container" "$T/rout")|$(grep -cE '^(publish=127.0.0.1:8080:80|publish=127.0.0.1:9080:5432|volume=sa_code:/x/code|restart=unless-stopped|hostname=sa)$' "$R/etc/joinery/sites/sa/run_spec")" "1|5"
chk "  sa runs a new image on the base; the one with no health check was judged by its answer" \
    "$(cat "$STUB/images/$(cimg sa)/label")|$(tagid joinery-sa:previous)" "$BASE|$OLD_A"
chk "  sa's old start (a shell TERM does not end): its restart policy off, PostgreSQL stopped cleanly inside, then Apache, before the stop" \
    "$(grep -nE '^(update --restart=no sa|exec sa service postgresql stop|exec sa apache2ctl -k stop|stop -t 60 sa)$' "$STUB/calls" | cut -d: -f2 | tr '\n' ',')" \
    "update --restart=no sa,exec sa service postgresql stop,exec sa apache2ctl -k stop,stop -t 60 sa,"
chk "  sb, on the joinery start, is stopped as it always was" "$(grep -c '^exec sb service\|^exec sb apache2ctl' "$STUB/calls")|$(grep -c '^stop -t 60 sb$' "$STUB/calls")" "0|1"
chk "  the container that is no site was never stopped, removed or run" "$(cimg tool)|$(grep -cE '^(stop|rm|run -d) .*tool$' "$STUB/calls")" "$OLD_B|0"
dreset
touch "$STUB/next_build_fails"
chk "a new image that does not come up: rolled back onto the image with no health check, which answers" \
    "$(rec sa)|$(outcome)|$(cimg sa)|$(cat "$STUB/containers/sa/state")" "3|rolled_back|$OLD_A|running"
dreset
printf 'volume|sa_code|/x/code|true|/srv/sa_code\nbind||/data|true|/srv/data\n' > "$STUB/containers/sa/mounts"; : > "$STUB/calls"
chk "a container with what a spec cannot carry (a bind mount): refused by name, nothing built or stopped, no spec" \
    "$(rec sa)|$(grep -m1 -c "could not be recorded as one: .*a bind mount at /data" "$T/out")|$(grep -c '^build' "$STUB/calls")|$(nothing_stopped)|$(ls "$R/etc/joinery/sites/sa/" | grep -c run_spec)" "1|1|0|0|0"
dreset
sed -i 's/^SITENAME=sa/SITENAME=other/' "$STUB/containers/sa/env"
chk "a container with no run spec that is not the site's own: refused before a spec is recorded" \
    "$(rec sa)|$(grep -m1 -c "is not that site's own" "$T/out")|$(ls "$R/etc/joinery/sites/sa/" | grep -c run_spec)" "1|1|0"
chk "  and the nightly run does not count it" "$(srun check)|$(st sites)" "0|1"
dreset
touch "$STUB/rm_fails"; : > "$STUB/calls"
chk "an old-start container that cannot be removed: refused, started again with its restart policy back, the new image dropped (reviewer2)" \
    "$(rec sa)|$(outcome)|$(grep -E '^(update .*sa|exec sa (service|apache2ctl) .*|stop .*sa|rm -f sa|start sa)$' "$STUB/calls" | tr '\n' ',')|$(cimg sa)|$(cat "$STUB/containers/sa/state")|$(ls "$STUB/tags" | grep -c ':next')" \
    "1|refused|update --restart=no sa,exec sa service postgresql stop,exec sa apache2ctl -k stop,stop -t 60 sa,rm -f sa,start sa,update --restart=unless-stopped sa,|$OLD_A|running|0"
: > "$STUB/calls"
chk "  one on the joinery start: started again, and its policy never touched" \
    "$(rec sb)|$(grep -cE '^start sb$' "$STUB/calls")|$(grep -c '^update' "$STUB/calls")" "1|1|0"
rm -f "$STUB/rm_fails"
dreset
rm "$STUB/tags/joinery-base:${BASE_VERSION}"; : > "$STUB/calls"
chk "no base yet (docker-prod before its first run): refused before a run spec is recorded (reviewer2 F1)" \
    "$(rec sa)|$(grep -m1 -c "no joinery-base:${BASE_VERSION}" "$T/out")|$(ls "$R/etc/joinery/sites/sa/" | grep -c run_spec)" "1|1|0"
dreset
touch "$STUB/build_nohc"; : > "$STUB/calls"
chk "an image built with no health check (the template lost it): refused, nothing stopped, the image dropped (reviewer2 B)" \
    "$(rec sb)|$(grep -m1 -c 'carries no health check' "$T/out")|$(nothing_stopped)|$(ls "$STUB/tags" | grep -c ':next')" "1|1|0|0"
rm "$STUB/build_nohc"
dreset
rm -rf "$STUB/containers/sa" "$STUB/containers/sb"
echo 'Inst curl [1] (2 Ubuntu:26.04/resolute-security [amd64])' > "$STUB/apt"; : > "$STUB/calls"
chk "a Docker host with no site: a fix pending builds no base" \
    "$(srun run)|$(grep -c -- 'Dockerfile.base' "$STUB/calls")|$(grep -c 'no site container runs on this host' "$T/rout")|$(st run)" "0|0|1|nothing_due"
export JOINERY_SITE_ENGINE_KIND=podman
dreset
chk "on the site engine a container with no run spec is no site, and is refused" "$(srun check)|$(st sites)|$(rec sa)|$(grep -m1 -c 'has no run spec' "$T/out")" "0|1|1|1"
export JOINERY_SITE_ENGINE_KIND=docker

echo "=== site_refresh start: the nightly run, now (Refresh now) ==="
export SITE_REFRESH_SYSTEMCTL_PAUSE=0
rm -rf "$R/etc/systemd"; : > "$STUB/systemctl"; rm -f "$STUB/timer_on" "$STUB/service_running"
chk "not installed: refused" "$(srun start)|$(grep -c 'not installed on this host' "$T/rout")" "1|1"
mkdir -p "$R/etc/systemd/system"; : > "$R/etc/systemd/system/joinery-site-refresh.service"
chk "the timer off (a tree not root's alone stops it): refused, nothing started" "$(srun start)|$(grep -c 'timer is off' "$T/rout")|$(grep -c '^start' "$STUB/systemctl")" "1|1|0"
touch "$STUB/timer_on"
chk "installed and on: the service is started, without waiting for it" "$(srun start)|$(grep -c '^start --no-block joinery-site-refresh.service$' "$STUB/systemctl")" "0|1"
touch "$STUB/service_running"; : > "$STUB/systemctl"
chk "a run under way: says so, and starts no second" "$(srun start)|$(grep -c 'already under way' "$T/rout")|$(grep -c '^start' "$STUB/systemctl")" "0|1|0"
rm -f "$STUB/service_running"; touch "$STUB/start_fails"; : > "$STUB/systemctl"
chk "systemd refuses the start: tried three times, then an error" "$(SITE_REFRESH_SYSTEMCTL_PAUSE=0 srun start)|$(grep -c 'could not be started' "$T/rout")|$(grep -c '^start' "$STUB/systemctl")" "1|1|3"
rm -f "$STUB/start_fails"; echo 1 > "$STUB/start_fails_left"; : > "$STUB/systemctl"
chk "  one refusal (D-Bus restarting under an update), then started on the second try" "$(SITE_REFRESH_SYSTEMCTL_PAUSE=0 srun start)|$(grep -c '^start' "$STUB/systemctl")" "0|2"
rm -f "$STUB/start_fails" "$STUB/timer_on"
export JOINERY_SITE_ENGINE_KIND=podman

echo "=== The base and the template ==="
chk "Dockerfile.base stops PostgreSQL before its build step ends (B8)" \
    "$(grep -A3 'install.sh server --skip-postgres-password' "$TOOLS/Dockerfile.base" | grep -m1 -c 'service postgresql stop')" "1"
chk "the template takes the extensions from JOINERY_DECLARED_APT when the context has no code" \
    "$(grep -m1 -c '^ARG JOINERY_DECLARED_APT=' "$TOOLS/Dockerfile.template")|$(grep -m1 -c 'SPECS="${JOINERY_DECLARED_APT}"' "$TOOLS/Dockerfile.template")" "1|1"
chk "  and skips a name that is not a package's, or ends in - (apt: remove)" "$(grep -c 'case "$primary" in \*-) echo "WARNING: skipped $spec, not a package name"; continue ;; \[a-z0-9\]\*) ;;' "$TOOLS/Dockerfile.template")|$(grep -c 'case "$fallback" in \*-)' "$TOOLS/Dockerfile.template")" "1|1"

DSD="$(sed -n '/^do_site_docker() {/,/^}/p' "$TOOLS/install.sh")"
chk "install.sh site takes recreate_site's lock and refuses a fresh mark before it stops anything" \
    "$(awk '/exec 5>>"\$RLOCK"/ { o = NR } /if ! flock -n 5; then/ { l = NR } /find "\$RMARK" -mmin -60/ { m = NR } /site_engine stop "\$SITENAME"/ && !st { st = NR } END { print (o && l && m && o < l && l < st && m < st) ? "yes" : "no" }' <<< "$DSD")" "yes"
chk "  and closes it for every container it starts (create, run -d twice, the copy's start)" \
    "$(grep -cE 'site_engine (create|run -d) .*5>&-' <<< "$DSD")|$(sed -n '/^start_dormant_container() {/,/^}/p' "$TOOLS/install.sh" | grep -c 'site_engine start "\$site" > /dev/null 5>&-')" "3|1"
chk "  and a rebuilt site's nightly-replacement hold is lifted" "$(grep -c 'rm -f "/var/lib/joinery/site-refresh/held/${SITENAME}"' <<< "$DSD")" "1"
chk "rebase_site_container refuses a site being replaced (its lock tried, or a fresh mark)" \
    "$(grep -c 'flock -n "/run/joinery/recreate.${SITE}.lock" true' "$ROOT/maintenance_scripts/sysadmin_tools/rebase_site_container.sh")|$(grep -c 'replacing" -mmin -60' "$ROOT/maintenance_scripts/sysadmin_tools/rebase_site_container.sh")" "1|1"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
