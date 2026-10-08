#!/bin/bash
# @joinery-test
# name: parser_jail_acl
# tier: safe
# env: any
# needs: []
# timeout: 30
# covers: [maintenance_scripts/install_tools/install_parser_jail.sh]
#
# The parser jail's read grant never makes the executable set writable
# (specs/read_only_tree.md). setfacl recalculates an ACL's mask from the group
# entry unless the mask is given, so a grant landing on a tree that was 770 left
# public_html and vendor at 775 and the deploy gate rolled the upgrade back.
# Pinned: the grant on public_html and vendor states group r-x, the mask and the
# defaults; cache keeps the plain grant; every setfacl that grants goes through
# jail_acl; and a grant made before that is narrowed whether or not one is due.
# Read from the script: the dev box has no setfacl and the installer needs root.

set -u
J="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/maintenance_scripts/install_tools/install_parser_jail.sh"
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}

chk "the installer parses" "$(bash -n "$J" && echo ok)" "ok"
eval "$(sed -n '/^jail_acl() {/,/^}/p' "$J")"
JAIL_USER=joinery-jail
chk "public_html: group, mask and defaults stated" "$(jail_acl public_html)" \
    "u:joinery-jail:rX,d:u:joinery-jail:rX,g::rX,m::rX,d:g::rX,d:m::rX,d:o::rX"
chk "vendor: the same" "$(jail_acl vendor)" "$(jail_acl public_html)"
chk "cache: the plain grant" "$(jail_acl cache)" "u:joinery-jail:rX,d:u:joinery-jail:rX"
chk "no setfacl grants to the jail except through jail_acl" \
    "$(grep -E '^[^#]*setfacl .*-R' "$J" | grep -c -v 'jail_acl')" "0"
chk "a too-wide earlier grant is narrowed, before the probe decides" \
    "$( [ "$(grep -n 'closed group write' "$J" | head -1 | cut -d: -f1)" -lt "$(grep -n '^grant_needed=0' "$J" | cut -d: -f1)" ] && echo yes)" "yes"

echo
echo "parser_jail_acl gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
