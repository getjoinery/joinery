#!/bin/bash
# @joinery-test
# name: security_check
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/sysadmin_tools/security_check.sh]
#
# Which security fixes a machine is waiting for, looked for every hour
# (specs/fleet_security_patching.md WP1). security_check.sh refreshes the
# package lists when they are old, simulates a full upgrade, and records each
# package that the machine's own automatic-update policy would take: a
# -security pocket, or an outside repository 51joinery-platform-repos names.
# This gate drives it against a stand-in apt-get and systemctl, as an
# unprivileged user under SECURITY_CHECK_ROOT.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/security_check.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged; as root the fixture root is ignored and the machine's own paths would be used"
    echo "RESULT: 0 passed, 0 failed"; exit 0
fi

R="$T/root"; STUB="$T/s"
export SECURITY_CHECK_ROOT="$R" STUB
mkdir -p "$T/bin" "$STUB" "$R/var/lib/apt/lists" "$R/etc/apt/apt.conf.d"
cat > "$T/bin/apt-get" <<'EOF'
#!/bin/bash
echo "$*" >> "$STUB/calls"
case "$*" in
  *update*) [ -f "$STUB/update_fails" ] && { echo "E: Could not get lock /var/lib/apt/lists/lock"; exit 100; }
            touch "$SECURITY_CHECK_ROOT/var/lib/apt/lists/archive.ubuntu.com_dists_noble-security_main_binary-amd64_Packages"; exit 0 ;;
  *-s*dist-upgrade*) [ -f "$STUB/sim_fails" ] && { echo "E: broken"; exit 100; }; cat "$STUB/sim" ;;
esac
EOF
cat > "$T/bin/systemctl" <<'EOF'
#!/bin/bash
echo "$*" >> "$STUB/systemctl"
case "$1" in is-enabled|is-active) [ -f "$STUB/timer_on" ] ;; enable) touch "$STUB/timer_on" ;; disable) rm -f "$STUB/timer_on" ;; esac
EOF
chmod 755 "$T/bin/apt-get" "$T/bin/systemctl"
export PATH="$T/bin:$PATH"

# The script runs from a tree of its own, beside stand-ins for the two scripts
# it starts: install_security_updates.sh (the at-once install records the call,
# prints an object, and takes the pending critical fixes, as a real run would)
# and site_refresh.sh.
mkdir -p "$T/tree/sysadmin_tools" "$T/tree/install_tools"
cp "$SCRIPT" "$T/tree/sysadmin_tools/"
printf '%s\n' '#!/bin/bash' 'echo "install_security_updates" >> "$STUB/calls"' \
    '[ -f "$STUB/install_fails" ] && { echo "{\"outcome\":\"failed\",\"reason\":\"x\"}"; exit 0; }' \
    'grep -v -E "^Inst (php|libssl|openssl|apache2|postgresql|libpq)" "$STUB/sim" > "$STUB/sim.n"; mv "$STUB/sim.n" "$STUB/sim"' \
    'echo "{\"outcome\":\"installed\",\"reason\":\"\",\"upgraded_count\":2}"' > "$T/tree/sysadmin_tools/install_security_updates.sh"
