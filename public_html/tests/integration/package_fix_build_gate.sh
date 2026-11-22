#!/bin/bash
# @joinery-test
# name: package_fix_build
# tier: safe
# env: any
# needs: []
# timeout: 600
# covers: [maintenance_scripts/install_tools/fixes/build.sh]
#
# Package fixes ahead of Ubuntu (specs/package_fixes_ahead_of_ubuntu.md WP1).
# build.sh takes Ubuntu's source for one package, adds upstream's patches, and
# builds every binary that source makes, tagged <ubuntu version>+joinery<N>,
# as a flat repository apt can read. This gate builds a small fixture source
# package (two binaries, one Ubuntu patch already in its series) in build.sh's
# test mode, which takes the source from a directory instead of apt and
# installs no build dependencies, and checks what it refuses. It builds the
# same fixture on a signed upstream release (upstream_release, a throwaway key
# made here), and walks Ubuntu's series on a fixture tree (series_walk).
#
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
BUILD="$ROOT/maintenance_scripts/install_tools/fixes/build.sh"
for tool in dpkg-buildpackage dpkg-source dpkg-scanpackages dh fakeroot gpg gpgv; do
    command -v "$tool" > /dev/null || { echo "  SKIP: no ${tool} on this box"; echo "RESULT: 0 passed, 0 failed"; exit 0; }
done
T="$(mktemp -d /var/tmp/package-fix-build-gate.XXXXXX)"
trap '[ -n "${KEEP:-}" ] || rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# --- The fixture source: jfixture 1.0-0ubuntu1 --------------------------------
mkdir -p "$T/pkg/jfixture-1.0"
P="$T/pkg/jfixture-1.0"
printf 'greeting=hello\nflaw=present\n' > "$P/hello.cfg"
mkdir -p "$P/suite/tests"; echo 'a test of a part no node uses' > "$P/suite/tests/t1"
tar -C "$T/pkg" -czf "$T/pkg/jfixture_1.0.orig.tar.gz" jfixture-1.0
mkdir -p "$P/debian/source" "$P/debian/patches"
echo '3.0 (quilt)' > "$P/debian/source/format"
cat > "$P/debian/control" <<'EOF'
Source: jfixture
Section: misc
Priority: optional
Maintainer: Fixture <fixture@example.invalid>
Build-Depends: debhelper-compat (= 13)
Standards-Version: 4.6.2

Package: jfixture
Architecture: all
Depends: ${misc:Depends}
Description: fixture package
 A fixture.

Package: jfixture-extra
Architecture: all
Depends: jfixture (= ${binary:Version}), ${misc:Depends}
Description: fixture extra package
 A fixture that depends on its sibling at the exact version.
EOF
printf '%s\n' '#!/usr/bin/make -f' '%:' '	dh $@' 'override_dh_auto_test:' '	grep -q "^greeting=hello, Ubuntu" hello.cfg' > "$P/debian/rules"
chmod 755 "$P/debian/rules"
echo 'hello.cfg usr/share/jfixture/' > "$P/debian/jfixture.install"
echo 'hello.cfg usr/share/jfixture-extra/' > "$P/debian/jfixture-extra.install"
cat > "$P/debian/changelog" <<'EOF'
jfixture (1.0-0ubuntu1) resolute-security; urgency=medium

  * SECURITY UPDATE: CVE-2026-1000
    - debian/patches/CVE-2026-1000.patch: say hello to Ubuntu.

 -- Fixture <fixture@example.invalid>  Mon, 19 Oct 2026 12:00:00 +0000
EOF
cat > "$P/debian/patches/CVE-2026-1000.patch" <<'EOF'
Description: Ubuntu's own patch
--- a/hello.cfg
+++ b/hello.cfg
@@ -1,2 +1,2 @@
-greeting=hello
+greeting=hello, Ubuntu
 flaw=present
EOF
echo 'CVE-2026-1000.patch' > "$P/debian/patches/series"
(cd "$T/pkg" && dpkg-source -q -b jfixture-1.0 > /dev/null 2>&1) || { echo "  FAIL: could not make the fixture source"; echo "RESULT: 0 passed, 1 failed"; exit 1; }
SRCDIR="$T/srcdir"
mkdir "$SRCDIR"
cp "$T/pkg/jfixture_1.0.orig.tar.gz" "$T/pkg/jfixture_1.0-0ubuntu1.debian.tar.xz" "$T/pkg/jfixture_1.0-0ubuntu1.dsc" "$SRCDIR/"
DSC_SHA="$(sha256sum "$SRCDIR/jfixture_1.0-0ubuntu1.dsc" | awk '{print $1}')"

