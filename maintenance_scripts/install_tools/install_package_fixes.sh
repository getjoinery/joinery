#!/usr/bin/env bash
#
# install_package_fixes.sh - this machine takes the package fixes its release
# names, and gives each back to Ubuntu once Ubuntu's own build carries the same
# fixes (specs/package_fixes_ahead_of_ubuntu.md WP3).
#
# Version: 1.3 - an archive is fetched first from the site's own upgrade server (its upgrade_source
#                setting): a box installed from dev with no agent asked only getjoinery.com (B19)
# Version: 1.2 - a fix is handed back only when Ubuntu's newer source carries every fingerprint of every
#                patch, not only when its changelog names every CVE (owner Q6, reviewer2 H1): a
#                changelog is Ubuntu's claim, the source is what it ships
# Version: 1.1 - reviewer2's build review: the fixes' origin is allowed for automatic updates by a
#                drop-in of this script's own (52joinery-package-fixes), which a container's base
#                carries too (G1); apt busy is not apt refusing (G2); a machine on a newer Ubuntu build
#                is judged by that build's changelog, handed back or holding a build that lacks ours
#                (G5); a container host's base-only fix says so (G10); a changelog that could not be
#                read is reported (G13); only -security builds hand back (G14)
# Version: 1.0
#
# A package fix is our build of one Ubuntu source package with upstream's
# security patches added, for a fix Ubuntu has not shipped yet. fixes/ACTIVE,
# beside this script and covered by the release's signed manifest, names each
# one: its archive, the archive's SHA-256, the version, the CVEs it carries,
# the Ubuntu build it was made from, and the binary whose changelog says what
# a build fixes.
#
#   install_package_fixes.sh converge [SERVER]
#       For each ACTIVE line that applies here (this machine has a binary of
#       that source installed, or is a container host and the source is one
#       the site containers' base installs):
#         1. the archive is fetched (this tree's own static_files, then
#            SERVER, or the management node this machine's agent joined, then
#            UPGRADE_SERVER or getjoinery.com) and kept only when its hash is
#            ACTIVE's;
#         2. unpacked into /var/lib/joinery/package-fixes/<source>/<version>/,
#            root's to write;
#         3. listed as a package source, one list per version, so a new build
#            never reuses a path (apt refuses a repository whose Label changed
#            at the same path);
#         4. read by apt-get update against that list alone; a list apt
#            refuses is removed and reported (source_refused), so one bad
#            repository never stops apt for the whole machine;
#         5. pinned at priority 1001, naming every binary in the archive,
#            unless the hand-back below applies or the machine is already on
#            a newer Ubuntu build of that source (ahead_of_build: at 1001 apt
#            would downgrade it);
#         6. handed back where Ubuntu has caught up (below).
#       A source no longer in ACTIVE has its list, pin and directory removed.
#       Installing is not done here: the hourly security check installs
#       anything from the Joinery-Fixes origin at once.
#   install_package_fixes.sh handback
#       For each fix in place: when Ubuntu offers the changelog_from binary at
#       a version newer than the fix's Ubuntu base, that .deb is downloaded
#       and its changelog read, the entries newer than the base. Every CVE of
#       the fix named there (by id, or by number in a combined form such as
#       CVE-2026-109618_109619), and every fingerprint of every patch (FIXINFO,
#       written by fixes/build.sh) found in Ubuntu's source for that version
#       (fetched by apt from the -security pocket, its signature checked: the
#       packaging, and upstream's tarball when the upstream version moved):
#       the pin is removed and Ubuntu's build installs (handed_back). Any CVE
#       missing from either: the pin stays (ubuntu_lacks). A changelog or a
#       source that cannot be read: the pin stays (unreadable). The verdict is
#       kept per source and Ubuntu version, so each is read once.
#   install_package_fixes.sh status
#       Print the state host_report.sh reads.
#   install_package_fixes.sh pinned [--base]
#       Print the fixes pinned now, one "<source> <version>" per line. With
#       --base, only the sources the site containers' base installs: what a
#       container host builds the base with (WP4), never Postfix.
#
# While any fix is listed, /etc/apt/apt.conf.d/52joinery-package-fixes allows
# its origin for automatic updates: without it unattended-upgrades sets the
# 1001 pin aside and takes Ubuntu's newer build, even one lacking our fix.
#
# State: /var/lib/joinery/package-fixes/state, one line per fix:
#   fix=<source>|<version>|<installed 0/1>|<handed_back version>|<ubuntu_lacks version:CVE,CVE>|<ahead_of_build version>|<source_refused 0/1>|<for_base 0/1>|<unreadable version>
# with "-" where a field does not apply. for_base: a container host taking a
# fix only for its site containers' base (it runs none of the source itself).
# unreadable: Ubuntu's build whose changelog could not be read; ours stays.
#
# A test, never root, runs it under PACKAGE_FIXES_ROOT, which stands in for /
# in every path it keeps, with stand-ins for apt-get, apt-cache and dpkg-query
# on PATH.

