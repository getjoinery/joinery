#!/bin/bash
# @joinery-test
# name: install_package_fixes
# tier: safe
# env: any
# needs: []
# timeout: 180
# covers: [maintenance_scripts/install_tools/install_package_fixes.sh]
#
# The package fix channel (specs/package_fixes_automatic.md WP6, WP8): taken only
# when it verifies (a stand-in verifier here) and its serial is not lower than
# the one in force; its withdrawn builds are installed back down.
#
# Package fixes ahead of Ubuntu (specs/package_fixes_ahead_of_ubuntu.md WP3).
# install_package_fixes.sh converge takes each fix the release's fixes/ACTIVE
# names that applies to this machine: fetched and checked against ACTIVE's
# hash, unpacked, listed as a package source, read by apt alone, and pinned
# over Ubuntu's build. handback lifts the pin once Ubuntu's own newer build
# names every CVE in its changelog. This gate drives it as an unprivileged
# user under PACKAGE_FIXES_ROOT, with stand-ins for apt-get, apt-cache and
# dpkg-query; the archives and the .deb whose changelog is read are real.
#
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/install_tools/install_package_fixes.sh"
for tool in dpkg-deb dpkg-scanpackages xz; do
    command -v "$tool" > /dev/null || { echo "  SKIP: no ${tool} on this box"; echo "RESULT: 0 passed, 0 failed"; exit 0; }
done
if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged; as root the fixture root is ignored and the machine's own paths would be used"
    echo "RESULT: 0 passed, 0 failed"; exit 0
fi
T="$(mktemp -d /var/tmp/install-package-fixes-gate.XXXXXX)"
trap '[ -n "${KEEP:-}" ] || rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

