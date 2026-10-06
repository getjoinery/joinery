#!/bin/bash
# @joinery-test
# name: site_run_spec
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/_site_run_spec.sh, maintenance_scripts/install_tools/install.sh, maintenance_scripts/sysadmin_tools/rebase_site_container.sh, maintenance_scripts/sysadmin_tools/migrate_site_to_code_volumes.sh, maintenance_scripts/sysadmin_tools/remove_account.sh]
#
# A site container's limits live in its docker run arguments, so anything that
# recreated the container from what Docker shows of it dropped them
# (specs/multi_tenant_docker_hosts.md WP0, S9). The host keeps one run spec per
# site and every creator builds its arguments from it. This gate pins: the spec
# round-trips to the arguments, limits included; a line that is not one
# argument, or a newer format, is refused and the old spec survives; a
# container made before specs is read once, limits included, and anything a
# spec cannot carry is refused by name, never dropped; a rebuild keeps the
# recorded limits, and the site's own ports and volumes, gives a new site the
# process ceiling, and checks every limit (a CPU ceiling against the host's
# CPUs) before anything stops; a failed environment read never destroys a kept copy;
# a move prepared before specs can still roll back; and the rebuild scripts
# check what they need before they change anything. Every docker call goes to
# a stub; the same moves on a real Docker host are live checks.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
HELPER="$ROOT/maintenance_scripts/install_tools/_site_run_spec.sh"
INSTALL="$ROOT/maintenance_scripts/install_tools/install.sh"
REBASE="$ROOT/maintenance_scripts/sysadmin_tools/rebase_site_container.sh"
MIGRATE="$ROOT/maintenance_scripts/sysadmin_tools/migrate_site_to_code_volumes.sh"
REMOVE="$ROOT/maintenance_scripts/sysadmin_tools/remove_account.sh"
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
    echo "  SKIP: run unprivileged; as root the fixture root is ignored and /etc would be written"
    echo "RESULT: 0 passed, 0 failed"
    exit 0
fi

export JOINERY_SITE_STATE_ROOT="$T/root"
mkdir -p "$T/bin"
# What the stub reports is set per case through STUB_* variables.
cat > "$T/bin/docker" <<'STUB'
#!/bin/bash
echo "$*" >> "${STUB_CALLS:-/dev/null}"
if [ "$1" = "info" ]; then   # the CPUs Docker counts; STUB_NPROC= (empty) says nothing
    [ -n "${STUB_NPROC-2}" ] && echo "${STUB_NPROC-2}"; exit 0
fi
[ "$1" = "inspect" ] || exit 0
# Docker refuses len of a list the container never set (nil), and a real
# container leaves CapAdd, ExtraHosts and Devices unset; so does the stub.
if printf '%s' "$3" | grep -q '[^}]{{len \.HostConfig\.\|^{{len \.HostConfig\.'; then
    echo 'template parsing error: error calling len: len of nil pointer' >&2; exit 1
fi
[ -n "${STUB_INSPECT_FAIL:-}" ] && [ "$#" -ge 3 ] && exit 1
if [ "$#" -lt 3 ]; then   # `docker inspect NAME`: it exists, unless named absent
    [ "$2" = "${STUB_ABSENT:-}" ] && exit 1
    exit 0
fi
case "$3" in
    *Privileged*)              echo "${STUB_HOSTCONFIG:-false|default||0|0|0||0|268435456}|268435456|1500000000|512|unless-stopped|oldsite|${STUB_CAPDROP:-}" ;;
    *PortBindings*)            printf "${STUB_PORTS:-127.0.0.1|8090|80/tcp\n127.0.0.1|9090|5432/tcp\n}" ;;
    *Mounts*)                  printf "${STUB_MOUNTS:-volume|oldsite_code|/var/www/html/oldsite/public_html|true\nvolume|oldsite_postgres|/var/lib/postgresql|true\n}" ;;
    *Config.Env*)
        [ -n "${STUB_ENV_FAIL:-}" ] && exit 1
        [ -n "${STUB_ENV_EMPTY:-}" ] && exit 0
        printf 'PATH=/usr/bin\nSITENAME=oldsite\nPOSTGRES_PASSWORD=hunter2\nDEBIAN_FRONTEND=noninteractive\n' ;;
esac
STUB
chmod +x "$T/bin/docker"
# nproc can be told anything (OMP_NUM_THREADS); Docker compares with its own count.
printf '#!/bin/sh\necho 64\n' > "$T/bin/nproc"
chmod +x "$T/bin/nproc"
export PATH="$T/bin:$PATH"
. "$HELPER"
SPECS="$T/root/etc/joinery/sites"