set -euo pipefail
export LC_ALL=C

CMD="${1:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TROOT=""
if [[ "$(id -u)" -ne 0 && -n "${PACKAGE_FIXES_ROOT:-}" ]]; then
    TROOT="${PACKAGE_FIXES_ROOT%/}"
fi
STAMP="${PACKAGE_FIXES_ACTIVE:-$HERE/fixes/ACTIVE}"
[[ -z "$TROOT" ]] && STAMP="$HERE/fixes/ACTIVE"
LIB="${TROOT}/var/lib/joinery/package-fixes"
STATE="${LIB}/state"
VERDICTS="${LIB}/verdicts"
LISTS="${TROOT}/etc/apt/sources.list.d"
PREFS="${TROOT}/etc/apt/preferences.d"
ORIGIN_CONF="${TROOT}/etc/apt/apt.conf.d/52joinery-package-fixes"
LOCAL_STATIC="$(cd "$HERE/../.." 2>/dev/null && pwd)/static_files/fixes"
# The source packages install.sh server installs in the site containers' base
# (Dockerfile.base): a container host takes a fix for one of these though it
# runs none of them itself. Never Postfix: the base holds none.
BASE_SOURCES="php8.5 postgresql-18 apache2 openssl"
# What a deb-src line for Ubuntu's archive is checked against.
UBUNTU_KEYRING=/usr/share/keyrings/ubuntu-archive-keyring.gpg

say() { echo "package fixes: $*"; }
die() { echo "package fixes: $*" >&2; exit 1; }

case "$CMD" in converge|handback|status|pinned) ;; *)
    echo "usage: install_package_fixes.sh converge [SERVER] | handback | status | pinned [--base]" >&2; exit 2 ;;
esac

if [[ "$CMD" == status ]]; then
    [[ -f "$STATE" ]] && cat "$STATE" || echo "no package fix has been looked at on this machine"
    exit 0
fi
# What is pinned now, read from the pins themselves, changing nothing. A pin
# counts only while its fix's list and directory are in place.
if [[ "$CMD" == pinned ]]; then
    for p in "${TROOT}/etc/apt/preferences.d"/joinery-fix-*.pref; do
        [[ -f "$p" ]] || continue
        s="$(sed -n 's/^Explanation: joinery-fix source=//p' "$p")"
        v="$(sed -n 's/^Pin: version //p' "$p")"
        [[ -n "$s" && -n "$v" ]] || continue
        sl="$(printf '%s' "$s" | sed 's/[^A-Za-z0-9._-]/-/g')"
        vl="$(printf '%s' "$v" | sed 's/^[0-9]*://; s/[^A-Za-z0-9._-]/-/g')"
        [[ -f "${TROOT}/etc/apt/sources.list.d/joinery-fix-${sl}-${vl}.list" && -f "${TROOT}/var/lib/joinery/package-fixes/${s}/${vl}/Packages" ]] || continue
        [[ "${2:-}" != --base || " ${BASE_SOURCES} " == *" ${s} "* ]] || continue
        echo "$s $v"
    done | sort
    exit 0
fi
[[ "$(id -u)" -eq 0 || -n "$TROOT" ]] || die "run as root"

# A name used in a file name under sources.list.d or preferences.d: apt
# ignores a file there whose name holds anything but letters, digits, _, - and .
slug() { printf '%s' "$1" | sed 's/^[0-9]*://; s/[^A-Za-z0-9._-]/-/g'; }
list_path() { echo "${LISTS}/joinery-fix-$(slug "$1")-$(slug "$2").list"; }   # SOURCE VERSION
pin_path()  { echo "${PREFS}/joinery-fix-$(slug "$1").pref"; }                  # SOURCE
fix_dir()   { echo "${LIB}/$1/$(slug "$2")"; }                                  # SOURCE VERSION

