#!/usr/bin/env bash
#
# install_site_engine.sh - install or converge the site container engine on a
# container host (specs/site_container_engine.md D2, D3).
#
# Version: 1.3 - the engine's containers.conf drop-in and apt pin are written by every converge, with
#               the rest of its configuration: a changed one reached a host only with a new engine.
#               The engine reads OCI hooks from /etc/containers/oci/hooks.d too, where the host's own
#               go (multi_tenant_host.sh: every container start checks the walls, reviewer2 R3).
# Version: 1.2 - apt's package lists are fetched before runc and conmon are installed (a new
#               machine has none), and a failed apt step stops the run; converge runs the install
#               as a plain statement, since bash ignores errexit inside a condition and an install
#               there carried on past a failure.
# Version: 1.1 - the engine brings its own configuration instead of Ubuntu's containers-common
#               (written for Podman 5.7, and pulling in the old CNI plugins once netavark is
#               pinned out): the image policy, the registry list, and the system call filter
#               every site runs under (site_engine/site_seccomp.json, Docker's own default, since
#               Podman's built-in one lets www-data make user namespaces); container storage on
#               the data root. converge writes them every run, so a new filter in the tree
#               reaches the host without a new engine (specs/site_container_engine.md WP2).
# Version: 1.0
#
# The engine is our own build of Podman, netavark and aardvark-dns
# (site_engine/build.sh, from the pinned sources in site_engine/versions.env).
# Each release publishes it as static_files/site_engine/joinery-site-engine-
# <version>-<arch>.tar.xz, and the tree carries site_engine/ENGINE naming that
# archive and its SHA-256, covered by the release's signed manifest. Ubuntu's
# own Podman is never used: it has had no security update since 26.04 shipped.
#
# Usage:
#   install_site_engine.sh converge [UPGRADE_SERVER]
#         Root. Makes the engine the release names in site_engine/ENGINE (beside
#         this script) the current one: when it is not, fetches the archive from
#         UPGRADE_SERVER (default $UPGRADE_SERVER, else https://getjoinery.com)
#         and installs it as install does. A tree with no ENGINE: nothing to do.
#   install_site_engine.sh install ARCHIVE SHA256
#         Root. Installs the engine in ARCHIVE when its SHA-256 is SHA256, and
#         makes it the current one. Run again with the same archive, it changes
#         nothing.
#   install_site_engine.sh status
#         Prints the current engine's BUILDINFO; exit 1 when there is none.
#
# What install leaves behind:
#   /usr/local/lib/joinery-site-engine/<version>-<sha12>/  bin/podman, helpers/, systemd/, BUILDINFO
#         named by the archive's hash as well as its version: a rebuild of the same
#         Podman (a newer Go, a changed build) is a different engine
#   /usr/local/lib/joinery-site-engine/current      -> that directory, switched in one rename
#   /usr/local/bin/podman                           a copy of current/bin/podman, not a link:
#         podman records its own resolved path in every container it starts (the
#         exit command conmon runs when the container stops), so that path must
#         stay the same across engines
#   /etc/containers/containers.conf.d/50-joinery-site-engine.conf
#         runtime runc, the helpers, the systemd cgroup manager, netavark
#   /etc/systemd/system/podman-restart.service, enabled (not started): at boot it
#         starts every container whose restart policy is always or unless-stopped
#   /etc/apt/preferences.d/joinery-site-engine
#         Ubuntu's podman, podman-docker, netavark, aardvark-dns, crun and their
#         configuration packages are never installed; any already installed are
#         removed first
#   /etc/containers/policy.json, registries.conf    images from docker.io, unsigned, as Docker takes them
#   /etc/containers/joinery-site-seccomp.json       the filter every site runs under (run_spec_args)
#   /etc/containers/storage.conf                    overlay on the data root, where it is mounted
#   runc (Ubuntu main, patched by Canonical) and conmon, from Ubuntu's archive
#
# The previous engine's directory is kept, so a bad engine is undone by
# installing the previous archive again, which only moves `current` back. Older
# ones are removed. Running containers are not touched: each is held by its own
# conmon, and only the next podman command runs the new binaries.
#
# Refuses, changing nothing, when the archive's hash is not SHA256, its name is
# not an engine for this machine's architecture, or what it unpacks to is not
# the engine it names. After the switch, on a host whose container storage is
# configured, it checks podman itself: the version, runc as the runtime,
# seccomp on, and AppArmor on where the kernel has it; on any miss `current`
# goes back to what it was and it exits 1.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="${JOINERY_SITE_ENGINE_ROOT:-}"          # tests only: a fixture tree in place of /
STAMP="${JOINERY_SITE_ENGINE_STAMP:-$SCRIPT_DIR/site_engine/ENGINE}"
LIB="${ROOT}/usr/local/lib/joinery-site-engine"
BIN="${ROOT}/usr/local/bin/podman"
CONF="${ROOT}/etc/containers/containers.conf.d/50-joinery-site-engine.conf"
UNIT="${ROOT}/etc/systemd/system/podman-restart.service"
PREF="${ROOT}/etc/apt/preferences.d/joinery-site-engine"
STORAGE_CONF="${ROOT}/etc/containers/storage.conf"
POLICY="${ROOT}/etc/containers/policy.json"
REGISTRIES="${ROOT}/etc/containers/registries.conf"
SITE_SECCOMP="${ROOT}/etc/containers/joinery-site-seccomp.json"
SITE_SECCOMP_SRC="${SCRIPT_DIR}/site_engine/site_seccomp.json"
DATA_ROOT_MNT="${ROOT}/srv/joinery"
UBUNTU_ENGINE_PACKAGES="podman podman-docker netavark aardvark-dns crun golang-github-containers-common golang-github-containers-image containers-storage containernetworking-plugins"
UBUNTU_KEPT_PACKAGES="runc conmon"
ARCHIVE_RE='^(joinery-site-engine-([0-9]+\.[0-9]+\.[0-9]+)-([a-z0-9]+))\.tar\.xz$'
SLOT_RE='^[0-9]+\.[0-9]+\.[0-9]+-[0-9a-f]{12}$'

