#!/usr/bin/env bash
#
# _site_run_spec.sh - how a site's container is run, recorded once on its
# Docker host (specs/multi_tenant_docker_hosts.md WP0).
#
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
#   pids_limit=512       empty: Docker's default
#   publish=127.0.0.1:8087:80       one line per published port; [v6]:h:c and /udp allowed
#   volume=mysite_code:/var/www/html/mysite/public_html    one line per volume, :ro allowed
#
# A test points /etc at a fixture with JOINERY_SITE_STATE_ROOT, and only an
# unprivileged run may, the same rule as _site_state.sh.
#
# Sourced, never executed:  . "${TOOLS_DIR}/_site_run_spec.sh"
# Functions only; sourcing it runs nothing.

RUN_SPEC_VERSION=1

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
# no ceiling, printed as nothing.
run_spec_norm_cpus() {  # VALUE
    local v="$1"
    case "$v" in ''|none|0) return 0 ;; esac
    [[ "$v" =~ ^([0-9]+(\.[0-9]*)?|\.[0-9]+)$ ]] || return 1
    awk -v n="$v" 'BEGIN { if (n <= 0) exit 1; s = sprintf("%.3f", n); sub(/0+$/, "", s); sub(/\.$/, ".0", s); print s }'
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
        cpus)         [[ -z "$v" || "$v" =~ ^[0-9]*\.?[0-9]+$ ]] ;;
        pids_limit)   [[ -z "$v" || "$v" =~ ^[1-9][0-9]*$ ]] ;;
        publish)      [[ "$v" =~ ^((([0-9]{1,3}\.){3}[0-9]{1,3}|\[[0-9A-Fa-f:.]+\]):)?[0-9]{1,5}:[0-9]{1,5}(/(tcp|udp))?$ ]] ;;
        volume)       [[ "$v" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*:/[A-Za-z0-9_./-]+(:(ro|rw))?$ ]] ;;
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

# A spec file whole: every line one argument, and a format this script reads.
run_spec_check_file() {  # PATH
    local line v
    [[ -f "$1" ]] || { echo "run spec: none at ${1}" >&2; return 1; }
    while IFS= read -r line; do
        [[ -z "$line" || "$line" == \#* ]] && continue
        run_spec_check_line "$line" || { echo "run spec: ${1} has a line that is not one: '${line:0:120}'" >&2; return 1; }
    done < "$1"
    v="$(sed -n 's/^spec_version=//p' "$1" | tail -1)"
    if [[ -z "$v" || "$v" -gt "$RUN_SPEC_VERSION" ]]; then
        echo "run spec: ${1} is format '${v:-none}'; this script reads up to ${RUN_SPEC_VERSION}" >&2; return 1
    fi
}

# The `docker run` arguments the spec describes, NUL-separated (read them with
# mapfile -d ''), ending before the environment and the image, which are the
# caller's. A spec that fails its own check, or was written by a newer format,
# gives nothing and returns 1.
run_spec_args() {  # SITE
    local site="$1" p v
    p="$(run_spec_path "$site")" || return 1
    run_spec_check_file "$p" || return 1
    printf '%s\0' --name "$site" --hostname "$(run_spec_get "$site" hostname)"
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
# Anything a spec cannot carry (a bind mount, added capabilities, another
# network, swap set apart from memory...) is refused by name, never dropped:
# the spec is permanent, so a silent loss here would be a loss for good.
run_spec_adopt() {  # SITE
    local site="$1" mem swap cpus nano pids restart retries hip hport cport proto type name dest rw
    local priv netmode ncap nhosts ndev quota cpuset problems=""
    run_spec_exists "$site" && return 0
    docker inspect "$site" > /dev/null 2>&1 || { echo "run spec: no container ${site} to adopt" >&2; return 1; }
    IFS='|' read -r priv netmode ncap nhosts ndev quota cpuset retries swap < <(docker inspect -f \
        '{{.HostConfig.Privileged}}|{{.HostConfig.NetworkMode}}|{{len .HostConfig.CapAdd}}|{{len .HostConfig.ExtraHosts}}|{{len .HostConfig.Devices}}|{{.HostConfig.CpuQuota}}|{{.HostConfig.CpusetCpus}}|{{.HostConfig.RestartPolicy.MaximumRetryCount}}|{{.HostConfig.MemorySwap}}' "$site")
    mem="$(docker inspect -f '{{.HostConfig.Memory}}' "$site")"
    nano="$(docker inspect -f '{{.HostConfig.NanoCpus}}' "$site")"
    pids="$(docker inspect -f '{{if .HostConfig.PidsLimit}}{{.HostConfig.PidsLimit}}{{end}}' "$site")"
    restart="$(docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$site")"
    [[ "$priv" == "true" ]] && problems="${problems}; privileged"
    case "$netmode" in ''|default|bridge) ;; *) problems="${problems}; network ${netmode}" ;; esac
    [[ "${ncap:-0}" == 0 ]] || problems="${problems}; added capabilities"
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
    done < <(docker inspect -f '{{range .Mounts}}{{.Type}}|{{.Name}}|{{.Destination}}|{{.RW}}{{println}}{{end}}' "$site")
    if [[ -n "$problems" ]]; then
        echo "run spec: ${site}'s container has what a run spec cannot carry: ${problems#; }. Recreating it would lose that; nothing was changed. Remove what is named from the container (or recreate it without it), then run this again" >&2
        return 1
    fi
    {
        printf 'spec_version=%s\n' "$RUN_SPEC_VERSION"
        printf 'hostname=%s\n' "$(docker inspect -f '{{.Config.Hostname}}' "$site")"
        printf 'restart=%s\n' "${restart:-no}"
        printf 'memory=%s\ncpus=%s\npids_limit=%s\n' "$mem" "$cpus" "$pids"
        while IFS='|' read -r hip hport cport; do
            [[ -z "$cport" ]] && continue
            proto="${cport#*/}"; [[ "$proto" == "$cport" ]] && proto="tcp"
            [[ "$hip" == *:* ]] && hip="[${hip}]"
            printf 'publish=%s%s:%s%s\n' "${hip:+${hip}:}" "$hport" "${cport%%/*}" "$([[ "$proto" == tcp ]] || echo "/${proto}")"
        done < <(docker inspect -f '{{range $p, $conf := .HostConfig.PortBindings}}{{range $conf}}{{.HostIp}}|{{.HostPort}}|{{$p}}{{println}}{{end}}{{end}}' "$site")
        while IFS='|' read -r type name dest rw; do
            [[ "$type" == "volume" && -n "$name" ]] || continue
            printf 'volume=%s:%s%s\n' "$name" "$dest" "$([[ "$rw" == false ]] && echo ':ro')"
        done < <(docker inspect -f '{{range .Mounts}}{{.Type}}|{{.Name}}|{{.Destination}}|{{.RW}}{{println}}{{end}}' "$site")
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
