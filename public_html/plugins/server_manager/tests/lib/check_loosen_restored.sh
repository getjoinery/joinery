#!/usr/bin/env bash
#
# check_loosen_restored.sh - a kernel setting loosened on this machine is put
# back within a minute (specs/multi_tenant_host_hardening.md B37; the final
# copy test's rig, specs/site_copy_final_test.md). TEST PLANE ONLY: the live
# tests copy it to a test box over SSH and run it as root there. Never on a
# production machine: it loosens kernel.yama.ptrace_scope for 70 seconds. It
# is the one probe that is no agent word's (copy_probes has every other row),
# so that no shipped script can loosen a kernel setting.
#
# Version: 1.0
#
# One line: PASS or FAIL, what, and its evidence. Exit 0 on PASS. A setting
# not put back is set back here.

set -u
export LC_ALL=C
[[ "$(id -u)" == "0" ]] || { echo "check_loosen_restored: run as root" >&2; exit 2; }
was="$(sysctl -n kernel.yama.ptrace_scope 2>/dev/null)"
sysctl -q -w kernel.yama.ptrace_scope=0 >/dev/null 2>&1
sleep 70
now="$(sysctl -n kernel.yama.ptrace_scope 2>/dev/null)"
if [[ "$now" == "$was" && "$now" != 0 ]]; then
    printf '%-6s %s - %s\n' PASS "A loosened kernel setting is put back within a minute" "ptrace_scope 0 for 70 s, back to ${now}"
    exit 0
fi
printf '%-6s %s - %s\n' FAIL "A loosened kernel setting is put back within a minute" "ptrace_scope ${now} after 70 s (was ${was}); set back by hand"
sysctl -q -w "kernel.yama.ptrace_scope=${was:-1}" >/dev/null 2>&1
exit 1