R="$T/root"; STUB="$T/s"; SRV="$T/srv/static_files/fixes"
export PACKAGE_FIXES_ROOT="$R" STUB
mkdir -p "$T/bin" "$STUB/debs" "$R/etc/apt/sources.list.d" "$R/etc/apt/preferences.d" "$SRV"
# apt-get: update records the list it was given and fails when told to;
# source hands over Ubuntu's source files from $STUB/src/<source>_<version>/;
# download hands over a real .deb from $STUB/debs.
cat > "$T/bin/apt-get" <<'EOF'
#!/bin/bash
echo "$*" >> "$STUB/calls"
case "$*" in
  *" update "*|update*)
    list="$(printf '%s\n' "$@" | sed -n 's/^Dir::Etc::sourcelist=//p')"
    uri="$(sed -n 's/^deb \[trusted=yes\] \(file:[^ ]*\) .*/\1/p' "$list")"
    [ -f "$STUB/refuse_$(basename "$list")" ] && { echo "E: Repository '${uri} ./ Release' changed its 'Label' value"; exit 100; }
    [ -f "$STUB/apt_busy" ] && { echo "E: Could not get lock /var/lib/apt/lists/lock. It is held by process 4242 (apt-get)"; exit 100; }
    grep -h '^deb-src' "$list" >> "$STUB/debsrc" 2>/dev/null
    exit 0 ;;
  source*|*" source "*)
    want="${@: -1}"; d="$STUB/src/${want%%=*}_${want#*=}"
    [ -d "$d" ] || { echo "E: Unable to find a source package for $want"; exit 100; }
    cp "$d"/* . ;;
  *download*)
    want="${@: -1}"; f="$(ls "$STUB/debs/${want%%=*}_${want#*=}"{.deb,_all.deb} 2>/dev/null | head -n 1)"
    [ -n "$f" ] || { echo "E: no $want"; exit 100; }
    cp "$f" . ;;
esac
EOF
# apt-cache madison: what $STUB/madison_<binary> says.
cat > "$T/bin/apt-cache" <<'EOF'
#!/bin/bash
[ "$1" = madison ] && cat "$STUB/madison_$2" 2>/dev/null
exit 0
EOF
# dpkg-query: what is installed, from $STUB/dpkg ("<source> <package> <version>").
cat > "$T/bin/dpkg-query" <<'EOF'
#!/bin/bash
fmt="$3"
while read -r s p v; do
  [ -z "$s" ] && continue
  case "$fmt" in
    *Package*Version*) echo "ii  $s $p $v" ;;
    *) echo "ii  $s" ;;
  esac
done < "$STUB/dpkg"
EOF
chmod 755 "$T/bin/"*
export PATH="$T/bin:$PATH"
: > "$STUB/dpkg"

# The script runs from a tree of its own, as it ships.
TREE="$T/tree/maintenance_scripts/install_tools"
mkdir -p "$TREE/fixes"
cp "$SCRIPT" "$TREE/"
ACTIVE="$TREE/fixes/ACTIVE"
run() { bash "$TREE/install_package_fixes.sh" "$@" > "$T/out" 2>&1; echo $?; }
st() { grep "^fix=$1|" "$R/var/lib/joinery/package-fixes/state" | cut -d'|' -f"$2"; }

# A .deb of BINARY at VERSION, its changelog's newest entry naming TEXT.
mkdeb() {  # SOURCE BINARY VERSION OUTDIR CHANGELOG_TEXT [BASE]
    local d="$T/build/$2-$3"
    rm -rf "$d"; mkdir -p "$d/DEBIAN" "$d/usr/share/doc/$2"
    printf 'Package: %s\nSource: %s\nVersion: %s\nArchitecture: all\nMaintainer: x <x@example.invalid>\nDescription: fixture\n' "$2" "$1" "$3" > "$d/DEBIAN/control"
    {
        printf '%s (%s) resolute-security; urgency=medium\n\n  * %s\n\n -- x <x@example.invalid>  Thu, 19 Nov 2026 12:00:00 +0000\n\n' "$1" "$3" "$5"
        printf '%s (%s) resolute-security; urgency=medium\n\n  * CVE-2025-1111 (older, must not count)\n\n -- x <x@example.invalid>  Mon, 19 Oct 2026 12:00:00 +0000\n' "$1" "${6:-1.0-0ubuntu1}"
    } | gzip -9n > "$d/usr/share/doc/$2/changelog.Debian.gz"
    dpkg-deb --root-owner-group -b "$d" "$4/${2}_${3}_all.deb" > /dev/null
}
fix_line() { printf 'fixed_%s(ctx, len);' "${1//-/_}"; }  # CVE
# Ubuntu's source for SOURCE at VERSION, as apt would fetch it: each CVE's fix
# line in a patch under debian/patches (patch), only as a removed line there
# (removed), or in upstream's tarball (orig).
mksrc() {  # SOURCE VERSION patch|removed|orig CVES...
    local s="$1" v="$2" kind="$3" c up; shift 3
    local d="$STUB/src/${s}_${v}" w="$T/build/src-$s-$v"
    up="${v%-*}"
    rm -rf "$d" "$w"; mkdir -p "$d" "$w/debian/patches" "$w/orig/${s}-${up}/src"
    printf 'Format: 3.0 (quilt)\nSource: %s\nVersion: %s\n' "$s" "$v" > "$d/${s}_${v}.dsc"
    printf -- '--- a/src/f.c\n+++ b/src/f.c\n@@ -1,2 +1,2 @@\n int f(void)\n' > "$w/debian/patches/fix.patch"
    printf 'int f(void)\n' > "$w/orig/${s}-${up}/src/f.c"
    for c; do
        case "$kind" in
            patch)   printf '+\t%s\n' "$(fix_line "$c")" >> "$w/debian/patches/fix.patch" ;;
            removed) printf -- '-\t%s\n' "$(fix_line "$c")" >> "$w/debian/patches/fix.patch" ;;
            orig)    printf '\t%s\n' "$(fix_line "$c")" >> "$w/orig/${s}-${up}/src/f.c" ;;
        esac
    done
    tar -C "$w" -cJf "$d/${s}_${v}.debian.tar.xz" debian
    tar -C "$w/orig" -cJf "$d/${s}_${up}.orig.tar.xz" "${s}-${up}"
}
# A fix archive for SOURCE at VERSION with BINARIES, published in $SRV; echoes its ACTIVE line.
mkfix() {  # SOURCE VERSION BASE CVES BINARIES...
    local source="$1" version="$2" base="$3" cves="$4"; shift 4
    local name="joinery-fix-${source}-${version}-amd64" st="$T/stage/$source-$version"
    rm -rf "$st"; mkdir -p "$st/$name"
    for b in "$@"; do mkdeb "$source" "$b" "$version" "$st/$name" "SECURITY UPDATE by Joinery: ${cves}" "$base"; done
    (cd "$st/$name" && dpkg-scanpackages -m . /dev/null 2>/dev/null) > "$st/$name/Packages"
    printf 'Origin: Joinery-Fixes\nLabel: Joinery-Fixes\nSuite: resolute-joinery\nSHA256:\n %s %s Packages\n' \
        "$(sha256sum "$st/$name/Packages" | awk '{print $1}')" "$(stat -c %s "$st/$name/Packages")" > "$st/$name/Release"
    printf 'source=%s\nversion=%s\nubuntu_base=%s\ncves=%s\nchangelog_from=%s\nbinaries=%s\n' "$source" "$version" "$base" "$cves" "$1" "$*" > "$st/$name/FIXINFO"
    # One patch per CVE, its fingerprint one line (fix_line), as build.sh writes it.
    local c n=0
    for c in $cves; do
        n=$((n + 1))
        [ -n "${NOFP:-}" ] || printf 'fingerprint=%04d-%s.patch %s\n' "$n" "$c" "$(fix_line "$c" | base64 -w0)" >> "$st/$name/FIXINFO"
    done
    tar -C "$st" --sort=name --owner=0 --group=0 --numeric-owner --mtime='@0' -cJf "$SRV/$name.tar.xz" "$name"
    echo "source=${source} version=${version} ubuntu_base=${base} archive=${name}.tar.xz sha256=$(sha256sum "$SRV/$name.tar.xz" | awk '{print $1}') recipe_sha256=$(printf 'c%.0s' $(seq 64)) changelog_from=${1} cves=${cves}"
}
header='# Written by the publish (PackageFixPublisher). Do not edit.'
LA="$(mkfix jfa 1.0-0ubuntu1+joinery1 1.0-0ubuntu1 'CVE-2026-109618 CVE-2026-109619' jfa jfa-extra)"
LB="$(mkfix jfb 2.0-1ubuntu2+joinery1 2.0-1ubuntu2 'CVE-2026-5000' jfb)"
LC="$(mkfix jfc 3.0-1+joinery1 3.0-1 'CVE-2026-6000' jfc)"
LP="$(mkfix php8.5 8.5.4-0ubuntu1.5+joinery1 8.5.4-0ubuntu1.5 'CVE-2026-129289' php8.5)"
SERVER="file://$T/srv"
printf 'jfa jfa 1.0-0ubuntu1\njfb jfb 2.0-1ubuntu2\n' > "$STUB/dpkg"
PREF="$R/etc/apt/preferences.d"; LISTS="$R/etc/apt/sources.list.d"; LIB="$R/var/lib/joinery/package-fixes"

echo "Two fixes, both installed here, one not"
printf '%s\n' "$header" "$LA" "$LB" "$LC" > "$ACTIVE"
chk "converge succeeds" "$(run converge "$SERVER")" "0"
chk "both installed sources are listed, one list per version" "$(ls "$LISTS" | paste -sd ' ')" \
    "joinery-fix-jfa-1.0-0ubuntu1-joinery1.list joinery-fix-jfb-2.0-1ubuntu2-joinery1.list"
chk "the list points at the unpacked fix" "$(grep '^deb' "$LISTS/joinery-fix-jfa-1.0-0ubuntu1-joinery1.list")" \
    "deb [trusted=yes] file:/var/lib/joinery/package-fixes/jfa/1.0-0ubuntu1-joinery1/ ./"
chk "the fix is unpacked there" "$(ls "$LIB/jfa/1.0-0ubuntu1-joinery1" | paste -sd ' ')" \
    "FIXINFO Packages Release jfa-extra_1.0-0ubuntu1+joinery1_all.deb jfa_1.0-0ubuntu1+joinery1_all.deb"
chk "the pin names every binary in the archive, not only the installed one" "$(sed -n 's/^Package: //p' "$PREF/joinery-fix-jfa.pref")" "jfa jfa-extra"
chk "the pin names our version, at 1001" "$(sed -n 's/^Pin: //p; s/^Pin-Priority: //p' "$PREF/joinery-fix-jfa.pref" | paste -sd ' ')" "version 1.0-0ubuntu1+joinery1 1001"
chk "a source not installed here is skipped" "$(ls "$LIB" | grep -c '^jfc$')" "0"
chk "apt read each list alone, keeping the other lists" "$(grep -c 'update -qq -o DPkg::Lock::Timeout=300 -o Dir::Etc::sourcelist=.*joinery-fix-jf[ab]-.*sourceparts=- -o APT::Get::List-Cleanup=0' "$STUB/calls")" "2"
chk "state: jfa not yet installed" "$(st jfa 3)" "0"
bash "$TREE/install_package_fixes.sh" pinned > "$T/pinned"
chk "pinned names source and version" "$(paste -sd ',' "$T/pinned")" "jfa 1.0-0ubuntu1+joinery1,jfb 2.0-1ubuntu2+joinery1"
OC="$R/etc/apt/apt.conf.d/52joinery-package-fixes"
chk "our origin is allowed for automatic updates (reviewer2 G1: without it they set the pin aside)" "$(grep -c '^    "Joinery-Fixes:resolute-joinery";$' "$OC" 2>/dev/null)" "1"
chk "state: neither is for the base only" "$(st jfa 8)|$(st jfb 8)" "0|0"

echo "Run again with nothing changed"
: > "$STUB/calls"
mtime="$(stat -c %Y "$PREF/joinery-fix-jfa.pref")"
chk "converge succeeds" "$(run converge "$SERVER")" "0"
chk "says nothing was placed" "$(grep -c 'placed' "$T/out")" "0"
chk "the pin is left as it was" "$(stat -c %Y "$PREF/joinery-fix-jfa.pref")" "$mtime"

echo "Our build installed"
printf 'jfa jfa 1.0-0ubuntu1+joinery1\njfb jfb 2.0-1ubuntu2\n' > "$STUB/dpkg"
run converge "$SERVER" > /dev/null
chk "state: jfa installed" "$(st jfa 3)" "1"

echo "No server named: the site's upgrade server first (B19)"
rm -rf "$LIB/jfb" "$LISTS"/joinery-fix-jfb-* "$PREF/joinery-fix-jfb.pref"
mkdir -p "$R/etc/joinery-agent"
printf '{\n  "plane_url": "https://plane.invalid",\n  "node_id": 1\n}\n' > "$R/etc/joinery-agent/node_identity.json"
chk "converge succeeds" "$(PACKAGE_FIXES_UPGRADE_SOURCE="$SERVER" UPGRADE_SERVER=file:///nonexistent run converge)" "0"
chk "the fix came from the site's upgrade server, before the agent's plane" "$(grep -c "placed jfb 2.0-1ubuntu2+joinery1 (from ${SERVER})" "$T/out")" "1"
rm -rf "$LIB/jfb" "$LISTS"/joinery-fix-jfb-* "$PREF/joinery-fix-jfb.pref"
chk "with no upgrade server known, the plane and the fallback are asked, and fail here" "$(PACKAGE_FIXES_UPGRADE_SOURCE= UPGRADE_SERVER=file:///nonexistent run converge | tr -d "\n"; grep -c "jfb: could not fetch" "$T/out")" "01"
rm -f "$R/etc/joinery-agent/node_identity.json"
run converge "$SERVER" > /dev/null

echo "An archive that is not ACTIVE's"
printf '%s\n' "$header" "$LA" "$(sed "s/sha256=[0-9a-f]*/sha256=$(printf 'd%.0s' $(seq 64))/" <<< "$LB")" > "$ACTIVE"
rm -rf "$LIB/jfb" "$LISTS"/joinery-fix-jfb-* "$PREF/joinery-fix-jfb.pref"
chk "converge succeeds for the rest" "$(run converge "$SERVER")" "0"
chk "the bad one is not placed, listed or pinned" "$(ls "$LIB/jfb" "$LISTS"/joinery-fix-jfb-* "$PREF/joinery-fix-jfb.pref" 2>/dev/null | wc -l | tr -d ' ')" "0"
chk "state: jfb could not be fetched with ACTIVE's hash (2), not refused by apt (1)" "$(st jfb 7)" "2"
chk "says why" "$(grep -c "jfb: could not fetch .* with ACTIVE's hash" "$T/out")" "1"

