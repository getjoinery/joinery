#!/usr/bin/env bash
#
# build.sh - build one package fix: Ubuntu's own source for one source package,
# plus upstream's security patches, as every binary package that source makes
# (specs/package_fixes_ahead_of_ubuntu.md WP1).
#
# Version: 1.2 - each patch's fingerprint (owner Q6, reviewer2 H1): a few of its added code lines found
#                nowhere in Ubuntu's base source, written into FIXINFO; a machine hands a fix back only
#                when Ubuntu's newer source carries every one
# Version: 1.1 - reviewer2's build review: the package builds and its tests run as an unprivileged
#                user under fakeroot, as on Ubuntu's builders (G3: PostgreSQL's initdb refuses root,
#                and upstream test code never runs as root on the publishing box); build
#                dependencies are still installed as root. Best-effort I/O priority, not idle (G11).
# Version: 1.0
#
# Runs inside Ubuntu 26.04, as root: the build dependencies come from Ubuntu's
# archive, so the packages are built against what every node has. On dev the
# publish pipeline runs it in the build root (build_root.sh build); by hand, on
# any 26.04 machine with deb-src lines:
#
#     sudo bash build.sh RECIPE_DIR OUT_DIR
#
# RECIPE_DIR is fixes/<source>/, holding:
#
#   RECIPE      one key=value per line:
#                 state=active|none       none: nothing is built or shipped
#                 ubuntu_source=<source> <version>
#                                         the Ubuntu build the patches go on
#                 dsc_sha256=<hex>        that build's .dsc, as apt fetched it
#                 revision=<N>            the +joinery<N> in the version
#                 cves=<CVE ...>          what the patches fix, space-separated
#                 changelog_from=<binary> the binary whose .deb carries the
#                                         changelog (machines read it to see
#                                         whether a newer Ubuntu build has the
#                                         same fixes)
#                 date=<YYYY-MM-DD>       the day the recipe was written; it
#                                         dates the changelog entry, so two
#                                         builds of one recipe carry one date
#                 test=default|<command>  default: the package's own suite, as
#                                         dpkg-buildpackage runs it; a command
#                                         instead runs in the built source tree
#                                         and must exit 0
#   patches/    NNNN-CVE-YYYY-N.patch, one per fix, each applying with -p1.
#               Each carries, above the diff, a line
#                 Origin: <upstream fix commit URL>
#               and may carry
#                 Joinery-Test: <command>
#               run in the built source tree after the build; it must exit 0, and
#                 Joinery-Fingerprint: <one added line of the patch>
#               (repeatable) naming the patch's fingerprint by hand, where the
#               automatic pick (below) is poor.
#
# A patch's fingerprint is up to three pieces of its code, from files that are
# not tests or docs, with runs of white space made one space, that appear
# nowhere in Ubuntu's base source: its longest added lines, or only where none
# is distinctive (a short fix of common lines), three lines in a row of the
# patched code holding an added one. A machine hands the fix back to
# a newer Ubuntu build only when that build's source carries every fingerprint
# line of every patch: a changelog that names a CVE is Ubuntu's claim, its
# source is what it ships.
#
# Leaves in OUT_DIR, and nothing else:
#   joinery-fix-<source>-<version>-<arch>.tar.xz
#       joinery-fix-<source>-<version>-<arch>/
#           *.deb      every binary package of the source, the version tagged
#                      <ubuntu version>+joinery<N> (debug symbol packages left out)
#           Packages   the flat repository index
#           Release    Origin and Label Joinery-Fixes, Suite resolute-joinery,
#                      and the SHA256 of Packages
#           FIXINFO    source, version, ubuntu_base, cves, changelog_from, binaries,
#                      and per patch, one line per fingerprint line:
#                        fingerprint=<patch file name> <the line or lines, base64>
#   joinery-fix-<source>-<version>-<arch>.tar.xz.sha256
#
# <version> drops any epoch, as .deb file names do.
#
# Refuses (exit 1, OUT_DIR left as it was) when the recipe is not whole, the
# fetched .dsc is not the recipe's, the source is not 3.0 (quilt), a patch does
# not apply cleanly or names no Origin, a patch has no fingerprint, the build or a test fails, a binary does
# not carry the new version, the new version does not sort above Ubuntu's, or
# changelog_from's .deb lacks the new entry.
#
# For the safe-tier gate only: FIX_BUILD_TEST=1 with FIX_BUILD_SOURCE_DIR set to
# a directory holding the .dsc and its files builds as any user on any release,
# from that directory instead of apt, and installs no build dependencies.