die() { echo "site engine: $*" >&2; exit 1; }
say() { echo "site engine: $*"; }

conf_text() {
    cat <<'EOF'
# Written by install_site_engine.sh (specs/site_container_engine.md D3).
# Edits are replaced at the next converge.
[engine]
runtime = "runc"
cgroup_manager = "systemd"
helper_binaries_dir = ["/usr/local/lib/joinery-site-engine/current/helpers"]
# Hooks this host adds (multi_tenant_host.sh's walls check) are read beside the
# engine's own; every container start runs them.
hooks_dir = ["/usr/share/containers/oci/hooks.d", "/etc/containers/oci/hooks.d"]

[network]
network_backend = "netavark"
EOF
}

pref_text() {
    cat <<EOF
# Written by install_site_engine.sh (specs/site_container_engine.md D3): this
# host runs our own build of the engine; Ubuntu's is never installed beside it.
Package: ${UBUNTU_ENGINE_PACKAGES}
Pin: release *
Pin-Priority: -1
EOF
}

write_if_changed() {  # PATH MODE < text
    local path="$1" mode="$2" tmp
    mkdir -p "$(dirname "$path")"
    tmp="$(mktemp "$(dirname "$path")/.joinery-site-engine.XXXXXX")"
    cat > "$tmp"
    chmod "$mode" "$tmp"
    if [[ -f "$path" ]] && cmp -s "$tmp" "$path"; then
        rm -f "$tmp"; return 1
    fi
    mv -f "$tmp" "$path"
    return 0
}

storage_text() {
    cat <<'EOF'
# Written by install_site_engine.sh (specs/site_container_engine.md D3): container
# storage on the data root, XFS with project quotas, like Docker's before it.
[storage]
driver = "overlay"
graphroot = "/srv/joinery/containers/storage"
runroot = "/run/containers/storage"
EOF
}