# --- A recipe -------------------------------------------------------------------
fix_patch() {  # PATH [ORIGIN] [TEST] [FINGERPRINT]
    {
        echo "Description: remove the flaw"
        [ -n "${2:-}" ] && echo "Origin: $2"
        [ -n "${3:-}" ] && echo "Joinery-Test: $3"
        [ -n "${4:-}" ] && echo "Joinery-Fingerprint: $4"
        printf '%s\n' '--- a/hello.cfg' '+++ b/hello.cfg' '@@ -1,2 +1,3 @@' ' greeting=hello, Ubuntu' '-flaw=present' '+bounds_checked=yes, by upstream' '+flaw=fixed'
    } > "$1"
}
recipe() {  # DIR (writes a good recipe; callers spoil it)
    rm -rf "$1"; mkdir -p "$1/patches"
    cat > "$1/RECIPE" <<EOF
state=active
ubuntu_source=jfixture 1.0-0ubuntu1
dsc_sha256=${DSC_SHA}
revision=1
cves=CVE-2026-2000
changelog_from=jfixture
date=2026-11-20
test=default
EOF
    fix_patch "$1/patches/0001-CVE-2026-2000.patch" "https://example.invalid/upstream/commit/abc123" "grep -q '^flaw=fixed' hello.cfg"
}
build() {  # RECIPE_DIR OUT_DIR -> exit code; output in $T/out
    FIX_BUILD_TEST=1 FIX_BUILD_SOURCE_DIR="$SRCDIR" FIX_BUILD_WORK_BASE="$T" bash "$BUILD" "$1" "$2" > "$T/out" 2>&1
    echo $?
}
set_key() { sed -i "s|^$2=.*|$2=$3|" "$1/RECIPE"; }
outcount() { find "$1" -type f 2>/dev/null | wc -l | tr -d ' '; }

echo "A good recipe"
R="$T/r/jfixture"; recipe "$R"
chk "builds" "$(build "$R" "$T/o1")" "0"
NAME="joinery-fix-jfixture-1.0-0ubuntu1+joinery1-$(dpkg --print-architecture)"
chk "leaves the archive and its .sha256, nothing else" "$(ls "$T/o1" | paste -sd ' ')" "${NAME}.tar.xz ${NAME}.tar.xz.sha256"
chk "the .sha256 matches" "$(cd "$T/o1" && sha256sum -c --quiet "${NAME}.tar.xz.sha256" > /dev/null 2>&1; echo $?)" "0"
mkdir "$T/x1"; tar -C "$T/x1" -xJf "$T/o1/${NAME}.tar.xz"
X="$T/x1/$NAME"
chk "every binary of the source ships" "$(ls "$X"/*.deb | xargs -n1 basename | paste -sd ' ')" \
    "jfixture-extra_1.0-0ubuntu1+joinery1_all.deb jfixture_1.0-0ubuntu1+joinery1_all.deb"
chk "FIXINFO names the binaries" "$(sed -n 's/^binaries=//p' "$X/FIXINFO")" "jfixture jfixture-extra"
chk "FIXINFO names the version" "$(sed -n 's/^version=//p' "$X/FIXINFO")" "1.0-0ubuntu1+joinery1"
chk "FIXINFO names the Ubuntu base" "$(sed -n 's/^ubuntu_base=//p' "$X/FIXINFO")" "1.0-0ubuntu1"
chk "FIXINFO holds the patch's fingerprint: its added line Ubuntu's base lacks, not one too short to tell apart (owner Q6)" \
    "$(sed -n 's/^fingerprint=0001-CVE-2026-2000.patch //p' "$X/FIXINFO" | base64 -d | paste -sd '|')" "bounds_checked=yes, by upstream"
chk "the sibling depends on the new version" "$(dpkg-deb -f "$X/jfixture-extra_1.0-0ubuntu1+joinery1_all.deb" Depends)" "jfixture (= 1.0-0ubuntu1+joinery1)"
chk "the fix is in the package" "$(dpkg-deb --fsys-tarfile "$X/jfixture_1.0-0ubuntu1+joinery1_all.deb" | tar -xOf - ./usr/share/jfixture/hello.cfg | paste -sd ' ')" "greeting=hello, Ubuntu bounds_checked=yes, by upstream flaw=fixed"
cl="$(dpkg-deb --fsys-tarfile "$X/jfixture_1.0-0ubuntu1+joinery1_all.deb" | tar -xOf - ./usr/share/doc/jfixture/changelog.Debian.gz | gzip -dc)"
chk "the changelog's newest entry is ours" "$(head -n 1 <<< "$cl")" "jfixture (1.0-0ubuntu1+joinery1) resolute-joinery; urgency=high"
chk "the changelog entry names the CVE" "$(grep -c '^    - CVE-2026-2000$' <<< "$cl")" "1"
chk "Ubuntu's entry is kept beneath" "$(grep -c '^jfixture (1.0-0ubuntu1) ' <<< "$cl")" "1"
for f in 'Origin: Joinery-Fixes' 'Label: Joinery-Fixes' 'Suite: resolute-joinery'; do
    chk "Release has '$f'" "$(grep -cx "$f" "$X/Release")" "1"
