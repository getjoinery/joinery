#!/usr/bin/env bash
#
# _kernel_settings.sh - the kernel and network settings every machine pins
# (specs/multi_tenant_host_hardening.md D1), in one list. Sourced, never run:
# host_housekeeping.sh writes them to /etc/sysctl.d and applies them,
# host_report.sh reports whether the running kernel holds them (hardening),
# and multi_tenant_host.sh check says so. Sources nothing.
#
# Version: 1.0
#
# Each is pinned even where Ubuntu ships the same value, so a distro change or
# a hand edit cannot loosen a shared kernel quietly. What each group is for is
# said beside it in host_housekeeping.sh section 11.

KERNEL_SETTINGS=(
    "dev.tty.ldisc_autoload = 0"
    "kernel.unprivileged_bpf_disabled = 1"
    "kernel.dmesg_restrict = 1"
    "vm.unprivileged_userfaultfd = 0"
    "kernel.kptr_restrict = 2"
    "net.core.bpf_jit_harden = 2"
    "kernel.perf_event_paranoid = 4"
    "kernel.yama.ptrace_scope = 1"
    "kernel.kexec_load_disabled = 1"
    "kernel.apparmor_restrict_unprivileged_userns = 1"
    "kernel.randomize_va_space = 2"
    "fs.protected_symlinks = 1"
    "fs.protected_hardlinks = 1"
    "fs.protected_regular = 2"
    "fs.protected_fifos = 2"
    "fs.inotify.max_user_instances = 2048"
)
NETWORK_SETTINGS=(
    "net.ipv4.conf.default.rp_filter = 1"
    "net.ipv4.conf.all.rp_filter = 1"
    "net.ipv4.conf.all.accept_redirects = 0"
    "net.ipv6.conf.all.accept_redirects = 0"
    "net.ipv4.conf.default.accept_redirects = 0"
    "net.ipv6.conf.default.accept_redirects = 0"
    "net.ipv4.conf.all.send_redirects = 0"
    "net.ipv4.conf.default.send_redirects = 0"
    "net.ipv4.conf.all.accept_source_route = 0"
    "net.ipv6.conf.all.accept_source_route = 0"
    "net.ipv4.conf.default.accept_source_route = 0"
    "net.ipv6.conf.default.accept_source_route = 0"
    "net.ipv4.conf.all.log_martians = 1"
    "net.ipv4.conf.default.log_martians = 1"
    "net.ipv4.icmp_echo_ignore_all = 0"
    "net.ipv4.icmp_echo_ignore_broadcasts = 1"
    "net.ipv6.conf.all.disable_ipv6 = 0"
    "net.ipv6.conf.default.disable_ipv6 = 0"
    "net.ipv4.tcp_syncookies = 1"
    "net.ipv4.tcp_max_syn_backlog = 2048"
    "net.ipv4.tcp_synack_retries = 2"
    "net.ipv4.tcp_syn_retries = 5"
)

# The running kernel against the list, one line per pinned setting:
#   held KEY            the kernel holds the pinned value
#   drift KEY LIVE WANT it holds another: the kernel refuses the value, or
#                       something set it since host housekeeping last applied
#                       its file (each run does)
#   unread KEY          this kernel has no such setting, or it cannot be read
#                       here (net.core.bpf_jit_harden is root's alone)
# KERNEL_SETTINGS_PROC (tests only) stands in for /proc/sys.
kernel_settings_state() {
    local setting key want proc got
    for setting in "${KERNEL_SETTINGS[@]}" "${NETWORK_SETTINGS[@]}"; do
        key="${setting%% =*}"; want="${setting##*= }"
        proc="${KERNEL_SETTINGS_PROC:-/proc/sys}/${key//.//}"
        if [[ ! -r "${proc}" ]] || ! got="$(cat "${proc}" 2>/dev/null)"; then
            printf 'unread %s\n' "${key}"
        elif [[ "${got}" == "${want}" ]]; then
            printf 'held %s\n' "${key}"
        else
            printf 'drift %s %s %s\n' "${key}" "${got//[[:space:]]/_}" "${want}"
        fi
    done
}