args_of() { run_spec_args "$1" | tr '\0' ' '; }

echo "=== A spec install.sh writes round-trips to the arguments, limits included ==="
run_spec_render mysite 127.0.0.1 8087 9087 256m 1.0 512 | run_spec_write mysite
chk "written under the site's state directory" "$(test -f "$SPECS/mysite/run_spec" && echo yes)" "yes"
chk "mode 644, no secret in it" "$(stat -c %a "$SPECS/mysite/run_spec")" "644"
A="$(args_of mysite)"
chk "name and hostname" "$(echo "$A" | grep -c -- '--name mysite --hostname mysite ')" "1"
chk "memory, with swap pinned to it" "$(echo "$A" | grep -c -- '--memory=256m --memory-swap=256m ')" "1"
chk "cpus" "$(echo "$A" | grep -c -- '--cpus=1.0 ')" "1"
chk "pids limit" "$(echo "$A" | grep -c -- '--pids-limit=512 ')" "1"
chk "web port on 127.0.0.1" "$(echo "$A" | grep -c -- '-p 127.0.0.1:8087:80 ')" "1"
chk "database port on 127.0.0.1" "$(echo "$A" | grep -c -- '-p 127.0.0.1:9087:5432 ')" "1"
chk "fifteen volumes" "$(echo "$A" | grep -o -- '-v mysite_' | wc -l)" "15"
chk "no environment and no image" "$(echo "$A" | grep -c -- '-e \|--env\|joinery-mysite')" "0"
chk "every capability dropped, then exactly the six a site needs" \
    "$(echo "$A" | grep -o -- '--cap-[a-z]*=[A-Z_]*' | paste -sd ' ')" \
    "--cap-drop=ALL --cap-add=CHOWN --cap-add=DAC_OVERRIDE --cap-add=FOWNER --cap-add=SETUID --cap-add=SETGID --cap-add=KILL"

echo "=== No limits means no limit arguments; a site with no domain answers on every interface ==="
run_spec_render bare "" 8088 9088 "" "" "" | run_spec_write bare
A="$(args_of bare)"
chk "no memory, cpus or pids argument" "$(echo "$A" | grep -c -- '--memory\|--cpus\|--pids')" "0"
chk "the capabilities are the platform's, not a limit: a spec with none still drops them" "$(echo "$A" | grep -c -- '--cap-drop=ALL ')" "1"
chk "web port on every interface" "$(echo "$A" | grep -c -- '-p 8088:80 ')" "1"

echo "=== Docker's own forms are accepted; what Docker refuses is refused ==="
chk "1G" "$(run_spec_norm_memory 1G)" "1024m"
chk "512M" "$(run_spec_norm_memory 512M)" "512m"
chk "1.5g" "$(run_spec_norm_memory 1.5g)" "1536m"
chk "512mb" "$(run_spec_norm_memory 512mb)" "512m"
chk "bytes" "$(run_spec_norm_memory 268435457)" "268435457b"
chk "none lifts it" "$(run_spec_norm_memory none)|$?" "|0"
chk "lots is refused" "$(run_spec_norm_memory lots; echo "|$?")" "|1"
chk "under Docker's 6 MiB floor is refused" "$(run_spec_norm_memory 4m; echo "|$?")" "|1"
chk "cpus .5" "$(run_spec_norm_cpus .5)" "0.5"
chk "cpus 2 is 2.0" "$(run_spec_norm_cpus 2)" "2.0"
chk "cpus none lifts it" "$(run_spec_norm_cpus none)|$?" "|0"
chk "cpus under Docker's 0.01 is refused" "$(run_spec_norm_cpus 0.001; echo "|$?")" "|1"
chk "cpus 1,5 is refused" "$(run_spec_norm_cpus 1,5; echo "|$?")" "|1"
chk "a ceiling of the host's CPUs fits" "$(STUB_NPROC=2 run_spec_cpus_fit 2.0 && echo yes)" "yes"
chk "one above them does not" "$(STUB_NPROC=2 run_spec_cpus_fit 2.5 || echo no)" "no"
chk "no ceiling always fits" "$(STUB_NPROC=1 run_spec_cpus_fit '' && echo yes)" "yes"
chk "the count is Docker's, not nproc's (64 here)" "$(STUB_NPROC=1 run_spec_cpus_fit 2.0 || echo no)" "no"
chk "no count from Docker fails closed" "$(STUB_NPROC= run_spec_cpus_fit 0.5 || echo no)" "no"
chk "cpus=0.001 is not a line" "$(run_spec_check_line cpus=0.001 || echo refused)" "refused"
chk "cpus=0 is not a line" "$(run_spec_check_line cpus=0 || echo refused)" "refused"
chk "cpus=0.01 is a line" "$(run_spec_check_line cpus=0.01 && echo ok)" "ok"
chk "pids 0512 is 512" "$(run_spec_norm_pids 0512)" "512"
chk "pids none lifts it" "$(run_spec_norm_pids none)|$?" "|0"
chk "pids 1e3 is refused" "$(run_spec_norm_pids 1e3; echo "|$?")" "|1"
chk "pids past nine digits is refused" "$(run_spec_norm_pids 9999999999; echo "|$?")" "|1"
chk "an IPv6 binding is a line" "$(run_spec_check_line 'publish=[::1]:8080:80' && echo ok)" "ok"
chk "a udp binding is a line" "$(run_spec_check_line 'publish=5353:53/udp' && echo ok)" "ok"
chk "a hostname with dots is a line" "$(run_spec_check_line 'hostname=site.example.com' && echo ok)" "ok"
chk "a read-only volume is a line" "$(run_spec_check_line 'volume=x_data:/srv/data:ro' && echo ok)" "ok"