# What the engine needs beside its binaries, written whole every run.
host_config() {
    local seccomp_src_sha
    conf_text | write_if_changed "$CONF" 644 && say "wrote ${CONF#"$ROOT"}"
    pref_text | write_if_changed "$PREF" 644 && say "wrote ${PREF#"$ROOT"}"
    printf '{\n  "default": [{"type": "insecureAcceptAnything"}],\n  "transports": {"docker-daemon": {"": [{"type": "insecureAcceptAnything"}]}}\n}\n' \
        | write_if_changed "$POLICY" 644 && say "wrote ${POLICY#"$ROOT"}"
    printf '# Written by install_site_engine.sh: a short image name is looked for on docker.io alone.\nunqualified-search-registries = ["docker.io"]\nshort-name-mode = "enforcing"\n' \
        | write_if_changed "$REGISTRIES" 644 && say "wrote ${REGISTRIES#"$ROOT"}"
    [[ -f "$SITE_SECCOMP_SRC" ]] || die "no site system call filter beside this script (${SITE_SECCOMP_SRC}); no site may run without it"
    seccomp_src_sha="$(sed -n 's/^SHA256="\(.*\)"$/\1/p' "${SCRIPT_DIR}/site_engine/site_seccomp.source" 2>/dev/null)"
    [[ -n "$seccomp_src_sha" && "$(sha256sum "$SITE_SECCOMP_SRC" | awk '{print $1}')" == "$seccomp_src_sha" ]] \
        || die "${SITE_SECCOMP_SRC} is not the file site_seccomp.source records; nothing was changed"
    write_if_changed "$SITE_SECCOMP" 644 < "$SITE_SECCOMP_SRC" && say "wrote ${SITE_SECCOMP#"$ROOT"}"
    if [[ -n "$ROOT" && -d "$DATA_ROOT_MNT" ]] || mountpoint -q "$DATA_ROOT_MNT" 2>/dev/null; then
        mkdir -p "${DATA_ROOT_MNT}/containers/storage"
        storage_text | write_if_changed "$STORAGE_CONF" 644 && say "wrote ${STORAGE_CONF#"$ROOT"}"
    fi
    return 0
}

status() {
    [[ -f "$LIB/current/BUILDINFO" ]] || { echo "site engine: none installed"; exit 1; }
    cat "$LIB/current/BUILDINFO"
}

# The host's own packages: Ubuntu's engine out, the parts we keep in.
host_packages() {
    [[ -z "$ROOT" ]] || return 0
    local installed="" missing="" p
    for p in $UBUNTU_ENGINE_PACKAGES; do
        dpkg-query -W -f='${Status}\n' "$p" 2>/dev/null | grep -q '^install ok installed$' && installed="$installed $p"
    done
    if [[ -n "$installed" ]]; then
        say "removing Ubuntu's own engine packages:${installed}"
        DEBIAN_FRONTEND=noninteractive apt-get remove -y -qq $installed > /dev/null \
            || die "could not remove${installed}; nothing else was changed"
    fi
    for p in $UBUNTU_KEPT_PACKAGES; do
        dpkg-query -W -f='${Status}\n' "$p" 2>/dev/null | grep -q '^install ok installed$' || missing="$missing $p"
    done
    if [[ -n "$missing" ]]; then
        say "installing from Ubuntu's archive:${missing}"
        # A new machine has no package lists yet.
        apt-get update -qq || die "apt-get update failed; could not install${missing}"
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends $missing > /dev/null \
            || die "could not install${missing} from Ubuntu's archive"
    fi
}