echo "A fix removed from ACTIVE"
printf '%s\n' "$header" "$LA" > "$ACTIVE"
run converge "$SERVER" > /dev/null
printf '%s\n' "$header" "$LA" "$LB" > "$ACTIVE"
run converge "$SERVER" > /dev/null
chk "both in place again" "$(ls "$PREF" | paste -sd ' ')" "joinery-fix-jfa.pref joinery-fix-jfb.pref"
printf '%s\n' "$header" "$LA" > "$ACTIVE"
chk "converge succeeds" "$(run converge "$SERVER")" "0"
chk "only its pin goes" "$(ls "$PREF" | paste -sd ' ')" "joinery-fix-jfa.pref"
chk "only its list goes" "$(ls "$LISTS" | paste -sd ' ')" "joinery-fix-jfa-1.0-0ubuntu1-joinery1.list"
chk "its directory goes" "$(ls "$LIB" | grep -c '^jfb$')" "0"
chk "state no longer names it" "$(grep -c '^fix=jfb|' "$LIB/state")" "0"

echo "A new build at a new path"
LA2="$(mkfix jfa 1.0-0ubuntu1+joinery2 1.0-0ubuntu1 'CVE-2026-109618 CVE-2026-109619' jfa jfa-extra)"
printf '%s\n' "$header" "$LA2" > "$ACTIVE"
chk "converge succeeds" "$(run converge "$SERVER")" "0"
chk "the new list replaces the old" "$(ls "$LISTS" | paste -sd ' ')" "joinery-fix-jfa-1.0-0ubuntu1-joinery2.list"
chk "the old version's directory goes" "$(ls "$LIB/jfa" | paste -sd ' ')" "1.0-0ubuntu1-joinery2"
chk "the pin names the new version" "$(sed -n 's/^Pin: version //p' "$PREF/joinery-fix-jfa.pref")" "1.0-0ubuntu1+joinery2"

