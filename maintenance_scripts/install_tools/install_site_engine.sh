#!/usr/bin/env bash
#
# install_site_engine.sh - install or converge the site container engine on a
# container host (specs/site_container_engine.md D2, D3).
#
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
#         Ubuntu's podman, podman-docker, netavark, aardvark-dns and crun are never
#         installed; any already installed are removed first
#   runc (Ubuntu main, patched by Canonical), conmon and the containers-common
#   configuration files, from Ubuntu's archive
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
UBUNTU_ENGINE_PACKAGES="podman podman-docker netavark aardvark-dns crun"
UBUNTU_KEPT_PACKAGES="runc conmon golang-github-containers-common"
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
        DEBIAN_FRONTEND=noninteractive apt-get remove -y -qq $installed > /dev/null
    fi
    for p in $UBUNTU_KEPT_PACKAGES; do
        dpkg-query -W -f='${Status}\n' "$p" 2>/dev/null | grep -q '^install ok installed$' || missing="$missing $p"
    done
    if [[ -n "$missing" ]]; then
        say "installing from Ubuntu's archive:${missing}"
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends $missing > /dev/null
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
        trap 'rm -rf "$incoming"' EXIT
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

    conf_text | write_if_changed "$CONF" 644 && say "wrote ${CONF#"$ROOT"}"
    pref_text | write_if_changed "$PREF" 644 && say "wrote ${PREF#"$ROOT"}"
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
        say "engine ${slot} is current"
        return 0
    fi
    [[ -z "$ROOT" && "$(id -u)" -ne 0 ]] && die "run as root"
    mkdir -p "${ROOT}/var/tmp"
    dl="$(mktemp -d "${ROOT}/var/tmp/site-engine-fetch.XXXXXX")"
    trap 'rm -rf "$dl"' EXIT
    say "fetching ${archive} from ${server}"
    curl -sfL --max-time 900 -o "$dl/$archive" "${server%/}/static_files/site_engine/${archive}" \
        || die "could not fetch ${server%/}/static_files/site_engine/${archive}"
    # In a subshell, so a refusal (install_engine exits) still removes the download.
    if ! ( install_engine "$dl/$archive" "$sha" ); then
        exit 1
    fi
    rm -rf "$dl"; trap - EXIT
}

case "${1:-}" in
    converge) [[ $# -le 2 ]] || die "usage: install_site_engine.sh converge [UPGRADE_SERVER]"; converge "${2:-}" ;;
    install)  [[ $# -eq 3 ]] || die "usage: install_site_engine.sh install ARCHIVE SHA256"; install_engine "$2" "$3" ;;
    status)   status ;;
    *)        die "usage: install_site_engine.sh converge [UPGRADE_SERVER] | install ARCHIVE SHA256 | status" ;;
esac
