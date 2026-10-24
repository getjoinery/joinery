#!/bin/bash
# @joinery-test
# name: root_ssh_login
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/install.sh]
#
# What install.sh server and install.sh docker do to root SSH login
# (configure_ssh_access, derive_ssh_access; specs/beta_break_glass_root_access.md
# section 2), against a fixture /etc/ssh and a stand-in sshd, with no terminal:
#
#   - by default root SSH login is off, root's keys included, and the run says
#     the provider's web console still logs in; under sudo that account keeps
#     its access and root is off
#   - --keep-root-login: key-only when root holds keys, off under sudo, and
#     left as it is when root's password is the only way in, removing this
#     script's own drop-in from an earlier run
#   - with no Include in sshd_config the line goes in the main file
#   - a setting sshd -t refuses is taken back: the drop-in removed, or the
#     main file's line put back as it was
#   - inside a container nothing is touched
#   - install.sh server and both paths of install.sh docker run it, and
#     do_server_setup (which the base image hashes) holds no sshd step

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
INSTALL="$ROOT/maintenance_scripts/install_tools/install.sh"
T=$(mktemp -d) || { echo "mktemp failed"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] && [ "$T" != "/" ] || { echo "no scratch dir"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# The two functions and the print helpers they use, alone.
FN="$T/fn.sh"
for f in print_info print_step print_success print_warning derive_ssh_access configure_ssh_access; do
    awk "/^${f}\\(\\) \\{/,/^}\$/" "$INSTALL" >> "$FN"
done

# One run: a fresh fixture /etc/ssh, then configure_ssh_access with stdin not
# a terminal. Args: include|noinclude, keys|nokeys, then VAR=VALUE settings.
run() {
    local include="$1" keys="$2"; shift 2
    rm -rf "$T/etc" "$T/root"; mkdir -p "$T/etc/sshd_config.d" "$T/root"
    {
        [ "$include" = include ] && echo 'Include /etc/ssh/sshd_config.d/*.conf'
        echo '#PermitRootLogin prohibit-password'
        echo '#MaxAuthTries 6'
    } > "$T/etc/sshd_config"
    [ "$keys" = keys ] && echo 'ssh-ed25519 AAAAC3Nza fixture' > "$T/root/authorized_keys"
    : > "$T/calls"
    (
        RED='' GREEN='' YELLOW='' BLUE='' NC=''
        QUIET_MODE=0 ASSUME_YES=0 KEEP_ROOT_LOGIN=0 STUB_SSHD_RC=0 IN_CONTAINER=1
        unset SUDO_USER
        for kv in "$@"; do eval "$kv"; done
        export SSHD_ETC="$T/etc" ROOT_AUTHORIZED_KEYS="$T/root/authorized_keys"
        is_docker() { [ "$IN_CONTAINER" -eq 0 ]; }
        service_restart() { echo "restart $1" >> "$T/calls"; }
        sshd() { echo "sshd $*" >> "$T/calls"; return "$STUB_SSHD_RC"; }
        . "$FN"
        configure_ssh_access
    ) < /dev/null > "$T/out" 2>&1
}
dropin() { cat "$T/etc/sshd_config.d/00-joinery-root-login.conf" 2>/dev/null | grep '^PermitRootLogin' || echo none; }
mainline() { grep '^#\?PermitRootLogin' "$T/etc/sshd_config"; }
said() { grep -c -- "$1" "$T/out"; }

echo "=== The default: root SSH login off ==="
run include keys
chk "root holding keys, no sudo: off, keys included" "$(dropin)" "PermitRootLogin no"
chk "  checked with sshd -t, then sshd restarted" "$(tr '\n' '|' < "$T/calls")" "sshd -t|restart ssh|"
chk "  and the run says the console still logs in, and names the flag" "$(said "Root SSH login is now off. Your provider's web console still logs in as root")|$(said 're-run with --keep-root-login')" "1|1"
chk "  the rest of the hardening applies" "$(grep -c '^MaxAuthTries 3$' "$T/etc/sshd_config")" "1"
run include nokeys
chk "root's password the only way in: off too" "$(dropin)|$(said 'Root SSH login is now off')" "PermitRootLogin no|1"
run include keys 'SUDO_USER=alice'
chk "under sudo: off, and that account keeps its access" "$(dropin)|$(said "as 'alice' - that account keeps its own SSH access; root SSH login is turned off")|$(said 'Root SSH login is now off')" "PermitRootLogin no|1|0"
run include keys 'ASSUME_YES=1'
chk "-y: off, nothing asked" "$(dropin)|$(said 'Turn root SSH login off?')" "PermitRootLogin no|0"

# With a terminal: the question is asked once, and its answer typed.
asked() {
    rm -rf "$T/etc" "$T/root"; mkdir -p "$T/etc/sshd_config.d" "$T/root"
    printf 'Include /etc/ssh/sshd_config.d/*.conf\n#PermitRootLogin prohibit-password\n' > "$T/etc/sshd_config"
    echo 'ssh-ed25519 AAAAC3Nza fixture' > "$T/root/authorized_keys"
    cat > "$T/asked.sh" <<SH
RED='' GREEN='' YELLOW='' BLUE='' NC=''
QUIET_MODE=0 ASSUME_YES=0 KEEP_ROOT_LOGIN=0; unset SUDO_USER
export SSHD_ETC="$T/etc" ROOT_AUTHORIZED_KEYS="$T/root/authorized_keys"
is_docker() { return 1; }; service_restart() { :; }; sshd() { return 0; }
. "$FN"; configure_ssh_access
SH
    printf '%s' "$1" | script -qec "bash $T/asked.sh" /dev/null > "$T/out" 2>&1
}
if command -v script > /dev/null 2>&1; then
    asked 'y'
    chk "with a terminal, asked once; yes: off" "$(dropin)|$(said 'Turn root SSH login off? \[Y/n\]')" "PermitRootLogin no|1"
    asked 'n'
    chk "  no: the older rules (root holding keys: key-only)" "$(dropin)|$(said 'Keeping root SSH login')|$(said 'Root SSH login is now off')" "PermitRootLogin prohibit-password|1|0"
else
    echo "  SKIP: no script(1) here to give the question a terminal"
fi

echo "=== --keep-root-login: the older rules ==="
run include keys 'KEEP_ROOT_LOGIN=1'
chk "root holding keys: key-only" "$(dropin)|$(said 'Root SSH login is now off')" "PermitRootLogin prohibit-password|0"
run include nokeys 'KEEP_ROOT_LOGIN=1' 'SUDO_USER=alice'
chk "under sudo: off" "$(dropin)" "PermitRootLogin no"
run include nokeys 'KEEP_ROOT_LOGIN=1'
chk "root's password the only way in: no drop-in, and the run says it is left on" "$(dropin)|$(said 'root password login is the only way into this server')" "none|1"
run include nokeys
cp "$T/etc/sshd_config.d/00-joinery-root-login.conf" "$T/earlier.conf"
# The same machine, the earlier run's drop-in still there.
(
    rm -rf "$T/etc" "$T/root"; mkdir -p "$T/etc/sshd_config.d" "$T/root"
    printf 'Include /etc/ssh/sshd_config.d/*.conf\n#PermitRootLogin prohibit-password\n' > "$T/etc/sshd_config"
    cp "$T/earlier.conf" "$T/etc/sshd_config.d/00-joinery-root-login.conf"
    RED='' GREEN='' YELLOW='' BLUE='' NC=''
    QUIET_MODE=0 ASSUME_YES=0 KEEP_ROOT_LOGIN=1; unset SUDO_USER
    export SSHD_ETC="$T/etc" ROOT_AUTHORIZED_KEYS="$T/root/authorized_keys"
    is_docker() { return 1; }; service_restart() { :; }; sshd() { return 0; }
    . "$FN"; configure_ssh_access
) < /dev/null > "$T/out" 2>&1
chk "  re-run with the flag on that machine: the earlier drop-in is removed, and said" "$(dropin)|$(said 'Removed .*00-joinery-root-login.conf from an earlier run')" "none|1"

echo "=== No Include: the main file ==="
run noinclude keys
chk "the line goes in sshd_config, and no drop-in" "$(mainline)|$(dropin)" "PermitRootLogin no|none"

echo "=== A setting sshd refuses is taken back ==="
run include keys 'STUB_SSHD_RC=1'
chk "the drop-in is removed, said, and no 'now off'" "$(dropin)|$(said 'sshd refused the root-login setting')|$(said 'Root SSH login is now off')" "none|1|0"
run noinclude keys 'STUB_SSHD_RC=1'
chk "the main file's line is put back as it was, the rest of the hardening kept" "$(mainline)|$(grep -c '^MaxAuthTries 3$' "$T/etc/sshd_config")" "#PermitRootLogin prohibit-password|1"

echo "=== Inside a container ==="
run include keys 'IN_CONTAINER=0'
chk "nothing is touched and sshd is not restarted" "$(dropin)|$(grep -c '^#MaxAuthTries 6$' "$T/etc/sshd_config")|$(wc -l < "$T/calls")" "none|1|0"

echo "=== Its callers ==="
dock="$(awk '/^do_docker_install\(\) \{/,/^}$/' "$INSTALL")"
chk "install.sh docker takes --keep-root-login" "$(grep -c -- '--keep-root-login) KEEP_ROOT_LOGIN=1 ;;' <<< "$dock")" "1"
chk "and runs it on both its paths" "$(grep -c '^ *configure_ssh_access$' <<< "$dock")" "2"
chk "as does install.sh docker --site-engine" "$(awk '/^do_site_engine_host\(\) \{/,/^}$/' "$INSTALL" | grep -c '^ *configure_ssh_access$')" "1"
srv="$(awk '/^do_server\(\) \{/,/^}$/' "$INSTALL")"
chk "install.sh server takes it and runs it outside an image build" "$(grep -c -- '--keep-root-login) KEEP_ROOT_LOGIN=1 ;;' <<< "$srv")|$(grep -c 'is_docker || configure_ssh_access' <<< "$srv")" "1|1"
chk "do_server_setup, which the base image hashes, holds no sshd step" "$(awk '/^do_server_setup\(\) \{/,/^}$/' "$INSTALL" | grep -c -E 'sshd|PermitRootLogin|derive_ssh_access')" "0"

echo
echo "root_ssh_login gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