echo "A repository apt refuses"
touch "$STUB/refuse_joinery-fix-jfb-2.0-1ubuntu2-joinery1.list"
printf '%s\n' "$header" "$LA2" "$LB" > "$ACTIVE"
chk "converge succeeds for the rest" "$(run converge "$SERVER")" "0"
chk "its list is removed" "$(ls "$LISTS" | grep -c jfb)" "0"
chk "it is not pinned" "$(ls "$PREF" | grep -c jfb)" "0"
chk "state: jfb refused" "$(st jfb 7)" "1"
chk "says apt refused it" "$(grep -c 'jfb: apt refused the package source' "$T/out")" "1"
chk "the other is untouched" "$(ls "$PREF" | paste -sd ' ')" "joinery-fix-jfa.pref"
rm -f "$STUB/refuse_"*

echo "apt busy is not apt refusing (reviewer2 G2)"
touch "$STUB/apt_busy"
chk "converge succeeds" "$(run converge "$SERVER")" "0"
chk "the list and the pin stay" "$(ls "$LISTS" | grep -c jfa)|$(ls "$PREF" | grep -c jfa)" "1|1"
chk "not reported refused" "$(st jfa 7)" "0"
chk "says it will try again" "$(grep -c 'jfa: apt could not read the package lists now' "$T/out")" "1"
chk "apt is asked to wait for its lock" "$(grep -c 'DPkg::Lock::Timeout=300' "$STUB/calls")" "$(grep -c 'update -qq' "$STUB/calls")"
rm -f "$STUB/apt_busy"

echo "A machine already on a newer Ubuntu build"
printf 'jfa jfa 1.0-0ubuntu2\n' > "$STUB/dpkg"
printf '%s\n' "$header" "$LA2" > "$ACTIVE"
chk "converge succeeds" "$(run converge "$SERVER")" "0"
chk "no pin (apt would downgrade it)" "$(ls "$PREF" | grep -c jfa)" "0"
chk "state: ahead_of_build names the installed version" "$(st jfa 6)" "1.0-0ubuntu2"
chk "  its changelog could not be read: said (reviewer2 G13)" "$(st jfa 9)" "1.0-0ubuntu2"
mkdeb jfa jfa 1.0-0ubuntu2 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618 and CVE-2026-109619"
mksrc jfa 1.0-0ubuntu2 patch CVE-2026-109618 CVE-2026-109619
echo " jfa | 1.0-0ubuntu2 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
rm -f "$LIB/verdicts/"*
run converge "$SERVER" > /dev/null
chk "a newer Ubuntu build whose changelog names every CVE and whose source carries each fix: handed back, not ahead (reviewer2 G5)" "$(st jfa 4)|$(st jfa 6)" "1.0-0ubuntu2|-"
mkdeb jfa jfa 1.0-0ubuntu2 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618 only"
rm -f "$LIB/verdicts/"*
run converge "$SERVER" > /dev/null
chk "one that lacks a CVE: ahead, and lacking it" "$(st jfa 6)|$(st jfa 5)|$(ls "$PREF" | grep -c jfa)" "1.0-0ubuntu2|1.0-0ubuntu2:CVE-2026-109619|0"
rm -f "$STUB/debs/jfa_1.0-0ubuntu2"*; rm -f "$LIB/verdicts/"*
printf 'jfa jfa 1.0-0ubuntu1\n' > "$STUB/dpkg"
run converge "$SERVER" > /dev/null
chk "back on Ubuntu's base: pinned again" "$(ls "$PREF" | grep -c jfa)" "1"
rm -f "$STUB/madison_jfa"

