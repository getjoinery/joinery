#!/usr/bin/env bash
#
# container_inventory.sh - every container on this machine, whether install.sh
# made it, its image, its state, its mounts and whether its run spec exists,
# as ONE JSON object on stdout.
#
# Version: 1.5 - packages: for each container install.sh made, the installed version of each source a package
#                fix can cover in the site base (php8.5, openssl, apache2, postgresql-18), read from its dpkg
#                status through /proc/PID/root, and base_fixes: its image's joinery.package_fixes label; so the
#                plane sees a site running our builds (the 2026-11-20 running-list entry)
# Version: 1.4 - security on the site container engine (Podman) reads the set a container keeps from Podman's
#                own BoundingCaps: Podman records the defaults it dropped, never ALL, and nothing as added, so
#                every Podman site read as adding nothing and dropping not all (site_copy_final_test C9)
# Version: 1.3 - security: for each container install.sh made, the system call filter it was made under (its
#                joinery.seccomp label), the capabilities it adds, whether it drops every other, and the
#                setuid-root files in its image, read from the host through the running container's root
#                (/proc/PID/root) once per image and kept under /run; what a site copy is checked against from
#                the management node (site_copy_final_test.md, the rig)
# Version: 1.2 - every mount of a site is listed (32 a container, a site has 16 volumes), and a container
#                with more says so in truncated, as a cut list does (B65: ten were kept, truncated false).
# Version: 1.1 - the engine is run by its command (site_engine_bin: /usr/local/bin/podman by path), its name
#                still what "engine" says (site_container_engine WP4 review T3).
# Version: 1.0 - the container_inventory observe word of
#                specs/agent_recipes_and_vocabulary.md (the running list,
#                2026-10-06: removing getjoinery's old container from
#                docker-prod reached for two sibling sites' volumes by a name
#                prefix, and deciding the rule that fixed it took a read-only
#                SSH session for each container's image and mounts). Its own
#                word, not keys in host_report: a host's hundred sites already
#                fill most of the agent's 64 KiB there. The agent runs this
#                file, verified against the release manifest, with no argv and
#                no stdin.
#
# THE CONTRACT, which tests/integration/container_inventory_gate.sh pins:
#
#   - It takes NOTHING: no argument, no stdin. A caller cannot name a
#     container, an image or a path.
#   - READ ONLY: the engine's ps and ONE inspect for every container. The
#     engine is the one this host's sites run on (install_tools/_site_engine.sh:
#     the site container engine, Podman, where it is installed, else Docker),
#     named in engine. The inspect template filters the environment to
#     SITENAME inside the engine, so no other variable (a password) ever
#     reaches this script. Podman reads an image built here back as
#     localhost/NAME; it is reported as NAME, as install.sh gave it.
#   - install_sh says whether install.sh made the container: its name is a
#     plain site name and its SITENAME is its name - the one rule
#     remove_account.sh, restart_container and hold_container keep.
#   - A mount is its type and, for a volume, the volume's name. A bind's or a
#     tmpfs's source path on the server is never read.
#   - run_spec says whether /etc/joinery/sites/{name}/run_spec exists: the
#     record install.sh keeps of how it ran the container.
#   - security, for a container install.sh made: filter (the sha256 its
#     joinery.seccomp label names, or null), caps (what it holds, as X, never
#     CAP_X: on Docker what it adds, on Podman the set it keeps), drop_all,
#     and setuid (the count of setuid-root files its image
#     ships, found through /proc/PID/root the first time a container of that
#     image runs and kept per image under /run, so a file made setuid later in
#     a running container's own layer or in a volume is not counted; unknown
#     while it has never run). Nothing runs inside it.
#   - packages, for a container install.sh made: an object naming each source a
#     package fix can cover in the site base, php8.5, openssl, apache2 and
#     postgresql-18, with the installed version of its main binary
#     (php8.5-common, libssl3t64, apache2, postgresql-18) or null when it is not
#     installed, read from the running container's dpkg status through
#     /proc/PID/root; "unknown" while it is not running. base_fixes: its image's
#     joinery.package_fixes label (what the base took, "none", or null where
#     the image has none). Nothing runs inside it.
#   - A name that is not a plain word is listed, reduced to safe characters,
#     and never inspected: its image, state and mounts are unknown.
#   - Bounded: at most MAX_CONTAINERS entries and MAX_MOUNTS mounts each, every
#     string reduced to a safe character set and capped, and the whole list
#     held under MAX_BYTES; truncated says when anything was left out.
#   - Every key is ALWAYS present: containers is "none" where the engine is
#     not installed and "unknown" where it does not answer. Exit 0 whenever the
#     object was printed; exit 2, with nothing printed, where the tree has no
#     _site_engine.sh to name the engine.