done
chk "Release hashes Packages" "$(awk '/^ [0-9a-f]{64} / {print $1}' "$X/Release")" "$(sha256sum "$X/Packages" | awk '{print $1}')"
chk "Packages lists both binaries" "$(grep -c '^Package: ' "$X/Packages")" "2"

echo "Real apt reads the repository, and the pin wins"
A="$T/apt"
mkdir -p "$A/state/lists/partial" "$A/cache/archives/partial" "$A/prefs" "$A/fix"
cp -a "$X"/. "$A/fix/"
: > "$A/status"
echo "deb [trusted=yes] file:$A/fix/ ./" > "$A/list"
printf 'Package: jfixture jfixture-extra\nPin: version 1.0-0ubuntu1+joinery1\nPin-Priority: 1001\n' > "$A/prefs/joinery-fix-jfixture.pref"
aptq() { "$@" -o Dir::State="$A/state" -o Dir::State::status="$A/status" -o Dir::Cache="$A/cache" -o Dir::Etc::sourcelist="$A/list" \
    -o Dir::Etc::sourceparts=- -o Dir::Etc::preferencesparts="$A/prefs" -o Dir::Etc::preferences="$A/none" -o Debug::NoLocking=1 \
    -o APT::Sandbox::User="$(id -un)"; }
aptq apt-get update -qq > "$T/aptout" 2>&1
chk "apt-get update accepts it" "$?:$(grep -c '^[EW]:' "$T/aptout")" "0:0"
pol="$(aptq apt-cache policy jfixture-extra 2>&1)"
chk "the candidate is ours" "$(awk '/Candidate:/ {print $2}' <<< "$pol")" "1.0-0ubuntu1+joinery1"
chk "at priority 1001" "$(grep -cE '^ +(\*\*\* )?1\.0-0ubuntu1\+joinery1 1001$' <<< "$pol")" "1"
chk "apt sees Origin and Label Joinery-Fixes, Suite resolute-joinery" \
    "$(aptq apt-cache policy 2>&1 | grep -c 'release o=Joinery-Fixes,a=resolute-joinery,n=resolute-joinery,l=Joinery-Fixes')" "1"

printf 'Package: jfixture\nStatus: install ok installed\nPriority: optional\nSection: misc\nMaintainer: x <x@example.invalid>\nArchitecture: all\nVersion: 1.0-0ubuntu1\nDescription: f\n' > "$A/status"
sim="$(aptq apt-get -s dist-upgrade 2>&1 | grep '^Inst jfixture ')"
chk "a simulated upgrade names it Joinery-Fixes:resolute-joinery, the word security_check.sh reads" \
    "$sim" "Inst jfixture [1.0-0ubuntu1] (1.0-0ubuntu1+joinery1 Joinery-Fixes:resolute-joinery [all])"

echo "The same recipe built twice"
chk "builds again" "$(build "$R" "$T/o2")" "0"
chk "gives the same bytes" "$(cat "$T/o2/${NAME}.tar.xz.sha256" | awk '{print $1}')" "$(awk '{print $1}' "$T/o1/${NAME}.tar.xz.sha256")"

echo "A revision"
set_key "$R" revision 2
chk "builds" "$(build "$R" "$T/o3")" "0"
chk "is tagged +joinery2" "$(ls "$T/o3" | grep -c 'joinery-fix-jfixture-1.0-0ubuntu1+joinery2-')" "2"
set_key "$R" revision 1

echo "Two patches, the second on top of the first"
recipe "$R"
printf '%s\n' 'Origin: https://example.invalid/c/2' '--- a/hello.cfg' '+++ b/hello.cfg' '@@ -2,2 +2,2 @@' ' bounds_checked=yes, by upstream' '-flaw=fixed' '+flaw=fixed twice, by a second commit' \
    > "$R/patches/0002-CVE-2026-2001.patch"