echo "A container host takes a base source it does not have installed"
touch "$R/container_host"
printf '%s\n' "$header" "$LA2" "$LP" > "$ACTIVE"
chk "converge succeeds" "$(run converge "$SERVER")" "0"
chk "php8.5 is placed and pinned" "$(ls "$PREF" | grep -c 'php8.5')" "1"
chk "the pin file name ends .pref (apt ignores any other)" "$(ls "$PREF" | grep -c '^joinery-fix-php8.5.pref$')" "1"
chk "state: for the base only (reviewer2 G10)" "$(st php8.5 8)" "1"
rm -f "$R/container_host"
run converge "$SERVER" > /dev/null
chk "not a container host: php8.5 goes" "$(ls "$PREF" "$LISTS" | grep -c 'php8.5')" "0"

echo "Fetched from this tree's own static_files first"
mkdir -p "$T/tree/static_files/fixes"
cp "$SRV"/joinery-fix-jfb-* "$T/tree/static_files/fixes/"
printf '%s\n' "$header" "$LA2" "$LB" > "$ACTIVE"
printf 'jfa jfa 1.0-0ubuntu1\njfb jfb 2.0-1ubuntu2\n' > "$STUB/dpkg"
chk "converge succeeds with no server" "$(run converge "file://$T/nowhere")" "0"
chk "jfb came from the tree" "$(grep -c 'placed jfb .*(from local)' "$T/out")" "1"
printf '%s\n' "$header" "$LA2" > "$ACTIVE"
run converge "$SERVER" > /dev/null

echo "Hand-back: Ubuntu's newer build names every CVE"
mkdeb jfa jfa 1.0-0ubuntu1.1 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618_109619-pre1.patch and CVE-2026-109618.patch"
mksrc jfa 1.0-0ubuntu1.1 patch CVE-2026-109618 CVE-2026-109619
echo " jfa | 1.0-0ubuntu1.1 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
echo " jfa | 1.0-0ubuntu1+joinery2 | file:/var/lib/joinery/package-fixes/jfa/1.0-0ubuntu1-joinery2 ./ Packages" >> "$STUB/madison_jfa"
: > "$STUB/calls"
chk "handback succeeds" "$(run handback)" "0"
chk "the combined form counts: the pin is removed" "$(ls "$PREF" | grep -c jfa)" "0"
chk "state: handed back to Ubuntu's version" "$(st jfa 4)" "1.0-0ubuntu1.1"
chk "the named version was downloaded" "$(grep -c 'download -qq jfa=1.0-0ubuntu1.1' "$STUB/calls")" "1"
: > "$STUB/calls"
run handback > /dev/null
chk "the verdict is kept: no second download" "$(grep -c download "$STUB/calls")" "0"
chk "and the pin stays off" "$(ls "$PREF" | grep -c jfa)" "0"
run converge "$SERVER" > /dev/null
chk "converge keeps it handed back" "$(ls "$PREF" | grep -c jfa)" "0"

echo "Hand-back: Ubuntu's newer build lacks one"
mkdeb jfa jfa 1.0-0ubuntu1.2 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618 only; CVE-2026-1096190 is another"
echo " jfa | 1.0-0ubuntu1.2 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
chk "handback succeeds" "$(run handback)" "0"
chk "the pin is back" "$(ls "$PREF" | grep -c jfa)" "1"
chk "state: ubuntu_lacks names the version and the CVE" "$(st jfa 5)" "1.0-0ubuntu1.2:CVE-2026-109619"
chk "a longer number with the same start does not count" "$(grep -c '^verdict=lacks CVE-2026-109619$' "$LIB/verdicts/jfa_1.0-0ubuntu1.2")" "1"

echo "Hand-back: a newer build in -updates alone hands nothing back (reviewer2 G14)"
mkdeb jfa jfa 1.0-0ubuntu1.7 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618_109619"
printf '%s\n' " jfa | 1.0-0ubuntu1.7 | http://archive.ubuntu.com/ubuntu resolute-updates/main amd64 Packages" \
    " jfa | 1.0-0ubuntu1.2 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
run handback > /dev/null
chk "judged on the -security build, which lacks one: the pin stays" "$(st jfa 5)|$(ls "$PREF" | grep -c jfa)" "1.0-0ubuntu1.2:CVE-2026-109619|1"

echo "Hand-back: an older entry is not read"
mkdeb jfa jfa 1.0-0ubuntu1.3 "$STUB/debs" "No security change. CVE-2026-109618 CVE-2026-109619 appear only in the base entry" "1.0-0ubuntu1"
# Its newest entry names both; a build whose only mention is under the base does not count:
d="$T/build/older"; rm -rf "$d"; mkdir -p "$d/DEBIAN" "$d/usr/share/doc/jfa"
printf 'Package: jfa\nVersion: 1.0-0ubuntu1.4\nArchitecture: all\nMaintainer: x <x@example.invalid>\nDescription: f\n' > "$d/DEBIAN/control"
printf 'jfa (1.0-0ubuntu1.4) resolute; urgency=medium\n\n  * No-change rebuild.\n\n -- x <x@e.invalid>  Thu, 19 Nov 2026 12:00:00 +0000\n\njfa (1.0-0ubuntu1) resolute; urgency=medium\n\n  * CVE-2026-109618 CVE-2026-109619\n\n -- x <x@e.invalid>  Mon, 19 Oct 2026 12:00:00 +0000\n' \
    | gzip -9n > "$d/usr/share/doc/jfa/changelog.Debian.gz"
