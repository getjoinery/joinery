#!/usr/bin/env bash
#
# _site_run_spec.sh - how a site's container is run, recorded once on its
# Docker host (specs/multi_tenant_docker_hosts.md WP0).
#
# Version: 1.7 - outbound_set_by=plane: the management node set the site's own outbound
#                figures (joinery-limits set --site --by=plane), so the site is told to ask
#                whoever hosts it; kept across a rebuild with the figures.
# Version: 1.5 - A site's own outbound figures (outbound_limits.sh, specs/node_outbound_and_transfer.md
#                WP5): outbound_ceiling (Mbit/s or off), outbound_conn_rate, outbound_conn_burst and
#                outbound_open_conns, each empty or absent for the machine's. Not run arguments: the
#                host's joinery-limits unit reads them. run_spec_outbound_lines gives a site's for
#                install.sh to keep across a rebuild.
# Version: 1.4 - run_spec_limits_refresh: the outbound limits (outbound_limits.sh,
#                specs/node_outbound_and_transfer.md WP3) follow a site created, moved,
#                rebuilt or removed, through the host's joinery-limits unit.
# Version: 1.3 - Every site runs on a network of its own (specs/node_outbound_and_transfer.md
#                WP2): network=, bridge=, subnet= and subnet6= lines, format 2. A slot N is
#                10.250.N.0/24, fd00:250:N::/64 and the bridge jsnetN, taken under a host
#                lock by creating the network (run_spec_network_lines). run_spec_args makes
#                the network the spec names exist, with exactly its subnets, before it gives
#                --network, so no creator can run a site onto a missing or different one. IPv6
#                only where the host has an IPv6 route and Docker NATs it (27 or later).
#                Adopt accepts a container on its own network of that shape. A network that
#                cannot be remade as the spec says comes back as it was, spec and all.
# Version: 1.2 - Every site container runs with --cap-drop=ALL and the six capabilities a
#                site needs (RUN_SPEC_CAPS, WP5 item 2). They are the platform's, not the
#                site's, so they are not spec lines: run_spec_args adds them for every
#                site, and a rebuild of an older container picks them up. Adopt accepts a
#                container holding those six and refuses, by name, any capability beyond them.
#                run_spec_docker_remaps_ids says whether the host's Docker remaps user ids. Adopt
#                also refuses a container that dropped one of the six, which a rebuild would give back.
# Version: 1.1 - --cpus and --pids-limit (WP3): run_spec_norm_pids, and a CPU ceiling
#                is checked against the CPUs Docker counts, since it refuses one above them,
#                by every builder of run arguments (run_spec_args) and by run_spec_fits_host,
#                which the rebase and move scripts run before they stop anything.
#                Adopt guards each len against Docker's nil lists (a real container has
#                no CapAdd) and stops when any read of the container fails.
# Version: 1.0
#
# A container's limits live in its `docker run` arguments and nowhere else, so
# anything that recreates the container from what it can see (its name, ports,
# environment and volumes) drops them. The host keeps one file per site instead,
# /etc/joinery/sites/{site}/run_spec, and everything that creates the container
# builds its arguments from that file with run_spec_args: install.sh site, the
# PostgreSQL rebase, and the move onto code volumes. A rebuild keeps every limit
# because the limits are read from the file, not from the container.
#
# The file holds no secret. The environment (the database password among it) is
# not a run argument here: each caller passes it as an --env-file of its own.
#
#   # joinery-managed ...
#   spec_version=1
#   hostname=mysite
#   restart=unless-stopped
#   memory=256m          empty: no limit
#   cpus=1.0             empty: no ceiling
#   pids_limit=512       empty: no ceiling
#   publish=127.0.0.1:8087:80       one line per published port; [v6]:h:c and /udp allowed
#   volume=mysite_code:/var/www/html/mysite/public_html    one line per volume, :ro allowed
#   network=mysite_net   the site's own network; with no network line, Docker's default
#   bridge=jsnet17       its bridge on the host, which the outbound limits name
#   subnet=10.250.17.0/24
#   subnet6=fd00:250:17::/64     empty: the network is IPv4 only
#   outbound_ceiling=100 the site's own speed ceiling in Mbit/s, or off; absent: the machine's
#   outbound_conn_rate=20, outbound_conn_burst=100, outbound_open_conns=256
#                        the site's own connection figures; absent: the machine's
#   outbound_set_by=plane        the management node set those figures; absent: root on the machine
#
# A test points /etc at a fixture with JOINERY_SITE_STATE_ROOT, and only an
# unprivileged run may, the same rule as _site_state.sh.
#
# Sourced, never executed:  . "${TOOLS_DIR}/_site_run_spec.sh"
# Functions only; sourcing it runs nothing.

RUN_SPEC_VERSION=2

# Every site network on a host comes from one slot N, 1 to 254: the subnets
# 10.250.N.0/24 and fd00:250:N::/64 and the bridge jsnetN. One private /64 per
# site; its traffic leaves through the host from the machine's own IPv6 address
# (Docker's NAT), where same-data-center traffic is not charged.
RUN_SPEC_NET4_PREFIX="10.250"
RUN_SPEC_NET6_PREFIX="fd00:250"
RUN_SPEC_BRIDGE_PREFIX="jsnet"
RUN_SPEC_NET_SLOTS=254
# The Docker release from which an IPv6 network's traffic is NATed out without
# a setting in daemon.json (ip6tables on by default).
RUN_SPEC_DOCKER_IPV6_MAJOR=27

# The suffix of every volume a site owns, and where it mounts under the site
# root (or absolute, for the ones outside it). install.sh's ALL_SITE_VOLUMES is
# the same list of names.
RUN_SPEC_VOLUMES=(
    "code:public_html"
    "vendor:vendor"
    "scripts:maintenance_scripts"
    "postgres:/var/lib/postgresql"
    "uploads:uploads"
    "storage:storage"
    "config:config"
    "backups:backups"
    "static:static_files"
    "logs:logs"
    "cache:cache"
    "sessions:/var/lib/php/sessions"
    "apache_logs:/var/log/apache2"
    "pg_logs:/var/log/postgresql"
    "agent:/etc/joinery-agent"
)