# Podman itself, on a host whose storage is configured: what it reports, not
# what the files say.
check_engine() {  # VERSION
    local want="$1" got info runtime seccomp apparmor
    got="$("$BIN" --version 2>/dev/null | awk '{print $3}')"
    [[ "$got" == "$want" ]] || { echo "podman reports version '${got}', not ${want}"; return 1; }
    [[ -z "$ROOT" && -f "$STORAGE_CONF" ]] || return 0
    info="$("$BIN" info --format '{{.Host.OCIRuntime.Name}}|{{.Host.Security.SECCOMPEnabled}}|{{.Host.Security.AppArmorEnabled}}' 2>&1)" \
        || { echo "podman info failed: ${info}"; return 1; }
    IFS='|' read -r runtime seccomp apparmor <<< "$info"
    [[ "$runtime" == "runc" ]] || { echo "the runtime is '${runtime}', not runc"; return 1; }
    [[ "$seccomp" == "true" ]] || { echo "seccomp is off"; return 1; }
    if [[ "$(cat /sys/module/apparmor/parameters/enabled 2>/dev/null)" == "Y" && "$apparmor" != "true" ]]; then
        echo "the kernel has AppArmor and podman does not use it"; return 1
    fi
    return 0
}

# The current engine's podman at its one path, replaced in one rename (a running
# podman keeps the file it started from).
put_podman() {
    mkdir -p "$(dirname "$BIN")"
    [[ -L "$BIN" ]] && rm -f "$BIN"
    cmp -s "$LIB/current/bin/podman" "$BIN" 2>/dev/null && return 0
    install -m 755 "$LIB/current/bin/podman" "$BIN.joinery-new"
    mv -f "$BIN.joinery-new" "$BIN"
}

point_current() {  # SLOT
    ln -sfn "$1" "$LIB/.current.new" && mv -T "$LIB/.current.new" "$LIB/current"
}