echo "=== A line that is not one argument is refused, and the old spec survives ==="
before="$(cat "$SPECS/mysite/run_spec")"
for bad in "memory=256m --privileged" "volume=x:/a b" "publish=0.0.0.0:80:80 -v /:/host" "privileged=true" "cpus=1;reboot" "hostname=a b" "pids_limit=0"; do
    { run_spec_render mysite 127.0.0.1 8087 9087 256m "" ""; echo "$bad"; } | run_spec_write mysite 2> "$T/err"; rc=$?
    chk "refused: '$bad'" "$rc" "1"
    chk "said which line: '$bad'" "$(grep -c 'refusing line' "$T/err")" "1"
done
chk "the spec on disk is unchanged" "$(cat "$SPECS/mysite/run_spec")" "$before"
chk "no temporary file left" "$(ls -A "$SPECS/mysite" | grep -c '^\.run_spec')" "0"
printf 'memory=1g --privileged\n' >> "$SPECS/mysite/run_spec"
chk "a hand-damaged spec gives no arguments" "$(run_spec_args mysite 2>/dev/null | wc -c)" "0"
printf '%s\n' "$before" | sed 's/^spec_version=1$/spec_version=2/' > "$SPECS/mysite/run_spec"
chk "a newer format gives no arguments" "$(run_spec_args mysite 2>/dev/null | wc -c)" "0"
printf '%s\n' "$before" > "$SPECS/mysite/run_spec"

echo "=== One key changes, every other line is kept; a volume is added once ==="
run_spec_set mysite memory 512m
chk "memory changed" "$(run_spec_get mysite memory)" "512m"
chk "cpus kept" "$(run_spec_get mysite cpus)" "1.0"
chk "volumes kept" "$(run_spec_list mysite volume | wc -l)" "15"
run_spec_add_volume mysite mysite_extra /var/www/html/mysite/extra
run_spec_add_volume mysite mysite_extra /var/www/html/mysite/extra
chk "added once" "$(run_spec_list mysite volume | grep -c ':/var/www/html/mysite/extra$')" "1"
run_spec_add_volume mysite other_code /var/www/html/mysite/public_html
chk "a destination already mounted is not mounted twice" "$(run_spec_list mysite volume | grep -c ':/var/www/html/mysite/public_html$')" "1"

echo "=== What install.sh does not own is the site's, and a rebuild keeps it ==="
run_spec_render keeper 127.0.0.1 8089 9089 "" "" "" > "$T/lines"
printf 'publish=25:25\npublish=192.168.1.5:9089:5432\nvolume=keeper_mail:/var/spool/postfix\nvolume=keeper_other_code:/var/www/html/keeper/public_html\n' >> "$T/lines"
run_spec_write keeper < "$T/lines"
chk "a hand-published port and its own volume are kept, nothing install.sh owns" \
    "$(run_spec_foreign_lines keeper | paste -sd ' ')" "publish=25:25 volume=keeper_mail:/var/spool/postfix"