dpkg-deb --root-owner-group -b "$d" "$STUB/debs/jfa_1.0-0ubuntu1.4.deb" > /dev/null
echo " jfa | 1.0-0ubuntu1.4 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
run handback > /dev/null
chk "CVEs named only at or below the base do not count" "$(st jfa 5)" "1.0-0ubuntu1.4:CVE-2026-109618,CVE-2026-109619"
chk "the pin stays" "$(ls "$PREF" | grep -c jfa)" "1"

echo "Hand-back: Ubuntu's .deb cannot be read"
echo " jfa | 1.0-0ubuntu1.9 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
chk "handback succeeds" "$(run handback)" "0"
chk "the pin stays" "$(ls "$PREF" | grep -c jfa)" "1"
chk "says so" "$(grep -c "could not read Ubuntu 1.0-0ubuntu1.9's changelog or source; ours stays" "$T/out")" "1"

echo "Hand-back: Ubuntu's source must carry each fix, not only its changelog name it (owner Q6, reviewer2 H1)"
mkdeb jfa jfa 1.0-0ubuntu1.5 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618 and CVE-2026-109619"
mksrc jfa 1.0-0ubuntu1.5 patch CVE-2026-109618
echo " jfa | 1.0-0ubuntu1.5 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
: > "$STUB/calls"; : > "$STUB/debsrc"
chk "handback succeeds" "$(run handback)" "0"
chk "the changelog names both, the source carries one: the pin stays" "$(ls "$PREF" | grep -c jfa)" "1"
chk "state: lacks the one whose code is missing" "$(st jfa 5)" "1.0-0ubuntu1.5:CVE-2026-109619"
chk "says the changelog named it but the source lacks it" "$(grep -c "Ubuntu 1.0-0ubuntu1.5's changelog names every CVE ours carries, but its source lacks the fix for CVE-2026-109619" "$T/out")" "1"
chk "the source came from the -security pocket the binary did, checked against Ubuntu's key" "$(sort -u "$STUB/debsrc")" \
    "deb-src [signed-by=/usr/share/keyrings/ubuntu-archive-keyring.gpg] http://archive.ubuntu.com/ubuntu resolute-security main"
chk "the named version's source was fetched, into private lists" "$(grep -c 'source -qq --download-only .*Dir::State::Lists=.*package-fix-source.* jfa=1.0-0ubuntu1.5$' "$STUB/calls")" "1"
chk "the machine's own package lists were not touched" "$(grep 'update -qq' "$STUB/calls" | grep -vc 'Dir::State::Lists=')" "0"
mkdeb jfa jfa 1.0-0ubuntu1.6 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618 and CVE-2026-109619"
mksrc jfa 1.0-0ubuntu1.6 removed CVE-2026-109618 CVE-2026-109619
echo " jfa | 1.0-0ubuntu1.6 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
run handback > /dev/null
chk "the fix's line only as a line a patch removes does not count" "$(st jfa 5)" "1.0-0ubuntu1.6:CVE-2026-109618,CVE-2026-109619"
mkdeb jfa jfa 1.0-0ubuntu1.10 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618 and CVE-2026-109619"
echo " jfa | 1.0-0ubuntu1.10 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
run handback > /dev/null
chk "the source cannot be fetched: the pin stays, unreadable" "$(ls "$PREF" | grep -c jfa)|$(st jfa 9)" "1|1.0-0ubuntu1.10"
chk "and nothing is kept, so the next run tries again" "$(ls "$LIB/verdicts" | grep -c '1.0-0ubuntu1.10')" "0"
mkdeb jfa jfa 1.0-0ubuntu1.11 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618 and CVE-2026-109619"
mksrc jfa 1.0-0ubuntu1.11 patch CVE-2026-109618 CVE-2026-109619
echo " jfa | 1.0-0ubuntu1.11 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
LA3="$(NOFP=1 mkfix jfa 1.0-0ubuntu1+joinery3 1.0-0ubuntu1 'CVE-2026-109618 CVE-2026-109619' jfa jfa-extra)"
printf '%s\n' "$header" "$LA3" > "$ACTIVE"
run converge "$SERVER" > /dev/null
chk "a build with no fingerprint proves nothing: it lacks every CVE" "$(st jfa 5)" "1.0-0ubuntu1.11:CVE-2026-109618,CVE-2026-109619"
printf '%s\n' "$header" "$LA2" > "$ACTIVE"
run converge "$SERVER" > /dev/null
chk "the same Ubuntu build, a fix with fingerprints: judged again, not from the kept verdict" "$(st jfa 4)|$(ls "$PREF" | grep -c jfa)" "1.0-0ubuntu1.11|0"
mkdeb jfa jfa 1.1-0ubuntu1 "$STUB/debs" "New upstream release 1.1, fixes CVE-2026-109618 and CVE-2026-109619"
mksrc jfa 1.1-0ubuntu1 orig CVE-2026-109618 CVE-2026-109619
echo " jfa | 1.1-0ubuntu1 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
run handback > /dev/null
chk "a new upstream version: its tarball is read, and carries both" "$(st jfa 4)" "1.1-0ubuntu1"
mksrc jfa 1.0-0ubuntu1.12 orig CVE-2026-109618 CVE-2026-109619
mkdeb jfa jfa 1.0-0ubuntu1.12 "$STUB/debs" "SECURITY UPDATE: CVE-2026-109618 and CVE-2026-109619"
echo " jfa | 1.0-0ubuntu1.12 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfa"
run handback > /dev/null
chk "the same upstream version: its tarball is Ubuntu's base, so not read" "$(st jfa 5)|$(ls "$PREF" | grep -c jfa)" "1.0-0ubuntu1.12:CVE-2026-109618,CVE-2026-109619|1"
chk "build.sh picks fingerprints in the form this script reads them" \
    "$(grep -E '^(normal_form|units)\(\)' "$ROOT/maintenance_scripts/install_tools/fixes/build.sh")" \
    "$(grep -E '^(normal_form|units)\(\)' "$SCRIPT")"