printf '%s\n' '#!/bin/bash' 'echo "site_refresh $*" >> "$STUB/calls"' > "$T/tree/install_tools/site_refresh.sh"
chmod -R go-w "$T/tree"
TS="$T/tree/sysadmin_tools/security_check.sh"
run() { bash "$TS" "$@" > "$T/out" 2>&1; echo $?; }
st() { sed -n "s/^$1=//p" "$R/var/lib/joinery/security-check/state"; }
pk() { st packages | tr ' ' '\n' | cut -d'|' -f1-4 | paste -sd ' '; }
cat > "$STUB/sim.all" <<'EOF'
NOTE: This is only a simulation!
Inst php8.3-fpm [8.3.6-0ubuntu0.24.04.12] (8.3.6-0ubuntu0.24.04.13 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64]) []
Inst tzdata [2026a-0ubuntu0.24.04] (2026c-0ubuntu0.24.04 Ubuntu:24.04/noble-updates [all])
Inst libfoo-security-dev [1.0] (1.1 Ubuntu:24.04/noble-updates [amd64])
Inst curl [8.5.0-2ubuntu10.6] (8.5.0-2ubuntu10.7 Ubuntu:24.04/noble-security [amd64])
Inst linux-image-6.8.0-90-generic (6.8.0-90.91 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Inst libssl3t64 [3.0.13-0ubuntu3.5] (3.0.13-0ubuntu3.6 UbuntuESM:24.04/noble-infra-security [amd64])
Inst php8.4-cli [8.4.11-1+ubuntu24.04.1+deb.sury.org+1] (8.4.12-1+ubuntu24.04.1+deb.sury.org+1 LP-PPA-ondrej-php:24.04/noble [amd64])
Inst docker-ce [5:29.1.5-1~ubuntu.24.04~noble] (5:29.1.6-1~ubuntu.24.04~noble Docker CE:24.04/noble [amd64])
Conf php8.3-fpm (8.3.6-0ubuntu0.24.04.13 Ubuntu:24.04/noble-security [amd64])
EOF

echo "=== run: what a security run would take ==="
cp "$STUB/sim.all" "$STUB/sim"; touch "$STUB/install_fails"
chk "the first run: exit 0, the lists refreshed (there were none)" "$(run run)|$(grep -c 'update -qq -o DPkg::Lock::Timeout=0' "$STUB/calls")" "0|1"
chk "  a -security pocket counts (Ubuntu's and Pro's); -updates alone does not, nor a name holding the word; an outside repository not named does not" \
    "$(st pending)" "4"
chk "  each with installed and candidate; a new package's installed is none" \
    "$(pk)" "php8.3-fpm|8.3.6-0ubuntu0.24.04.12|8.3.6-0ubuntu0.24.04.13|1 curl|8.5.0-2ubuntu10.6|8.5.0-2ubuntu10.7|0 linux-image-6.8.0-90-generic|none|6.8.0-90.91|1 libssl3t64|3.0.13-0ubuntu3.5|3.0.13-0ubuntu3.6|1"
chk "  PHP, the kernel and OpenSSL are critical; curl is not" "$(st critical)" "3"
chk "  the check and the lists' time recorded" "$(st check)|$([ -n "$(st checked_ok_at)" ] && echo t)|$([ -n "$(st lists_at)" ] && echo t)" "ok|t|t"
FIRST_PHP="$(st packages | tr ' ' '\n' | grep '^php8.3-fpm|' | cut -d'|' -f5)"
chk "  each fix carries when it was first seen waiting" "$([ "$FIRST_PHP" = "$(st checked_at)" ] && echo t)" "t"
chk "critical fixes (PHP, OpenSSL) are installed at once; a failed install is recorded, and the check stands" \
    "$(grep -c '^install_security_updates$' "$STUB/calls")|$(st at_once_outcome)|$(st pending)" "1|failed|4"
printf '// x\nUnattended-Upgrade::Allowed-Origins {\n    "LP-PPA-ondrej-php:${distro_codename}";\n};\n' > "$R/etc/apt/apt.conf.d/51joinery-platform-repos"
: > "$STUB/calls"; sleep 1
chk "an outside repository 51joinery-platform-repos names counts; fresh lists are not fetched again" \
    "$(run run)|$(st pending)|$(pk | tr ' ' '\n' | grep -c '^php8.4-cli|8.4.11-1+ubuntu24.04.1+deb.sury.org+1|8.4.12-1+ubuntu24.04.1+deb.sury.org+1|1$')|$(grep -c "^update -qq" "$STUB/calls")" "0|5|1|0"
chk "  a fix still waiting keeps the time it was first seen" "$(st packages | tr ' ' '\n' | grep '^php8.3-fpm|' | cut -d'|' -f5)" "$FIRST_PHP"
sed -i 's/0ubuntu0.24.04.13 Ubuntu/0ubuntu0.24.04.14 Ubuntu/' "$STUB/sim"; sleep 1; run run > /dev/null
chk "  a new candidate version is a new fix, first seen now" "$([ "$(st packages | tr ' ' '\n' | grep '^php8.3-fpm|' | cut -d'|' -f5)" != "$FIRST_PHP" ] && echo new)" "new"
touch -d '2 hours ago' "$R/var/lib/apt/lists/"*; : > "$STUB/calls"
chk "lists unchanged on the mirror keep an old date: this check's own refresh time counts, nothing fetched again" \
    "$(run run)|$(grep -c "^update -qq" "$STUB/calls")" "0|0"
sed -i 's/^lists_at=.*/lists_at=2026-01-01T00:00:00Z/' "$R/var/lib/joinery/security-check/state"; touch "$STUB/update_fails"
chk "old lists that cannot be refreshed (another package run holds them): checked on the old lists, saying so" \
    "$(run run)|$(st check)|$(st lists_error | grep -c 'not refreshed')|$(st pending)" "0|ok|1|5"
rm "$STUB/update_fails"; OK_AT="$(st checked_ok_at)"; touch "$STUB/sim_fails"; sleep 1
chk "a simulation that fails: recorded as failed, the last good check's time kept" "$(run run)|$(st check)|$(st checked_ok_at)" "1|failed|$OK_AT"
rm "$STUB/sim_fails" "$STUB/install_fails"; : > "$STUB/calls"
chk "an at-once install that works: the check is taken again after it, and only the kernel and the rest wait" \
    "$(run run)|$(grep -c '^install_security_updates$' "$STUB/calls")|$(st at_once_outcome)|$(pk | tr ' ' '\n' | cut -d'|' -f1 | paste -sd ' ')|$(st critical)" \
    "0|1|installed|curl linux-image-6.8.0-90-generic|1"
: > "$STUB/calls"
chk "  the kernel alone (a reboot is a person's): nothing installed at once" "$(run run)|$(grep -c '^install_security_updates$' "$STUB/calls")" "0|0"
printf 'NOTE: This is only a simulation!\n' > "$STUB/sim"
chk "nothing waiting: zero, and no packages" "$(run run)|$(st pending)|$(st critical)|$(st packages)" "0|0|0|"
printf 'Inst evil;$(reboot) [1] (2 Ubuntu:24.04/noble-security [amd64])\nInst a"b [1<x>] (2 Ubuntu:24.04/noble-security [amd64])\n' > "$STUB/sim"
chk "a name or version is kept to the characters Debian allows" "$(run run)|$(pk)" "0|evilreboot|1|2|0 ab|1x|2|0"
chk "status prints the state" "$(bash "$TS" status | grep -c '^pending=')" "1"
chk "a second run while one runs: says so, does nothing" \
    "$(exec 9>>"$R/run/joinery/security-check.lock"; flock 9; bash "$TS" run 2>&1 | grep -c 'under way')" "1"
mkdir -p "$R/etc/systemd/system" "$R/var/lib/joinery/site-refresh"; : > "$R/etc/systemd/system/joinery-site-refresh.service"
printf 'security_packages=curl tar\n' > "$R/var/lib/joinery/site-refresh/state"; : > "$STUB/calls"
chk "a container host whose refresh timer is off (its tree not root's alone): the base is not checked from it (reviewer2 G2)" \
    "$(run run)|$(grep -c '^site_refresh' "$STUB/calls")" "0|0"
touch "$STUB/timer_on"; : > "$STUB/calls"
chk "on a container host, the site containers' base is asked too; a base with no critical fix starts nothing" \
    "$(run run)|$(grep -c '^site_refresh check$' "$STUB/calls")|$(grep -c '^site_refresh start$' "$STUB/calls")" "0|1|0"
printf 'security_packages=curl php8.5-fpm libssl3t64\n' > "$R/var/lib/joinery/site-refresh/state"; : > "$STUB/calls"
chk "  a base waiting for a critical fix: the containers' replacement starts now" \
    "$(run run)|$(grep -c '^site_refresh start$' "$STUB/calls")|$([ -n "$(st at_once_refresh_at)" ] && echo t)" "0|1|t"
rm -f "$R/etc/systemd/system/joinery-site-refresh.service" "$STUB/timer_on"; : > "$STUB/calls"
run run > /dev/null
chk "  and not elsewhere" "$(grep -c '^site_refresh' "$STUB/calls")" "0"

echo "=== install ==="
rm -rf "$R/etc/systemd" "$R/var/lib/joinery/security-check"; : > "$STUB/systemctl"; rm -f "$STUB/timer_on"
chk "install: the service and the timer written, enabled, and when recorded" \
    "$(bash "$TS" install > "$T/out" 2>&1; echo $?)|$(ls "$R/etc/systemd/system" | tr '\n' ' ')|$(grep -c 'enable --now joinery-security-check.timer' "$STUB/systemctl")|$(grep -c '^installed_at=' "$R/var/lib/joinery/security-check/state")" \
    "0|joinery-security-check.service joinery-security-check.timer |1|1"
chk "  the service's time limit is past the run's own bounds, so systemd never kills a package run part way (reviewer2 G1)" \
    "$(grep -c '^TimeoutStartSec=2h$' "$R/etc/systemd/system/joinery-security-check.service")" "1"
chk "  hourly, spread, caught up after a stop; the service runs this tree's script at low priority" \
    "$(grep -cE '^(OnCalendar=hourly|RandomizedDelaySec=15m|Persistent=true)$' "$R/etc/systemd/system/joinery-security-check.timer")|$(grep -c "^ExecStart=/bin/bash $TS run$" "$R/etc/systemd/system/joinery-security-check.service")|$(grep -c '^Nice=10$' "$R/etc/systemd/system/joinery-security-check.service")" "3|1|1"
: > "$STUB/systemctl"
chk "a second install: current, nothing reloaded" "$(bash "$TS" install)|$(grep -c daemon-reload "$STUB/systemctl")" "security_check: the hourly security check is current|0"
for f in install_tools/site_refresh.sh sysadmin_tools/install_security_updates.sh; do
    chmod g+w "$T/tree/$f"; touch "$STUB/timer_on"; : > "$STUB/systemctl"
    chk "a script it runs ($f) that another account can write: refused, and a timer already on is stopped" \
        "$(bash "$TS" install 2>&1 | grep -c "not root's alone")|$(grep -c 'disable --now joinery-security-check.timer' "$STUB/systemctl")" "1|1"
    chmod g-w "$T/tree/$f"
done
chk "an argument it does not know: refused" "$(bash "$TS" now > /dev/null 2>&1; echo $?)" "2"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