# The container ports install.sh publishes itself: the web server and the
# database. Every other published port is the site's own, and a rebuild keeps it.
RUN_SPEC_OWN_PORTS="80 5432"

# The only capabilities a site container holds; every other is dropped. Each
# was measured as needed (specs/multi_tenant_docker_hosts.md WP5 item 2):
# CHOWN, DAC_OVERRIDE and FOWNER for the ownership and mode fixes at container
# start and PostgreSQL's own files; SETUID and SETGID for su, cron's jobs as
# www-data, PHP-FPM's workers and the parser jail; KILL for the supervisor,
# which clears what a dead service left running under another user. Two that
# are dropped fail quietly rather than loudly: without SETFCAP a package's
# setcap leaves its binary without the capability, and without FSETID a setgid
# bit on a file whose group root is not in is cleared. Nothing declared today
# needs either (WP5 item 2).
RUN_SPEC_CAPS=(CHOWN DAC_OVERRIDE FOWNER SETUID SETGID KILL)

run_spec_root() {
    if [[ "$(id -u)" != "0" && -n "${JOINERY_SITE_STATE_ROOT:-}" ]]; then
        echo "${JOINERY_SITE_STATE_ROOT%/}"
    fi
}

run_spec_path() {  # SITE
    [[ "$1" =~ ^[A-Za-z0-9_-]{1,50}$ ]] || { echo "run spec: '$1' is not a site name" >&2; return 1; }
    echo "$(run_spec_root)/etc/joinery/sites/$1/run_spec"
}

run_spec_exists() {  # SITE
    local p
    p="$(run_spec_path "$1")" || return 1
    [[ -f "$p" ]]
}

# The value of one single-valued key, or nothing.
run_spec_get() {  # SITE KEY
    local p
    p="$(run_spec_path "$1")" || return 1
    [[ -f "$p" ]] || return 0
    sed -n "s/^$2=//p" "$p" | tail -1
}

# Each line of a repeated key (publish, volume), one per line.
run_spec_list() {  # SITE KEY
    local p
    p="$(run_spec_path "$1")" || return 1
    [[ -f "$p" ]] || return 0
    sed -n "s/^$2=//p" "$p"
}

# A memory budget in any form Docker takes (512m, 512M, 1G, 1.5g, 512mb,
# 268435456, 268435456b), as the one form a spec holds: whole MiB as Nm,
# anything else as Nb. none or 0 is no limit, printed as nothing. Returns 1 for
# what Docker would refuse, or a budget under Docker's 6 MiB floor.
run_spec_norm_memory() {  # VALUE
    local v
    v="$(printf '%s' "$1" | tr 'A-Z' 'a-z' | tr -d ' ')"
    case "$v" in ''|none|0) return 0 ;; esac
    [[ "$v" =~ ^([0-9]+(\.[0-9]+)?)([kmgtp]?)i?b?$ ]] || return 1
    awk -v n="${BASH_REMATCH[1]}" -v u="${BASH_REMATCH[3]}" 'BEGIN {
        m = 1; if (u == "k") m = 1024; else if (u == "m") m = 1048576; else if (u == "g") m = 1073741824;
        else if (u == "t") m = 1099511627776; else if (u == "p") m = 1125899906842624;
        b = int(n * m); if (b < 6291456) exit 1;
        if (b % 1048576 == 0) printf "%dm", b / 1048576; else printf "%db", b }'
}

# A CPU ceiling in cores (1, 1.0, .5, 1.50) as a plain decimal; none or 0 is
# no ceiling, printed as nothing. Returns 1 for what Docker would refuse
# anywhere, which is anything under 0.01.
run_spec_norm_cpus() {  # VALUE
    local v="$1"
    case "$v" in ''|none|0) return 0 ;; esac
    [[ "$v" =~ ^([0-9]+(\.[0-9]*)?|\.[0-9]+)$ ]] || return 1
    awk -v n="$v" 'BEGIN { if (n < 0.01) exit 1; s = sprintf("%.3f", n); sub(/0+$/, "", s); sub(/\.$/, ".0", s); print s }'
}

# The number of CPUs Docker counts on this host, which is what it compares a
# CPU ceiling with (nproc can be told otherwise, by OMP_NUM_THREADS for one).
run_spec_host_cpus() {
    local n
    n="$(docker info -f '{{.NCPU}}' 2>/dev/null)"
    [[ "$n" =~ ^[1-9][0-9]*$ ]] || return 1
    echo "$n"
}

# Whether this host's Docker remaps user ids (userns-remap): 0 it does, 1 it
# does not, 2 Docker did not say. Under remapping a container's root is an
# unprivileged user on the host, and a file written into a volume from the host
# keeps the host's ids, which are not its owners inside the container.
run_spec_docker_remaps_ids() {
    local opts
    opts="$(docker info -f '{{range .SecurityOptions}}{{.}} {{end}}' 2>/dev/null)" || return 2
    [[ -n "$opts" ]] || return 2
    [[ " ${opts}" == *" name=userns "* ]]
}

# Whether this host can give a CPU ceiling: Docker refuses --cpus above the
# number of CPUs it counts, so a ceiling recorded on a bigger machine, or before
# this one was resized down, would leave a rebuilt site removed and not run.
# Fails closed when Docker's count cannot be read.
run_spec_cpus_fit() {  # CPUS (normalised; empty fits)
    local h
    [[ -z "$1" ]] && return 0
    h="$(run_spec_host_cpus)" || return 1
    awk -v n="$1" -v h="$h" 'BEGIN { exit !(n <= h) }'
}

