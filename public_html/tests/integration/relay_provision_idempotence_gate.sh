#!/bin/bash
# @joinery-test
# name: relay_provision_idempotence
# tier: safe
# env: any
# needs: []
# timeout: 90
#
# provision_relay.sh 3.0 builds a relay once, from first-boot user-data, and
# nothing else on the relay ever asks it to run again — but a hand run
# (--keep-sshd, on a box you can watch) may run it twice, and every mutation in
# it still goes through the idempotence helpers so a second run changes nothing
# and restarts nothing. Two of those properties are the kind that come back
# quietly, and this gate pins them, plus the shape the relay must have:
#
#   1. RESTART-ONLY-ON-CHANGE. A returning `systemctl restart postfix` would not
#      fail any test that only checks the end state, because the end state is
#      identical. It has to be caught here.
#
#   2. THE FIREWALL IS CONVERGED, NOT RESET, and it is 25 and 443 ONLY. Port 22
#      is admitted by --keep-sshd alone, and an existing 22 rule is removed on
#      a real build; there is no WireGuard port and no tunnel egress rule.
#
#   3. NO SHELL SURFACE IN THE SOURCE. WireGuard, the sshd drop-in, the tenant
#      shell, tenant accounts and the sudoers rule are gone
#      (specs/relay_without_a_shell.md); any one of them coming back in a merge
#      is a door.
#
# The functions are EXTRACTED FROM the shipped script rather than copied, so
# this exercises the code that ships. `ufw` and `systemctl` are stubbed onto
# PATH: nothing here touches this machine's firewall or services, and the real
# script is never executed.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT}/plugins/mailbox/provisioning/provision_relay.sh"

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

# Pull one shell function out of the script by name, from its `name() {` line to
# the first column-zero `}`.
extract() {
    awk -v fn="$1" 'index($0, fn "() {") == 1 {f=1} f{print} f && /^\}$/{exit}' "$SCRIPT"
}

echo "== the script is where we think it is, and parses =="
chk "provision_relay.sh found" "$([ -f "$SCRIPT" ] && echo yes || echo no)" "yes"
bash -n "$SCRIPT" >/dev/null 2>&1
chk "provision_relay.sh parses" "$?" "0"

# ---------------------------------------------------------------------------
echo "== the old defects and the shell surface are gone from the source =="
# Text assertions, because each of these is a single line that would silently
# restore the old behaviour if it came back in a merge.
chk "no 'ufw --force reset'" \
    "$(grep -c "^ufw --force reset" "$SCRIPT")" "0"
chk "no 'go build' on the relay" \
    "$(grep -c "go build" "$SCRIPT")" "0"
chk "no golang-go package" \
    "$(grep -c "golang-go" "$SCRIPT")" "0"
chk "no unconditional systemctl restart at column 0" \
    "$(grep -cE "^systemctl (restart|reload) " "$SCRIPT")" "0"
chk "dpkg-reconfigure is guarded (not at column 0)" \
    "$(grep -cE "^dpkg-reconfigure " "$SCRIPT")" "0"
chk "no in-place sed of /etc/default files" \
    "$(grep -c "sed -i" "$SCRIPT")" "0"
chk "the prebuilt sealer is named by uname -m" \
    "$(grep -c 'SEALER_CANDIDATES+=("\${SCRIPT_DIR}/bin/relay-sealer-\${SEALER_MACHINE}")' "$SCRIPT")" "1"
chk "an absent sealer is a hard failure" \
    "$(grep -c 'no prebuilt relay-sealer binary was delivered' "$SCRIPT")" "1"
chk "no WireGuard (outside the version history)" \
    "$(grep -vE '^#' "$SCRIPT" | grep -ciE 'wg-quick|wireguard|wg genkey|/etc/wireguard')" "0"
chk "no sshd drop-in" \
    "$(grep -c 'sshd_config' "$SCRIPT")" "0"
chk "no tenant shell or tenant accounts" \
    "$(grep -ciE 'joinery-tenant-shell|useradd .*jt-|TENANT_SHELL' "$SCRIPT")" "0"
chk "no sudoers rule" \
    "$(grep -c 'sudoers' "$SCRIPT" | awk '{print ($1<=3)?"few":"many"}')" "few"
chk "  (sudoers is mentioned only in the version history)" \
    "$(grep -vE '^#' "$SCRIPT" | grep -c 'sudoers')" "0"
chk "no smarthost / tunnel submission" \
    "$(grep -vE '^#' "$SCRIPT" | grep -ciE 'smarthost|10\.99\.0')" "0"
chk "no 8442 egress binding" \
    "$(grep -vE '^#' "$SCRIPT" | grep -c '8442')" "0"