# ACTIVE as "<source> <version> <ubuntu_base> <archive> <sha256> <changelog_from> <cves,comma>" lines.
active_lines() {
    [[ -f "$STAMP" ]] || return 0
    local line source version base archive sha from cves
    while IFS= read -r line; do
        [[ -z "$line" || "$line" == \#* ]] && continue
        source="$(sed -n 's/.*\bsource=\([^ ]*\).*/\1/p' <<< "$line")"
        version="$(sed -n 's/.*\bversion=\([^ ]*\).*/\1/p' <<< "$line")"
        base="$(sed -n 's/.*\bubuntu_base=\([^ ]*\).*/\1/p' <<< "$line")"
        archive="$(sed -n 's/.*\barchive=\([^ ]*\).*/\1/p' <<< "$line")"
        sha="$(sed -n 's/.*\bsha256=\([0-9a-f]\{64\}\)\b.*/\1/p' <<< "$line")"
        from="$(sed -n 's/.*\bchangelog_from=\([^ ]*\).*/\1/p' <<< "$line")"
        cves="$(sed -n 's/.*\bcves=\(CVE-.*\)$/\1/p' <<< "$line" | tr ' ' ',')"
        if [[ ! "$source" =~ ^[a-z0-9][a-z0-9.+-]+$ || ! "$version" =~ ^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*\+joinery[0-9]+$ \
                || "$archive" != "joinery-fix-${source}-${version#*:}-"*.tar.xz || ! "${archive%.tar.xz}" =~ -[a-z0-9]+$ \
                || "$archive" == */* || -z "$sha" || -z "$base" || -z "$from" || -z "$cves" ]]; then
            die "${STAMP} has a line that is not whole: ${line}"
        fi
        printf '%s %s %s %s %s %s %s\n' "$source" "$version" "$base" "$archive" "$sha" "$from" "$cves"
    done < "$STAMP"
}

# The sources of what dpkg has installed here, one per line.
installed_sources() {
    dpkg-query -W -f '${db:Status-Abbrev} ${source:Package}\n' 2>/dev/null | awk '$1 ~ /^.i/ {print $2}' | sort -u
}
# "<binary> <version>" for each installed binary of SOURCE.
installed_binaries() {  # SOURCE
    dpkg-query -W -f '${db:Status-Abbrev} ${source:Package} ${Package} ${Version}\n' 2>/dev/null \
        | awk -v s="$1" '$1 ~ /^.i/ && $2 == s {print $3, $4}'
}
is_container_host() {
    [[ -n "$TROOT" ]] && { [[ -f "${TROOT}/container_host" ]]; return; }
    [[ -L /usr/local/lib/joinery-site-engine/current || -e /usr/local/lib/joinery-site-engine/current ]] || command -v docker > /dev/null 2>&1
}

# The upgrade server of the site this copy belongs to (its upgrade_source
# setting): the release, ACTIVE with it, came from there, so its archives are
# there. Empty on a machine with no site (a container host's bundle copy) or
# when the database cannot be read.
site_upgrade_source() {
    local site config name user pass value
    # The gate's fixture root names it; it never reads a real site's database.
    if [[ -n "$TROOT" ]]; then
        printf '%s' "${PACKAGE_FIXES_UPGRADE_SOURCE:-}"; return 0
    fi
    site="$(cd "$HERE/../.." 2>/dev/null && pwd)" || return 0
    config="$site/config/Globalvars_site.php"
    [[ -f "$config" && -f "$HERE/_config_value.php" ]] && command -v php > /dev/null && command -v psql > /dev/null || return 0
    name="$(php "$HERE/_config_value.php" "$config" dbname 2>/dev/null)"
    user="$(php "$HERE/_config_value.php" "$config" dbusername 2>/dev/null)"
    pass="$(php "$HERE/_config_value.php" "$config" dbpassword 2>/dev/null)"
    [[ -n "$name" && -n "$user" ]] || return 0
    value="$(PGPASSWORD="$pass" PGCONNECT_TIMEOUT=10 psql -h localhost -U "$user" -d "$name" -tAX \
        -c "SELECT stg_value FROM stg_settings WHERE stg_name = 'upgrade_source'" 2>/dev/null | tr -d '[:space:]')"
    [[ "$value" =~ ^https://[^/[:space:]]+/?$ ]] && printf '%s' "${value%/}"
    return 0
}

# Where an archive is fetched from, in order: SERVER alone when named;
# otherwise the site's upgrade server, the management node this machine's
# agent joined, then UPGRADE_SERVER or getjoinery.com, each once.
fix_servers() {  # [SERVER]
    if [[ -n "${1:-}" ]]; then printf '%s\n' "$1"; return 0; fi
    local plane upgrade fallback="${UPGRADE_SERVER:-https://getjoinery.com}"
    upgrade="$(site_upgrade_source)"
    plane="$(sed -n 's|^[[:space:]]*"plane_url":[[:space:]]*"\(https://[^"/[:space:]]*\)/\{0,1\}"[[:space:]]*,\{0,1\}[[:space:]]*$|\1|p' \
        "${TROOT}/etc/joinery-agent/node_identity.json" 2>/dev/null | head -1)"
    printf '%s\n' "$upgrade" "$plane" "${fallback%/}" | awk 'NF && !seen[$0]++'
}

# Fetch, check and unpack one archive into its directory. Echoes nothing;
# exits non-zero with a reason on stderr.
place_fix() {  # SOURCE VERSION ARCHIVE SHA [SERVER]
    local source="$1" version="$2" archive="$3" sha="$4" dir dl got="" server
    dir="$(fix_dir "$source" "$version")"
    if [[ -f "$dir/.archive_sha256" && "$(cat "$dir/.archive_sha256")" == "$sha" && -f "$dir/Release" ]]; then
        return 0
    fi
    mkdir -p "${TROOT}/var/tmp"
    dl="$(mktemp -d "${TROOT}/var/tmp/package-fix-fetch.XXXXXX")"
    if [[ -f "$LOCAL_STATIC/$archive" ]] && [[ "$(sha256sum "$LOCAL_STATIC/$archive" | awk '{print $1}')" == "$sha" ]]; then
        cp "$LOCAL_STATIC/$archive" "$dl/$archive"
        got=local
    else
        while IFS= read -r server; do
            if curl -sfL --max-time 900 -o "$dl/$archive" "${server%/}/static_files/fixes/${archive}" \
                    && [[ "$(sha256sum "$dl/$archive" | awk '{print $1}')" == "$sha" ]]; then
                got="$server"
                break
            fi
            rm -f "$dl/$archive"
        done < <(fix_servers "${5:-}")
    fi
    if [[ -z "$got" ]]; then
        rm -rf "$dl"
        echo "could not fetch ${archive} with ACTIVE's hash from $(fix_servers "${5:-}" | paste -sd ' ' | sed 's/ / or /g')" >&2
        return 1
    fi
    mkdir "$dl/x"
    tar -C "$dl/x" -xJf "$dl/$archive" --no-same-owner
    local top="$dl/x/${archive%.tar.xz}"
    if [[ ! -f "$top/Release" || ! -f "$top/Packages" || ! -f "$top/FIXINFO" ]] \
            || [[ "$(sed -n 's/^source=//p' "$top/FIXINFO")" != "$source" || "$(sed -n 's/^version=//p' "$top/FIXINFO")" != "$version" ]]; then
        rm -rf "$dl"
        echo "${archive} is not the fix ACTIVE names (no Release, Packages or FIXINFO for ${source} ${version})" >&2
        return 1
    fi
    echo "$sha" > "$top/.archive_sha256"
    # Root's to write, everyone's to read: apt reads a file: source as _apt.
    chmod -R u=rwX,go=rX "$top"
    mkdir -p "$(dirname "$dir")"
    rm -rf "${dir:?}.new"
    mv "$top" "$dir.new"
    rm -rf "$dir"
    mv "$dir.new" "$dir"
    rm -rf "$dl"
    say "placed ${source} ${version} (from ${got})"
}

write_if_changed() {  # PATH (content on stdin)
    local tmp
    tmp="$(mktemp "$1.XXXXXX")"
    cat > "$tmp"
    if [[ -f "$1" ]] && cmp -s "$tmp" "$1"; then rm -f "$tmp"; return 1; fi
    chmod 644 "$tmp"
    mv "$tmp" "$1"
}

# apt-get update against one list alone, keeping every other list's indexes.
# 0: read; 1: apt refused this repository (an error naming its path);
# 2: apt could not run now (another package run holds the lists, the
# network): nothing is removed, and the next run tries again.
update_one() {  # LIST DIR
    local rc=0
    apt-get update -qq -o DPkg::Lock::Timeout=300 -o Dir::Etc::sourcelist="$1" -o Dir::Etc::sourceparts=- \
        -o APT::Get::List-Cleanup=0 > "${LIB}/.update.log" 2>&1 || rc=$?
    if [[ "$rc" -eq 0 ]] && ! grep -qE '^E: ' "${LIB}/.update.log"; then
        return 0
    fi
    grep -E '^(E|W): ' "${LIB}/.update.log" | grep -qF "${2#"$TROOT"}" && return 1
    return 2
}

write_pin() {  # SOURCE VERSION
    local dir binaries
    dir="$(fix_dir "$1" "$2")"
    binaries="$(sed -n 's/^Package: //p' "$dir/Packages" | sort -u | paste -sd ' ')"
    [[ -n "$binaries" ]] || return 1
    mkdir -p "$PREFS"
    if printf '%s\n' "# Written by install_package_fixes.sh (Joinery): our build of ${1}, ahead of Ubuntu." \
            "# Removed when Ubuntu's own build carries the same fixes. Do not edit." \
            "Explanation: joinery-fix source=${1}" "Package: ${binaries}" "Pin: version ${2}" "Pin-Priority: 1001" \
            | write_if_changed "$(pin_path "$1")"; then
        say "pinned ${1} ${2} (${binaries})"
    fi
}

# Whether the CVE is named in changelog text on stdin, by id or by number in a
# combined form (CVE-2026-109618_109619 names CVE-2026-109619).
names_cve() {  # CVE
    local year="${1#CVE-}" num
    num="${year#*-}"; year="${year%%-*}"
    grep -qE "CVE-${year}-([0-9]+_)*${num}(_[0-9]+)*([^0-9]|\$)"
}

# The newest version of BINARY in a -security pocket: what automatic updates
# would install once our pin is gone. A newer build in -updates alone is never
# installed by them, so it hands nothing back.
ubuntu_newest() {  # BINARY
    apt-cache madison "$1" 2>/dev/null | awk -F'|' '{gsub(/ /, "", $2); print $2 "|" $3}' \
        | { grep -v '^[^|]*+joinery[0-9]' || true; } | { grep -v '| *file:' || true; } \
        | { grep -E '[a-z]+-security(/| )' || true; } | cut -d'|' -f1 \
        | { best=""; while IFS= read -r v; do
                [[ -z "$v" ]] && continue
                if [[ -z "$best" ]] || dpkg --compare-versions "$v" gt "$best"; then best="$v"; fi
            done; echo "$best"; }
}

# The same normal form and units as fixes/build.sh picks a patch's fingerprint
# with (the gate checks the two definitions match).
normal_form() { sed -E '/^-/d; s/^[+ ]//; s/[[:space:]]+/ /g; s/^ //; s/ $//'; }
units() { awk '{ print; if (NR > 2) print a "\036" b "\036" $0; a = b; b = $0 }'; }

upstream_of() { local v="${1#*:}"; [[ "$v" == *-* ]] && v="${v%-*}"; echo "$v"; }  # VERSION

# Every unit of Ubuntu's VERSION of SOURCE, as apt fetches it from the
# -security pocket BINARY comes from, Ubuntu's signature checked, into FILE:
# its packaging (the patches it adds), and upstream's tarball when the upstream
# version is not BASE's. Uses private apt lists; the machine's own are untouched.
ubuntu_source_units() {  # SOURCE BINARY BASE UBUNTU_VERSION FILE
    local source="$1" bin="$2" base="$3" uver="$4" out="$5" repo uri suite comp dl f
    repo="$(apt-cache madison "$bin" 2>/dev/null | awk -F'|' -v v="$uver" '{gsub(/ /, "", $2)} $2 == v {print $3}' \
        | { grep -E -- '-security/' || true; } | head -n 1)"
    read -r uri suite _ <<< "$repo" || true
    [[ -n "${uri:-}" && "${suite:-}" == */* ]] || return 1
    comp="${suite#*/}"; suite="${suite%%/*}"
    dl="$(mktemp -d "${TROOT}/var/tmp/package-fix-source.XXXXXX")"
    mkdir -p "$dl/lists/partial" "$dl/archives/partial" "$dl/get"
    # apt fetches as _apt.
    chmod 755 "$dl" "$dl/get"
    echo "deb-src [signed-by=${UBUNTU_KEYRING}] ${uri} ${suite} ${comp}" > "$dl/sources.list"
    local o=(-o Dir::Etc::sourcelist="$dl/sources.list" -o Dir::Etc::sourceparts=- -o Dir::State::Lists="$dl/lists"
        -o Dir::Cache::Archives="$dl/archives" -o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache=)
    if ! apt-get update -qq "${o[@]}" > "$dl/log" 2>&1 || grep -q '^E: ' "$dl/log" \
            || ! (cd "$dl/get" && apt-get source -qq --download-only "${o[@]}" "${source}=${uver}") >> "$dl/log" 2>&1 \
            || ! ls "$dl/get"/*.dsc > /dev/null 2>&1; then
        rm -rf "$dl"
        return 1
    fi
    for f in "$dl/get"/*; do
        case "$f" in
            *.dsc|*.asc) ;;
            *.debian.tar.*) tar -xOf "$f" ;;
            *.diff.gz) gzip -dc "$f" ;;
            *.tar.*) if [[ "$(upstream_of "$uver")" != "$(upstream_of "$base")" ]]; then tar -xOf "$f"; fi ;;
        esac
    done 2> /dev/null | normal_form | units > "$out" || true
    rm -rf "$dl"
    [[ -s "$out" ]]
}

# The fix's CVEs whose patch's fingerprint is not all in UNITS, comma-separated
# in the fix's order: a patch's CVEs are those its file name holds, or every
# CVE of the fix when it holds none of them. A build with no fingerprint
# proves nothing, so lacks every CVE.
missing_fix_text() {  # FIX_DIR UNITS CVES(comma)
    local dir="$1" units="$2" cves="$3" pb b64 text n=0 miss="" c out=""
    while read -r pb b64; do
        n=$((n + 1))
        text="$(printf '%s' "$b64" | base64 -d 2>/dev/null || true)"
        if [[ -z "$text" ]] || ! grep -qxF -- "$text" "$units"; then
            miss="${miss} $(grep -oE 'CVE-[0-9]{4}-[0-9]+' <<< "$pb" | paste -sd ' ' || true)"
            [[ "$pb" =~ CVE-[0-9]{4}-[0-9]+ && ",${cves}," == *",${BASH_REMATCH[0]},"* ]] || miss="${miss} ${cves//,/ }"
        fi
    done < <(sed -n 's/^fingerprint=//p' "$dir/FIXINFO" 2>/dev/null)
    [[ "$n" -gt 0 ]] || { echo "$cves"; return 0; }
    for c in ${cves//,/ }; do
        [[ " ${miss} " == *" ${c} "* ]] && out="${out:+$out,}$c"
    done
    echo "$out"
}

# The verdict on Ubuntu's VERSION of a fix's source: "covers", or "lacks CVE,CVE".
verdict() {  # SOURCE BINARY BASE UBUNTU_VERSION CVES(comma) FIX_DIR
    local source="$1" bin="$2" base="$3" uver="$4" cves="$5" fdir="$6" cache dl deb text missing="" cve key
    cache="${VERDICTS}/$(slug "$source")_$(slug "$uver")"
    # Kept while the fix's CVEs and fingerprints are the ones it was reached for.
    key="${cves} $(sed -n 's/^fingerprint=//p' "$fdir/FIXINFO" 2>/dev/null | sha256sum | cut -c1-16)"
    if [[ -f "$cache" ]] && [[ "$(sed -n 's/^key=//p' "$cache")" == "$key" ]]; then
        sed -n 's/^verdict=//p' "$cache"
        return 0
    fi
    mkdir -p "${TROOT}/var/tmp" "$VERDICTS"
    dl="$(mktemp -d "${TROOT}/var/tmp/package-fix-handback.XXXXXX")"
    chmod 755 "$dl"
    # The version is named: with the pin in place the candidate is ours.
    if ! (cd "$dl" && apt-get download -qq "${bin}=${uver}" > /dev/null 2>&1); then
        rm -rf "$dl"
        return 1
    fi
    deb="$(find "$dl" -maxdepth 1 -name '*.deb' | head -n 1)"
    text="$(dpkg-deb --fsys-tarfile "$deb" 2>/dev/null | tar -xOf - "./usr/share/doc/${bin}/changelog.Debian.gz" 2>/dev/null | gzip -dc 2>/dev/null \
        | awk -v stop="(${base})" 'index($0, stop) && $0 !~ /^ / {exit} {print}' || true)"
    rm -rf "$dl"
    [[ -n "$text" ]] || return 1
    for cve in ${cves//,/ }; do
        names_cve "$cve" <<< "$text" || missing="${missing:+$missing,}$cve"
    done
    # Every CVE named: Ubuntu's source must also carry each patch's code.
    if [[ -z "$missing" ]]; then
        if ! ubuntu_source_units "$source" "$bin" "$base" "$uver" "$dl.units"; then
            rm -f "$dl.units"
            return 1
        fi
        missing="$(missing_fix_text "$fdir" "$dl.units" "$cves")"
        rm -f "$dl.units"
        [[ -z "$missing" ]] || echo "package fixes: ${source}: Ubuntu ${uver}'s changelog names every CVE ours carries, but its source lacks the fix for ${missing}" >&2
    fi
    local v="covers"
    [[ -n "$missing" ]] && v="lacks ${missing}"
    printf 'key=%s\nverdict=%s\n' "$key" "$v" > "$cache"
    echo "$v"
}

# --- converge / handback --------------------------------------------------------
mkdir -p "$LIB"
exec 8> "${LIB}/.lock"
flock -w 600 8 || die "another run holds ${LIB}/.lock"

declare -A ROW=()
ACTIVE_SOURCES=()
INSTALLED_SOURCES="$(installed_sources)"
CONTAINER_HOST=0
is_container_host && CONTAINER_HOST=1

# Read whole before anything changes: a line that is not whole stops the run
# here, never after fixes it did not name were removed.
ACTIVE_TEXT="$(active_lines)" || exit 1

applies() {  # SOURCE
    grep -qx "$1" <<< "$INSTALLED_SOURCES" && return 0
    [[ "$CONTAINER_HOST" == 1 && " ${BASE_SOURCES} " == *" $1 "* ]]
}

row() {  # SOURCE VERSION INSTALLED HANDED LACKS AHEAD REFUSED FOR_BASE UNREAD
    ROW[$1]="fix=$1|$2|$3|$4|$5|$6|$7|$8|$9"
}

while read -r source version base archive sha from cves; do
    [[ -n "${source:-}" ]] || continue
    applies "$source" || continue
    ACTIVE_SOURCES+=("$source")
    installed=0; lacks="-"; unread="-"; for_base=0
    grep -qx "$source" <<< "$INSTALLED_SOURCES" || for_base=1
    dir="$(fix_dir "$source" "$version")"
    list="$(list_path "$source" "$version")"

    if [[ "$CMD" == converge ]]; then
        if ! out="$(place_fix "$source" "$version" "$archive" "$sha" "${2:-}" 2>&1)"; then
            say "WARNING - ${source}: ${out}"
            row "$source" "$version" 0 - - - 1 "$for_base" -
            continue
        fi
        [[ -n "$out" ]] && echo "$out"
        # Every other version of this source: its list and directory go.
        for old in "${LISTS}/joinery-fix-$(slug "$source")-"*.list; do
            [[ -e "$old" && "$old" != "$list" ]] && rm -f "$old" && say "removed $(basename "$old")"
        done
        for old in "${LIB}/${source}"/*/; do
            [[ -d "$old" && "${old%/}" != "$dir" ]] && rm -rf "$old"
        done
        mkdir -p "$LISTS"
        if printf '%s\n' "# Written by install_package_fixes.sh (Joinery): our build of ${source} ${version}. Do not edit." \
                "deb [trusted=yes] file:${dir#"$TROOT"}/ ./" | write_if_changed "$list"; then
            say "listed ${source} ${version}"
        fi
        urc=0; update_one "$list" "$dir" || urc=$?
        if [[ "$urc" -eq 1 ]]; then
            rm -f "$list" "$(pin_path "$source")"
            say "WARNING - ${source}: apt refused the package source for ${version}; removed ($(grep -E '^(E|W): ' "${LIB}/.update.log" | head -n 2 | tr '\n' ' '))"
            row "$source" "$version" 0 - - - 1 "$for_base" -
            continue
        elif [[ "$urc" -eq 2 ]]; then
            say "WARNING - ${source}: apt could not read the package lists now ($(grep -E '^(E|W): ' "${LIB}/.update.log" | head -n 1 | tr '\n' ' ')); left as it was, tried again next run"
        fi
    elif [[ ! -f "$list" ]]; then
        continue
    fi

    # What is installed: ours, or an Ubuntu build newer than ours. At 1001
    # apt would downgrade the newer one, so it is never pinned over; its own
    # changelog says whether it carries our fixes.
    ahead="-"
    while read -r b v; do
        [[ -z "${b:-}" ]] && continue
        [[ "$v" == "$version" ]] && installed=1
        if [[ "$v" != *+joinery* ]] && dpkg --compare-versions "$v" gt "$version"; then
            if [[ "$ahead" == "-" ]] || dpkg --compare-versions "$v" gt "$ahead"; then ahead="$v"; fi
        fi
    done < <(installed_binaries "$source")
    if [[ "$ahead" != "-" ]]; then
        [[ -f "$(pin_path "$source")" ]] && rm -f "$(pin_path "$source")" && say "unpinned ${source}: ${ahead} is installed, newer than ours"
        if v="$(verdict "$source" "$from" "$base" "$ahead" "$cves" "$dir")"; then
            if [[ "$v" == covers ]]; then
                row "$source" "$version" 0 "$ahead" - - 0 "$for_base" -
            else
                say "WARNING - ${source}: Ubuntu ${ahead} is installed and lacks ${v#lacks }"
                row "$source" "$version" 0 - "${ahead}:${v#lacks }" "$ahead" 0 "$for_base" -
            fi
        else
            row "$source" "$version" 0 - - "$ahead" 0 "$for_base" "$ahead"
        fi
        continue
    fi

    # The hand-back.
    uver="$(ubuntu_newest "$from")"
    if [[ -n "$uver" ]] && dpkg --compare-versions "$uver" gt "$base"; then
        if v="$(verdict "$source" "$from" "$base" "$uver" "$cves" "$dir")"; then
            if [[ "$v" == covers ]]; then
                [[ -f "$(pin_path "$source")" ]] && rm -f "$(pin_path "$source")" && say "handed ${source} back to Ubuntu ${uver}: its changelog names every CVE ours carries, and its source carries each fix"
                row "$source" "$version" "$installed" "$uver" - - 0 "$for_base" -
                continue
            fi
            lacks="${uver}:${v#lacks }"
        else
            unread="$uver"
            say "WARNING - ${source}: could not read Ubuntu ${uver}'s changelog or source; ours stays"
        fi
    fi
    refused=0
    write_pin "$source" "$version" || { say "WARNING - ${source}: its Packages names no binary"; refused=1; }
    row "$source" "$version" "$installed" - "$lacks" - "$refused" "$for_base" "$unread"
done <<< "$ACTIVE_TEXT"

# A source no longer in ACTIVE (or no longer applying here): its lists, pin and directory go.
if [[ "$CMD" == converge ]]; then
    for f in "${LISTS}"/joinery-fix-*.list "${PREFS}"/joinery-fix-*.pref; do
        [[ -e "$f" ]] || continue
        keep=0
        for s in "${ACTIVE_SOURCES[@]}"; do
            case "$(basename "$f")" in "joinery-fix-$(slug "$s")-"*.list|"joinery-fix-$(slug "$s").pref") keep=1 ;; esac
        done
        [[ "$keep" == 1 ]] || { rm -f "$f"; say "removed $(basename "$f"): no longer in this release"; }
    done
    for d in "${LIB}"/*/; do
        [[ -d "$d" ]] || continue
        s="$(basename "$d")"
        [[ "$s" == verdicts ]] && continue
        [[ " ${ACTIVE_SOURCES[*]} " == *" $s "* ]] || { rm -rf "$d"; say "removed ${s}'s fix: no longer in this release"; }
    done
    # Automatic updates take our origin while any fix is listed. Without this
    # unattended-upgrades sets the 1001 pin aside ("not in an allowed origin")
    # and installs Ubuntu's newer build, one lacking our fix included.
    if compgen -G "${LISTS}/joinery-fix-*.list" > /dev/null; then
        mkdir -p "$(dirname "$ORIGIN_CONF")"
        if printf '%s\n' '// Written by install_package_fixes.sh (Joinery) while a package fix is listed. Do not edit.' \
                '// Automatic updates take our own builds ahead of Ubuntu, origin Joinery-Fixes.' \
                'Unattended-Upgrade::Allowed-Origins {' '    "Joinery-Fixes:resolute-joinery";' '};' | write_if_changed "$ORIGIN_CONF"; then
            say "wrote $(basename "$ORIGIN_CONF"): automatic updates take our builds"
        fi
    elif [[ -e "$ORIGIN_CONF" ]]; then
        rm -f "$ORIGIN_CONF"
        say "removed $(basename "$ORIGIN_CONF"): no package fix is listed"
    fi
fi

{
    echo "updated=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    for s in "${ACTIVE_SOURCES[@]}"; do
        [[ -n "${ROW[$s]:-}" ]] && echo "${ROW[$s]}"
    done
} > "${STATE}.new"
mv "${STATE}.new" "$STATE"