echo "=== A container made before specs is read once, limits included ==="
run_spec_adopt oldsite
chk "memory read as 256m" "$(run_spec_get oldsite memory)" "256m"
chk "cpus read as 1.5" "$(run_spec_get oldsite cpus)" "1.5"
chk "pids limit read" "$(run_spec_get oldsite pids_limit)" "512"
chk "restart policy read" "$(run_spec_get oldsite restart)" "unless-stopped"
chk "both ports read" "$(run_spec_list oldsite publish | paste -sd ' ')" "127.0.0.1:8090:80 127.0.0.1:9090:5432"
chk "its volumes read" "$(run_spec_list oldsite volume | wc -l)" "2"
run_spec_set oldsite memory 1g
run_spec_adopt oldsite
chk "never read again once a spec exists" "$(run_spec_get oldsite memory)" "1g"
rm -f "$SPECS/oldsite/run_spec"
STUB_PORTS='::1|8090|80/tcp\n|5353|53/udp\n' STUB_MOUNTS='volume|oldsite_code|/var/www/html/oldsite/public_html|false\n' run_spec_adopt oldsite
chk "IPv6 and udp bindings carried as they are" "$(run_spec_list oldsite publish | paste -sd ' ')" "[::1]:8090:80 5353:53/udp"
chk "a read-only volume stays read-only" "$(run_spec_list oldsite volume)" "oldsite_code:/var/www/html/oldsite/public_html:ro"
rm -f "$SPECS/oldsite/run_spec"
STUB_HOSTCONFIG='false|default|CAP_CHOWN CAP_DAC_OVERRIDE CAP_FOWNER CAP_SETUID CAP_SETGID CAP_KILL |0|0|0||0|268435456' run_spec_adopt oldsite 2> "$T/err"; rc=$?
chk "a container already holding the six is read, as Docker names them" "$rc|$(test -f "$SPECS/oldsite/run_spec" && echo written)" "0|written"
chk "and no capability line is written: the six are not the site's" "$(grep -c -i 'cap' "$SPECS/oldsite/run_spec")" "0"
rm -f "$SPECS/oldsite/run_spec"
STUB_CAPDROP='ALL CAP_NET_RAW ' run_spec_adopt oldsite 2> "$T/err"; rc=$?
chk "a container that dropped ALL, or one the site is not given, is read" "$rc|$(test -f "$SPECS/oldsite/run_spec" && echo written)" "0|written"
rm -f "$SPECS/oldsite/run_spec"
STUB_CAPDROP='CAP_KILL MKNOD ' run_spec_adopt oldsite 2> "$T/err"; rc=$?
chk "a container that dropped one of the six is refused, not given it back" \
    "$rc|$(test -f "$SPECS/oldsite/run_spec" && echo written)|$(grep -c 'dropped capabilities a site is given (KILL)' "$T/err")" "1||1"
for c in "bind:volume|oldsite_code|/x|true\nbind||/srv/data|true\n:a bind mount at /srv/data" \
         "cap:|true|default||0|0|0||0|268435456:privileged" \
         "net:|false|host||0|0|0||0|268435456:network host" \
         "swap:|false|default||0|0|0||0|536870912:docker update --memory-swap=268435456 oldsite" \
         "caps:|false|default|CAP_CHOWN CAP_NET_ADMIN SYS_ADMIN |0|0|0||0|268435456:added capabilities (NET_ADMIN, SYS_ADMIN)"; do
    kind="${c%%:*}"; rest="${c#*:}"; want="${rest##*:}"; val="${rest%:*}"
    if [ "$kind" = bind ]; then
        STUB_MOUNTS="$val" run_spec_adopt oldsite 2> "$T/err"; rc=$?
    else
        STUB_HOSTCONFIG="${val#|}" run_spec_adopt oldsite 2> "$T/err"; rc=$?
    fi
    chk "refused, not dropped: $kind" "$rc|$(test -f "$SPECS/oldsite/run_spec" && echo written)" "1|"
    chk "and named: $kind" "$(grep -c "$want" "$T/err")" "1"
done
STUB_INSPECT_FAIL=1 run_spec_adopt oldsite 2> "$T/err"; rc=$?
chk "a failed read of the container is refused, not read as no limits" \
    "$rc|$(test -f "$SPECS/oldsite/run_spec" && echo written)|$(grep -c 'could not read' "$T/err")" "1||1"