chk "--keep-sshd is refused without a terminal" \
    "$(grep -c 'KEEP_SSHD}" -eq 1 && ! -t 0' "$SCRIPT")" "1"
chk "a relay without a client key or --skeleton-only is refused" \
    "$(grep -c -- '--client-public-key is required' "$SCRIPT")" "1"
chk "the listener never gains root (NoNewPrivileges in its unit)" \
    "$(grep -c '^NoNewPrivileges=yes' "$SCRIPT")" "1"
chk "root reacts to a path unit, not a request" \
    "$(grep -c '^PathChanged=' "$SCRIPT")" "1"
chk "root collects the ping's privileged half on a timer" \
    "$(grep -c '^OnUnitActiveSec=30s' "$SCRIPT")" "1"

# ---------------------------------------------------------------------------
# Stubs. Each records its argv so a test can assert on what WOULD have been done
# to a real relay.
mkdir -p "$T/bin"
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
echo "systemctl $*" >> "$STUB_LOG"
case "$1" in
    is-active) echo "${FAKE_ACTIVE:-active}"; exit 0;;
esac
exit 0
STUB
cat > "$T/bin/ufw" <<'STUB'
#!/bin/bash
echo "ufw $*" >> "$STUB_LOG"
case "$1 ${2:-}" in
    "status verbose") cat "$FAKE_UFW_VERBOSE"; exit 0;;
    "status "*|"status") cat "$FAKE_UFW_RULES"; exit 0;;