# A spec file this host can run, said in plain words when it cannot: every
# caller about to stop a container to recreate it from the spec asks first.
# The way out for a site's own spec is install.sh; a caller holding a kept copy
# names its own.
run_spec_fits_host() {  # PATH [WAY_OUT]
    local cpus
    cpus="$(sed -n 's/^cpus=//p' "$1" | tail -1)"
    run_spec_cpus_fit "$cpus" && return 0
    echo "run spec: ${1} sets a CPU ceiling of ${cpus}, more than this host has (CPUs: $(run_spec_host_cpus || echo unknown)), so Docker would refuse to run it. ${2:-Rebuild the site with install.sh site --cpus set to one that fits, or --cpus=none}, then run this again" >&2
    return 1
}

# A process ceiling (a whole number of processes and threads, which is what
# the cgroup counts); none or 0 is no ceiling, printed as nothing.
run_spec_norm_pids() {  # VALUE
    local v="$1"
    case "$v" in ''|none|0) return 0 ;; esac
    [[ "$v" =~ ^[0-9]{1,9}$ ]] || return 1
    printf '%d' "$((10#$v))"
}

# A spec's lines, checked one by one: a value that would not be one docker
# argument, or a key nobody writes, is refused with the line named.
run_spec_check_line() {  # LINE
    local k="${1%%=*}" v="${1#*=}"
    case "$k" in
        spec_version) [[ "$v" =~ ^[0-9]+$ ]] ;;
        hostname)     [[ "$v" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]{0,62}$ ]] ;;
        restart)      [[ "$v" =~ ^(no|always|unless-stopped|on-failure)$ ]] ;;
        memory)       [[ -z "$v" || "$v" =~ ^[0-9]+[bkmg]?$ ]] ;;
        cpus)         [[ -z "$v" ]] || { [[ "$v" =~ ^([0-9]+(\.[0-9]*)?|\.[0-9]+)$ ]] && awk -v n="$v" 'BEGIN { exit !(n >= 0.01) }'; } ;;
        pids_limit)   [[ -z "$v" || "$v" =~ ^[1-9][0-9]*$ ]] ;;
        publish)      [[ "$v" =~ ^((([0-9]{1,3}\.){3}[0-9]{1,3}|\[[0-9A-Fa-f:.]+\]):)?[0-9]{1,5}:[0-9]{1,5}(/(tcp|udp))?$ ]] ;;
        volume)       [[ "$v" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*:/[A-Za-z0-9_./-]+(:(ro|rw))?$ ]] ;;
        network)      [[ "$v" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$ && "$v" != bridge && "$v" != host && "$v" != none ]] ;;
        bridge)       [[ "$v" =~ ^[a-z][a-z0-9]{0,14}$ ]] ;;
        subnet)       [[ "$v" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}/[0-9]{1,2}$ ]] ;;
        subnet6)      [[ -z "$v" || "$v" =~ ^[0-9a-f:]+/[0-9]{1,3}$ ]] ;;
        outbound_ceiling)   [[ -z "$v" || "$v" == off || "$v" =~ ^[1-9][0-9]{0,5}$ ]] ;;
        outbound_conn_rate|outbound_conn_burst|outbound_open_conns)
                      [[ -z "$v" || "$v" =~ ^[1-9][0-9]{0,6}$ ]] ;;
        outbound_set_by)    [[ -z "$v" || "$v" == plane ]] ;;
        *)            false ;;
    esac
}

# Write the lines on stdin (no comments, no blanks) to PATH, all or nothing:
# every line is checked first, then the file is replaced in one move.
run_spec_write_to() {  # PATH LABEL < lines
    local p="$1" label="$2" dir tmp line n=0
    dir="$(dirname "$p")"
    if ! mkdir -p "$dir"; then echo "run spec: cannot create ${dir}" >&2; return 1; fi
    chmod 700 "$(dirname "$dir")" "$dir" 2>/dev/null || true
    tmp="$(mktemp "${dir}/.run_spec.XXXXXX")" || { echo "run spec: cannot write in ${dir}" >&2; return 1; }
    printf '# joinery-managed - how this site'"'"'s container is run. Every rebuild reads it\n# (_site_run_spec.sh); edit it with install.sh, never by hand.\n' > "$tmp"
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        if ! run_spec_check_line "$line"; then
            echo "run spec: refusing line '${line:0:120}' for ${label}" >&2
            rm -f "$tmp"; return 1
        fi
        printf '%s\n' "$line" >> "$tmp"; n=$((n + 1))
    done
    [[ "$n" -gt 0 ]] || { rm -f "$tmp"; echo "run spec: nothing to write for ${label}" >&2; return 1; }
    chmod 644 "$tmp" && mv -f "$tmp" "$p" || { rm -f "$tmp"; echo "run spec: could not replace ${p}" >&2; return 1; }
}

run_spec_write() {  # SITE < lines
    local p
    p="$(run_spec_path "$1")" || return 1
    run_spec_write_to "$p" "$1"
}