echo "=== The environment travels as a file, and a failed read keeps the copy it has ==="
run_spec_save_env oldsite "$T/env"
chk "env file is owner-only" "$(stat -c %a "$T/env")" "600"
chk "it carries the site's variables" "$(paste -sd ' ' "$T/env")" "SITENAME=oldsite POSTGRES_PASSWORD=hunter2"
STUB_ENV_FAIL=1 run_spec_save_env oldsite "$T/env" 2>/dev/null; rc=$?
chk "a failed inspect returns non-zero" "$rc" "1"
chk "and the kept file is whole" "$(grep -c POSTGRES_PASSWORD=hunter2 "$T/env")" "1"
STUB_ENV_EMPTY=1 run_spec_save_env oldsite "$T/env" 2>/dev/null; rc=$?
chk "an empty environment returns non-zero" "$rc" "1"
chk "and the kept file is whole" "$(grep -c POSTGRES_PASSWORD=hunter2 "$T/env")" "1"
chk "no temporary file left" "$(ls -A "$T" | grep -c '^\.env')" "0"

echo "=== A move prepared before run specs can still roll back ==="
mkdir -p "$T/work"
printf '%s\0' --name oldsite --hostname oldsite --restart unless-stopped -p 127.0.0.1:8090:80 -p 127.0.0.1:9090:5432 \
    -e SITENAME=oldsite -e 'POSTGRES_PASSWORD=a=b c' -v oldsite_code:/var/www/html/oldsite/public_html > "$T/work/run_args"
run_spec_from_run_args "$T/work/run_args" "$T/work/run_spec" "$T/work/env"
chk "the spec is whole" "$(run_spec_check_file "$T/work/run_spec" && echo ok)" "ok"
chk "ports, volume, restart and hostname carried" \
    "$(grep -c '^publish=\|^volume=\|^restart=unless-stopped$\|^hostname=oldsite$' "$T/work/run_spec")" "5"
chk "the environment is a file, the password whole" "$(sed -n 's/^POSTGRES_PASSWORD=//p' "$T/work/env")" "a=b c"
chk "env owner-only" "$(stat -c %a "$T/work/env")" "600"
rm -f "$T/work/run_spec" "$T/work/env"
printf '%s\0' --name oldsite --privileged true -e A=1 > "$T/work/run_args"
run_spec_from_run_args "$T/work/run_args" "$T/work/run_spec" "$T/work/env" 2>/dev/null; rc=$?
chk "an argument a spec cannot carry is refused" "$rc" "1"
chk "and nothing is written" "$(ls "$T/work" | grep -c 'run_spec\|^env$')" "0"

echo "=== A rebuild keeps the recorded limits, and checks every limit before anything stops ==="
# The block install.sh runs before it removes a container, run against the stub.
BLOCK="$(awk '/^do_site_docker\(\) \{$/,/^}$/' "$INSTALL" | awk '/^    \. "\$SCRIPT_DIR\/_site_run_spec\.sh"$/,/^    # \(end of the limits check\)$/')"
chk "install.sh's spec block is findable" "$(printf '%s\n' "$BLOCK" | grep -c 'end of the limits check')" "1"
DEFAULTS="$(grep '^CONTAINER_PIDS_DEFAULT=\|^CONTAINER_PIDS_FLOOR=' "$INSTALL")"
rebuild() {  # SITE MEMORY MEMORY_GIVEN [CPUS CPUS_GIVEN [PIDS PIDS_GIVEN]]
    bash -c 'SCRIPT_DIR="$1"; SITENAME="$2"; CONTAINER_MEMORY="$3"; CONTAINER_MEMORY_GIVEN="$4"
        CONTAINER_CPUS="$5"; CONTAINER_CPUS_GIVEN="$6"; CONTAINER_PIDS="$7"; CONTAINER_PIDS_GIVEN="$8"
        '"$DEFAULTS"'
        print_info() { :; }; print_error() { echo "ERR $*" >&2; }
        f() { '"$BLOCK"'
        echo "$CONTAINER_MEMORY|$CONTAINER_CPUS|$CONTAINER_PIDS"; }; f' _ "$(dirname "$INSTALL")" \
        "$1" "$2" "$3" "${4:-}" "${5:-0}" "${6:-}" "${7:-0}"
}
chk "no flag: the spec's 512m and limits" "$(rebuild mysite "" 0)" "512m|1.0|512"
chk "--memory=2g wins" "$(rebuild mysite 2g 1)" "2048m|1.0|512"
chk "--memory=1G is taken as Docker takes it" "$(rebuild mysite 1G 1)" "1024m|1.0|512"
chk "--memory=none lifts the budget" "$(rebuild mysite none 1)" "|1.0|512"
out="$(rebuild mysite "" 1 2>&1)"; rc=$?
chk "--memory= with no size stops the run rather than lifting the budget" "$rc|$(echo "$out" | grep -c -- '--memory was given with no value')" "1|1"
export STUB_CALLS="$T/calls"; : > "$T/calls"
out="$(rebuild mysite lots 1 2>&1)"; rc=$?
chk "--memory=lots stops the run" "$rc" "1"
chk "and says why" "$(echo "$out" | grep -c 'is not a memory size Docker takes')" "1"
chk "with no stop or rm sent" "$(grep -c '^stop\|^rm' "$T/calls")" "0"
unset STUB_CALLS
rm -f "$SPECS/oldsite/run_spec"
chk "a container with no spec has it read first" "$(rebuild oldsite "" 0)" "256m|1.5|512"
chk "and the spec is written" "$(test -f "$SPECS/oldsite/run_spec" && echo yes)" "yes"