set_key "$R" cves "CVE-2026-2000 CVE-2026-2001"
chk "builds" "$(build "$R" "$T/o4")" "0"
mkdir "$T/x4"; tar -C "$T/x4" -xJf "$T/o4/${NAME}.tar.xz"
chk "both are in the package, in order" "$(dpkg-deb --fsys-tarfile "$T/x4/$NAME/jfixture_1.0-0ubuntu1+joinery1_all.deb" | tar -xOf - ./usr/share/jfixture/hello.cfg | tail -n 1)" "flaw=fixed twice, by a second commit"
chk "FIXINFO names both CVEs" "$(sed -n 's/^cves=//p' "$T/x4/$NAME/FIXINFO")" "CVE-2026-2000 CVE-2026-2001"
chk "each patch has its own fingerprint" "$(sed -n 's/^fingerprint=\([^ ]*\) .*/\1/p' "$T/x4/$NAME/FIXINFO" | sort -u | paste -sd ' ')" "0001-CVE-2026-2000.patch 0002-CVE-2026-2001.patch"

echo "A fingerprint named by hand"
recipe "$R"; fix_patch "$R/patches/0001-CVE-2026-2000.patch" "https://example.invalid/c/1" "" "flaw=fixed"
chk "builds" "$(build "$R" "$T/o5")" "0"
mkdir "$T/x5"; tar -C "$T/x5" -xJf "$T/o5/${NAME}.tar.xz"
chk "the named line is the fingerprint, and only it" "$(sed -n 's/^fingerprint=0001-CVE-2026-2000.patch //p' "$T/x5/$NAME/FIXINFO" | base64 -d | paste -sd '|')" "flaw=fixed"

echo "Test folders a recipe leaves out (test_skip)"
recipe "$R"; fix_patch "$R/patches/0001-CVE-2026-2000.patch" "https://example.invalid/upstream/commit/abc123" "test -d suite/tests"
chk "without test_skip, the folder stays" "$(build "$R" "$T/o6")" "0"
recipe "$R"; echo 'test_skip=suite/tests' >> "$R/RECIPE"
fix_patch "$R/patches/0001-CVE-2026-2000.patch" "https://example.invalid/upstream/commit/abc123" "test ! -e suite/tests && grep -q '^flaw=fixed' hello.cfg"
chk "with test_skip, it builds and the folder is gone" "$(build "$R" "$T/o7")" "0"
chk "it says which folder it left out" "$(grep -c 'left out the tests in suite/tests/' "$T/out")" "1"

echo "What build.sh refuses"
refuse() {  # LABEL RECIPE_DIR WANT_IN_OUTPUT
    local o="$T/refused-$RANDOM"
    chk "$1: refused" "$(build "$2" "$o")" "1"
    chk "$1: nothing left in OUT_DIR" "$(outcount "$o")" "0"
    chk "$1: says why" "$(grep -c -- "$3" "$T/out")" "1"
}
recipe "$R"; set_key "$R" state none
refuse "state=none" "$R" "not active"
recipe "$R"; set_key "$R" dsc_sha256 "$(printf '0%.0s' $(seq 64))"
refuse "a .dsc that is not the recipe's" "$R" "not the recipe's"
recipe "$R"; fix_patch "$R/patches/0001-CVE-2026-2000.patch" ""
refuse "a patch with no Origin" "$R" "names no 'Origin:'"
recipe "$R"; sed -i 's/^-flaw=present/-flaw=missing/' "$R/patches/0001-CVE-2026-2000.patch"
refuse "a patch that does not apply" "$R" "does not apply cleanly"
recipe "$R"; fix_patch "$R/patches/0001-CVE-2026-2000.patch" "https://example.invalid/c/1" "grep -q '^flaw=gone' hello.cfg"
refuse "a patch whose own test fails" "$R" "test failed"
recipe "$R"; set_key "$R" changelog_from jfixture-docs
refuse "changelog_from naming no built package" "$R" "made no jfixture-docs package"
recipe "$R"; set_key "$R" test "false"
refuse "a recipe test command that fails" "$R" "the recipe's test failed"
recipe "$R"; set_key "$R" cves "CVE-26-1"
refuse "a malformed CVE id" "$R" "is not a CVE id"
recipe "$R"; fix_patch "$R/patches/0001-CVE-2026-2000.patch" "https://example.invalid/c/1" "" "flaw=present"
refuse "a named fingerprint the patch does not add" "$R" "is not a line the patch adds"
recipe "$R"; printf '%s\n' 'Origin: https://example.invalid/c/1' 'Joinery-Fingerprint: greeting=hello, Ubuntu' '--- a/hello.cfg' '+++ b/hello.cfg' '@@ -1,2 +1,3 @@' \
    ' greeting=hello, Ubuntu' '+greeting=hello, Ubuntu' '-flaw=present' '+flaw=fixed' > "$R/patches/0001-CVE-2026-2000.patch"