set -u
export LC_ALL=C

CMD_TIMEOUT=20          # seconds per external command
MAX_CONTAINERS=150      # entries
MAX_MOUNTS=32           # mounts per entry: a site has 16 volumes
MAX_NAME=64             # characters kept of a name
MAX_BYTES=57344         # the list's size, under the agent's 64 KiB

ETC="${CONTAINER_INVENTORY_ETC:-/etc}"
PROC="${CONTAINER_INVENTORY_PROC:-/proc}"   # tests only: another folder
# A package fix's source in the site base, and the binary whose version is its version.
BASE_PACKAGES="php8.5=php8.5-common openssl=libssl3t64 apache2=apache2 postgresql-18=postgresql-18"

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }
refuse() { printf 'container_inventory: %s\n' "$1" >&2; exit 2; }

# The engine this host's sites run on. site_engine is a shell function, which
# timeout cannot run, so the engine's own command is called under it.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ -f "$HERE/../install_tools/_site_engine.sh" ]] || refuse "there is no install_tools/_site_engine.sh beside this script's tree"
. "$HERE/../install_tools/_site_engine.sh"
ENGINE_KIND="$(site_engine_kind)" || refuse "this machine's container engine could not be named"
# Run by its command: the site container engine's podman by path, whatever PATH holds.
ENGINE="$(site_engine_bin)" || refuse "this machine's container engine could not be named"
safe_name() { local s="${1//[^A-Za-z0-9._@:-]/}"; printf '%s' "${s:0:$MAX_NAME}"; }
json_str() { printf '"%s"' "$(safe_name "$1")"; }
# An image reference keeps its slashes.
json_image() { local drop='[^A-Za-z0-9._@:/-]' s; s="${1//$drop/}"; printf '"%s"' "${s:0:128}"; }

# Each container on one line, |-separated: its name, its SITENAME entry, its
# state, its image, and each mount's type and name ("type:name ", a bind's
# name empty). Every part reads the engine's typed container; Docker names it
# with a leading slash, Podman without.
# The capabilities a site holds, as its engine states them. Docker records what
# was added and that ALL was dropped. Podman records instead the defaults it
# dropped (never ALL) and nothing as added, and states the set a container keeps
# as BoundingCaps, beyond which it can hold none: every other is dropped.
if [[ "$ENGINE_KIND" == "podman" ]]; then
    CAPS_FORMAT='{{range .BoundingCaps}}{{.}} {{end}}|ALL'
else
    CAPS_FORMAT='{{range .HostConfig.CapAdd}}{{.}} {{end}}|{{range .HostConfig.CapDrop}}{{.}} {{end}}'
fi
INSPECT_FORMAT='{{.Name}}|{{range .Config.Env}}{{if eq (index (split . "=") 0) "SITENAME"}}{{.}}{{end}}{{end}}|{{.State.Status}}|{{.Config.Image}}|{{range .Mounts}}{{.Type}}:{{.Name}} {{end}}|{{index .Config.Labels "joinery.seccomp"}}|'"$CAPS_FORMAT"'|{{.State.Pid}}|{{.Image}}|{{index .Config.Labels "joinery.package_fixes"}}'