echo "=== CPU and process ceilings: given, kept, defaulted, lifted, checked ==="
chk "--cpus=1.5 and --pids-limit=300 win over the spec" "$(rebuild mysite "" 0 1.5 1 300 1)" "512m|1.5|300"
chk "--cpus=none and --pids-limit=none lift both" "$(rebuild mysite "" 0 none 1 none 1)" "512m||"
chk "a new site gets the process ceiling and no CPU ceiling" "$(STUB_ABSENT=newsite rebuild newsite "" 0)" "||512"
chk "a new site given --pids-limit=none has none" "$(STUB_ABSENT=newsite rebuild newsite "" 0 "" 0 none 1)" "||"
chk "the new site's spec is not written by the check" "$(test -e "$SPECS/newsite" && echo written)" ""
run_spec_set oldsite pids_limit 100
chk "a spec's ceiling below the floor is kept, not refused" "$(rebuild oldsite "" 0)" "256m|1.5|100"
export STUB_CALLS="$T/calls"
for c in "--cpus=4 on a 2-CPU host:4:1:::more than this host has (CPUs" \
         "--cpus=0.001:0.001:1:::not a CPU ceiling Docker takes" \
         "--cpus= with no value::1:::--cpus was given with no value" \
         "--pids-limit=50:::50:1:is below 128" \
         "--pids-limit=lots:::lots:1:not a number of processes" \
         "--pids-limit= with no value::::1:--pids-limit was given with no value"; do
    IFS=: read -r label cv cg pv pg want <<< "$c"
    : > "$T/calls"
    out="$(STUB_NPROC=2 rebuild mysite "" 0 "$cv" "${cg:-0}" "$pv" "${pg:-0}" 2>&1)"; rc=$?
    chk "$label stops the run and says why" "$rc|$(echo "$out" | grep -c -- "$want")" "1|1"
    chk "$label sends no stop or rm" "$(grep -c '^stop\|^rm' "$T/calls")" "0"
done
run_spec_set mysite cpus 3.0
: > "$T/calls"
out="$(STUB_NPROC=2 rebuild mysite "" 0 2>&1)"; rc=$?
chk "a spec's CPU ceiling above this host's CPUs stops the rebuild, naming the way out" \
    "$rc|$(echo "$out" | grep -c "run spec has cpus=3.0, which is more than this host has (CPUs: 2).*Run again with --cpus set to one that is not, or --cpus=none")" "1|1"
chk "and sends no stop or rm" "$(grep -c '^stop\|^rm' "$T/calls")" "0"
chk "--cpus=2 on the same host fixes it" "$(STUB_NPROC=2 rebuild mysite "" 0 2 1)" "512m|2.0|512"
: > "$T/calls"
out="$(STUB_NPROC= rebuild mysite "" 0 2>&1)"; rc=$?
chk "no CPU count from Docker stops the rebuild before anything stops" \
    "$rc|$(echo "$out" | grep -c 'Docker did not say how many CPUs')|$(grep -c '^stop\|^rm' "$T/calls")" "1|1|0"
run_spec_set mysite cpus 1.0
sed -i 's/^memory=.*/memory=1m/' "$SPECS/mysite/run_spec"
out="$(rebuild mysite "" 0 2>&1)"; rc=$?
chk "a bad value from the spec is named as the spec's, not as an option" \
    "$rc|$(echo "$out" | grep -c "run spec has memory=1m, which is not a memory size")|$(echo "$out" | grep -c -- '^ERR --memory=')" "1|1|0"