refuse "a named fingerprint Ubuntu's base already has" "$R" "is already in Ubuntu's"
recipe "$R"; printf '%s\n' 'Origin: https://example.invalid/c/1' '--- a/hello.cfg' '+++ b/hello.cfg' '@@ -1,2 +1,2 @@' \
    ' greeting=hello, Ubuntu' '-flaw=present' '+flaw=fixed' > "$R/patches/0001-CVE-2026-2000.patch"
refuse "a patch with nothing to tell it apart" "$R" "adds no code found nowhere in Ubuntu's"
recipe "$R"; rm "$R/patches/0001-CVE-2026-2000.patch"
refuse "no patches" "$R" "has no patches"
recipe "$R"; mv "$R/patches/0001-CVE-2026-2000.patch" "$R/patches/fix.patch"
refuse "a patch not named NNNN-" "$R" "is not named NNNN-"
recipe "$R"; echo 'test_skip=../suite/tests' >> "$R/RECIPE"
refuse "a test_skip path leaving the source" "$R" "is not a source path ending in /tests"
recipe "$R"; echo 'test_skip=suite' >> "$R/RECIPE"
refuse "a test_skip path that is not a tests folder" "$R" "is not a source path ending in /tests"
recipe "$R"; echo 'test_skip=nowhere/tests' >> "$R/RECIPE"
refuse "a test_skip folder the source lacks" "$R" "has no nowhere/tests/"
R2="$T/r/elsewhere"; recipe "$R2"
refuse "a recipe outside its source's directory" "$R2" "sits in elsewhere/"

echo "Who wrote the recipe (made_by)"
chk "a recipe without it: FIXINFO's keys as always" "$(cut -d= -f1 "$X/FIXINFO" | uniq | paste -sd ' ')" "source version ubuntu_base cves changelog_from binaries fingerprint"
recipe "$R"; echo 'made_by=automatic' >> "$R/RECIPE"
chk "made_by=automatic builds" "$(build "$R" "$T/o8")" "0"
mkdir "$T/x8"; tar -C "$T/x8" -xJf "$T/o8/${NAME}.tar.xz"
chk "FIXINFO says made_by=automatic" "$(sed -n 's/^made_by=//p' "$T/x8/$NAME/FIXINFO")" "automatic"
recipe "$R"; echo 'made_by=robot' >> "$R/RECIPE"
refuse "made_by neither automatic nor person" "$R" "made_by must be automatic or person"
recipe "$R"; echo 'made_by=' >> "$R/RECIPE"
refuse "an empty made_by" "$R" "made_by must be automatic or person"

echo "Ubuntu's series on upstream's release (series_walk)"
W="$T/walk"; mkdir -p "$W/debian/patches"
printf 'one\ntwo\n' > "$W/a.txt"; printf 'three\n' > "$W/b.txt"
wpatch() {  # NAME FILE OLD NEW
    printf '%s\n' "--- a/$2" "+++ b/$2" '@@ -1 +1 @@' "-$3" "+$4" > "$W/debian/patches/$1"
}
printf '%s\n' '--- a/a.txt' '+++ b/a.txt' '@@ -1,2 +1,2 @@' ' one' '-two' '+two, fixed upstream' > "$W/debian/patches/in-upstream.patch"
printf 'one\ntwo, fixed upstream\n' > "$W/a.txt"
wpatch still-needed.patch b.txt three 'three, Ubuntu'
wpatch on-top.patch b.txt 'three, Ubuntu' 'three, Ubuntu twice'
printf '%s\n' '# Ubuntu comment' 'in-upstream.patch' '' 'still-needed.patch' 'on-top.patch -p1' > "$W/debian/patches/series"
cp -a "$W" "$T/walk-bad"
walk() { (. "$BUILD"; series_walk "$1" 1.1) > "$T/out" 2>&1; echo $?; }
chk "walks" "$(walk "$W")" "0"
chk "the patch upstream has leaves the series; the rest stay, in order, comments kept" "$(paste -sd '|' "$W/debian/patches/series")" "# Ubuntu comment||still-needed.patch|on-top.patch -p1"
chk "it says which it left out" "$(grep -c "left out Ubuntu's in-upstream.patch: upstream's 1.1 has it already" "$T/out")" "1"
chk "the kept ones are applied, each on the one before" "$(cat "$W/b.txt")" "three, Ubuntu twice"
chk "the one upstream has is not applied twice" "$(paste -sd '|' "$W/a.txt")" "one|two, fixed upstream"
W="$T/walk-bad"; printf 'thirty\n' > "$W/b.txt"
chk "a patch that neither applies nor is upstream's: refused" "$(walk "$W")" "1"
chk "names it" "$(grep -c "Ubuntu's still-needed.patch neither applies to upstream's 1.1 nor is in it already" "$T/out")" "1"
chk "the series is left as it was" "$(paste -sd '|' "$W/debian/patches/series")" "# Ubuntu comment|in-upstream.patch||still-needed.patch|on-top.patch -p1"
W="$T/walk-bad"; printf 'three\n' > "$W/b.txt"; echo 'other.patch -R' >> "$W/debian/patches/series"; touch "$W/debian/patches/other.patch"
chk "a series option it does not read: refused" "$(walk "$W")" "1"
chk "says so" "$(grep -c "the option '-R'" "$T/out")" "1"
W="$T/walk-bad"; sed -i '$d' "$W/debian/patches/series"; echo 'missing.patch' >> "$W/debian/patches/series"
chk "a series line naming no file: refused" "$(walk "$W")" "1"