set -euo pipefail

RECIPE_DIR="${1:-}"
OUT="${2:-}"

die() { echo "package fix build: $*" >&2; exit 1; }
say() { echo "package fix build: $*"; }

[[ -n "$RECIPE_DIR" && -n "$OUT" ]] || die "usage: build.sh RECIPE_DIR OUT_DIR"
[[ -f "$RECIPE_DIR/RECIPE" ]] || die "no RECIPE in ${RECIPE_DIR}"
RECIPE_DIR="$(cd "$RECIPE_DIR" && pwd)"
TEST_MODE="${FIX_BUILD_TEST:-0}"
if [[ "$TEST_MODE" != "1" ]]; then
    [[ "$(id -u)" -eq 0 ]] || die "run as root (it installs the build dependencies from Ubuntu's archive)"
    . /etc/os-release
    [[ "${VERSION_CODENAME:-}" == "resolute" ]] || die "builds on Ubuntu 26.04 only, the nodes' release (this is ${PRETTY_NAME:-unknown})"
fi

# --- The recipe ---------------------------------------------------------------
recipe_value() {  # KEY
    sed -n "s/^$1=//p" "$RECIPE_DIR/RECIPE" | tail -n 1
}
STATE="$(recipe_value state)"
[[ "$STATE" == "active" ]] || die "the recipe's state is '${STATE:-missing}', not active; nothing to build"
read -r SOURCE BASE_VERSION EXTRA <<< "$(recipe_value ubuntu_source)" || true
DSC_SHA="$(recipe_value dsc_sha256)"
REVISION="$(recipe_value revision)"
CVES="$(recipe_value cves)"
CHANGELOG_FROM="$(recipe_value changelog_from)"
RECIPE_DATE="$(recipe_value date)"
TEST_CMD="$(recipe_value test)"
[[ -n "${SOURCE:-}" && -n "${BASE_VERSION:-}" && -z "${EXTRA:-}" ]] || die "ubuntu_source must be '<source> <version>'"
[[ "$SOURCE" =~ ^[a-z0-9][a-z0-9.+-]+$ ]] || die "'${SOURCE}' is not a source package name"
[[ "$(basename "$RECIPE_DIR")" == "$SOURCE" ]] || die "the recipe for ${SOURCE} sits in $(basename "$RECIPE_DIR")/, not ${SOURCE}/"
[[ "$BASE_VERSION" =~ ^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*$ ]] || die "'${BASE_VERSION}' is not a package version"
[[ "$DSC_SHA" =~ ^[0-9a-f]{64}$ ]] || die "dsc_sha256 must be the .dsc's SHA-256"
[[ "$REVISION" =~ ^[1-9][0-9]*$ ]] || die "revision must be a whole number from 1"
[[ -n "$CVES" ]] || die "the recipe names no CVE"
for cve in $CVES; do
    [[ "$cve" =~ ^CVE-[0-9]{4}-[0-9]{4,}$ ]] || die "'${cve}' is not a CVE id"