run_spec_set mysite memory 512m
unset STUB_CALLS
check_at="$(grep -n 'not a memory size Docker takes' "$INSTALL" | head -1 | cut -d: -f1)"
stop_at="$(grep -n 'docker stop "$SITENAME"' "$INSTALL" | head -1 | cut -d: -f1)"
chk "install.sh checks --memory before its first stop" "$([ "$check_at" -lt "$stop_at" ] && echo yes)" "yes"
check_at="$(grep -n 'end of the limits check' "$INSTALL" | head -1 | cut -d: -f1)"
chk "and every other limit too" "$([ "$check_at" -lt "$stop_at" ] && echo yes)" "yes"
chk "install.sh writes the checked ceilings into the spec" \
    "$(grep -c 'run_spec_render "$SITENAME" .*"$CONTAINER_MEMORY" "$CONTAINER_CPUS" "$CONTAINER_PIDS")' "$INSTALL")" "1"
refuse_at="$(grep -n 'a bare-metal site has none' "$INSTALL" | head -1 | cut -d: -f1)"
bare_at="$(grep -n '^        do_site_baremetal "$SITENAME"' "$INSTALL" | head -1 | cut -d: -f1)"
chk "a limit given for a bare-metal site is refused before it is installed" \
    "$([ -n "$refuse_at" ] && [ -n "$bare_at" ] && [ "$refuse_at" -lt "$bare_at" ] && echo yes)" "yes"

echo "=== A spec this host's Docker would refuse is refused before any script stops anything ==="
run_spec_render bigcpu 127.0.0.1 8092 9092 "" 2.0 512 | run_spec_write bigcpu
chk "run_spec_args gives no arguments for a ceiling above Docker's CPUs" "$(STUB_NPROC=1 run_spec_args bigcpu 2>/dev/null | wc -c)" "0"
chk "and says why" "$(STUB_NPROC=1 run_spec_args bigcpu 2>&1 >/dev/null | grep -c 'install.sh site --cpus set to one that fits, or --cpus=none')" "1"
SAVE="$(awk '/^save_run_spec\(\) \{$/,/^}$/' "$REBASE")"
mkdir -p "$T/rework"
chk "rebase's save_run_spec is findable" "$(printf '%s\n' "$SAVE" | grep -c run_spec_fits_host)" "1"
chk "rebase prepare and swap refuse it (save_run_spec) and keep no copy" \
    "$(STUB_NPROC=1 bash -c '. "$1"; SITE=bigcpu; WORK="$2"; '"$SAVE"'; save_run_spec' _ "$HELPER" "$T/rework" > /dev/null 2>&1; echo $?)|$(ls -A "$T/rework" | wc -l)" "1|0"

