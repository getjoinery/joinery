#!/bin/bash
# @joinery-test
# name: agent_installer_siteless
# tier: safe
# env: any
# needs: []
# timeout: 90
#
# install_agent.sh installs the agent on a machine with NO Joinery site — a mail
# relay, a Docker host (spec A13). Three things about that path are easy to get
# wrong in ways that fail silently, and this gate covers each.
#
#   1. A MACHINE WITH NO SITE HAS NO PHP. provision_relay.sh installs postfix,
#      opendkim, opendmarc, wireguard, ufw, rspamd and golang-go; PHP is not
#      among them. The installer's own `command -v php || exit 0` guard would
#      therefore have made every siteless install exit 0 having done nothing,
#      reporting success — a check that passes by not running. So the manifest
#      reader has a PHP-free path, and it must agree with the PHP one exactly.
#
#   2. THE MANIFEST READER MUST NOT BE LINE-BASED. A manifest is machine
#      generated and its whitespace is not a contract. A line-based reader on a
#      one-line manifest matches every architecture's "file" key in turn and
#      keeps the last, so asking for amd64 returns the arm64 filename — a wrong
#      answer shaped like a right one. This is not hypothetical: a Fields-split
#      manifest parser in this same agent was poisoned by one spaced filename in
#      August 2026.
#
#   3. SITELESS IS EXPLICIT, NEVER INFERRED. A missing site config must keep
#      meaning "not my machine, exit 0" — the two DNS resolvers rely on it, and
#      so does a node whose config is briefly absent mid-upgrade.
#
# The reader function is EXTRACTED FROM install_agent.sh rather than copied, so
# this exercises the code that ships. The installer is run only in ways that
# stop at its root check; it is never allowed to install anything on the machine
# running the suite.

set -u
# This gate depends on the installer's root check to stop it. As root that check
# passes and the installer RUNS: it wrote the run switch off, installed the
# bundled binary and stopped the live agent on the dev box on 2026-09-02, killing
# the publish that was running the suite. Never run as root, and say why.
if [ "$(id -u)" = "0" ]; then
    echo "  FAIL: agent_installer_siteless must not run as root - the installer's root check is what keeps this gate from installing anything"
    exit 1
fi
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE="$(dirname "$ROOT")"
INSTALLER="${SITE}/maintenance_scripts/install_tools/install_agent.sh"
MANIFEST="${ROOT}/agent_dist/manifest.json"

T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "== the installer is where we think it is =="
chk "install_agent.sh found" "$([ -f "$INSTALLER" ] && echo yes || echo no)" "yes"

# ---------------------------------------------------------------------------
echo "== --siteless refuses to guess at an artifact =="
# Each of these is a way an operator can get it wrong, and each must say so
# rather than proceeding to install nothing.
out=$(bash "$INSTALLER" --siteless 2>&1); rc=$?
chk "--siteless without --dist-dir fails" "$rc" "1"
chk "  and says what is missing" "$(echo "$out" | grep -c -- '--dist-dir')" "1"

out=$(bash "$INSTALLER" --siteless --dist-dir="$T/nothing-here" 2>&1); rc=$?
chk "--dist-dir at a directory with no manifest fails" "$rc" "1"
chk "  and names the directory" "$(echo "$out" | grep -c "nothing-here")" "1"

out=$(bash "$INSTALLER" --wat 2>&1); rc=$?
chk "an unrecognised option fails loudly" "$rc" "1"

# ---------------------------------------------------------------------------
echo "== the legacy call shapes are untouched =="
# The nine nodes in the field all take this path. Every existing caller — the
# Dockerfile CMD, install.sh, upgrade.php, _plugin_installers_start.sh — passes
# positionals and must land exactly where it always did.
out=$(bash "$INSTALLER" 2>&1)
chk "no arguments still skips" "$(echo "$out" | grep -c 'no SITENAME given')" "1"

out=$(bash "$INSTALLER" somesite 2>&1)
chk "a positional sitename reaches the root check" "$(echo "$out" | grep -c 'not running as root')" "1"