# The setuid-root files on a running container's own filesystem, read from the
# host through its root (/proc/PID/root), counted once per image and kept.
SETUID_CACHE="${CONTAINER_INVENTORY_RUN:-/run/joinery}/inventory_setuid"
setuid_count() {  # IMAGE_ID PID
    local f count
    [[ "$1" =~ ^[A-Za-z0-9:._-]{1,128}$ ]] || { printf '"unknown"'; return; }
    f="${SETUID_CACHE}/${1//:/_}"
    count="$(head -c 16 "$f" 2>/dev/null)"
    if [[ ! "$count" =~ ^[0-9]+$ ]]; then
        [[ "$2" =~ ^[1-9][0-9]*$ && -d "/proc/$2/root/" ]] || { printf '"unknown"'; return; }
        count="$(run find "/proc/$2/root/" -xdev -perm -4000 -type f | wc -l)"
        [[ "$count" =~ ^[0-9]+$ ]] || { printf '"unknown"'; return; }
        mkdir -p "$SETUID_CACHE" 2>/dev/null && printf '%s' "$count" > "$f" 2>/dev/null
    fi
    printf '%s' "$count"
}

security_json() {  # LABEL CAPADD CAPDROP PID IMAGE_ID
    local cap caps="" sep="" drop=false
    for cap in $2; do
        cap="${cap#CAP_}"; cap="${cap^^}"
        [[ "$cap" =~ ^[A-Z_]{2,40}$ ]] || continue
        caps+="${sep}\"${cap}\""; sep=","
    done
    [[ " ${3^^} " == *" ALL "* || " ${3^^} " == *" CAP_ALL "* ]] && drop=true
    printf '{"filter":%s,"caps":[%s],"drop_all":%s,"setuid":%s}' \
        "$([[ "$1" =~ ^[0-9a-f]{64}$ ]] && printf '"%s"' "$1" || printf 'null')" "$caps" "$drop" "$(setuid_count "$5" "$4")"
}

# The installed version of each base source's binary, from the running
# container's own dpkg status, read from the host.
packages_json() {  # PID
    local status pair src bin v sep="" out=""
    [[ "$1" =~ ^[1-9][0-9]*$ ]] || { printf '"unknown"'; return; }
    status="${PROC}/$1/root/var/lib/dpkg/status"
    [[ -f "$status" && ! -L "$status" ]] || { printf '"unknown"'; return; }
    local found
    found="$(run awk -v want="$(for pair in $BASE_PACKAGES; do printf '%s ' "${pair#*=}"; done)" '
        BEGIN { n = split(want, w, " "); for (i = 1; i <= n; i++) keep[w[i]] = 1 }
        /^Package: / { pkg = $2; ok = 0; ver = "" }
        /^Status: install ok installed$/ { ok = 1 }
        /^Version: / { ver = $2 }
        /^$/ { if (pkg in keep && ok && ver != "") print pkg, ver; pkg = "" }
        END { if (pkg in keep && ok && ver != "") print pkg, ver }' "$status")"
    for pair in $BASE_PACKAGES; do
        src="${pair%%=*}"; bin="${pair#*=}"
        v="$(awk -v b="$bin" '$1 == b { print $2; exit }' <<< "$found")"
        v="${v//[^A-Za-z0-9.+~:-]/}"
        out+="${sep}\"${src}\":$([[ -n "$v" ]] && printf '"%s"' "${v:0:64}" || printf 'null')"; sep=","
    done
    printf '{%s}' "$out"
}

# The base's joinery.package_fixes label: source=version pairs, "none", or null.
base_fixes_json() {  # LABEL
    local drop='[^A-Za-z0-9.+~:=,_-]' s
    [[ -n "$1" && "$1" != "<no value>" ]] || { printf 'null'; return; }
    s="${1//$drop/}"; printf '"%s"' "${s:0:512}"
}