echo "=== Rebase prepare keeps a hand-made binding only when the spec carries it ==="
LOOP="$(awk '/^    EXTRA_PORTS=""$/,/^    done < <\(docker inspect/' "$REBASE")"
CARRY="$(awk '/^    FOREIGN="\$\(run_spec_foreign_lines/,/^    \[ -z "\$NOT_CARRIED" \] \|\| die/' "$REBASE")"
chk "the binding loop and the carried check are findable" "$([ -n "$LOOP" ] && [ -n "$CARRY" ] && echo yes)" "yes"
prepare_bindings() {  # SITE BINDINGS
    bash -c '. "$1"; SITE="$2"; PORT=8091; BINDINGS="$3"
        die() { echo "DIE $*"; exit 1; }
        docker() { printf "$BINDINGS"; }
        '"$LOOP"'
        '"$CARRY"'
        echo carried' _ "$HELPER" "$1" "$2" 2>&1
}
run_spec_render carry 127.0.0.1 8091 9091 "" "" "" > "$T/lines"; echo 'publish=25:25' >> "$T/lines"; run_spec_write carry < "$T/lines"
chk "a port the spec carries passes" "$(prepare_bindings carry '127.0.0.1|8091|80/tcp
127.0.0.1|9091|5432/tcp
|25|25/tcp
')" "carried"
chk "a port the spec does not carry still refuses" "$(prepare_bindings carry '127.0.0.1|8091|80/tcp
|2525|25/tcp
' | grep -c 'DIE.*2525:25, which its run spec does not carry')" "1"
chk "a second binding of the web port refuses (install.sh owns port 80)" "$(prepare_bindings carry '127.0.0.1|8091|80/tcp
::1|8091|80/tcp
' | grep -c 'DIE.*\[::1\]:8091:80')" "1"
chk "the check runs before prepare records anything" \
    "$([ "$(grep -n 'NOT_CARRIED" \] || die' "$REBASE" | cut -d: -f1)" -lt "$(grep -n ': > "$STATE"; chmod 600' "$REBASE" | cut -d: -f1)" ] && echo yes)" "yes"
chk "install.sh names each spec line a rebuild does not keep" "$(grep -c 'Not kept from the run spec' "$INSTALL")" "1"

echo "=== Every rebuild runs the container from the spec, and checks before it changes anything ==="
code() { grep -v '^[[:space:]]*#' "$1"; }
chk "install.sh runs from the spec in both forms" "$(code "$INSTALL" | grep -c 'docker run -d "${RUN_ARGS\[@\]}" --env-file "$ENV_FILE" "joinery-$SITENAME"')" "2"
chk "install.sh keeps the site's own lines" "$(code "$INSTALL" | grep -c 'run_spec_foreign_lines "$SITENAME"')" "1"
chk "rebase: no arguments rebuilt from docker inspect" "$(code "$REBASE" | grep -c 'save_run_args\|mapfile -d .. ARGS < "${WORK}/run_args"')" "0"
chk "rebase: rollback runs from the kept spec with the kept environment" "$(code "$REBASE" | grep -c 'docker run -d "${ARGS\[@\]}" --env-file "${WORK}/env" "$KEEP_IMAGE"')" "1"
SWAP="$(awk '/^if \[ "\$STAGE" = "swap" \]/,/^fi$/' "$REBASE")"
ROLL="$(awk '/^if \[ "\$STAGE" = "rollback" \]/,/^fi$/' "$REBASE")"
line_of() { printf '%s\n' "$1" | grep -n -- "$2" | head -1 | cut -d: -f1; }
chk "rebase swap keeps the spec before it stops anything" \
    "$([ "$(line_of "$SWAP" 'save_run_spec ||')" -lt "$(line_of "$SWAP" 'stop_site_writes ||')" ] && echo yes)" "yes"
chk "rebase rollback checks its spec and environment before it removes anything" \
    "$([ "$(line_of "$ROLL" 'run_spec_check_file "${WORK}/run_spec"')" -lt "$(line_of "$ROLL" 'docker stop "$SITE"')" ] && echo yes)" "yes"
chk "rebase rollback checks the kept spec fits this host before it removes anything" \
    "$([ "$(line_of "$ROLL" 'run_spec_fits_host "${WORK}/run_spec"')" -lt "$(line_of "$ROLL" 'docker stop "$SITE"')" ] && echo yes)" "yes"
chk "rebase's plan says none for no process limit" "$(grep -c "pids_limit | sed 's/^\$/none/'" "$REBASE")" "1"
chk "rebase rollback reads a 1.7 run_args before it removes anything" \
    "$([ "$(line_of "$ROLL" 'run_spec_from_run_args')" -lt "$(line_of "$ROLL" 'docker stop "$SITE"')" ] && echo yes)" "yes"
chk "rebase: no cp chained with && (set -e ignores the failure)" "$(code "$REBASE" | grep -c 'cp [^|]*&& chmod')" "0"
chk "migrate: no arguments rebuilt from docker inspect" "$(code "$MIGRATE" | grep -c 'PortBindings\|RUN_ARGS+=')" "0"
chk "migrate: runs from the spec" "$(code "$MIGRATE" | grep -c 'docker run -d "${RUN_ARGS\[@\]}" --env-file "$ENV_FILE" "$IMAGE"')" "1"
MIG_SWAP_AT="$(code "$MIGRATE" | grep -n 'has no run spec; run prepare again' | head -1 | cut -d: -f1)"
MIG_FIT="$(code "$MIGRATE" | awk -v from="$MIG_SWAP_AT" 'NR > from && /run_spec_fits_host/ { print NR; exit }')"
chk "migrate swap checks the spec fits this host before it changes the spec or stops anything" \
    "$([ -n "$MIG_FIT" ] && [ "$MIG_FIT" -lt "$(code "$MIGRATE" | grep -n 'run_spec_add_volume' | head -1 | cut -d: -f1)" ] \
        && [ "$MIG_FIT" -lt "$(code "$MIGRATE" | grep -n 'docker stop "$SITE"' | tail -1 | cut -d: -f1)" ] && echo yes)" "yes"
unset JOINERY_SITE_STATE_ROOT
want_rm="rm -f \"$(run_spec_path SITEX | sed 's/SITEX/${SITE_NAME}/')\""
chk "remove_account removes the file run_spec_path names" "$(grep -cF "$want_rm" "$REMOVE")" "1"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