echo "A fix on upstream's release (upstream_release)"
# A copy of build.sh with the project's key beside it, as in the build root.
FX="$T/fixes"; mkdir -p "$FX/upstream_keys"; cp "$BUILD" "$FX/build.sh"
G="$T/gnupg"; mkdir -m 700 "$G"
gpgq() { GNUPGHOME="$G" gpg --batch --quiet --pinentry-mode loopback --passphrase '' "$@"; }
gpgq --quick-gen-key 'Fixture Upstream <upstream@example.invalid>' ed25519 sign never > /dev/null 2>&1
gpgq --armor --export upstream@example.invalid > "$FX/upstream_keys/jfixture.asc"
UPD="$T/upstream"; mkdir -p "$UPD/jfixture-1.1/suite/tests"
# Upstream's 1.1 has Ubuntu's patch already, and a file of its own.
printf 'greeting=hello, Ubuntu\nflaw=present\n' > "$UPD/jfixture-1.1/hello.cfg"
echo 'a test of a part no node uses' > "$UPD/jfixture-1.1/suite/tests/t1"
echo 'upstream 1.1' > "$UPD/jfixture-1.1/RELEASE"
tar -C "$UPD" -czf "$UPD/jfixture-1.1.tar.gz" jfixture-1.1
gpgq --armor --detach-sign -o "$UPD/jfixture-1.1.tar.gz.asc" "$UPD/jfixture-1.1.tar.gz"
GNUPGHOME="$G" gpgconf --kill gpg-agent > /dev/null 2>&1 || true
UPLINE='upstream_release=1.1 https://example.invalid/dl/jfixture-1.1.tar.gz https://example.invalid/dl/jfixture-1.1.tar.gz.asc'
ubuild() {  # RECIPE_DIR OUT_DIR [BUILD_SH]
    FIX_BUILD_TEST=1 FIX_BUILD_SOURCE_DIR="$SRCDIR" FIX_BUILD_UPSTREAM_DIR="$UPD" FIX_BUILD_WORK_BASE="$T" bash "${3:-$FX/build.sh}" "$1" "$2" > "$T/out" 2>&1
    echo $?
}
urefuse() {  # LABEL RECIPE_DIR WANT_IN_OUTPUT [BUILD_SH]
    local o="$T/refused-$RANDOM"
    chk "$1: refused" "$(ubuild "$2" "$o" "${4:-}")" "1"
    chk "$1: nothing left in OUT_DIR" "$(outcount "$o")" "0"
    chk "$1: says why" "$(grep -c -- "$3" "$T/out")" "1"
}
UNAME="joinery-fix-jfixture-1.1-0ubuntu1+joinery1-$(dpkg --print-architecture)"
recipe "$R"; rm -f "$R"/patches/*.patch; echo "$UPLINE" >> "$R/RECIPE"
chk "with no patch of its own, it builds" "$(ubuild "$R" "$T/u1")" "0"
chk "it checked the signature" "$(grep -c "jfixture-1.1.tar.gz's signature verifies against upstream_keys/jfixture.asc" "$T/out")" "1"
chk "Ubuntu's patch upstream has is left out" "$(grep -c "left out Ubuntu's CVE-2026-1000.patch: upstream's 1.1 has it already" "$T/out")" "1"
chk "the version: upstream's, Ubuntu's debian revision, +joinery1" "$(ls "$T/u1" | paste -sd ' ')" "${UNAME}.tar.xz ${UNAME}.tar.xz.sha256"
mkdir "$T/xu1"; tar -C "$T/xu1" -xJf "$T/u1/${UNAME}.tar.xz"
XU="$T/xu1/$UNAME"
chk "the package holds upstream's file" "$(dpkg-deb --fsys-tarfile "$XU/jfixture_1.1-0ubuntu1+joinery1_all.deb" | tar -xOf - ./usr/share/jfixture/hello.cfg | paste -sd ' ')" "greeting=hello, Ubuntu flaw=present"
chk "FIXINFO names the release and its tarball's hash" "$(sed -n 's/^upstream_release=//p' "$XU/FIXINFO")" "1.1 $(sha256sum "$UPD/jfixture-1.1.tar.gz" | awk '{print $1}')"
chk "FIXINFO keeps Ubuntu's base" "$(sed -n 's/^ubuntu_base=//p' "$XU/FIXINFO")" "1.0-0ubuntu1"
chk "FIXINFO has no fingerprint (so a machine never hands it back on one)" "$(grep -c '^fingerprint=' "$XU/FIXINFO")" "0"
ucl="$(dpkg-deb --fsys-tarfile "$XU/jfixture_1.1-0ubuntu1+joinery1_all.deb" | tar -xOf - ./usr/share/doc/jfixture/changelog.Debian.gz | gzip -dc)"
chk "the changelog's newest entry is ours" "$(head -n 1 <<< "$ucl")" "jfixture (1.1-0ubuntu1+joinery1) resolute-joinery; urgency=high"
chk "it names the release and the patch left out" "$(grep -cE "^    - (upstream's release 1.1, its signature checked: https://example.invalid/dl/jfixture-1.1.tar.gz|debian/patches/CVE-2026-1000.patch: left out, in upstream's 1.1)$" <<< "$ucl")" "2"
chk "the version sorts above Ubuntu's base" "$(dpkg --compare-versions 1.1-0ubuntu1+joinery1 gt 1.0-0ubuntu1 && echo yes)" "yes"

recipe "$R"; echo "$UPLINE" >> "$R/RECIPE"
chk "with a patch of its own on top, it builds" "$(ubuild "$R" "$T/u2")" "0"
mkdir "$T/xu2"; tar -C "$T/xu2" -xJf "$T/u2/${UNAME}.tar.xz"
chk "the patch is on upstream's file" "$(dpkg-deb --fsys-tarfile "$T/xu2/$UNAME/jfixture_1.1-0ubuntu1+joinery1_all.deb" | tar -xOf - ./usr/share/jfixture/hello.cfg | paste -sd ' ')" "greeting=hello, Ubuntu bounds_checked=yes, by upstream flaw=fixed"
chk "the patch has its fingerprint" "$(sed -n 's/^fingerprint=0001-CVE-2026-2000.patch //p' "$T/xu2/$UNAME/FIXINFO" | base64 -d)" "bounds_checked=yes, by upstream"

recipe "$R"; echo "$UPLINE" >> "$R/RECIPE"
urefuse "no key committed for the source" "$R" "a person checks upstream's release key and commits it as maintenance_scripts/install_tools/fixes/upstream_keys/jfixture.asc" "$BUILD"
recipe "$R"; echo "$UPLINE" >> "$R/RECIPE"
cp "$UPD/jfixture-1.1.tar.gz" "$T/good.tar.gz"; printf 'x' >> "$UPD/jfixture-1.1.tar.gz"
urefuse "a tarball its signature does not match" "$R" "signature does not verify against upstream_keys/jfixture.asc"
mv "$T/good.tar.gz" "$UPD/jfixture-1.1.tar.gz"
recipe "$R"; echo "$UPLINE" >> "$R/RECIPE"
cp "$FX/upstream_keys/jfixture.asc" "$T/good.asc"; echo 'not a key' > "$FX/upstream_keys/jfixture.asc"
urefuse "a key file that is not an armored key" "$R" "is not an armored public key"
mv "$T/good.asc" "$FX/upstream_keys/jfixture.asc"
# An Ubuntu patch upstream's release neither has nor takes.
mkdir -p "$T/up-bad/jfixture-1.1"; printf 'greeting=hi\nflaw=present\n' > "$T/up-bad/jfixture-1.1/hello.cfg"
tar -C "$T/up-bad" -czf "$T/up-bad/jfixture-1.1.tar.gz" jfixture-1.1
cp "$UPD/jfixture-1.1.tar.gz" "$UPD/jfixture-1.1.tar.gz.asc" "$T/"
cp "$T/up-bad/jfixture-1.1.tar.gz" "$UPD/"; gpgq --yes --armor --detach-sign -o "$UPD/jfixture-1.1.tar.gz.asc" "$UPD/jfixture-1.1.tar.gz"
GNUPGHOME="$G" gpgconf --kill gpg-agent > /dev/null 2>&1 || true
recipe "$R"; echo "$UPLINE" >> "$R/RECIPE"
urefuse "an Ubuntu patch that neither applies nor is upstream's" "$R" "Ubuntu's CVE-2026-1000.patch neither applies to upstream's 1.1"
mv "$T/jfixture-1.1.tar.gz" "$T/jfixture-1.1.tar.gz.asc" "$UPD/"

# A release that lacks Ubuntu's patch: it is kept, and applied under ours.
mkdir -p "$T/up-keep/jfixture-1.1"; printf 'greeting=hello\nflaw=present\n' > "$T/up-keep/jfixture-1.1/hello.cfg"
echo 'upstream 1.1' > "$T/up-keep/jfixture-1.1/RELEASE"
tar -C "$T/up-keep" -czf "$T/up-keep/jfixture-1.1.tar.gz" jfixture-1.1
cp "$UPD/jfixture-1.1.tar.gz" "$UPD/jfixture-1.1.tar.gz.asc" "$T/"
cp "$T/up-keep/jfixture-1.1.tar.gz" "$UPD/"; gpgq --yes --armor --detach-sign -o "$UPD/jfixture-1.1.tar.gz.asc" "$UPD/jfixture-1.1.tar.gz"
GNUPGHOME="$G" gpgconf --kill gpg-agent > /dev/null 2>&1 || true
recipe "$R"; echo "$UPLINE" >> "$R/RECIPE"
chk "a release lacking Ubuntu's patch builds" "$(ubuild "$R" "$T/u3")" "0"
chk "Ubuntu's patch is not left out" "$(grep -c "left out Ubuntu's" "$T/out")" "0"
mkdir "$T/xu3"; tar -C "$T/xu3" -xJf "$T/u3/${UNAME}.tar.xz"
chk "Ubuntu's patch, then ours, on upstream's file" "$(dpkg-deb --fsys-tarfile "$T/xu3/$UNAME/jfixture_1.1-0ubuntu1+joinery1_all.deb" | tar -xOf - ./usr/share/jfixture/hello.cfg | paste -sd ' ')" "greeting=hello, Ubuntu bounds_checked=yes, by upstream flaw=fixed"
chk "and it names upstream's release" "$(dpkg-deb -f "$T/xu3/$UNAME/jfixture_1.1-0ubuntu1+joinery1_all.deb" Version)" "1.1-0ubuntu1+joinery1"
mv "$T/jfixture-1.1.tar.gz" "$T/jfixture-1.1.tar.gz.asc" "$UPD/"

echo "What upstream_release refuses in the recipe"
ubad() {  # LABEL VALUE WANT
    recipe "$R"; echo "upstream_release=$2" >> "$R/RECIPE"
    urefuse "$1" "$R" "$3"
}
ubad "two fields" "1.1 https://example.invalid/dl/jfixture-1.1.tar.gz" "must be '<version> <tarball URL> <signature URL>'"
ubad "four fields" "1.1 https://example.invalid/a/jfixture-1.1.tar.gz https://example.invalid/a/s.asc x" "must be '<version> <tarball URL> <signature URL>'"
ubad "a version with a revision" "1.1-1 https://example.invalid/dl/jfixture-1.1-1.tar.gz https://example.invalid/dl/s.asc" "is not an upstream version"
ubad "a version with an epoch" "1:1.1 https://example.invalid/dl/jfixture-1.1.tar.gz https://example.invalid/dl/s.asc" "is not an upstream version"
ubad "an http tarball" "1.1 http://example.invalid/dl/jfixture-1.1.tar.gz https://example.invalid/dl/s.asc" "is not an https URL"
ubad "an http signature" "1.1 https://example.invalid/dl/jfixture-1.1.tar.gz http://example.invalid/dl/s.asc" "is not an https URL"
ubad "a URL with a query" "1.1 https://example.invalid/dl/jfixture-1.1.tar.gz?x=1 https://example.invalid/dl/s.asc" "is not an https URL"
ubad "a tarball that is not one" "1.1 https://example.invalid/dl/jfixture-1.1.zip https://example.invalid/dl/s.asc" "is not a .tar.gz, .tar.xz or .tar.bz2"
ubad "a tarball not named for its version" "1.1 https://example.invalid/dl/jfixture-1.2.tar.gz https://example.invalid/dl/s.asc" "does not carry the version 1.1"
ubad "a signature that is not one" "1.1 https://example.invalid/dl/jfixture-1.1.tar.gz https://example.invalid/dl/s.txt" "is not a detached signature"
ubad "a release not above Ubuntu's upstream" "1.0 https://example.invalid/dl/jfixture-1.0.tar.gz https://example.invalid/dl/s.asc" "1.0 does not sort above Ubuntu's upstream 1.0"
ubad "an empty value" "" "must be '<version> <tarball URL> <signature URL>'"
recipe "$R"; echo "$UPLINE" >> "$R/RECIPE"; set_key "$R" cves ""
urefuse "no CVE, even on a release" "$R" "names no CVE"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