mounts_json() {
    local m t nm sep="" k=0
    printf '['
    for m in $1; do
        (( k < MAX_MOUNTS )) || break
        t="${m%%:*}"; nm="${m#*:}"
        t="${t//[^a-z]/}"; [[ -n "$t" ]] || continue
        printf '%s{"type":"%s","name":%s}' "$sep" "${t:0:16}" "$(json_str "$nm")"
        sep=","; k=$((k+1))
    done
    printf ']'
}

has_run_spec() {
    [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$ && -f "${ETC}/joinery/sites/$1/run_spec" ]] && echo true || echo false
}

TRUNCATED=false
emit_containers() {
    local all names c site state image mounts entry out="" n=0 own
    local -a all_mounts
    command -v "$ENGINE" >/dev/null 2>&1 || { printf '"none"'; return; }
    all="$(run "$ENGINE" ps -a --format '{{.Names}}')" || { printf '"unknown"'; return; }
    names="$(grep -E '^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$' <<< "$all")"
    add() {
        if (( n >= MAX_CONTAINERS )) || (( ${#out} + ${#1} + 1 > MAX_BYTES )); then TRUNCATED=true; return 1; fi
        out+="${out:+,}$1"; n=$((n+1))
    }
    # A name that is not a plain word: named, reduced, never inspected.
    while IFS= read -r c; do
        [[ -n "$c" ]] || continue
        add "$(printf '{"name":%s,"install_sh":false,"image":"unknown","state":"unknown","mounts":"unknown","run_spec":false,"security":null,"packages":null,"base_fixes":null}' "$(json_str "$c")")" || break
    done <<< "$(grep -v -E '^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$' <<< "$all")"
    if [[ -n "$names" ]]; then
        local inspected
        # shellcheck disable=SC2086  # the names are checked above: one word each
        inspected="$(run "$ENGINE" inspect -f "$INSPECT_FORMAT" $names)"
        [[ -n "$inspected" ]] || { printf '"unknown"'; return; }
        local label capadd capdrop pid image_id fixes_label
        while IFS='|' read -r c site state image mounts label capadd capdrop pid image_id fixes_label; do
            c="${c#/}"
            [[ -n "$c" ]] || continue
            if [[ "$c" =~ ^[a-z0-9_-]{1,50}$ && "$site" == "SITENAME=$c" ]]; then own=true; else own=false; fi
            image="${image#localhost/}"
            # A container with more mounts than are listed cuts the list as surely as a missing entry.
            read -r -a all_mounts <<< "$mounts"
            (( ${#all_mounts[@]} <= MAX_MOUNTS )) || TRUNCATED=true
            entry="$(printf '{"name":%s,"install_sh":%s,"image":%s,"state":%s,"mounts":%s,"run_spec":%s,"security":%s,"packages":%s,"base_fixes":%s}' \
                "$(json_str "$c")" "$own" "$(json_image "$image")" "$(json_str "${state:-unknown}")" \
                "$(mounts_json "$mounts")" "$(has_run_spec "$c")" \
                "$([[ "$own" == true ]] && security_json "$label" "$capadd" "$capdrop" "$pid" "$image_id" || printf 'null')" \
                "$([[ "$own" == true && "$state" == running ]] && packages_json "$pid" || { [[ "$own" == true ]] && printf '"unknown"' || printf 'null'; })" \
                "$([[ "$own" == true ]] && base_fixes_json "$fixes_label" || printf 'null')")"
            add "$entry" || break
        done <<< "$inspected"
    fi
    printf '[%s]' "$out"
    # The flag rides out on its own line; this ran in a subshell.
    printf '\n%s' "$TRUNCATED"
}

REPLY_LINES="$(emit_containers)"
CONTAINERS="$(head -n 1 <<< "$REPLY_LINES")"
TRUNCATED="$(sed -n 2p <<< "$REPLY_LINES")"
[[ "$TRUNCATED" == "true" ]] || TRUNCATED=false

printf '{"engine":"%s","containers":%s,"truncated":%s,"generated_at":%d}\n' "$ENGINE_KIND" "$CONTAINERS" "$TRUNCATED" "$(date +%s)"
exit 0