# The lines of a fresh spec for a site install.sh creates: the standard
# volumes, the web port (on BIND, or every interface when BIND is empty) and
# the database port on 127.0.0.1.
run_spec_render() {  # SITE WEB_BIND WEB_PORT DB_PORT MEMORY CPUS PIDS_LIMIT
    local site="$1" bind="$2" port="$3" db_port="$4" entry suffix dest
    printf 'spec_version=%s\nhostname=%s\nrestart=unless-stopped\n' "$RUN_SPEC_VERSION" "$site"
    printf 'memory=%s\ncpus=%s\npids_limit=%s\n' "$5" "$6" "$7"
    printf 'publish=%s%s:80\n' "${bind:+${bind}:}" "$port"
    printf 'publish=127.0.0.1:%s:5432\n' "$db_port"
    for entry in "${RUN_SPEC_VOLUMES[@]}"; do
        suffix="${entry%%:*}"; dest="${entry#*:}"
        [[ "$dest" == /* ]] || dest="/var/www/html/${site}/${dest}"
        printf 'volume=%s_%s:%s\n' "$site" "$suffix" "$dest"
    done
}

# The lines of SITE's spec install.sh does not write itself: a port other than
# the web and database ports, a volume at a destination the standard set does
# not use. A rebuild keeps them; install.sh re-renders only what it owns.
run_spec_foreign_lines() {  # SITE
    local site="$1" v cport dest entry std="" own p
    for entry in "${RUN_SPEC_VOLUMES[@]}"; do
        dest="${entry#*:}"
        [[ "$dest" == /* ]] || dest="/var/www/html/${site}/${dest}"
        std="${std} ${dest} "
    done
    while IFS= read -r v; do
        [[ -z "$v" ]] && continue
        cport="${v##*:}"; cport="${cport%%/*}"
        own=0
        for p in $RUN_SPEC_OWN_PORTS; do [[ "$cport" == "$p" ]] && own=1; done
        [[ "$own" == 1 ]] || printf 'publish=%s\n' "$v"
    done < <(run_spec_list "$site" publish)
    while IFS= read -r v; do
        [[ -z "$v" ]] && continue
        dest="${v#*:}"; dest="${dest%:ro}"; dest="${dest%:rw}"
        [[ "$std" == *" ${dest} "* ]] || printf 'volume=%s\n' "$v"
    done < <(run_spec_list "$site" volume)
}

# SITE's own outbound figures, as the lines its spec holds (the host's
# joinery-limits unit reads them). install.sh site keeps them across a rebuild.
run_spec_outbound_lines() {  # SITE
    local p
    p="$(run_spec_path "$1")" || return 1
    [[ -f "$p" ]] || return 0
    grep -E '^outbound_(ceiling|conn_rate|conn_burst|open_conns|set_by)=.' "$p" || true
}

# A spec file whole: every line one argument, and a format this script reads.
run_spec_check_file() {  # PATH
    local line v k
    [[ -f "$1" ]] || { echo "run spec: none at ${1}" >&2; return 1; }
    while IFS= read -r line; do
        [[ -z "$line" || "$line" == \#* ]] && continue
        run_spec_check_line "$line" || { echo "run spec: ${1} has a line that is not one: '${line:0:120}'" >&2; return 1; }
    done < "$1"
    v="$(sed -n 's/^spec_version=//p' "$1" | tail -1)"
    if [[ -z "$v" || "$v" -gt "$RUN_SPEC_VERSION" ]]; then
        echo "run spec: ${1} is format '${v:-none}'; this script reads up to ${RUN_SPEC_VERSION}" >&2; return 1
    fi
    # A network is its name, its bridge and its IPv4 subnet together, or none.
    v=0
    for k in network bridge subnet; do grep -q "^${k}=." "$1" && v=$((v + 1)); done
    if [[ "$v" != 0 && "$v" != 3 ]] || { [[ "$v" == 0 ]] && grep -q '^subnet6=.' "$1"; }; then
        echo "run spec: ${1} names part of a network; it needs network=, bridge= and subnet= together" >&2; return 1
    fi
}

# --- The site's own network ---------------------------------------------------

# Why a new site network on this host gets no IPv6, in plain words, or nothing
# when it does: the machine has no IPv6 route out, Docker is too old to carry a
# container's IPv6 out on its own, or daemon.json turned that off.
run_spec_net_no_ipv6_reason() {
    local version
    if [[ -z "$(ip -6 route show default 2>/dev/null)" ]]; then
        echo "this machine has no IPv6 route out"; return 0
    fi
    version="$(docker version -f '{{.Server.Version}}' 2>/dev/null)"
    if [[ ! "$version" =~ ^([0-9]+)\. ]] || (( BASH_REMATCH[1] < RUN_SPEC_DOCKER_IPV6_MAJOR )); then
        echo "Docker ${version:-of unknown version} is older than ${RUN_SPEC_DOCKER_IPV6_MAJOR}, the first to carry a container's IPv6 out on its own (apt-get install --only-upgrade docker-ce, then rebuild the site)"
        return 0
    fi
    if grep -qsE '"ip6tables"[[:space:]]*:[[:space:]]*false' "$(run_spec_root)/etc/docker/daemon.json"; then
        echo "/etc/docker/daemon.json sets ip6tables to false, so Docker would not carry a container's IPv6 out"
    fi
}

# Docker's account of network NAME as spec lines, when it has the shape of a
# site network: a bridge its creator named, one IPv4 subnet and at most one
# IPv6. Says why and returns 1 otherwise.
run_spec_network_read() {  # NAME
    local out driver bridge subnets s v4="" v6="" n4=0 n6=0
    out="$(docker network inspect -f '{{.Driver}}|{{index .Options "com.docker.network.bridge.name"}}|{{range .IPAM.Config}}{{.Subnet}} {{end}}' "$1" 2>/dev/null)" \
        || { echo "run spec: Docker has no network $1" >&2; return 1; }
    IFS='|' read -r driver bridge subnets <<< "$out"
    for s in $subnets; do
        if [[ "$s" == *:* ]]; then v6="$s"; n6=$((n6 + 1)); else v4="$s"; n4=$((n4 + 1)); fi
    done
    if [[ "$driver" != bridge || -z "$bridge" || "$n4" != 1 || "$n6" -gt 1 ]]; then
        echo "run spec: network $1 is not one a site runs on (driver '${driver}', bridge name '${bridge}', subnets '${subnets% }'): a site network is a bridge its creator named, with one IPv4 subnet and at most one IPv6" >&2
        return 1
    fi
    printf 'network=%s\nbridge=%s\nsubnet=%s\nsubnet6=%s\n' "$1" "$bridge" "$v4" "$v6"
}

# Create the network the spec lines on stdin describe.
run_spec_network_create() {  # < network lines
    local lines name bridge subnet subnet6 out args
    lines="$(cat)"
    name="$(sed -n 's/^network=//p' <<< "$lines")"; bridge="$(sed -n 's/^bridge=//p' <<< "$lines")"
    subnet="$(sed -n 's/^subnet=//p' <<< "$lines")"; subnet6="$(sed -n 's/^subnet6=//p' <<< "$lines")"
    args=(network create --driver bridge --subnet "$subnet" -o "com.docker.network.bridge.name=${bridge}")
    [[ -n "$subnet6" ]] && args+=(--ipv6 --subnet "$subnet6")
    out="$(docker "${args[@]}" "$name" 2>&1)" \
        || { echo "run spec: Docker would not create network ${name} (${subnet}${subnet6:+ and ${subnet6}}, bridge ${bridge}): ${out}" >&2; return 1; }
}

# The lowest slot whose subnets and bridge nothing on this host holds: no run
# spec, Docker network, route or interface. Docker refuses a subnet that
# overlaps another of its networks, but not one the host routes elsewhere (a
# VPN, a private network), which the host would then lose.
run_spec_net_free_slot() {
    local spec net
    command -v python3 > /dev/null \
        || { echo "run spec: choosing a free network for a site needs python3, which this host does not have" >&2; return 1; }
    {
        for spec in "$(run_spec_root)"/etc/joinery/sites/*/run_spec; do
            [[ -f "$spec" ]] && sed -n 's/^\(bridge\|subnet\|subnet6\)=\(..*\)$/\2/p' "$spec"
        done
        for net in $(docker network ls -q 2>/dev/null); do
            docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}{{index .Options "com.docker.network.bridge.name"}}' "$net" 2>/dev/null
        done
        { ip -o -4 route show table all; ip -o -6 route show table all; } 2>/dev/null \
            | awk '{ t = $1; if (t ~ /^(local|broadcast|unreachable|blackhole|prohibit|anycast|multicast|throw|nat|unicast)$/) t = $2; print t }'
        ip -o link show 2>/dev/null | awk -F': ' '{ sub(/@.*/, "", $2); print $2 }'
    } | python3 -c '
import ipaddress, sys
p4, p6, bp, slots = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
nets, names = [], set()
for tok in sys.stdin.read().split():
    try:
        n = ipaddress.ip_network(tok, strict=False)
        if n.prefixlen > 0:
            nets.append(n)
    except ValueError:
        names.add(tok)
for i in range(1, slots + 1):
    mine = (ipaddress.ip_network("%s.%d.0/24" % (p4, i)), ipaddress.ip_network("%s:%d::/64" % (p6, i)))
    if bp + str(i) in names or any(n.version == m.version and n.overlaps(m) for n in nets for m in mine):
        continue
    print(i)
    sys.exit(0)
sys.exit(1)
' "$RUN_SPEC_NET4_PREFIX" "$RUN_SPEC_NET6_PREFIX" "$RUN_SPEC_BRIDGE_PREFIX" "$RUN_SPEC_NET_SLOTS" \
        || { echo "run spec: every one of this host's ${RUN_SPEC_NET_SLOTS} site networks (${RUN_SPEC_NET4_PREFIX}.N.0/24) is taken or overlaps a route" >&2; return 1; }
}