echo "An ACTIVE line that is not whole"
before="$(ls "$PREF" "$LISTS" "$LIB" | paste -sd ' ')"
printf '%s\n' "$header" "$LA2" "source=jfb version=2.0 archive=x" > "$ACTIVE"
chk "converge refuses" "$(run converge "$SERVER")" "1"
chk "says which line" "$(grep -c 'has a line that is not whole: source=jfb' "$T/out")" "1"
chk "and removes nothing it did not read" "$(ls "$PREF" "$LISTS" "$LIB" | paste -sd ' ')" "$before"
printf '%s\n' "$header" "$LA2" > "$ACTIVE"

echo "No fix listed: the origin drop-in goes"
printf '%s\n' "$header" > "$ACTIVE"
run converge "$SERVER" > /dev/null
chk "removed with the last list" "$([ -e "$OC" ] && echo kept || echo removed)|$(ls "$LISTS" | wc -l | tr -d ' ')" "removed|0"
printf '%s\n' "$header" "$LA2" > "$ACTIVE"
run converge "$SERVER" > /dev/null

echo "status"
chk "prints the state" "$(bash "$TREE/install_package_fixes.sh" status | grep -c '^fix=jfa|1.0-0ubuntu1+joinery2|')" "1"

echo "The package fix channel (specs/package_fixes_automatic.md WP6, WP8)"
CH="$T/srv/static_files/fixes/channel"
mkdir -p "$CH"
cat > "$T/bin/verify-stub" <<'EOF2'
#!/bin/bash
[ -f "$STUB/verify_ok" ] && { echo "verdict: signed: ok"; exit 0; }
echo "verdict: unknown_key: not signed by a key this machine trusts"; exit 1
EOF2
chmod 755 "$T/bin/verify-stub"
export PACKAGE_FIXES_VERIFY="$T/bin/verify-stub"
LW1="$(mkfix jfw 5.0-1+joinery1 5.0-1 'CVE-2026-7777' jfw)"
LW2="$(mkfix jfw 5.0-1+joinery2 5.0-1 'CVE-2026-7777 CVE-2026-7778' jfw)"
mkchannel() {  # SERIAL WITHDRAWN-LINE ACTIVE-LINES...
    local serial="$1" w="$2"; shift 2
    printf '%s\n' "# channel" "$@" > "$CH/ACTIVE"
    printf '%s\n' "# withdrawn" ${w:+"$w"} > "$CH/WITHDRAWN"
    printf 'serial=%s\nissued=2026-11-22T12:00:00Z\nwide=yes\nrelease=0.8.641\n' "$serial" > "$CH/CHANNEL"
    for f in RELEASE_MANIFEST RELEASE_MANIFEST.sig RELEASE_STATEMENT; do echo x > "$CH/$f"; done
}
printf '%s\n' "$header" "$LA2" > "$ACTIVE"
printf 'jfa jfa 1.0-0ubuntu1+joinery2\njfw jfw 5.0-1+joinery2\n' > "$STUB/dpkg"
mkchannel 3 "" "$LA2" "$LW2"
rm -f "$STUB/verify_ok"
chk "a channel that does not verify is not taken" "$(run converge "$SERVER")|$(grep -c 'is not ours: verdict: unknown_key' "$T/out")|$([ -d "$LIB/channel" ] && echo taken || echo none)" "0|1|none"
touch "$STUB/verify_ok"
chk "one that verifies is taken" "$(run converge "$SERVER")|$(grep -c 'took the package fix channel, serial 3' "$T/out")" "0|1"
chk "and its ACTIVE is the set: a fix only it names is placed and pinned" "$(sed -n 's/^Pin: //p' "$PREF/joinery-fix-jfw.pref")" "version 5.0-1+joinery2"
chk "the state carries the channel serial" "$(grep -c '^channel=3$' "$LIB/state")" "1"
chk "the channel folder is kept across runs, never removed as a stale fix" "$(run converge "$SERVER")|$(cat "$LIB/channel/CHANNEL" | sed -n 's/^serial=//p')" "0|3"
mkchannel 2 "" "$LA2"
chk "a lower serial (an old channel replayed) is refused, the one in force stays" \
    "$(run converge "$SERVER")|$(grep -c 'serial 2, older than serial 3 in force; refused' "$T/out")|$(sed -n 's/^serial=//p' "$LIB/channel/CHANNEL")|$(ls "$PREF" | grep -c jfw)" "0|1|3|1"