done
[[ "$CHANGELOG_FROM" =~ ^[a-z0-9][a-z0-9.+-]+$ ]] || die "changelog_from must name a binary package"
[[ "$RECIPE_DATE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] && date -u -d "$RECIPE_DATE" > /dev/null 2>&1 \
    || die "date must be YYYY-MM-DD"
[[ -n "$TEST_CMD" ]] || TEST_CMD=default

VERSION="${BASE_VERSION}+joinery${REVISION}"
dpkg --compare-versions "$VERSION" gt "$BASE_VERSION" || die "${VERSION} does not sort above ${BASE_VERSION}"
FILE_VERSION="${VERSION#*:}"
ARCH="$(dpkg --print-architecture)"
NAME="joinery-fix-${SOURCE}-${FILE_VERSION}-${ARCH}"

PATCHES=()
if [[ -d "$RECIPE_DIR/patches" ]]; then
    while IFS= read -r p; do PATCHES+=("$p"); done < <(find "$RECIPE_DIR/patches" -maxdepth 1 -type f -name '*.patch' | sort)
fi
[[ ${#PATCHES[@]} -gt 0 ]] || die "the recipe has no patches/*.patch"
for p in "${PATCHES[@]}"; do
    [[ "$(basename "$p")" =~ ^[0-9]{4}-[A-Za-z0-9._+-]+\.patch$ ]] || die "$(basename "$p") is not named NNNN-<name>.patch"
    # The header is everything above the first diff line.
    header="$(awk '/^(--- |diff |Index: )/ {exit} {print}' "$p")"
    grep -qE '^Origin: +[^ ]+' <<< "$header" || die "$(basename "$p") names no 'Origin:' upstream commit above its diff"
done

mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
# On disk, not /tmp: 26.04 mounts /tmp in memory, and a PHP or PostgreSQL build
# wants several GB.
WORK="$(mktemp -d "${FIX_BUILD_WORK_BASE:-/var/tmp}/package-fix-build.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
export DEBIAN_FRONTEND=noninteractive LC_ALL=C.UTF-8

# --- 1. Ubuntu's source, as the recipe pins it --------------------------------
mkdir "$WORK/fetch"
# apt fetches as _apt: a folder only root can enter makes it fall back to root, with a warning.
chmod 755 "$WORK" "$WORK/fetch"
if [[ "$TEST_MODE" == "1" ]]; then
    [[ -d "${FIX_BUILD_SOURCE_DIR:-}" ]] || die "FIX_BUILD_TEST needs FIX_BUILD_SOURCE_DIR"
    cp -a "$FIX_BUILD_SOURCE_DIR"/. "$WORK/fetch/"
else
    # apt checks every file it fetches against the Sources index, whose Release
    # is signed with Ubuntu's archive key; the recipe's hash then pins the .dsc.
    say "fetching ${SOURCE} ${BASE_VERSION} from Ubuntu's archive"
    apt-get update -qq
    (cd "$WORK/fetch" && apt-get source -qq --download-only "${SOURCE}=${BASE_VERSION}") \
        || die "apt could not fetch ${SOURCE} ${BASE_VERSION} (is there a deb-src line for resolute?)"
fi
DSC="$WORK/fetch/${SOURCE}_${BASE_VERSION#*:}.dsc"
[[ -f "$DSC" ]] || die "no ${SOURCE}_${BASE_VERSION#*:}.dsc was fetched"
got="$(sha256sum "$DSC" | awk '{print $1}')"
[[ "$got" == "$DSC_SHA" ]] || die "the fetched .dsc is ${got}, not the recipe's ${DSC_SHA}"
grep -qx 'Format: 3.0 (quilt)' "$DSC" || die "${SOURCE} ${BASE_VERSION} is not a 3.0 (quilt) source; its patches cannot be added the same way"
# dpkg-source checks each file against the checksums the .dsc lists.
(cd "$WORK" && dpkg-source -q -x "$DSC" src > "$WORK/extract.log" 2>&1) \
    || { cat "$WORK/extract.log" >&2; die "the source would not unpack"; }
SRC="$WORK/src"
[[ "$(dpkg-parsechangelog -l "$SRC/debian/changelog" -S Version)" == "$BASE_VERSION" ]] \
    || die "the unpacked source's changelog is not at ${BASE_VERSION}"

# --- 2. Each patch's fingerprint ----------------------------------------------
# The same normal form and units as install_package_fixes.sh reads Ubuntu's
# newer source with (the gate checks the two definitions match): a removed
# patch line is dropped, a kept or added one loses its leading '+' or ' ', and
# white space becomes one space; a unit is one line, or three in a row joined
# by \036. Three in a row tell a short fix apart whose every line is common.
normal_form() { sed -E '/^-/d; s/^[+ ]//; s/[[:space:]]+/ /g; s/^ //; s/ $//'; }
units() { awk '{ print; if (NR > 2) print a "\036" b "\036" $0; a = b; b = $0 }'; }
# Every unit of Ubuntu's base source, as unpacked (its own patches applied).
find "$SRC" -type f -size -8M -not -path '*/.pc/*' -print0 | xargs -0 -r grep -hIs '' \
    | normal_form | units > "$WORK/base.units"
declare -A FINGERPRINT=()
for p in "${PATCHES[@]}"; do
    pb="$(basename "$p")"
    # The patch's candidates, outside tests and docs: each added line of 20
    # characters or more, and each run of three lines of the patched code
    # holding an added one; code before comments, the longest first.
    awk '
        function norm(x) { sub(/^[+ ]/, "", x); gsub(/[[:space:]]+/, " ", x); sub(/^ /, "", x); sub(/ $/, "", x); return x }
        # A comment ranks after code: a backport may word it differently.
        # Ranks: 1 a code line, 2 three code lines, 3 a comment, 4 three lines with a comment.
        function cmt(x) { return x ~ /^(\/\/|\/\*|\*|#)/ }
        function flush() { for (i = 1; i <= n; i++) { if (add[i] && length(line[i]) >= 20) print (cmt(line[i]) ? 3 : 1) "\t" length(line[i]) "\t" line[i] }
                           for (i = 3; i <= n; i++) if (add[i] || add[i-1] || add[i-2]) { w = line[i-2] "\036" line[i-1] "\036" line[i]
                               print ((cmt(line[i-2]) || cmt(line[i-1]) || cmt(line[i])) ? 4 : 2) "\t" length(w) "\t" w }
                           n = 0 }
        /^\+\+\+ / { flush(); f = $2; sub(/^[ab]\//, "", f); skip = (f ~ /(^|\/)(tests?|testsuite|t)\/|\.phpt$|(^|\/)(NEWS|ChangeLog|CHANGES|README)[^\/]*$|\.(md|txt|rst|po|pot)$|^debian\//); next }
        /^(--- |diff |index |@@)/ { flush(); next }
        skip { next }
        /^-/ { next }
        /^[+ ]/ || /^$/ { n++; line[n] = norm($0); add[n] = ($0 ~ /^\+/); next }
        { flush() }
        END { flush() }
    ' "$p" | LC_ALL=C sort -t "$(printf '\t')" -k1,1n -k2,2nr -k3 | cut -f3- | awk '!seen[$0]++' > "$WORK/candidates"
    manual="$(awk '/^(--- |diff |Index: )/ {exit} /^Joinery-Fingerprint: / {sub(/^Joinery-Fingerprint: +/, ""); print}' "$p" | normal_form)"
    if [[ -n "$manual" ]]; then
        picked="$manual"
        while IFS= read -r line; do
            # A line named by hand must be one the patch adds, and one Ubuntu's base does not have.
            grep -qxF -- "$line" <(grep '^+' "$p" | grep -v '^+++ ' | normal_form) \
                || die "${pb}: its Joinery-Fingerprint '${line}' is not a line the patch adds"
            ! grep -qxF -- "$line" "$WORK/base.units" || die "${pb}: its Joinery-Fingerprint '${line}' is already in Ubuntu's ${BASE_VERSION}"
        done <<< "$manual"
    else
        # The patch's few candidates are the patterns, Ubuntu's base the text:
        # those found there are not distinctive. (Not comm: 26.04's uutils comm
        # ignores LC_ALL=C and calls C-sorted input unsorted.)
        { [[ -s "$WORK/candidates" ]] && grep -xFf "$WORK/candidates" "$WORK/base.units" || true; } > "$WORK/common"
        # Lines alone where there are any: a run of three holds context lines a
        # backport may differ in.
        picked="$({ grep -vxFf "$WORK/common" "$WORK/candidates" || true; } | head -n 3 \
            | awk 'NR == 1 { runs = index($0, "\036") > 0 } runs || index($0, "\036") == 0')"
    fi
    [[ -n "$picked" ]] || die "${pb} adds no code found nowhere in Ubuntu's ${BASE_VERSION}, alone or three lines in a row; name a line with a Joinery-Fingerprint: line above its diff"
    FINGERPRINT[$pb]="$picked"
done
rm -f "$WORK/base.units" "$WORK/candidates" "$WORK/common"

# --- 3. The patches -----------------------------------------------------------
mkdir -p "$SRC/debian/patches"
touch "$SRC/debian/patches/series"
[[ -z "$(tail -c 1 "$SRC/debian/patches/series")" ]] || echo >> "$SRC/debian/patches/series"
# One at a time, each with fuzz 0, as dpkg-source applies them. dpkg-source
# alone is not enough: when the next patch in the series does not apply, it
# takes the tree to be patched already and says nothing.
for p in "${PATCHES[@]}"; do
    pname="joinery-$(basename "$p")"
    [[ ! -e "$SRC/debian/patches/$pname" ]] || die "debian/patches/${pname} already exists in Ubuntu's source"
    cp "$p" "$SRC/debian/patches/$pname"
    (cd "$SRC" && patch -p1 -F 0 -N --dry-run -s < "debian/patches/$pname" > "$WORK/patch.log" 2>&1) \
        || { tail -n 20 "$WORK/patch.log" >&2; die "$(basename "$p") does not apply cleanly to ${SOURCE} ${BASE_VERSION}"; }
    echo "$pname" >> "$SRC/debian/patches/series"
    (cd "$SRC" && dpkg-source --before-build . > "$WORK/patch.log" 2>&1) \
        || { tail -n 20 "$WORK/patch.log" >&2; die "dpkg-source would not apply $(basename "$p")"; }
    grep -qx "$pname" "$SRC/.pc/applied-patches" 2>/dev/null || die "$(basename "$p") was not applied"
done
# dpkg-source unapplies after the build what it applied before it; the patched
# tree is what the tests below read, so the patches stay applied.
rm -f "$SRC/.pc/.dpkg-source-unapply"

# --- 4. The changelog entry ---------------------------------------------------
{
    echo "${SOURCE} (${VERSION}) resolute-joinery; urgency=high"
    echo
    echo "  * SECURITY UPDATE built by Joinery ahead of Ubuntu, on Ubuntu's ${BASE_VERSION}"
    for p in "${PATCHES[@]}"; do
        origin="$(awk '/^(--- |diff |Index: )/ {exit} /^Origin: / {print $2; exit}' "$p")"
        echo "    - debian/patches/joinery-$(basename "$p"): ${origin}"
    done
    for cve in $CVES; do echo "    - ${cve}"; done
    echo
    echo " -- Joinery package fixes <noreply@getjoinery.com>  $(date -u -R -d "${RECIPE_DATE} 00:00:00")"
    echo
    cat "$SRC/debian/changelog"
} > "$WORK/changelog.new"
mv "$WORK/changelog.new" "$SRC/debian/changelog"
[[ "$(dpkg-parsechangelog -l "$SRC/debian/changelog" -S Version)" == "$VERSION" ]] || die "the changelog entry did not parse"

# --- 5. Build -----------------------------------------------------------------
if [[ "$TEST_MODE" != "1" ]]; then
    say "installing ${SOURCE}'s build dependencies"
    apt-get build-dep -y -qq "$SRC" > "$WORK/build-dep.log" 2>&1 \
        || { tail -n 20 "$WORK/build-dep.log" >&2; die "the build dependencies would not install"; }
fi
# The build and every test run as an unprivileged user, never root: a test
# suite run as root passes or skips differently (PostgreSQL's initdb refuses
# root), and upstream's test code has no business running as root here.
# fakeroot gives the packages root-owned files, as on Ubuntu's builders.
BUILDER="${FIX_BUILD_USER:-joinery-pkgbuild}"
as_builder() {  # COMMAND... (run in $SRC)
    if [[ "$TEST_MODE" == "1" ]]; then
        (cd "$SRC" && "$@")
    else
        (cd "$SRC" && runuser -u "$BUILDER" -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME="$WORK/home" LANG=C.UTF-8 LC_ALL=C.UTF-8 \
            DEB_BUILD_OPTIONS="${DEB_BUILD_OPTIONS:-}" "$@")
    fi
}
if [[ "$TEST_MODE" != "1" ]]; then
    id -u "$BUILDER" > /dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin "$BUILDER"
    mkdir -p "$WORK/home"
    chown -R "$BUILDER:" "$WORK"
fi
opts="parallel=$(nproc)"
[[ "$TEST_CMD" == "default" ]] || opts="$opts nocheck"
say "building ${SOURCE} ${VERSION} as ${BUILDER} (the package's own tests: ${TEST_CMD})"
DEB_BUILD_OPTIONS="$opts" as_builder nice -n 19 ionice -c 2 -n 7 dpkg-buildpackage -b -us -uc -rfakeroot > "$WORK/build.log" 2>&1 \
    || { tail -n 40 "$WORK/build.log" >&2; die "${SOURCE} did not build"; }
if [[ "$TEST_CMD" != "default" ]]; then
    as_builder bash -c "$TEST_CMD" > "$WORK/test.log" 2>&1 \
        || { tail -n 40 "$WORK/test.log" >&2; die "the recipe's test failed: ${TEST_CMD}"; }
fi
for p in "${PATCHES[@]}"; do
    ptest="$(awk '/^(--- |diff |Index: )/ {exit} /^Joinery-Test: / {sub(/^Joinery-Test: +/, ""); print; exit}' "$p")"
    [[ -n "$ptest" ]] || continue
    as_builder bash -c "$ptest" > "$WORK/ptest.log" 2>&1 \
        || { tail -n 40 "$WORK/ptest.log" >&2; die "$(basename "$p")'s test failed: ${ptest}"; }
done

# --- 6. Check what was built --------------------------------------------------
STAGE="$WORK/stage/$NAME"
mkdir -p "$STAGE"
BINARIES=()
while IFS= read -r deb; do
    b="$(dpkg-deb -f "$deb" Package)"
    [[ "$b" == *-dbgsym ]] && continue
    v="$(dpkg-deb -f "$deb" Version)"
    [[ "$v" == "$VERSION" ]] || die "$(basename "$deb") is version ${v}, not ${VERSION}"
    s="$(dpkg-deb -f "$deb" Source)"
    s="${s%% *}"
    [[ "${s:-$b}" == "$SOURCE" ]] || die "$(basename "$deb") is from source ${s:-$b}, not ${SOURCE}"
    cp "$deb" "$STAGE/"
    BINARIES+=("$b")
done < <(find "$WORK" -maxdepth 1 -name '*.deb' | sort)
[[ ${#BINARIES[@]} -gt 0 ]] || die "the build made no .deb"
cl_deb="$(find "$STAGE" -maxdepth 1 -name "${CHANGELOG_FROM}_*.deb" | head -n 1)"
[[ -n "$cl_deb" ]] || die "the build made no ${CHANGELOG_FROM} package (changelog_from)"
top="$(dpkg-deb --fsys-tarfile "$cl_deb" | tar -xOf - "./usr/share/doc/${CHANGELOG_FROM}/changelog.Debian.gz" 2>/dev/null | gzip -dc 2>/dev/null | head -n 1 || true)"
[[ "$top" == "${SOURCE} (${VERSION})"* ]] || die "${CHANGELOG_FROM}'s .deb does not carry the ${VERSION} changelog entry (its newest entry: ${top:-none})"

# --- 7. The flat repository ---------------------------------------------------
(cd "$STAGE" && dpkg-scanpackages -m . /dev/null 2>/dev/null) > "$STAGE/Packages"
[[ -s "$STAGE/Packages" ]] || die "the Packages index is empty"
{
    echo "Origin: Joinery-Fixes"
    echo "Label: Joinery-Fixes"
    echo "Suite: resolute-joinery"
    echo "Codename: resolute-joinery"
    echo "Date: $(date -u -R -d "${RECIPE_DATE} 00:00:00")"
    echo "Architectures: ${ARCH} all"
    echo "Description: Joinery's build of ${SOURCE} ${VERSION}, ahead of Ubuntu"
    echo "SHA256:"
    printf ' %s %s Packages\n' "$(sha256sum "$STAGE/Packages" | awk '{print $1}')" "$(stat -c %s "$STAGE/Packages")"
} > "$STAGE/Release"
# apt fails every command on the box over an empty Release, and only warns over
# one with no hash for Packages; refuse either here.
for field in 'Origin: Joinery-Fixes' 'Label: Joinery-Fixes' 'Suite: resolute-joinery'; do
    grep -qx "$field" "$STAGE/Release" || die "the Release lacks '${field}'"
done
grep -qE '^ [0-9a-f]{64} [0-9]+ Packages$' "$STAGE/Release" || die "the Release has no SHA256 line for Packages"
{
    echo "source=${SOURCE}"
    echo "version=${VERSION}"
    echo "ubuntu_base=${BASE_VERSION}"
    echo "cves=${CVES}"
    echo "changelog_from=${CHANGELOG_FROM}"
    echo "binaries=$(printf '%s\n' "${BINARIES[@]}" | sort -u | paste -sd ' ')"
    for p in "${PATCHES[@]}"; do
        while IFS= read -r line; do
            printf 'fingerprint=%s %s\n' "$(basename "$p")" "$(printf '%s' "$line" | base64 -w0)"
        done <<< "${FINGERPRINT[$(basename "$p")]}"
    done
} > "$STAGE/FIXINFO"

# A deterministic archive: names sorted, owner root, one timestamp.
tar -C "$WORK/stage" --sort=name --owner=0 --group=0 --numeric-owner --mtime='@0' -cf - "$NAME" \
    | xz -6 -T1 > "$WORK/$NAME.tar.xz"
(cd "$WORK" && sha256sum "$NAME.tar.xz") > "$WORK/$NAME.tar.xz.sha256"
mv -f "$WORK/$NAME.tar.xz" "$WORK/$NAME.tar.xz.sha256" "$OUT/"
say "$OUT/$NAME.tar.xz ($(du -h "$OUT/$NAME.tar.xz" | cut -f1); ${#BINARIES[@]} packages)"
