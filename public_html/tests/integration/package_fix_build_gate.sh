#!/bin/bash
# @joinery-test
# name: package_fix_build
# tier: safe
# env: any
# needs: []
# timeout: 300
# covers: [maintenance_scripts/install_tools/fixes/build.sh]
#
# Package fixes ahead of Ubuntu (specs/package_fixes_ahead_of_ubuntu.md WP1).
# build.sh takes Ubuntu's source for one package, adds upstream's patches, and
# builds every binary that source makes, tagged <ubuntu version>+joinery<N>,
# as a flat repository apt can read. This gate builds a small fixture source
# package (two binaries, one Ubuntu patch already in its series) in build.sh's
# test mode, which takes the source from a directory instead of apt and
# installs no build dependencies, and checks what it refuses.
#
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
BUILD="$ROOT/maintenance_scripts/install_tools/fixes/build.sh"
for tool in dpkg-buildpackage dpkg-source dpkg-scanpackages dh fakeroot; do
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
printf 'greeting=hello\nflaw=present\n' > "$P/hello.txt"
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
printf '%s\n' '#!/usr/bin/make -f' '%:' '	dh $@' 'override_dh_auto_test:' '	grep -q "^greeting=hello, Ubuntu" hello.txt' > "$P/debian/rules"
chmod 755 "$P/debian/rules"
echo 'hello.txt usr/share/jfixture/' > "$P/debian/jfixture.install"
echo 'hello.txt usr/share/jfixture-extra/' > "$P/debian/jfixture-extra.install"
cat > "$P/debian/changelog" <<'EOF'
jfixture (1.0-0ubuntu1) resolute-security; urgency=medium

  * SECURITY UPDATE: CVE-2026-1000
    - debian/patches/CVE-2026-1000.patch: say hello to Ubuntu.

 -- Fixture <fixture@example.invalid>  Mon, 19 Oct 2026 12:00:00 +0000
EOF
cat > "$P/debian/patches/CVE-2026-1000.patch" <<'EOF'
Description: Ubuntu's own patch
--- a/hello.txt
+++ b/hello.txt
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
fix_patch() {  # PATH [ORIGIN] [TEST]
    {
        echo "Description: remove the flaw"
        [ -n "${2:-}" ] && echo "Origin: $2"
        [ -n "${3:-}" ] && echo "Joinery-Test: $3"
        printf '%s\n' '--- a/hello.txt' '+++ b/hello.txt' '@@ -1,2 +1,2 @@' ' greeting=hello, Ubuntu' '-flaw=present' '+flaw=fixed'
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
    fix_patch "$1/patches/0001-CVE-2026-2000.patch" "https://example.invalid/upstream/commit/abc123" "grep -q '^flaw=fixed' hello.txt"
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
chk "the sibling depends on the new version" "$(dpkg-deb -f "$X/jfixture-extra_1.0-0ubuntu1+joinery1_all.deb" Depends)" "jfixture (= 1.0-0ubuntu1+joinery1)"
chk "the fix is in the package" "$(dpkg-deb --fsys-tarfile "$X/jfixture_1.0-0ubuntu1+joinery1_all.deb" | tar -xOf - ./usr/share/jfixture/hello.txt | paste -sd ' ')" "greeting=hello, Ubuntu flaw=fixed"
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
printf '%s\n' 'Origin: https://example.invalid/c/2' '--- a/hello.txt' '+++ b/hello.txt' '@@ -1,2 +1,2 @@' ' greeting=hello, Ubuntu' '-flaw=fixed' '+flaw=fixed twice' \
    > "$R/patches/0002-CVE-2026-2001.patch"
set_key "$R" cves "CVE-2026-2000 CVE-2026-2001"
chk "builds" "$(build "$R" "$T/o4")" "0"
mkdir "$T/x4"; tar -C "$T/x4" -xJf "$T/o4/${NAME}.tar.xz"
chk "both are in the package, in order" "$(dpkg-deb --fsys-tarfile "$T/x4/$NAME/jfixture_1.0-0ubuntu1+joinery1_all.deb" | tar -xOf - ./usr/share/jfixture/hello.txt | tail -n 1)" "flaw=fixed twice"
chk "FIXINFO names both CVEs" "$(sed -n 's/^cves=//p' "$T/x4/$NAME/FIXINFO")" "CVE-2026-2000 CVE-2026-2001"

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
recipe "$R"; fix_patch "$R/patches/0001-CVE-2026-2000.patch" "https://example.invalid/c/1" "grep -q '^flaw=gone' hello.txt"
refuse "a patch whose own test fails" "$R" "test failed"
recipe "$R"; set_key "$R" changelog_from jfixture-docs
refuse "changelog_from naming no built package" "$R" "made no jfixture-docs package"
recipe "$R"; set_key "$R" test "false"
refuse "a recipe test command that fails" "$R" "the recipe's test failed"
recipe "$R"; set_key "$R" cves "CVE-26-1"
refuse "a malformed CVE id" "$R" "is not a CVE id"
recipe "$R"; rm "$R/patches/0001-CVE-2026-2000.patch"
refuse "no patches" "$R" "has no patches"
recipe "$R"; mv "$R/patches/0001-CVE-2026-2000.patch" "$R/patches/fix.patch"
refuse "a patch not named NNNN-" "$R" "is not named NNNN-"
R2="$T/r/elsewhere"; recipe "$R2"
refuse "a recipe outside its source's directory" "$R2" "sits in elsewhere/"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