esac
exit 0
STUB
chmod 755 "$T/bin"/*

# ---------------------------------------------------------------------------
echo "== restart-only-on-change =="
{
    echo '#!/bin/bash'
    echo 'set -u'
    echo 'CHANGED_UNITS=""'
    extract mark_changed
    extract changed
    extract sync_service
    echo 'for u in $UNITS_TO_MARK; do mark_changed "$u"; done'
    echo 'sync_service "$1" "${2:-restart}"'
} > "$T/svc.sh"
chk "sync_service extracted" "$(grep -c '^sync_service() {' "$T/svc.sh")" "1"
bash -n "$T/svc.sh" >/dev/null 2>&1
chk "  and parses on its own" "$?" "0"

run_svc() { # <units-marked> <unit> <mode> <fake-active>
    : > "$T/log"
    STUB_LOG="$T/log" UNITS_TO_MARK="$1" FAKE_ACTIVE="$4" \
        PATH="$T/bin:$PATH" bash "$T/svc.sh" "$2" "$3" >/dev/null 2>&1
    grep -cE "systemctl (restart|reload|start) $2" "$T/log"
}

chk "an unchanged, running unit is left alone"      "$(run_svc ''        postfix reload  active)"   "0"
chk "a changed, running unit is acted on once"      "$(run_svc 'postfix' postfix reload  active)"   "1"
chk "an unrelated change does not touch this unit"  "$(run_svc 'rspamd'  postfix reload  active)"   "0"
chk "a dead unit is started even with no change"    "$(run_svc ''        postfix restart inactive)" "1"

: > "$T/log"
STUB_LOG="$T/log" UNITS_TO_MARK="rspamd" FAKE_ACTIVE=active \
    PATH="$T/bin:$PATH" bash "$T/svc.sh" rspamd reload >/dev/null 2>&1
chk "a reload-capable unit is reloaded, not restarted" \
    "$(grep -c 'systemctl reload rspamd' "$T/log")" "1"
chk "  and is not also restarted" \
    "$(grep -c 'systemctl restart rspamd' "$T/log")" "0"

# The whole point: six services, nothing changed, nothing touched.
: > "$T/log"
for u in postfix opendkim opendmarc rspamd joinery-relay-serve joinery-relay-apply.path joinery-relay-collect.timer; do
    STUB_LOG="$T/log" UNITS_TO_MARK="" FAKE_ACTIVE=active \
        PATH="$T/bin:$PATH" bash "$T/svc.sh" "$u" restart >/dev/null 2>&1
done
chk "a no-change converge restarts NOTHING" \
    "$(grep -cE 'systemctl (restart|reload|start)' "$T/log")" "0"

# ---------------------------------------------------------------------------
echo "== the firewall is converged, never reset, and is 25 + 443 only =="
{
    echo '#!/bin/bash'
    echo 'set -u'
    echo 'KEEP_SSHD="${KEEP_SSHD:-0}"'
    extract ufw_allow_once
    extract converge_firewall
    echo 'converge_firewall'
} > "$T/fw.sh"
chk "converge_firewall extracted" "$(grep -c '^converge_firewall() {' "$T/fw.sh")" "1"
bash -n "$T/fw.sh" >/dev/null 2>&1
chk "  and parses on its own" "$?" "0"

run_fw() { # <verbose-file> <rules-file> [keep-sshd]
    : > "$T/log"
    STUB_LOG="$T/log" FAKE_UFW_VERBOSE="$1" FAKE_UFW_RULES="$2" KEEP_SSHD="${3:-0}" \
        PATH="$T/bin:$PATH" bash "$T/fw.sh" >/dev/null 2>&1
}

# A fresh box: inactive, no rules.
cat > "$T/v_fresh" <<'EOF'
Status: inactive
EOF
: > "$T/r_fresh"
run_fw "$T/v_fresh" "$T/r_fresh"
chk "fresh box: never resets"          "$(grep -c 'ufw --force reset' "$T/log")" "0"
chk "fresh box: opens 25/tcp"          "$(grep -c 'ufw allow 25/tcp' "$T/log")" "1"
chk "fresh box: opens 443/tcp"         "$(grep -c 'ufw allow 443/tcp' "$T/log")" "1"
chk "fresh box: does NOT open 22/tcp"  "$(grep -c 'ufw allow 22/tcp' "$T/log")" "0"
chk "fresh box: no WireGuard port"     "$(grep -c '51820' "$T/log")" "0"
chk "fresh box: no tunnel egress rule" "$(grep -c '8442' "$T/log")" "0"
chk "fresh box: enables the firewall"  "$(grep -c 'ufw --force enable' "$T/log")" "1"
chk "fresh box: exactly two allow rules" "$(grep -c 'ufw allow' "$T/log")" "2"

# The hand run keeps 22 so the box is not locked.
run_fw "$T/v_fresh" "$T/r_fresh" 1
chk "hand run (--keep-sshd): opens 22/tcp" "$(grep -c 'ufw allow 22/tcp' "$T/log")" "1"
chk "hand run: still 25 and 443"           "$(grep -c 'ufw allow 25/tcp\|ufw allow 443/tcp' "$T/log")" "2"

# A converged box: everything already in place. The whole run must be reads.
cat > "$T/v_ok" <<'EOF'
Status: active
Default: deny (incoming), allow (outgoing), disabled (routed)
EOF
cat > "$T/r_ok" <<'EOF'
Status: active

To                         Action      From
--                         ------      ----
25/tcp                     ALLOW       Anywhere
443/tcp                    ALLOW       Anywhere
EOF
run_fw "$T/v_ok" "$T/r_ok"
chk "converged box: no rule is re-added" \
    "$(grep -cE 'ufw (allow|default|delete)' "$T/log")" "0"
chk "converged box: not re-enabled" \
    "$(grep -c 'ufw --force enable' "$T/log")" "0"
chk "converged box: only status reads" \
    "$(grep -cv 'ufw status' "$T/log")" "0"

# A box that arrived with SSH open: a real build closes it.
cat > "$T/r_ssh" <<'EOF'
Status: active

To                         Action      From
--                         ------      ----
22/tcp                     ALLOW       Anywhere
25/tcp                     ALLOW       Anywhere
443/tcp                    ALLOW       Anywhere
EOF
run_fw "$T/v_ok" "$T/r_ssh"
chk "ssh-open box: 22/tcp rule is removed"  "$(grep -c 'ufw delete allow 22/tcp' "$T/log")" "1"
chk "ssh-open box: nothing re-added"        "$(grep -c 'ufw allow' "$T/log")" "0"
run_fw "$T/v_ok" "$T/r_ssh" 1
chk "ssh-open box, hand run: 22/tcp kept"   "$(grep -c 'ufw delete allow 22/tcp' "$T/log")" "0"

# A partially-configured box adds only what is missing.
cat > "$T/r_partial" <<'EOF'
Status: active

To                         Action      From
--                         ------      ----
25/tcp                     ALLOW       Anywhere
EOF
run_fw "$T/v_ok" "$T/r_partial"
chk "partial box: 25/tcp not re-added"  "$(grep -c 'ufw allow 25/tcp' "$T/log")" "0"
chk "partial box: 443/tcp added"        "$(grep -c 'ufw allow 443/tcp' "$T/log")" "1"

# A box whose defaults drifted open.
cat > "$T/v_open" <<'EOF'
Status: active
Default: allow (incoming), allow (outgoing), disabled (routed)
EOF
run_fw "$T/v_open" "$T/r_ok"
chk "drifted default: incoming set back to deny" \
    "$(grep -c 'ufw default deny incoming' "$T/log")" "1"

echo
echo "passed=$passed failed=$failed"
[ "$failed" -eq 0 ] || exit 1