if [ -f "$MANIFEST" ]; then
    out=$(bash "$INSTALLER" --siteless --dist-dir="$(dirname "$MANIFEST")" 2>&1)
    chk "a valid --siteless call reaches the root check" "$(echo "$out" | grep -c 'not running as root')" "1"
else
    echo "  SKIP: no agent_dist/manifest.json in this tree"
fi

# ---------------------------------------------------------------------------
echo "== the shipped manifest reader extracts and parses =="
{
    echo '#!/bin/bash'
    echo 'set -u'
    awk '/^read_manifest_entry\(\) \{/{f=1} f{print} f&&/^\}$/{exit}' "$INSTALLER"
    echo 'read_manifest_entry "$1" "$2"'
} > "$T/reader.sh"
chk "reader function found in install_agent.sh" \
    "$(grep -c 'read_manifest_entry()' "$T/reader.sh")" "1"
bash -n "$T/reader.sh" >/dev/null 2>&1
chk "extracted reader parses" "$?" "0"

if [ ! -f "$MANIFEST" ]; then
    echo "  SKIP: no agent_dist/manifest.json to read"
    echo; echo "passed=$passed failed=$failed"
    [ "$failed" -eq 0 ] || exit 1
    exit 0
fi

# A PATH with no php on it, to exercise the fallback the relay will actually use.
mkdir -p "$T/nophp"
for t in awk sed grep cat head printf bash; do
    src="$(command -v $t 2>/dev/null)" && ln -sf "$src" "$T/nophp/$t"
done
chk "the no-php PATH really has no php" \
    "$(PATH=$T/nophp command -v php >/dev/null 2>&1 && echo yes || echo no)" "no"

# A one-line copy of the same manifest. This is the shape that breaks a
# line-based reader, and the reason the shipped one is not line-based.
tr -d ' \n\t' < "$MANIFEST" > "$T/compact.json"
chk "the compact manifest really is one line" "$(wc -l < "$T/compact.json")" "0"

echo "== every architecture reads back its OWN entry =="
for arch in linux-amd64 linux-arm64; do
    with_php=$(bash "$T/reader.sh" "$MANIFEST" "$arch")
    no_php=$(PATH=$T/nophp "$T/nophp/bash" "$T/reader.sh" "$MANIFEST" "$arch")
    compact=$(PATH=$T/nophp "$T/nophp/bash" "$T/reader.sh" "$T/compact.json" "$arch")

    chk "$arch: reads three fields" "$(echo "$with_php" | wc -w)" "3"
    chk "$arch: php and no-php readers agree" "$no_php" "$with_php"
    chk "$arch: layout does not change the answer" "$compact" "$with_php"
    chk "$arch: the filename is this architecture's" \
        "$(echo "$with_php" | awk -v a="$arch" '{print (index($2, a) > 0 ? "yes" : "no")}')" "yes"
done

echo "== the two architectures do not share an answer =="
# The specific failure a line-based reader produces: one architecture's hash
# returned for both. It would be caught downstream by the sha256 check, but as
# the wrong error entirely.
amd=$(bash "$T/reader.sh" "$MANIFEST" linux-amd64 | awk '{print $3}')
arm=$(bash "$T/reader.sh" "$MANIFEST" linux-arm64 | awk '{print $3}')
chk "amd64 and arm64 report different hashes" "$([ "$amd" != "$arm" ] && echo yes || echo no)" "yes"

echo "== an architecture the manifest does not carry returns nothing =="
# Not a neighbouring block, and not the last one seen.
for src in "$MANIFEST" "$T/compact.json"; do
    out=$(PATH=$T/nophp "$T/nophp/bash" "$T/reader.sh" "$src" linux-mips)
    chk "unknown arch in $(basename "$src") yields no entry" "$(echo -n "$out" | wc -w)" "0"
done

echo
echo "passed=$passed failed=$failed"
[ "$failed" -eq 0 ] || exit 1