# The network lines for SITE's spec, for install.sh. A spec that has them keeps
# them; a network made IPv4 only takes its slot's IPv6 at a rebuild once the
# host can carry it. A network Docker already has under the site's name (a run
# that stopped before it wrote the spec) is taken as it is. Otherwise the lowest
# free slot, whose network is created here, under the host's lock, so two
# installs never take one slot.
run_spec_network_lines() {  # SITE
    local site="$1" name="${1}_net" lines reason lock fd n v6=""
    if [[ -n "$(run_spec_get "$site" network)" ]]; then
        lines="$(printf 'network=%s\nbridge=%s\nsubnet=%s\nsubnet6=%s\n' "$(run_spec_get "$site" network)" \
            "$(run_spec_get "$site" bridge)" "$(run_spec_get "$site" subnet)" "$(run_spec_get "$site" subnet6)")"
        if [[ -z "$(run_spec_get "$site" subnet6)" ]]; then
            reason="$(run_spec_net_no_ipv6_reason)"
            n="$(run_spec_get "$site" subnet | sed -n "s/^${RUN_SPEC_NET4_PREFIX//./\\.}\.\([0-9]\{1,3\}\)\.0\/24$/\1/p")"
            if [[ -z "$reason" && -n "$n" ]]; then
                lines="$(sed "s/^subnet6=\$/subnet6=${RUN_SPEC_NET6_PREFIX}:${n}::\/64/" <<< "$lines")"
            elif [[ -n "$reason" ]]; then
                echo "run spec: ${site}'s network stays IPv4 only: ${reason}" >&2
            fi
        fi
        printf '%s\n' "$lines"
        return 0
    fi
    if docker network inspect "$name" > /dev/null 2>&1; then
        run_spec_network_read "$name"
        return
    fi
    lock="$(run_spec_root)/etc/joinery/sites/.networks.lock"
    mkdir -p "$(dirname "$lock")" && exec {fd}> "$lock" \
        || { echo "run spec: cannot open ${lock}" >&2; return 1; }
    if ! flock -w 120 "$fd"; then
        exec {fd}>&-; echo "run spec: another install has held ${lock} for two minutes" >&2; return 1
    fi
    if ! n="$(run_spec_net_free_slot)"; then exec {fd}>&-; return 1; fi
    reason="$(run_spec_net_no_ipv6_reason)"
    if [[ -n "$reason" ]]; then
        echo "run spec: ${site}'s network is IPv4 only: ${reason}" >&2
    else
        v6="${RUN_SPEC_NET6_PREFIX}:${n}::/64"
    fi
    lines="$(printf 'network=%s\nbridge=%s%s\nsubnet=%s.%s.0/24\nsubnet6=%s\n' "$name" "$RUN_SPEC_BRIDGE_PREFIX" "$n" "$RUN_SPEC_NET4_PREFIX" "$n" "$v6")"
    if ! printf '%s\n' "$lines" | run_spec_network_create; then exec {fd}>&-; return 1; fi
    exec {fd}>&-
    printf '%s\n' "$lines"
}

# Make the network SITE's spec names exist with exactly the spec's bridge and
# subnets. One that differs is recreated when no container is attached to it (a
# rebuild has removed the site's container by now); one a container still uses
# is refused by name, never changed under it. A spec with no network has
# nothing to make.
run_spec_ensure_network() {  # SITE
    local site="$1" name want have attached
    name="$(run_spec_get "$site" network)"
    [[ -n "$name" ]] || return 0
    want="$(printf 'network=%s\nbridge=%s\nsubnet=%s\nsubnet6=%s\n' "$name" "$(run_spec_get "$site" bridge)" \
        "$(run_spec_get "$site" subnet)" "$(run_spec_get "$site" subnet6)")"
    if docker network inspect "$name" > /dev/null 2>&1; then
        have="$(run_spec_network_read "$name" 2>/dev/null)"
        [[ "$have" == "$want" ]] && return 0
        attached="$(docker network inspect -f '{{range $id, $c := .Containers}}{{$c.Name}} {{end}}' "$name" 2>/dev/null)" || attached="(unknown)"
        if [[ -n "$attached" ]]; then
            echo "run spec: network ${name} is not what ${site}'s run spec says ($(grep -v '^network=' <<< "$want" | paste -sd ' ')), and ${attached% } still use it; nothing was changed. Stop what uses it, then run this again" >&2
            return 1
        fi
        docker network rm "$name" > /dev/null 2>&1 \
            || { echo "run spec: could not remove network ${name} to make it what ${site}'s run spec says" >&2; return 1; }
        # A rebuild has removed the site's container by now, so a network that
        # cannot be made as the spec says must not leave it with none: the one
        # it had comes back, the spec says so, and the next rebuild tries again.
        if ! printf '%s\n' "$want" | run_spec_network_create; then
            if [[ -n "$have" ]] && printf '%s\n' "$have" | run_spec_network_create \
                && printf '%s\n' "$have" | run_spec_set_network "$site"; then
                echo "run spec: ${site} keeps network ${name} as it was ($(grep -v '^network=' <<< "$have" | paste -sd ' ')); the next rebuild tries again" >&2
                return 0
            fi
            return 1
        fi
        return 0
    fi
    printf '%s\n' "$want" | run_spec_network_create
}

# Put SITE on the network the lines on stdin describe, keeping every other line.
run_spec_set_network() {  # SITE < network lines
    local p lines
    p="$(run_spec_path "$1")" || return 1
    [[ -f "$p" ]] || { echo "run spec: ${1} has none to change" >&2; return 1; }
    lines="$(cat)"
    { grep -v -e '^#' -e '^network=' -e '^bridge=' -e '^subnet=' -e '^subnet6=' "$p" \
        | sed "s/^spec_version=.*/spec_version=${RUN_SPEC_VERSION}/"; printf '%s\n' "$lines"; } | run_spec_write "$1"
}

# The `docker run` arguments the spec describes, NUL-separated (read them with
# mapfile -d ''), ending before the environment and the image, which are the
# caller's. A spec that fails its own check, or was written by a newer format,
# gives nothing and returns 1. The network the spec names exists, as the spec
# says, by the time this returns.
run_spec_args() {  # SITE
    local site="$1" p v
    p="$(run_spec_path "$site")" || return 1
    run_spec_check_file "$p" || return 1
    run_spec_fits_host "$p" || return 1
    run_spec_ensure_network "$site" || return 1
    printf '%s\0' --name "$site" --hostname "$(run_spec_get "$site" hostname)"
    v="$(run_spec_get "$site" network)"
    [[ -n "$v" ]] && printf '%s\0' --network "$v"
    v="$(run_spec_get "$site" restart)"
    [[ -n "$v" && "$v" != "no" ]] && printf '%s\0' --restart "$v"
    v="$(run_spec_get "$site" memory)"
    # Swap pinned to the same figure, so the limit is real: left alone Docker
    # allows as much swap again, and a 256m site quietly uses 512m.
    [[ -n "$v" ]] && printf '%s\0' "--memory=${v}" "--memory-swap=${v}"
    v="$(run_spec_get "$site" cpus)"
    [[ -n "$v" ]] && printf '%s\0' "--cpus=${v}"
    v="$(run_spec_get "$site" pids_limit)"
    [[ -n "$v" ]] && printf '%s\0' "--pids-limit=${v}"
    printf '%s\0' --cap-drop=ALL
    for v in "${RUN_SPEC_CAPS[@]}"; do printf '%s\0' "--cap-add=${v}"; done
    while IFS= read -r v; do [[ -n "$v" ]] && printf '%s\0' -p "$v"; done < <(run_spec_list "$site" publish)
    while IFS= read -r v; do [[ -n "$v" ]] && printf '%s\0' -v "$v"; done < <(run_spec_list "$site" volume)
    return 0
}

# Change one single-valued key and keep every other line.
run_spec_set() {  # SITE KEY VALUE
    local p
    p="$(run_spec_path "$1")" || return 1
    [[ -f "$p" ]] || { echo "run spec: ${1} has none to change" >&2; return 1; }
    { grep -v -e '^#' -e "^$2=" "$p"; printf '%s=%s\n' "$2" "$3"; } | run_spec_write "$1"
}

# Add a volume line unless the destination is already mounted.
run_spec_add_volume() {  # SITE NAME DEST
    local p
    p="$(run_spec_path "$1")" || return 1
    [[ -f "$p" ]] || { echo "run spec: ${1} has none to change" >&2; return 1; }
    run_spec_list "$1" volume | grep -qE ":$3(:r[ow])?\$" && return 0
    { grep -v '^#' "$p"; printf 'volume=%s:%s\n' "$2" "$3"; } | run_spec_write "$1"
}

run_spec_remove() {  # SITE
    local p
    p="$(run_spec_path "$1")" || return 1
    rm -f "$p"
}

# A container created before run specs existed has its arguments only in
# Docker's record of it. This reads them once, while it still exists, and
# writes the spec every later rebuild reads. Never used once a spec exists.
# Anything a spec cannot carry (a bind mount, a capability beyond RUN_SPEC_CAPS,
# a network other than its own, swap set apart from memory...) is refused by name, never
# dropped: the spec is permanent, so a silent loss here would be a loss for good.
run_spec_adopt() {  # SITE
    local site="$1" mem swap cpus nano pids restart retries hip hport cport proto type name dest rw
    local priv netmode caps capdrop cap extra_caps="" kept_out="" nhosts ndev quota cpuset problems="" host_config ports mounts hostname
    local networks netlines=""
    run_spec_exists "$site" && return 0
    docker inspect "$site" > /dev/null 2>&1 || { echo "run spec: no container ${site} to adopt" >&2; return 1; }
    # Every read must succeed: a template Docker cannot execute prints nothing,
    # and empty fields would read as no limit. A list Docker leaves unset is
    # nil, which len refuses, so each len is guarded (range over nil is empty).
    host_config="$(docker inspect -f '{{.HostConfig.Privileged}}|{{.HostConfig.NetworkMode}}|{{range .HostConfig.CapAdd}}{{.}} {{end}}|{{if .HostConfig.ExtraHosts}}{{len .HostConfig.ExtraHosts}}{{else}}0{{end}}|{{if .HostConfig.Devices}}{{len .HostConfig.Devices}}{{else}}0{{end}}|{{.HostConfig.CpuQuota}}|{{.HostConfig.CpusetCpus}}|{{.HostConfig.RestartPolicy.MaximumRetryCount}}|{{.HostConfig.MemorySwap}}|{{.HostConfig.Memory}}|{{.HostConfig.NanoCpus}}|{{if .HostConfig.PidsLimit}}{{.HostConfig.PidsLimit}}{{end}}|{{.HostConfig.RestartPolicy.Name}}|{{.Config.Hostname}}|{{range .HostConfig.CapDrop}}{{.}} {{end}}' "$site")" \
        && ports="$(docker inspect -f '{{range $p, $conf := .HostConfig.PortBindings}}{{range $conf}}{{.HostIp}}|{{.HostPort}}|{{$p}}{{println}}{{end}}{{end}}' "$site")" \
        && mounts="$(docker inspect -f '{{range .Mounts}}{{.Type}}|{{.Name}}|{{.Destination}}|{{.RW}}{{println}}{{end}}' "$site")" \
        && networks="$(docker inspect -f '{{range $n, $c := .NetworkSettings.Networks}}{{$n}} {{end}}' "$site")" \
        || { echo "run spec: could not read ${site}'s settings from Docker; nothing was changed" >&2; return 1; }
    networks="${networks% }"
    IFS='|' read -r priv netmode caps nhosts ndev quota cpuset retries swap mem nano pids restart hostname capdrop <<< "$host_config"
    if [[ ! "$priv" =~ ^(true|false)$ || -z "$hostname" || ! "$mem" =~ ^[0-9]+$ || ! "$nano" =~ ^[0-9]+$ \
          || ! "$quota" =~ ^-?[0-9]+$ || ! "$retries" =~ ^[0-9]+$ ]]; then
        echo "run spec: Docker's account of ${site} was not in the expected form ('${host_config:0:120}'); nothing was changed" >&2
        return 1
    fi
    [[ "$priv" == "true" ]] && problems="${problems}; privileged"
    # Docker's default network, or the site's own network alone.
    case "$netmode" in
        ''|default|bridge)
            [[ -z "$networks" || "$networks" == bridge ]] || problems="${problems}; networks ${networks}" ;;
        "${site}_net")
            if [[ "$networks" != "${site}_net" ]]; then
                problems="${problems}; networks ${networks}"
            elif ! netlines="$(run_spec_network_read "${site}_net" 2>&1)"; then
                problems="${problems}; ${netlines#run spec: }"; netlines=""
            fi ;;
        *) problems="${problems}; network ${netmode}" ;;
    esac
    # Docker names a capability with or without CAP_; a rebuild gives the six
    # whatever the container held, so only one beyond them would be lost.
    for cap in $caps; do
        cap="$(printf '%s' "${cap#[Cc][Aa][Pp]_}" | tr 'a-z' 'A-Z')"
        [[ " ${RUN_SPEC_CAPS[*]} " == *" ${cap} "* ]] || extra_caps="${extra_caps}, ${cap}"
    done
    [[ -z "$extra_caps" ]] || problems="${problems}; added capabilities (${extra_caps#, })"
    # One of the six dropped on purpose would be granted back by the rebuild.
    for cap in $capdrop; do
        cap="$(printf '%s' "${cap#[Cc][Aa][Pp]_}" | tr 'a-z' 'A-Z')"
        [[ "$cap" == "ALL" ]] && continue
        [[ " ${RUN_SPEC_CAPS[*]} " == *" ${cap} "* ]] || continue
        kept_out="${kept_out}, ${cap}"
    done
    [[ -z "$kept_out" ]] || problems="${problems}; dropped capabilities a site is given (${kept_out#, })"
    [[ "${nhosts:-0}" == 0 ]] || problems="${problems}; extra hosts"
    [[ "${ndev:-0}" == 0 ]] || problems="${problems}; devices"
    [[ "${quota:-0}" == 0 ]] || problems="${problems}; a CPU quota"
    [[ -z "$cpuset" ]] || problems="${problems}; a CPU set (${cpuset})"
    [[ "${retries:-0}" == 0 ]] || problems="${problems}; restart retries ${retries}"
    case "$mem" in ''|0) mem="" ;; *)
        [[ "$swap" == "$mem" ]] || problems="${problems}; swap (${swap}) set apart from memory (${mem}): run docker update --memory-swap=${mem} ${site}, then run this again"
        if [[ $((mem % 1048576)) -eq 0 ]]; then mem="$((mem / 1048576))m"; else mem="${mem}b"; fi ;;
    esac
    cpus=""
    case "$nano" in ''|0) ;; *) cpus="$(awk -v n="$nano" 'BEGIN { s = sprintf("%.3f", n / 1e9); sub(/0+$/, "", s); sub(/\.$/, ".0", s); print s }')" ;; esac
    case "$pids" in ''|0|-1) pids="" ;; esac
    while IFS='|' read -r type name dest rw; do
        [[ -z "$type" ]] && continue
        [[ "$type" == "volume" ]] || problems="${problems}; a ${type} mount at ${dest}"
    done <<< "$mounts"
    if [[ -n "$problems" ]]; then
        echo "run spec: ${site}'s container has what a run spec cannot carry: ${problems#; }. Recreating it would lose that; nothing was changed. Remove what is named from the container (or recreate it without it), then run this again" >&2
        return 1
    fi
    {
        printf 'spec_version=%s\n' "$RUN_SPEC_VERSION"
        printf 'hostname=%s\n' "$hostname"
        printf 'restart=%s\n' "${restart:-no}"
        printf 'memory=%s\ncpus=%s\npids_limit=%s\n' "$mem" "$cpus" "$pids"
        while IFS='|' read -r hip hport cport; do
            [[ -z "$cport" ]] && continue
            proto="${cport#*/}"; [[ "$proto" == "$cport" ]] && proto="tcp"
            [[ "$hip" == *:* ]] && hip="[${hip}]"
            printf 'publish=%s%s:%s%s\n' "${hip:+${hip}:}" "$hport" "${cport%%/*}" "$([[ "$proto" == tcp ]] || echo "/${proto}")"
        done <<< "$ports"
        while IFS='|' read -r type name dest rw; do
            [[ "$type" == "volume" && -n "$name" ]] || continue
            printf 'volume=%s:%s%s\n' "$name" "$dest" "$([[ "$rw" == false ]] && echo ':ro')"
        done <<< "$mounts"
        [[ -z "$netlines" ]] || printf '%s\n' "$netlines"
    } | run_spec_write "$site"
}