install_engine() {  # ARCHIVE SHA256
    local archive="$1" want_sha="$2" base name version arch have_sha slot incoming top prev="" f
    [[ -z "$ROOT" && "$(id -u)" -ne 0 ]] && die "run as root"
    [[ -f "$archive" ]] || die "no archive at ${archive}"
    [[ "$want_sha" =~ ^[0-9a-f]{64}$ ]] || die "SHA256 must be 64 lowercase hex digits"
    base="$(basename "$archive")"
    [[ "$base" =~ $ARCHIVE_RE ]] || die "${base} is not named like an engine archive (joinery-site-engine-<version>-<arch>.tar.xz)"
    name="${BASH_REMATCH[1]}"; version="${BASH_REMATCH[2]}"; arch="${BASH_REMATCH[3]}"
    [[ -n "$ROOT" || "$arch" == "$(dpkg --print-architecture)" ]] || die "${base} is for ${arch}, this machine is $(dpkg --print-architecture)"
    have_sha="$(sha256sum "$archive" | awk '{print $1}')"
    [[ "$have_sha" == "$want_sha" ]] || die "${base} has SHA-256 ${have_sha}, not the release's ${want_sha}; nothing was changed"
    slot="${version}-${have_sha:0:12}"

    mkdir -p "$LIB"
    if [[ ! -d "$LIB/$slot" ]]; then
        incoming="$(mktemp -d "$LIB/.incoming.XXXXXX")"
        trap "rm -rf $(printf '%q' "$incoming")" EXIT
        tar -xJf "$archive" -C "$incoming" --no-same-owner
        top="$(ls -A "$incoming")"
        [[ "$top" == "$name" ]] || die "${base} unpacks to '${top}', not ${name}"
        for f in bin/podman helpers/netavark helpers/aardvark-dns systemd/podman-restart.service BUILDINFO; do
            [[ -f "$incoming/$name/$f" ]] || die "${base} has no ${f}"
        done
        grep -qx "engine=${name}" "$incoming/$name/BUILDINFO" || die "${base}'s BUILDINFO names another engine"
        echo "$have_sha" > "$incoming/$name/ARCHIVE_SHA256"
        chmod -R go-w "$incoming/$name"
        mv -T "$incoming/$name" "$LIB/$slot"
        rm -rf "$incoming"; trap - EXIT
        say "unpacked ${name} (${slot})"
    fi

    host_packages

    [[ -L "$LIB/current" ]] && prev="$(readlink "$LIB/current")"
    if [[ "$prev" != "$slot" ]]; then
        point_current "$slot"
        say "current engine: ${slot}${prev:+ (was ${prev})}"
    fi
    put_podman

    host_config
    if write_if_changed "$UNIT" 644 < "$LIB/current/systemd/podman-restart.service"; then
        say "wrote ${UNIT#"$ROOT"}"
        [[ -n "$ROOT" ]] || systemctl daemon-reload
    fi
    [[ -n "$ROOT" ]] || systemctl is-enabled -q podman-restart.service 2>/dev/null || systemctl enable -q podman-restart.service

    local why
    if ! why="$(check_engine "$version")"; then
        if [[ -n "$prev" && "$prev" != "$slot" && -d "$LIB/$prev" ]]; then
            point_current "$prev"
            put_podman
            die "${slot} failed its check (${why}); current is back on ${prev}"
        fi
        die "${slot} failed its check (${why})"
    fi

    # Keep the current and the previous engine; remove the rest.
    local d
    for d in "$LIB"/*/; do
        d="$(basename "$d")"
        [[ "$d" == "$slot" || "$d" == "$prev" || "$d" == "current" ]] && continue
        [[ "$d" =~ $SLOT_RE ]] || continue
        rm -rf "${LIB:?}/$d" && say "removed engine ${d}"
    done
    say "engine ${slot} in place"
}

converge() {  # [UPGRADE_SERVER]
    local server="${1:-${UPGRADE_SERVER:-https://getjoinery.com}}" archive sha slot dl
    if [[ ! -f "$STAMP" ]]; then
        say "this tree names no engine (no ${STAMP}); nothing to do"
        return 0
    fi
    archive="$(sed -n 's/^archive=//p' "$STAMP")"
    sha="$(sed -n 's/^sha256=//p' "$STAMP")"
    [[ "$archive" =~ $ARCHIVE_RE ]] || die "${STAMP} names no engine archive ('${archive}')"
    slot="${BASH_REMATCH[2]}"
    [[ "$sha" =~ ^[0-9a-f]{64}$ ]] || die "${STAMP} has no SHA-256"
    slot="${slot}-${sha:0:12}"
    if [[ "$(readlink "$LIB/current" 2>/dev/null)" == "$slot" && -x "$BIN" ]] && cmp -s "$LIB/current/bin/podman" "$BIN"; then
        # Current, and still checked: an earlier run that stopped after the
        # switch (a package that would not install) left it named current.
        host_packages
        host_config
        local why
        why="$(check_engine "${slot%-*}")" || die "${slot} is current but fails its check (${why})"
        say "engine ${slot} is current"
        return 0
    fi
    [[ -z "$ROOT" && "$(id -u)" -ne 0 ]] && die "run as root"
    mkdir -p "${ROOT}/var/tmp"
    dl="$(mktemp -d "${ROOT}/var/tmp/site-engine-fetch.XXXXXX")"
    # The path itself, not the variable: an exit errexit makes runs this after
    # the function's locals are gone.
    trap "rm -rf $(printf '%q' "$dl")" EXIT
    say "fetching ${archive} from ${server}"
    curl -sfL --max-time 900 -o "$dl/$archive" "${server%/}/static_files/site_engine/${archive}" \
        || die "could not fetch ${server%/}/static_files/site_engine/${archive}"
    # In a subshell, so a refusal (install_engine exits) comes back here and the
    # EXIT trap removes the download. A plain statement, never a condition: bash
    # turns errexit off for everything run inside an if or a || list, so any
    # failure in the install would go unnoticed.
    ( install_engine "$dl/$archive" "$sha" )
    rm -rf "$dl"; trap - EXIT
}

case "${1:-}" in
    converge) [[ $# -le 2 ]] || die "usage: install_site_engine.sh converge [UPGRADE_SERVER]"; converge "${2:-}" ;;
    install)  [[ $# -eq 3 ]] || die "usage: install_site_engine.sh install ARCHIVE SHA256"; install_engine "$2" "$3" ;;
    status)   status ;;
    *)        die "usage: install_site_engine.sh converge [UPGRADE_SERVER] | install ARCHIVE SHA256 | status" ;;
esac