: > "$STUB/calls"
mkchannel 4 "jfw 5.0-1+joinery2" "$LA2" "$LW1"
chk "a withdrawal is taken" "$(run converge "$SERVER")|$(sed -n 's/^serial=//p' "$LIB/channel/CHANNEL")" "0|4"
chk "the build offered instead is pinned" "$(sed -n 's/^Pin: //p' "$PREF/joinery-fix-jfw.pref")" "version 5.0-1+joinery1"
chk "converge only records the withdrawn build, installing nothing" "$(grep -c 'allow-downgrades' "$STUB/calls")|$(cat "$LIB/rollback_due")" "0|jfw 5.0-1+joinery2 jfw=5.0-1+joinery1"
chk "rollback installs it back down, non-interactive, with no stdin" "$(bash "$TREE/install_package_fixes.sh" rollback < "$CH/WITHDRAWN" > "$T/out" 2>&1; echo $?)|$(grep -c 'install -y -qq --allow-downgrades .* jfw=5.0-1+joinery1' "$STUB/calls")|$([ -s "$LIB/rollback_due" ] && echo left || echo done)" "0|1|done"
run converge "$SERVER" > /dev/null
chk "the state records it" "$(grep -c '^rolled_back=jfw|5.0-1+joinery2|5.0-1+joinery1$' "$LIB/state")" "1"
: > "$STUB/calls"
mkchannel 5 "jfw 5.0-1+joinery2" "$LA2"
echo " jfw | 5.0-1ubuntu0.1 | http://archive.ubuntu.com/ubuntu resolute-security/main amd64 Packages" > "$STUB/madison_jfw"
printf 'jfa jfa 1.0-0ubuntu1+joinery2\njfw jfw 5.0-1+joinery2\n' > "$STUB/dpkg"
chk "with no fix left for the source, it goes back to Ubuntu's newest -security build" \
    "$(run converge "$SERVER")|$(bash "$TREE/install_package_fixes.sh" rollback > /dev/null 2>&1; grep -c 'install -y -qq --allow-downgrades .* jfw=5.0-1ubuntu0.1' "$STUB/calls")|$(ls "$PREF" | grep -c jfw)" "0|1|0"
: > "$STUB/calls"
printf 'jfa jfa 1.0-0ubuntu1+joinery2\njfw jfw 5.0-1+joinery2\n' > "$STUB/dpkg"
echo " jfw | 5.0-1 | http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages" > "$STUB/madison_jfw"
mkchannel 6 "jfw 5.0-1+joinery2" "$LA2"
chk "with no -security build either, Ubuntu's newest in any pocket (reviewer2 B8)" \
    "$(run converge "$SERVER")|$(cat "$LIB/rollback_due")" "0|jfw 5.0-1+joinery2 jfw=5.0-1"
printf 'jfa jfa 1.0-0ubuntu1+joinery2\n' > "$STUB/dpkg"
run converge "$SERVER" > /dev/null

echo "The highest serial any server offers wins (reviewer2 B12)"
SRV2="$T/srv2/static_files/fixes/channel"
mkdir -p "$SRV2"
cp "$CH"/* "$SRV2/"
sed -i 's/^serial=.*/serial=9/' "$SRV2/CHANNEL"
chk "a later server with a higher serial is taken over an earlier one" \
    "$(PACKAGE_FIXES_UPGRADE_SOURCE="$SERVER" UPGRADE_SERVER="file://$T/srv2" run converge)|$(sed -n 's/^serial=//p' "$LIB/channel/CHANNEL")" "0|9"
echo "A first channel issued before this tree's release is refused"
mkdir -p "$T/tree/public_html"; echo "0.8.700" > "$T/tree/public_html/VERSION"
mv "$LIB/channel" "$T/channel.kept"
chk "refused, saying why" "$(run converge "$SERVER")|$(grep -c "issued on release 0.8.641, before this tree's 0.8.700; refused" "$T/out")|$([ -d "$LIB/channel" ] && echo taken || echo none)" "0|1|none"
rm -f "$T/tree/public_html/VERSION"; mv "$T/channel.kept" "$LIB/channel"
printf 'jfa jfa 1.0-0ubuntu1+joinery2\n' > "$STUB/dpkg"
unset PACKAGE_FIXES_VERIFY

echo "A box that serves a channel takes its own copy only (reviewer2 B20)"
export PACKAGE_FIXES_VERIFY="$T/bin/verify-stub"
LOC="$T/tree/static_files/fixes/channel"
mkdir -p "$LOC"
cp "$CH"/* "$LOC/"; sed -i 's/^serial=.*/serial=10/' "$LOC/CHANNEL"
cp "$CH"/* "$SRV2/"; sed -i 's/^serial=.*/serial=11/' "$SRV2/CHANNEL"
chk "a mirror takes its own serial 10, not its upstream's 11" \
    "$(PACKAGE_FIXES_ROLE=mirror PACKAGE_FIXES_UPGRADE_SOURCE="file://$T/srv2" run converge)|$(sed -n 's/^serial=//p' "$LIB/channel/CHANNEL")" "0|10"
chk "a box that serves nothing ignores a stale local folder and asks its servers (reviewer2 B30)" \
    "$(PACKAGE_FIXES_ROLE=none PACKAGE_FIXES_UPGRADE_SOURCE="file://$T/srv2" run converge)|$(sed -n 's/^serial=//p' "$LIB/channel/CHANNEL")" "0|11"
rm -rf "$LOC"
sed -i 's/^serial=.*/serial=12/' "$SRV2/CHANNEL"
chk "a site whose role cannot be told (database down) takes no channel this run (reviewer2 B31)" \
    "$(PACKAGE_FIXES_ROLE=unknown PACKAGE_FIXES_UPGRADE_SOURCE="file://$T/srv2" run converge)|$(sed -n 's/^serial=//p' "$LIB/channel/CHANNEL")" "0|11"
chk "a mirror whose runner has served nothing yet takes nothing from its upstream (reviewer2 B29)" \
    "$(PACKAGE_FIXES_ROLE=mirror PACKAGE_FIXES_UPGRADE_SOURCE="file://$T/srv2" run converge)|$(sed -n 's/^serial=//p' "$LIB/channel/CHANNEL")" "0|11"
unset PACKAGE_FIXES_VERIFY

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