# The container's environment, as an --env-file at PATH (mode 600), for a
# caller that recreates a container it did not create: the environment is the
# container's own, never a run argument on a command line. A failed or empty
# read leaves any file already at PATH as it was and returns 1.
run_spec_save_env() {  # SITE PATH
    local out e kept=0 tmp
    out="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$1")" \
        || { echo "run spec: could not read ${1}'s environment" >&2; return 1; }
    tmp="$(mktemp "$(dirname "$2")/.env.XXXXXX")" || return 1
    while IFS= read -r e; do
        [[ -z "$e" ]] && continue
        case "$e" in PATH=*|DEBIAN_FRONTEND=*) continue ;; esac
        printf '%s\n' "$e" >> "$tmp"; kept=$((kept + 1))
    done <<< "$out"
    if [[ "$kept" -eq 0 ]]; then
        rm -f "$tmp"; echo "run spec: ${1}'s environment read as empty; nothing was kept" >&2; return 1
    fi
    chmod 600 "$tmp" && mv -f "$tmp" "$2" || { rm -f "$tmp"; return 1; }
}

# A rebase prepared before run specs kept the old container's arguments as one
# NUL-separated list (--name, --hostname, --restart, -p, -e, -v). This turns
# that list into a spec at SPEC_PATH and an env file at ENV_PATH, so a move
# begun then can still be rolled back. Nothing is written unless both are whole.
run_spec_from_run_args() {  # RUN_ARGS_FILE SPEC_PATH ENV_PATH
    local a flag="" lines="" envtmp kept=0 host="" restart="no"
    envtmp="$(mktemp "$(dirname "$3")/.env.XXXXXX")" || return 1
    while IFS= read -r -d '' a; do
        if [[ -z "$flag" ]]; then flag="$a"; continue; fi
        case "$flag" in
            --name) ;;
            --hostname) host="$a" ;;
            --restart)  restart="$a" ;;
            -p)         lines="${lines}"$'\n'"publish=${a}" ;;
            -v)         lines="${lines}"$'\n'"volume=${a}" ;;
            -e)         printf '%s\n' "$a" >> "$envtmp"; kept=$((kept + 1)) ;;
            *) rm -f "$envtmp"; echo "run spec: ${1} carries '${flag}', which a run spec cannot" >&2; return 1 ;;
        esac
        flag=""
    done < "$1"
    if [[ -n "$flag" || "$kept" -eq 0 ]]; then
        rm -f "$envtmp"; echo "run spec: ${1} is incomplete" >&2; return 1
    fi
    { printf 'spec_version=%s\nhostname=%s\nrestart=%s\nmemory=\ncpus=\npids_limit=\n' "$RUN_SPEC_VERSION" "$host" "$restart"
      printf '%s\n' "$lines"; } | run_spec_write_to "$2" "$2" || { rm -f "$envtmp"; return 1; }
    chmod 600 "$envtmp" && mv -f "$envtmp" "$3"
}

# The outbound limits follow this host's sites (outbound_limits.sh): a site
# created, moved, rebuilt or removed changes which bridges are limited. Runs the
# host's joinery-limits unit, which takes one run at a time; a host without the
# unit has no limits to change. Never fails its caller: the unit's timer runs
# it again within five minutes.
run_spec_limits_refresh() {
    [[ -f "$(run_spec_root)/etc/systemd/system/joinery-limits.service" ]] || return 0
    systemctl start joinery-limits.service > /dev/null 2>&1 \
        || echo "run spec: WARNING - the outbound limits did not follow this change (its timer tries again within five minutes); see: sudo joinery-limits status" >&2
    return 0
}
