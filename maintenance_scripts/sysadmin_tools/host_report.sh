#!/usr/bin/env bash
#
# host_report.sh - the machine this site runs on, as ONE JSON object on stdout:
# failed units, the expected units and their state, fail2ban's jails and how
# many addresses each has banned, how many SSH logins failed in the last day,
# sshd's password and root-login posture, disk, memory, swap, whether a reboot
# is pending, when unattended-upgrades last ran, the operating system with
# the release upgrade Ubuntu last said it offers, and on a Docker host each
# site container's state and figures.
#
# Version: 1.39 - php_workers: PHP-FPM's worker ceiling in the version Apache uses and where it came from
#                (tune_php_fpm.sh's plan line, or packaged), the host reserve beside what the machine's
#                other processes actually hold, and PostgreSQL's settings pending a restart
#                (specs/php_fpm_sizing_bare_metal.md WP5)
# Version: 1.38 - kernel_events_24h counts conntrack_full ("nf_conntrack: table full, dropping packet") and
#                syn_flood ("Possible SYN flooding on port"): the kernel dropping connections, which a site
#                behind an edge sees as requests that hang (specs/slow_request_diagnosis_words.md WP4)
# Version: 1.37 - security_pending: the security fixes this machine is waiting for, from its hourly check
#                (security_check.sh), each with when it was first seen, and the check's at-once install
#                (WP6, Q2); services_needing_restart: what still runs code an upgrade replaced
#                (needrestart, else /proc/*/exe "(deleted)") (fleet_security_patching.md WP1).
# Version: 1.36 - site_refresh.running: the nightly replacement's service is running now (a night's run, or
#                Refresh now's: site_refresh.sh start; fleet_security_patching.md WP4).
# Version: 1.35 - data_root_encryption.recipient_set_by: who named the recovery key the break-glass copy is sealed to
#               (site, operator or install), so a management node can mark its own key given at install as its own
# Version: 1.34 - kernel_warning counts a WARN() by its "WARNING: CPU:" line, not a boot notice about a CPU's
#               mitigations, which says WARNING on every boot of every machine with that CPU
# Version: 1.33 - site_security: the sha256 of the system call filter this host's sites are made under and the
#               capabilities a site adds (the run spec's), which each site's own (container_inventory) is checked
#               against from the management node (site_copy_final_test.md, the rig)
# Version: 1.32 - sites: the Joinery sites in /var/www/html on this machine (each directory with a public_html), so a
#               site copy knows a server serves more than the site it moves (site_copy_anything_to_anything D8)
# Version: 1.31 - site_refresh: the nightly replacement of this host's site containers (site_refresh.sh's
#                state: the last check, what Ubuntu had pending, the base, the last run, the sites replaced
#                with their seconds down, the holds failed replacements placed (read from held/ itself, so
#                a hold lifted shows lifted at once), and whether the timer is on);
#                a container recreate_site.sh is replacing says so (replacing: true, absent otherwise),
#                which container_health leaves alone (specs/multi_tenant_host_hardening.md D9).
# Version: 1.30 - served_certificates: self_signed, true where the certificate's issuer is its own
#                subject (the install placeholder, or anything else no browser trusts).
# Version: 1.29 - hardening: whether the running kernel holds every setting host housekeeping pins
#                (install_tools/_kernel_settings.sh; specs/multi_tenant_host_hardening.md WP1), and each one it
#                does not. kernel_events_24h counts kernel_warning, the kernel's own WARNING/BUG/oops lines (D7),
#                and a quiet machine's counts read 0, not unknown: journalctl -g exits 1 when nothing
#                matches, which read as an unreadable journal on every machine (B35); one that complains is
#                still unknown (reviewer2 R2). A BUG() is matched by 'kernel BUG at' too (R1).
# Version: 1.28 - data_root_stopped: a data root stopped by hand (joinery_data_root.sh stop) says when, and
#                which services stopped with it, so the management node tells a deliberate stop from a
#                fault (reviewer2 B17 D2); null while it is not stopped, or there is no data root
# Version: 1.27 - a site container this host records as quiet (/etc/joinery/sites/<site>/quiet, a dormant
#                copy or a frozen source) answers "quiet", not "no": its pages are the quiet state's 503,
#                and container_health restarted a copy waiting for its key for not answering through PHP
#                (specs/site_container_engine.md B27).
# Version: 1.26 - site_engine: which engine this host's sites run on (podman, docker, none or unknown), for
#                the management node to choose a host for a copy by (specs/site_container_engine.md D9).
# Version: 1.25 - the site container engine is installed where anything is at its current path, as
#                _site_engine.sh reads it (reviewer2 R7, T4), and its podman is run by path (T3); a site's
#                code_check that is a link is not followed (T1).
# Version: 1.24 - containers are read through the engine this host's sites run on (install_tools/
#                _site_engine.sh): Docker, or the site container engine (specs/site_container_engine.md
#                WP3), whose start time is asked in Docker's RFC 3339 form so started_at holds.
# Version: 1.23 - data_root_script: on a host that declares a data root, the version of the data root
#                 script a run's unit starts beside the release's, and each service it names as living
#                 on the data root with whether its drop-in is written and its unit installed. boot_id:
#                 the kernel's, so the management node sees a reboot happen (reboot_machine).
#                 (specs/agent_recipes_and_vocabulary.md, the running list.) A site container
#                 engine (Podman) container is a container too (/run/.containerenv), so its
#                 memory is its own group's, not the shared server's.
# Version: 1.22 - disk_pool.device: the device a data root on a device sits on (a Block Storage volume's
#                 by-id path), so the provider's layer under it is the volume's, not the server's disk's.
# Version: 1.21 - host_restrictions: what the machine refuses its sites (its restrictions file), and each
#                site container carries code_check, from its config/code_check: whether its code is what
#                was shipped, on a host that refuses unsigned code (specs/host_restrictions.md D8).
# Version: 1.20 - mail_gateway: the mail apps gateway (plugins/mail_apps, install_mail_gateway.sh) where
#                it is installed - its version, its unit's state, how often systemd restarted it
#                and for how long it has been up, its open connections, and for each site turned
#                on whether IMAP (993) and submission (465) greet under that site's mail name and
#                how many days its certificate has left; "none" where it is not installed.
# Version: 1.19 - data_root_encryption: an encrypted data root's state - open or locked, what opened
#                it, the unlock identity's public half, the escrow holder and slot, the recovery
#                recipient's fingerprint and the break-glass box (sealed, so public); "none" where
#                the data root is not encrypted (specs/hosted_data_promise_disk_encryption.md WP2).
# Version: 1.18 - disk_pool says "not_mounted" where this host declares a data root
#                (/etc/joinery/data_root) that is not mounted, and "none" only where it has
#                none: the node page offers to move a host's data only on the second
#                (specs/one_data_root.md WP4).
# Version: 1.17 - disk_pool reports the host's data root, /srv/joinery (specs/one_data_root.md WP1):
#                a Docker host's disk pool is that data root, and on any host it is a
#                filesystem of its own whose filling the root disk never shows. The key
#                keeps its name, which the agent's disk_headroom floor reads.
# Version: 1.16 - root_ssh: the public keys in root's authorized_keys, each with its SHA256 fingerprint,
#                so a copy of this machine can carry them (specs/site_copy.md WP15). A key line is
#                carried only when it is a bare key (type, key, comment): a line with options
#                (command=, from=, restrict) is listed with its fingerprint and carry:false, because
#                carrying it without its options would widen what it allows. Public keys only; capped
#                at 20 keys. "unknown" where root's file cannot be read.
# Version: 1.15 - a host's sites are listed up to 100 (MAX_SITES), not 20: a multi-tenant
#                host takes 50 (specs/multi_tenant_docker_hosts.md WP7 N1). To fit the agent's
#                minute, every container is read by ONE docker inspect, and every running
#                site is asked whether it answers at the same time, so the report costs its
#                slowest site rather than the sum. outbound_limits.sites is capped the same.
#                disk_pool: on a host whose /var/lib/docker is a disk pool of its own
#                (docker_disk_pool.sh, WP4), that filesystem's figures, which the root disk's
#                never show: the pool's file is allocated whole; "none" where it is not a
#                mount of its own (reviewer2 B2). A docker inspect that answers nothing (it
#                timed out, say) makes the containers "unknown", not an empty list (N5).
# Version: 1.14 - outbound_limits carries the figures while the limits are off too: what turning
#                them on brings back (outbound_limits.sh 1.3 keeps them in its status). Each
#                site's figures carry set_by: plane where the management node set the machine's
#                or that site's own (the status's site_set_by).
# Version: 1.12 - outbound_limits carries the figures in force (node_outbound_and_transfer WP5):
#                figures (the machine's ceiling_mbit, conn_rate, conn_burst, open_conns, and
#                set_by, plane where the management node set them), web_ceiling_mbit (the web
#                server's user's ceiling in force), and sites (each limited site's figures in
#                force). A ceiling is a number, or none.
# Version: 1.11 - outbound_limits: whether the machine's outbound connection limits are in
#                force (outbound_limits.sh, specs/node_outbound_and_transfer.md WP3), why
#                not where they are not (a code), since when their counters run, and the
#                web server's user's drops on a bare-metal machine. A reason rides with on
#                too: the last change was refused and the table before it is in force. Each site container
#                carries outbound_dropped: packets its limits dropped since then, or none
#                where it is not limited. Counters only; the plane turns two into a rate.
# Version: 1.10 - each site container says whether it is held stopped (held: hold_container's
#                 mark, /etc/joinery/sites/{site}/held), so container_health leaves it stopped.
# Version: 1.8 - reboot_required_since: when the pending reboot was first asked for (the
#                birth time of /var/run/reboot-required, which each later update rewrites in
#                place; its change time where the filesystem keeps no birth time), or null
#                with none pending. A multi-tenant host reboots itself the night an update
#                asks, so one pending for more than a day is one that did not happen
#                (specs/multi_tenant_docker_hosts.md WP5 item 7).
# Version: 1.7 - Each site container carries its own figures (specs/multi_tenant_docker_hosts.md
#                WP1): memory in use, its peak, its limit and how many times the kernel
#                killed a process in it for memory; CPU used and its ceiling; processes
#                and their ceiling; bytes sent; and the disk its volumes hold, from one
#                bounded du. Counters are cumulative since started_at; the plane turns
#                two reports into a rate. Inside a container, memory is the site's own
#                group out of its limit, not the shared server's /proc/meminfo.
# Version: 1.6 - cpus: how many processors the machine has (nproc), so a load
#                average can be read against it.
#                answers: on a quiet site (/etc/joinery/sites/<site>/state, a
#                dormant copy or a frozen source) every page is the quiet
#                state's 503, so php-fpm reads "quiet", not "no": the probe
#                never reaches PHP by design, and a "no" there opened a case
#                and tried restarts on every dormant copy (site copy B37).
# Version: 1.5 - sshd widens to the effective settings a lockout turns on
#                (specs/agent_recipes_and_vocabulary.md, Host files): public-key
#                and keyboard-interactive authentication, maximum auth tries,
#                the ports, and the allowed users and groups, each read out of
#                sshd -T by a compiled key. The two existing keys are unchanged.
#                answers: whether Apache, PHP-FPM and PostgreSQL answer, not
#                merely run (Apache and FPM by one loopback request for the
#                site's own name, PostgreSQL by pg_isready, no credential read).
#                php-fpm is "no" only on Apache's 502/503/504 without the
#                version header; any other headerless answer is unknown.
#                containers: each Joinery container's state, health, and
#                whether its site answers. Both feed the service_health recipe.
#                served_certificates: days left on the certificate each of the
#                site's names serves, the vhost's ServerName marked primary (the
#                name certificate_expiry renews), for the certificate_expiry recipe.
#                php-fpm in answers resolves to the ACTIVE unit, else the newest.
# Version: 1.4 - os:the distribution, its version and codename from
#                os-release, and the release upgrade Ubuntu's own daily check
#                last recorded (the release it offers, or none) with when it
#                recorded it. The check's cache is read, never refreshed: this
#                script makes no network call.
# Version: 1.3 - inode use is read with --output=ipcent alone (df refuses -i beside
#                --output, so the figure was always unknown); the three kernel
#                counts read the SYSTEM journal, because ENOSPC is an errno a
#                userspace program reports and never appears in the kernel ring —
#                the read that proved it (2026-09-22) was a mandb line, not a
#                kernel line.
# Version: 1.2 - disk carries avail_bytes and inodes_used_pct (what a writer can
#                actually use, and the other way a disk fills); kernel_events_24h
#                counts the three kernel events that explain a write that failed.
# Version: 1.1 - ssh_auth_failures_24h is unknown, not 0, where systemd does
#                not answer: a container has no system journal to count from.
# Version: 1.0 - the host_report observe word of specs/agent_tier1_recipes.md.
#                The agent runs this file, verified against the release
#                manifest, with no argv and no stdin; the plane stores what it
#                prints and renders the Host card from it.
#
# THE CONTRACT, which tests/integration/host_report_gate.sh pins:
#
#   - It takes NOTHING: no argument, no stdin, no environment hook. What it
#     reports is a compiled list of facts and the keys are a compiled set.
#     A caller cannot choose a unit, a jail, a file or a line count.
#   - Every key is ALWAYS present. A fact this run cannot read (no root, no
#     journal access, fail2ban not installed, systemd not answering) is the
#     string "unknown" for that key, never an error for the whole report, and
#     the exit code is 0 whenever the object was printed. Run as an ordinary
#     user it still prints the object, with unknowns where root was needed.
#   - Every list is capped here (20 failed units, 20 jails, 100 sites) and every string
#     is capped and reduced to a safe character set, so the agent's output cap
#     is never the thing that bounds this report and nothing a unit or jail
#     was named can break the JSON.
#   - COUNTS ONLY for SSH authentication failures and for kernel events. No
#     usernames, no source addresses, no kernel message text, ever: the journal
#     lines are counted and discarded. The spec records this as the line
#     (agent_tier1_recipes.md, "Accepted risk").
#   - READ ONLY. Every command here is a read: systemctl show and list-units,
#     fail2ban-client status, sshd -T, journalctl, df, /proc, stat. The sshd
#     posture is reported, never written.
#   - Each command runs under its own short timeout, so a stuck daemon costs
#     that one key its answer and never the report.
#
# The disk figure is for the filesystem holding this site's web root, derived
# from this file's own location (site root = two levels up), the way the host
# installers runner derives its site root. On a machine with no public_html
# beside this script (a support-bundle host) it is the root filesystem, and
# the object says which path it measured.
#
# Runs on: any systemd host. Nothing here is Ubuntu-specific except the
# unattended-upgrades stamp and the release-upgrade cache, each unknown where
# it is absent.

set -u
export LC_ALL=C

CMD_TIMEOUT=10          # seconds per external command
MAX_LIST=20             # failed units, jails
MAX_SITES=100           # site containers, and the sites in outbound_limits
MAX_NAME=64             # characters kept of any unit or jail name
MAX_PACKAGES=200        # security fixes waiting

SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WEB_ROOT="$SITE_ROOT/public_html"
[[ -d "$WEB_ROOT" ]] || WEB_ROOT="/"

# ---------------------------------------------------------------------------
# Emission helpers. JSON is written by hand so the script depends on nothing
# but coreutils: every string that reaches the object is first reduced to
# [A-Za-z0-9._@:-] and capped, so there is never anything to escape.
# ---------------------------------------------------------------------------
safe_name() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '%s' "${s:0:$MAX_NAME}"
}
json_str() { printf '"%s"' "$(safe_name "$1")"; }
# A file path inside a package: the same characters plus /, capped longer.
json_path() { local drop='[^A-Za-z0-9._@:/-]' s; s="${1//$drop/}"; printf '"%s"' "${s:0:200}"; }
# A number, or the string unknown when the value is not all digits.
json_num_or_unknown() {
    if [[ "$1" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '"unknown"'; fi
}
run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }

# ---------------------------------------------------------------------------
# systemd units
# ---------------------------------------------------------------------------
# One unit's state as one of active|inactive|failed|absent, or unknown when
# systemd did not answer.
unit_state() {
    local unit="$1" load active
    load="$(run systemctl show -p LoadState --value "$unit")" || { printf 'unknown'; return; }
    [[ -n "$load" ]] || { printf 'unknown'; return; }
    case "$load" in
        not-found|masked) printf 'absent'; return ;;
    esac
    active="$(run systemctl show -p ActiveState --value "$unit")" || { printf 'unknown'; return; }
    case "$active" in
        active|activating|reloading|refreshing) printf 'active' ;;
        inactive|deactivating)                  printf 'inactive' ;;
        failed)                                 printf 'failed' ;;
        *)                                      printf 'unknown' ;;
    esac
}

# The php-fpm unit carries its version in its name (php8.3-fpm.service). The
# one this host runs is the ACTIVE one; with none active, the newest version
# installed (a host with 8.1 left beside 8.3 serves 8.3). The same rule as
# restart_unit.sh, so a restart lands on the unit this report judged.
php_fpm_unit() {
    local u
    u="$(run systemctl list-units 'php*-fpm.service' --state=active --plain --no-legend --no-pager | awk 'NR==1 {print $1}')"
    if [[ -z "$u" ]]; then
        u="$(run systemctl list-unit-files 'php*-fpm.service' --plain --no-legend --no-pager | awk '{print $1}' | sort -V | tail -n 1)"
    fi
    printf '%s' "$u"
}

emit_expected_units() {
    local fpm
    fpm="$(php_fpm_unit)"
    printf '{'
    printf '"fail2ban":"%s",' "$(unit_state fail2ban.service)"
    printf '"apache2":"%s",' "$(unit_state apache2.service)"
    if [[ -n "$fpm" ]]; then
        printf '"php-fpm":"%s",' "$(unit_state "$fpm")"
    else
        printf '"php-fpm":"absent",'
    fi
    printf '"cron":"%s",' "$(unit_state cron.service)"
    printf '"postgresql":"%s"' "$(unit_state postgresql.service)"
    printf '}'
}

emit_failed_units() {
    local out n=0 name first=1
    out="$(run systemctl list-units --state=failed --plain --no-legend --no-pager)" \
        || { printf '"unknown"'; return; }
    printf '['
    while read -r name _; do
        [[ -n "$name" ]] || continue
        (( n < MAX_LIST )) || break
        (( first )) || printf ','
        first=0
        json_str "$name"
        n=$((n+1))
    done <<< "$out"
    printf ']'
}

# ---------------------------------------------------------------------------
# fail2ban
# ---------------------------------------------------------------------------
emit_fail2ban_jails() {
    local status jails jail n=0 first=1 banned line
    command -v fail2ban-client >/dev/null 2>&1 || { printf '"unknown"'; return; }
    status="$(run fail2ban-client status)" || { printf '"unknown"'; return; }
    jails="$(printf '%s\n' "$status" | awk -F'Jail list:' '/Jail list:/ {print $2}' | tr ',' ' ')"
    printf '['
    for jail in $jails; do
        (( n < MAX_LIST )) || break
        jail="$(safe_name "$jail")"
        [[ -n "$jail" ]] || continue
        banned="unknown"
        if line="$(run fail2ban-client status "$jail")"; then
            banned="$(printf '%s\n' "$line" | awk -F'Currently banned:' '/Currently banned:/ {gsub(/[^0-9]/,"",$2); print $2; exit}')"
        fi
        (( first )) || printf ','
        first=0
        printf '{"name":%s,"banned":%s}' "$(json_str "$jail")" "$(json_num_or_unknown "$banned")"
        n=$((n+1))
    done
    printf ']'
}

# ---------------------------------------------------------------------------
# SSH authentication failures, last 24 hours: a COUNT. The matched lines are
# counted by grep and never printed; nothing from them reaches the object.
# --system makes journalctl exit non-zero when the journal is unreadable,
# which is what tells "unknown" from "none".
# ---------------------------------------------------------------------------
emit_ssh_auth_failures() {
    local lines count
    # Where systemd does not answer there is no system journal to count from:
    # in a container journalctl exits clean with nothing, and that read as
    # zero failures. Zero means the journal holds none, never that there is no
    # journal. The same signal the unit states already fail on.
    run systemctl show -p Version --value >/dev/null || { printf '"unknown"'; return; }
    lines="$(run journalctl --system -u ssh -u sshd --since "24 hours ago" --no-pager -o cat)" \
        || { printf '"unknown"'; return; }
    count="$(printf '%s\n' "$lines" | grep -c -E 'Failed password|Invalid user|authentication failure|Failed publickey')"
    json_num_or_unknown "$count"
}

# ---------------------------------------------------------------------------
# sshd posture, as sshd -T prints it (root only: it has to open the host keys).
# Reported, never written.
# ---------------------------------------------------------------------------
#
# The EFFECTIVE settings, not a file: Ubuntu's cloud images set
# PasswordAuthentication in sshd_config.d/, so sshd_config alone misreads a
# lockout. Only the compiled keys below are read out of sshd -T; nothing else
# it prints (host key paths, the authorized-keys file) reaches the object.
SSHD_WORDS=(passwordauthentication permitrootlogin pubkeyauthentication kbdinteractiveauthentication maxauthtries)
sshd_value() { printf '%s\n' "$2" | awk -v k="$1" '$1==k {print $2; exit}'; }
# Every value of a key sshd -T may print more than once (port, allowusers,
# allowgroups), as a JSON list, capped and sanitised.
sshd_list() {
    local key="$1" conf="$2" v n=0 first=1
    printf '['
    while read -r v; do
        [[ -n "$v" ]] || continue
        (( n < MAX_LIST )) || break
        (( first )) || printf ','
        first=0
        json_str "$v"
        n=$((n+1))
    done < <(printf '%s\n' "$conf" | awk -v k="$key" '$1==k { for (i = 2; i <= NF; i++) print $i }')
    printf ']'
}

emit_sshd() {
    local conf k v
    local -A val=()
    if conf="$(run sshd -T)" && [[ -n "$conf" ]]; then
        for k in "${SSHD_WORDS[@]}"; do
            v="$(sshd_value "$k" "$conf")"
            val[$k]="${v:-unknown}"
        done
        printf '{"password_authentication":%s,"permit_root_login":%s' \
            "$(json_str "${val[passwordauthentication]}")" "$(json_str "${val[permitrootlogin]}")"
        printf ',"pubkey_authentication":%s,"kbd_interactive_authentication":%s,"max_auth_tries":%s' \
            "$(json_str "${val[pubkeyauthentication]}")" "$(json_str "${val[kbdinteractiveauthentication]}")" \
            "$(json_num_or_unknown "${val[maxauthtries]}")"
        printf ',"ports":%s,"allow_users":%s,"allow_groups":%s}' \
            "$(sshd_list port "$conf")" "$(sshd_list allowusers "$conf")" "$(sshd_list allowgroups "$conf")"
    else
        printf '{"password_authentication":"unknown","permit_root_login":"unknown"'
        printf ',"pubkey_authentication":"unknown","kbd_interactive_authentication":"unknown","max_auth_tries":"unknown"'
        printf ',"ports":"unknown","allow_users":"unknown","allow_groups":"unknown"}'
    fi
}

# ---------------------------------------------------------------------------
# root's authorized keys: public halves and fingerprints, so a copy can carry them.
# A line is carried (carry:true) only when it is a bare key; one with options is
# listed with its fingerprint and carry:false, never rewritten without them.
# ---------------------------------------------------------------------------
KEY_TYPE_RE='^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com)$'
emit_root_ssh() {
    local dir="${HOST_REPORT_ROOT_SSH_DIR:-/root/.ssh}" file line type blob comment fp n=0 first=1 carry
    file="$dir/authorized_keys"
    # Readable: read it. Unreadable as root: there is no file, so no keys. Unreadable as anyone
    # else: it could not be read, which is not the same as none.
    if [[ ! -r "$file" ]]; then
        if [[ "$(id -u)" == 0 ]]; then printf '{"keys":[]}'; else printf '"unknown"'; fi
        return
    fi
    printf '{"keys":['
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%$'\r'}"
        [[ "$line" =~ ^[[:space:]]*($|#) ]] && continue
        (( n < MAX_LIST )) || break
        fp="$(printf '%s\n' "$line" | run ssh-keygen -lf /dev/stdin -E sha256 | awk 'NR==1{print $2}')"
        fp="${fp//[^A-Za-z0-9+\/:=]/}"
        carry=false; type=""; blob=""; comment=""
        read -r type blob comment <<<"$line"
        if [[ "$type" =~ $KEY_TYPE_RE && "$blob" =~ ^[A-Za-z0-9+/=]+$ && -n "$fp" ]]; then
            carry=true
            comment="$(safe_name "$comment")"
        else
            type=""; blob=""; comment=""
        fi
        (( first )) || printf ','
        first=0
        printf '{"fingerprint":"%s","carry":%s,"type":"%s","key":"%s","comment":"%s"}' \
            "${fp:-unknown}" "$carry" "$type" "$blob" "$comment"
        n=$((n+1))
    done < "$file"
    printf ']}'
}

# ---------------------------------------------------------------------------
# disk, memory, swap
# ---------------------------------------------------------------------------
# avail is NOT total minus used. A filesystem keeps blocks back for root (5% by
# default on ext4 — 2.4 GiB on a 48 GiB disk), so the subtraction overstates
# what a writer can use by exactly the amount that matters when a disk is
# filling. df knows the real figure; it is reported rather than inferred.
#
# inodes_used_pct is the other way a disk fills: a table with no free inodes
# refuses writes while df shows space. A filesystem that does not count inodes
# (btrfs, zfs) prints "-" for it, which is unknown, not zero.
emit_disk() {
    local line used total avail ipct
    line="$(run df -B1 --output=used,size,avail "$WEB_ROOT" | tail -n 1)"
    read -r used total avail <<< "$line"
    ipct="$(run df --output=ipcent "$WEB_ROOT" | tail -n 1)"
    ipct="${ipct//[^0-9]/}"
    printf '{"path":%s,"used_bytes":%s,"total_bytes":%s,"avail_bytes":%s,"inodes_used_pct":%s}' \
        "\"$(printf '%s' "$WEB_ROOT" | tr -cd 'A-Za-z0-9._/-' | head -c 200)\"" \
        "$(json_num_or_unknown "${used:-}")" "$(json_num_or_unknown "${total:-}")" \
        "$(json_num_or_unknown "${avail:-}")" "$(json_num_or_unknown "${ipct:-}")"
}

# The host's data root (joinery_data_root.sh), reported under disk_pool: a
# filesystem of its own, in a file allocated whole or on a device, whose
# filling the root disk never shows. A Docker host's disk pool is its data
# root. "none" where this host has none; "not_mounted" where it declares one
# (/etc/joinery/data_root) and /srv/joinery is not a mount of its own.
emit_disk_pool() {
    local mp="${HOST_REPORT_DATA_ROOT:-/srv/joinery}" fstype opts line used total avail
    local conf="${HOST_REPORT_DATA_ROOT_CONF:-/etc/joinery/data_root}" backing device=""
    read -r fstype opts < <(run findmnt -n -o FSTYPE,OPTIONS --mountpoint "$mp") || true
    if [[ -z "${fstype:-}" ]]; then
        # Declared and down is not the same answer as never made.
        if [[ -f "${HOST_REPORT_DATA_ROOT_CONF:-/etc/joinery/data_root}" ]]; then printf '"not_mounted"'; else printf '"none"'; fi
        return
    fi
    line="$(run df -B1 --output=used,size,avail "$mp" | tail -n 1)"
    read -r used total avail <<< "$line"
    # On a device (a Block Storage volume), which one: the provider's own
    # encryption under it is that volume's, not the server's disk's.
    backing="$(awk -F= '$1=="backing" { print $2; exit }' "$conf" 2>/dev/null)"
    if [[ "$backing" == device || "$backing" == luks-device ]]; then
        device="$(awk -F= '$1=="source" { sub(/^[^=]*=/, ""); print; exit }' "$conf" 2>/dev/null | tr -cd 'A-Za-z0-9._/:-' | head -c 200)"
    fi
    printf '{"path":"%s","fstype":%s,"prjquota":%s,"used_bytes":%s,"total_bytes":%s,"avail_bytes":%s,"device":"%s"}' \
        "$(printf '%s' "$mp" | tr -cd 'A-Za-z0-9._/-' | head -c 200)" "$(json_str "$fstype")" \
        "$([[ ",${opts:-}," == *",prjquota,"* ]] && echo true || echo false)" \
        "$(json_num_or_unknown "${used:-}")" "$(json_num_or_unknown "${total:-}")" "$(json_num_or_unknown "${avail:-}")" "$device"
}

# The encrypted data root (specs/hosted_data_promise_disk_encryption.md D3,
# D2b): whether it is open and what opened it, the unlock identity's PUBLIC
# half (the private seed beside it is never read), the escrow holder and key
# slot, the recovery recipient's fingerprint and who named it, and the break-glass box, which
# is sealed to the recovery key and so public. Through this report, which the
# node's own key signs, its management node learns which unlock identity is
# this node's. "none" where the data root is not encrypted.
emit_data_root_encryption() {
    local conf="${HOST_REPORT_DATA_ROOT_CONF:-/etc/joinery/data_root}" dir="${HOST_REPORT_DATA_ROOT_DIR:-/etc/joinery/data-root}"
    local backing sidecar open=false identity holder slot recipient set_by fpr sealed opened held
    backing="$(awk -F= '$1=="backing" { print $2; exit }' "$conf" 2>/dev/null)"
    [[ "$backing" == luks-* ]] || { printf '"none"'; return; }
    [[ -e "${HOST_REPORT_MAPPER:-/dev/mapper/joinery-data}" ]] && open=true
    kv() { awk -F= -v k="$1" '$1 == k { sub(/^[^=]*=/, ""); print; exit }' "$2" 2>/dev/null; }
    sidecar="$(kv sidecar "$conf")"
    identity="$(kv public_key "$dir/unlock_identity" | tr -cd 'A-Za-z0-9+/=' | head -c 64)"
    holder="$(kv url "$dir/holder" | tr -cd 'A-Za-z0-9.:/-' | head -c 200)"
    slot="$(kv slot "$dir/holder")"
    recipient="$(kv fingerprint "$dir/recovery_recipient" | tr -cd '0-9a-f' | head -c 64)"
    set_by="$(kv set_by "$dir/recovery_recipient" | tr -cd 'a-z' | head -c 16)"
    fpr="$(grep -o '"fingerprint": *"[0-9a-f]*"' "${HOST_REPORT_ROOT:-}${sidecar}" 2>/dev/null | head -n 1 | grep -o '[0-9a-f]\{64\}' | head -n 1)"
    sealed="$(grep -o '"sealed": *"[A-Za-z0-9+/=]*"' "${HOST_REPORT_ROOT:-}${sidecar}" 2>/dev/null | head -n 1 | sed 's/.*: *"//; s/"$//' | tr -cd 'A-Za-z0-9+/=' | head -c 400)"
    opened="$(head -c 200 /run/joinery/data-root.opened-by 2>/dev/null | tr -cd 'A-Za-z0-9 ,.:/-')"
    # A reboot an update asked for and the tick held back (D4): when, and why.
    held="$(head -c 400 /run/joinery/data-root.reboot-held 2>/dev/null | tr -cd 'A-Za-z0-9 ,.:/()_-')"
    # An encryption or its undo running (D5): which, and how far, in percent.
    local mode pct="" progress
    mode="$(kv reencrypt "$conf" | tr -cd 'a-z')"
    if [[ -n "$mode" ]]; then
        progress="$(head -c 600 /run/joinery/data-root-encrypt.progress 2>/dev/null)"
        pct="$(printf '%s' "$progress" | sed -n 's/.*"device_bytes":"\{0,1\}\([0-9]*\).*"device_size":"\{0,1\}\([0-9]*\).*/\1 \2/p' | awk '$2 > 0 { printf "%.1f", $1 * 100 / $2 }')"
    fi
    printf '{"backing":%s,"open":%s,"opened_by":"%s","identity":"%s","holder":"%s","slot":%s,"recipient_fingerprint":"%s","recipient_set_by":"%s","box_fingerprint":"%s","box_sealed":"%s","reboot_held":"%s","reencrypt":"%s","reencrypt_percent":"%s"}' \
        "$(json_str "$backing")" "$open" "$opened" "$identity" "$holder" "$(json_num_or_unknown "${slot:-}")" "$recipient" "$set_by" "$fpr" "$sealed" "$held" "$mode" "$pct"
}

# A data root stopped by hand (joinery_data_root.sh stop; reviewer2 B17 D2):
# {"at": ISO UTC time, "services": [unit, ...]}, from the record the stop
# writes and start or the tick removes; null while it is not stopped, or the
# host declares no data root. Plain or encrypted alike.
emit_data_root_stopped() {
    local conf="${HOST_REPORT_DATA_ROOT_CONF:-/etc/joinery/data_root}"
    local flag="${HOST_REPORT_DATA_ROOT_STOPPED:-/run/joinery/data-root.stopped}" first at unit k=0 sep=""
    [[ -f "$conf" && -f "$flag" && ! -L "$flag" ]] || { printf 'null'; return; }
    first="$(head -n 1 "$flag" 2>/dev/null)"
    [[ "$first" =~ ^stopped\ by\ hand\ at\ ([0-9]{4}-[0-9]{2}-[0-9]{2})\ ([0-9]{2}:[0-9]{2}:[0-9]{2})\ UTC$ ]] || { printf 'null'; return; }
    at="${BASH_REMATCH[1]}T${BASH_REMATCH[2]}Z"
    printf '{"at":"%s","services":[' "$at"
    while IFS= read -r unit; do
        [[ "$unit" =~ ^[a-z0-9@._-]{1,60}\.(service|socket|mount|target)$ ]] || continue
        (( k < MAX_LIST )) || break
        printf '%s%s' "$sep" "$(json_str "$unit")"; sep=","; k=$((k + 1))
    done < <(tail -n +2 "$flag" 2>/dev/null | head -n 200)
    printf ']}'
}

# The data root's own script, on a host that declares a data root
# (/etc/joinery/data_root): the version of the copy a run's unit starts
# (/usr/local/sbin/joinery-data-root, which the tick brings up to the
# release's) beside the release's own, and for each service the script names
# as living on the data root (its CONSUMERS, a closed list in the script)
# whether the drop-in that orders it after the data root is written and
# whether the unit is installed here at all. "none" where the host declares no
# data root.
script_version() {  # FILE: its first "# Version: X.Y" line's number, or empty
    grep -m 1 -o '^# Version: [0-9][0-9.]*' "$1" 2>/dev/null | sed 's/^# Version: //' | head -c 16
}
emit_data_root_script() {
    local conf="${HOST_REPORT_DATA_ROOT_CONF:-/etc/joinery/data_root}"
    local installed="${HOST_REPORT_DATA_ROOT_SBIN:-/usr/local/sbin/joinery-data-root}"
    local release="$SITE_ROOT/maintenance_scripts/install_tools/joinery_data_root.sh"
    local sysdir="${HOST_REPORT_ETC:-/etc}/systemd/system" units="" unit listed sep="" k=0 v_inst v_rel
    [[ -f "$conf" ]] || { printf '"none"'; return; }
    v_inst="$(script_version "$installed")"; v_rel="$(script_version "$release")"
    # The release's list is what should be there now; an installed copy alone
    # (a host whose tree has no copy) speaks for itself.
    for f in "$release" "$installed"; do
        units="$(sed -n 's/^CONSUMERS="\([^"]*\)"$/\1/p' "$f" 2>/dev/null | head -n 1 | tr -cd 'a-z0-9@._ -')"
        [[ -n "$units" ]] && break
    done
    # One list of unit files for every unit: the ones it lists are installed.
    # shellcheck disable=SC2086  # the units are checked above: one word each
    listed=" $(run systemctl list-unit-files --no-legend --no-pager $units | awk '{print $1}' | tr '\n' ' ') "
    printf '{"installed_version":%s,"release_version":%s,"consumers":[' \
        "$([[ -n "$v_inst" ]] && json_str "$v_inst" || printf '"none"')" \
        "$([[ -n "$v_rel" ]] && json_str "$v_rel" || printf '"unknown"')"
    for unit in $units; do
        [[ "$unit" =~ ^[a-z0-9@._-]{1,60}\.service$ ]] || continue
        (( k < MAX_LIST )) || break
        printf '%s{"unit":%s,"drop_in":%s,"installed":%s}' "$sep" "$(json_str "$unit")" \
            "$([[ -f "${sysdir}/${unit}.d/joinery-data-root.conf" ]] && echo true || echo false)" \
            "$([[ "$listed" == *" $unit "* ]] && echo true || echo false)"
        sep=","; k=$((k+1))
    done
    printf ']}'
}

# The kernel's boot id: a new one each boot. The management node keeps the one
# a reboot_machine job saw and reads this to know the machine came back.
emit_boot_id() {
    local b
    b="$(tr -cd '0-9a-f-' < /proc/sys/kernel/random/boot_id 2>/dev/null | head -c 36)"
    if [[ -n "$b" ]]; then printf '"%s"' "$b"; else printf '"unknown"'; fi
}

meminfo_kb() { awk -v k="$1" '$1==k":" {print $2; exit}' /proc/meminfo 2>/dev/null; }
kb_to_bytes_or_unknown() {
    if [[ "${1:-}" =~ ^[0-9]+$ ]]; then printf '%s' $(( $1 * 1024 )); else printf '"unknown"'; fi
}
# ---------------------------------------------------------------------------
# cgroup v2 figures. Every reader is a file read under /sys/fs/cgroup or /proc
# (they cannot stall), and each prints nothing when the file says nothing
# usable, which the caller turns into "unknown".
# ---------------------------------------------------------------------------
# A file's first word when it is all digits.
cg_num() {
    local v
    v="$(head -c 32 "$1" 2>/dev/null)"; v="${v%%[[:space:]]*}"
    [[ "$v" =~ ^[0-9]+$ ]] && printf '%s' "$v"
}
# A ceiling: its number, or none where the file says max.
cg_limit() {
    local v
    v="$(head -c 32 "$1" 2>/dev/null)"; v="${v%%[[:space:]]*}"
    if [[ "$v" == "max" ]]; then printf 'none'; elif [[ "$v" =~ ^[0-9]+$ ]]; then printf '%s' "$v"; fi
}
# One figure out of a flat-keyed file (memory.stat, memory.events, cpu.stat).
cg_key() {
    local v
    v="$(awk -v k="$2" '$1==k {print $2; exit}' "$1" 2>/dev/null)"
    [[ "$v" =~ ^[0-9]+$ ]] && printf '%s' "$v"
}
# Memory in use the way MemAvailable counts it: everything the group holds but
# the inactive file cache, which the kernel takes back before it kills anything.
# memory.current alone counts that cache, and overstates an idle site by half.
cg_mem_used() {
    local cur inactive
    cur="$(cg_num "$1/memory.current")"
    [[ -n "$cur" ]] || return 0
    inactive="$(cg_key "$1/memory.stat" inactive_file)"
    inactive="${inactive:-0}"
    printf '%s' $(( cur > inactive ? cur - inactive : 0 ))
}
# A CPU ceiling in thousandths of a core: cpu.max is "QUOTA PERIOD", or
# "max PERIOD" for none.
cg_cpu_limit() {
    local quota period
    [[ -r "$1" ]] || return 0
    read -r quota period < "$1" || return 0
    if [[ "$quota" == "max" ]]; then
        printf 'none'
    elif [[ "$quota" =~ ^[0-9]+$ && "$period" =~ ^[1-9][0-9]*$ ]]; then
        printf '%s' $(( quota * 1000 / period ))
    fi
}
# A number, the string none, or unknown.
json_limit() {
    if [[ "${1:-}" == "none" ]]; then printf '"none"'; else json_num_or_unknown "${1:-}"; fi
}

# Inside a container /sys/fs/cgroup is the container's own group, and
# /proc/meminfo is the shared server's. Both are functions so the gate can
# point them at a fixture.
in_container() { [[ -f /.dockerenv || -f /run/.containerenv ]]; }

# The sites in /var/www/html on this machine: each directory holding a
# public_html, by name, at most 50. Inside a container, its one site.
emit_sites() {
    local d n=0 first=1
    printf '['
    for d in /var/www/html/*/public_html; do
        [[ -d "$d" ]] || continue
        (( n++ < 50 )) || break
        [[ "$first" == 1 ]] || printf ','
        first=0
        json_str "$(basename "$(dirname "$d")")"
    done
    printf ']'
}
own_cgroup_dir() { printf '/sys/fs/cgroup'; }

# The machine's memory. In a container it is the site's own: what its group
# holds, out of its limit (or out of the whole server, where it has none, since
# that is then what it may use). A figure read from /proc/meminfo there is the
# server's, and said nothing about the site.
emit_memory() {
    local total avail used="" cg limit
    total="$(meminfo_kb MemTotal)"; avail="$(meminfo_kb MemAvailable)"
    if in_container; then
        cg="$(own_cgroup_dir)"
        used="$(cg_mem_used "$cg")"
        if [[ -n "$used" ]]; then
            limit="$(cg_limit "$cg/memory.max")"
            if [[ "$total" =~ ^[0-9]+$ ]]; then
                total=$(( total * 1024 ))
                [[ "$limit" =~ ^[0-9]+$ ]] && (( limit < total )) && total="$limit"
            elif [[ "$limit" =~ ^[0-9]+$ ]]; then
                total="$limit"
            fi
            printf '{"used_bytes":%s,"total_bytes":%s}' "$(json_num_or_unknown "$used")" "$(json_num_or_unknown "$total")"
            return
        fi
        used=""
    fi
    [[ "$total" =~ ^[0-9]+$ && "$avail" =~ ^[0-9]+$ ]] && used=$(( total - avail ))
    printf '{"used_bytes":%s,"total_bytes":%s}' "$(kb_to_bytes_or_unknown "$used")" "$(kb_to_bytes_or_unknown "$total")"
}
emit_swap() {
    local total free used=""
    total="$(meminfo_kb SwapTotal)"; free="$(meminfo_kb SwapFree)"
    [[ "$total" =~ ^[0-9]+$ && "$free" =~ ^[0-9]+$ ]] && used=$(( total - free ))
    printf '{"used_bytes":%s,"total_bytes":%s}' "$(kb_to_bytes_or_unknown "$used")" "$(kb_to_bytes_or_unknown "$total")"
}

# How many processors this machine has, which a load average is read against:
# a number, or "unknown" when nproc does not answer.
emit_cpus() {
    local n
    n="$(run nproc)"
    if [[ "$n" =~ ^[0-9]{1,4}$ ]]; then printf '%s' "$n"; else printf '"unknown"'; fi
}

# ---------------------------------------------------------------------------
# PHP's workers (specs/php_fpm_sizing_bare_metal.md WP5): how many requests
# PHP-FPM may work on at once, in the version Apache hands PHP to, and where
# that figure came from - "plan" when tune_php_fpm.sh sized it (its
# joinery-plan line: the budget, its source, the host reserve kept back, the
# CPUs), "overridden" when a later pool file set another figure, "packaged"
# when nothing did. Beside the reserve, what the machine's
# processes other than PHP, PostgreSQL and Apache actually hold, so a reserve
# that has drifted from the machine is seen. And the PostgreSQL settings
# changed in the files that wait for a restart (pg_settings.pending_restart):
# the host converger writes them and never restarts PostgreSQL.
#
# Numbers and names only: a pool file's lines are never sent (a pool can
# carry env[] values). null where Apache hands PHP to no PHP-FPM.
# ---------------------------------------------------------------------------
emit_php_workers() {
    local etc="${HOST_REPORT_ETC:-/etc}" f ver="" max plan source budget="" src="" reserve="" cpus="" rss="" pending
    for f in "$etc"/apache2/conf-enabled/php*-fpm.conf; do
        [[ -e "$f" ]] || continue
        [[ "$(basename "$f")" =~ ^php([0-9]+\.[0-9]+)-fpm\.conf$ ]] && ver="${BASH_REMATCH[1]}"
    done
    if [[ -z "$ver" ]]; then printf 'null'; return; fi
    # The [www] pool's ceiling as php-fpm reads it: the last value in file order.
    max="$(cat "$etc/php/${ver}/fpm/pool.d/"*.conf 2>/dev/null | awk '
        /^[ \t]*\[/ { sec = $0; gsub(/[ \t\[\]]/, "", sec); next }
        sec == "www" && /^[ \t]*pm\.max_children[ \t]*=/ { v = $0; sub(/.*=[ \t]*/, "", v); gsub(/[^0-9]/, "", v); if (v != "") m = v }
        END { if (m != "") print m }')"
    plan="$(grep -m1 '^; joinery-plan: ' "$etc/php/${ver}/fpm/pool.d/zz-joinery-memory.conf" 2>/dev/null)"
    # "plan" only when the figure in force is the one the plan wrote: a later
    # file overriding max_children is "overridden", never the plan's.
    source=packaged
    if [[ -f "$etc/php/${ver}/fpm/pool.d/zz-joinery-memory.conf" ]]; then
        source=plan
        [[ "$plan" =~ children=([0-9]+) ]] && [[ "${BASH_REMATCH[1]}" != "$max" ]] && source=overridden
    fi
    if [[ -n "$plan" ]]; then
        [[ "$plan" =~ budget_mb=([0-9]+) ]] && budget="${BASH_REMATCH[1]}"
        [[ "$plan" =~ source=([A-Za-z_-]+) ]] && src="${BASH_REMATCH[1]}"
        [[ "$plan" =~ reserve_mb=([0-9]+) ]] && reserve="${BASH_REMATCH[1]}"
        [[ "$plan" =~ cpus=([0-9.]+) ]] && cpus="${BASH_REMATCH[1]}"
    fi
    # What is not a site: every process but PHP-FPM's, PostgreSQL's and
    # Apache's, by proportional set size (a shared page split between the
    # processes sharing it, so the sum is the memory they hold). Root only:
    # another user's smaps_rollup cannot be read, and a partial sum misleads.
    if [[ "$src" == "MemTotal" && "$(id -u)" == 0 ]]; then
        local d c k v kb=0
        for d in /proc/[0-9]*; do
            c=""; read -r c 2>/dev/null < "$d/comm" || continue
            [[ "$c" =~ ^(php-fpm|postgres|apache2|httpd) ]] && continue
            while read -r k v _; do
                [[ "$k" == "Pss:" ]] && { kb=$(( kb + v )); break; }
            done 2>/dev/null < "$d/smaps_rollup"
        done
        rss=$(( kb / 1024 ))
    fi
    pending='"unknown"'
    local out
    # setpriv, not runuser: no PAM session, so no auth.log pair on every report.
    if out="$(run setpriv --reuid=postgres --regid=postgres --init-groups psql -XAtc "SELECT name FROM pg_settings WHERE pending_restart ORDER BY name")"; then
        pending='['; local sep="" n
        while IFS= read -r n; do
            [[ -n "$n" ]] || continue
            pending="${pending}${sep}$(json_str "$n")"; sep=","
        done <<< "$out"
        pending="${pending}]"
    fi
    printf '{"version":%s,"max_children":%s,"source":"%s","budget_mb":%s,"plan_source":%s,"reserve_mb":%s,"cpus":%s,"non_site_rss_mb":%s,"pg_pending_restart":%s}' \
        "$(json_str "$ver")" "$(json_num_or_unknown "$max")" "$source" \
        "$(json_num_or_unknown "$budget")" "$([[ -n "$src" ]] && json_str "$src" || printf '"unknown"')" \
        "$(json_num_or_unknown "$reserve")" "$([[ -n "$cpus" ]] && json_str "$cpus" || printf '"unknown"')" \
        "$([[ -n "$rss" ]] && json_num_or_unknown "$rss" || printf 'null')" "$pending"
}

# ---------------------------------------------------------------------------
# The three events that explain a write that failed, last 24 hours: THREE
# COUNTS and nothing else.
#
# The OOM killer ran, a filesystem had no space, or the device errored. Each is
# a number; the matched lines are counted and discarded, exactly as the SSH
# figure above is, so nothing from a message — a path, a process name, an
# address — ever reaches the object.
#
# THE SYSTEM JOURNAL, not the kernel ring, and that is the correction this
# version exists for. "No space left on device" is an errno handed to a
# userspace program, which is the one that says so: on 2026-09-22 the line that
# proved a node's disk had filled came from mandb, and a kernel-ring read
# (journalctl -k) returned zero for the same window. The system journal carries
# the kernel's own messages too, so the OOM and I/O counts lose nothing by
# being read here.
#
# journalctl does the matching itself (-g, its own PCRE pass) so the shell is
# never handed a day of log to filter, and the count is taken again here with
# the same pattern — which also drops journalctl's own "-- No entries --".
#
# Why it earns its place: on 2026-09-22 a node filled its disk for fifteen
# minutes, took PostgreSQL and the man-page index down with it, and freed the
# space again on the way out. Every gauge read normal afterwards. One of these
# three numbers would have named it.
#
# A machine whose journal cannot be read (a container, or an unprivileged run)
# answers unknown for the whole object, which is the same signal the unit
# states use.
# ---------------------------------------------------------------------------
KERNEL_EVENT_OOM='Out of memory: Kill|oom-kill:|oom_reaper:'
KERNEL_EVENT_ENOSPC='No space left on device'
KERNEL_EVENT_IO='I/O error|Buffer I/O error|EXT4-fs error|Remounting filesystem read-only'
# The kernel's own warnings, read from its messages alone (a program's
# "WARNING:" is not one): a WARN(), a BUG, an oops, a protection fault. A
# shared host's kernel saying so while strangers run code on it is worth a look
# every time (specs/multi_tenant_host_hardening.md D7). Not "Call Trace:", which
# every OOM kill report and hung-task notice prints. A BUG() prints "kernel BUG
# at FILE:LINE!" with no colon, and on a 6.8 kernel the die header that follows
# names no Oops ("invalid opcode: 0000 [#1]"), so it is matched by its own words
# (reviewer2 R1). A WARN() is matched by its own line, "WARNING: CPU: N PID: N
# at FILE:LINE": the boot notices about a CPU's mitigations ("Speculative Return
# Stack Overflow: WARNING: See ...", "Spectre V2 : WARNING: Unprivileged eBPF
# ...") say WARNING too, on every boot of every machine with that CPU.
# The kernel dropping connections: its connection-tracking table full, or a
# listener's SYN queue overflowing. A site behind an edge sees either as
# requests that hang and then fail (specs/slow_request_diagnosis_words.md).
KERNEL_EVENT_CONNTRACK='nf_conntrack: table full, dropping packet'
KERNEL_EVENT_SYNFLOOD='Possible SYN flooding on port'
KERNEL_EVENT_WARNING='WARNING: CPU:|BUG:|[Kk]ernel BUG at|Oops:|general protection fault|kernel NULL pointer'

# Whether the system journal can be read here. journalctl -g exits 1 both when
# it cannot read the journal and when nothing matched (systemd 255 and 259),
# so a quiet machine read as unknown until the two were told apart first (B35).
journal_readable() { run journalctl --system -n 0 --no-pager -q >/dev/null; }

# One pattern's count over the last day, from a journal journal_readable()
# found readable; any further arguments are journalctl matches. The lines go
# straight into grep -c, and journalctl's own complaints come back beside the
# count: exit 1 with nothing said is "nothing matched", exit 1 with a complaint
# (a journalctl built without pattern support, an argument it refuses) or any
# other exit (a timeout) is nothing at all, which the caller turns into unknown
# for the whole object; read as 0, a broken read would say what a healthy
# machine says, for good (reviewer2 R2).
journal_event_count() {  # PATTERN [MATCH...]
    local pattern="$1" res rc grep_rc count said
    shift
    res="$( { timeout "$CMD_TIMEOUT" journalctl --system "$@" --since "24 hours ago" --no-pager -o cat -q -g "$pattern" 2>&3 \
              | grep -c -E "$pattern"; echo "${PIPESTATUS[0]} ${PIPESTATUS[1]}"; } 3>&1 )"
    read -r rc grep_rc <<< "$(tail -n 1 <<< "$res")"
    count="$(tail -n 2 <<< "$res" | head -n 1)"
    said="$(head -n -2 <<< "$res")"
    # grep -c exits 1 for a count of 0; anything above is grep itself failing.
    [[ "${grep_rc:-2}" -le 1 && "$count" =~ ^[0-9]+$ ]] || return 1
    case "$rc" in
        0) ;;
        1) [[ -z "$said" ]] || return 1 ;;
        *) return 1 ;;
    esac
    printf '%s' "$count"
}

emit_kernel_events() {
    local oom enospc io warning conntrack syn
    run systemctl show -p Version --value >/dev/null || { printf '"unknown"'; return; }
    journal_readable || { printf '"unknown"'; return; }
    oom="$(journal_event_count "$KERNEL_EVENT_OOM")"       || { printf '"unknown"'; return; }
    enospc="$(journal_event_count "$KERNEL_EVENT_ENOSPC")" || { printf '"unknown"'; return; }
    io="$(journal_event_count "$KERNEL_EVENT_IO")"         || { printf '"unknown"'; return; }
    warning="$(journal_event_count "$KERNEL_EVENT_WARNING" _TRANSPORT=kernel)" || { printf '"unknown"'; return; }
    conntrack="$(journal_event_count "$KERNEL_EVENT_CONNTRACK" _TRANSPORT=kernel)" || { printf '"unknown"'; return; }
    syn="$(journal_event_count "$KERNEL_EVENT_SYNFLOOD" _TRANSPORT=kernel)" || { printf '"unknown"'; return; }
    printf '{"oom":%s,"enospc":%s,"io_error":%s,"kernel_warning":%s,"conntrack_full":%s,"syn_flood":%s}' \
        "$(json_num_or_unknown "$oom")" "$(json_num_or_unknown "$enospc")" "$(json_num_or_unknown "$io")" \
        "$(json_num_or_unknown "$warning")" "$(json_num_or_unknown "$conntrack")" "$(json_num_or_unknown "$syn")"
}

# ---------------------------------------------------------------------------
# reboot-required, unattended-upgrades
# ---------------------------------------------------------------------------
emit_reboot_required() {
    if [[ -e /var/run/reboot-required ]]; then printf 'true'
    elif [[ -d /var/run ]]; then printf 'false'
    else printf '"unknown"'; fi
}
# When the pending reboot was first asked for. /run is a tmpfs, emptied at boot,
# and the update hook rewrites the file in place (>), so its birth time is the
# first request since the last boot; %W is 0 where no birth time is kept, and
# the change time stands in.
emit_reboot_required_since() {
    local f=/var/run/reboot-required t
    [[ -e "$f" ]] || { printf 'null'; return; }
    t="$(run stat -c %W "$f")" || t=""
    if [[ ! "$t" =~ ^[1-9][0-9]*$ ]]; then t="$(run stat -c %Z "$f")" || t=""; fi
    json_num_or_unknown "$t"
}
emit_unattended_upgrades_last_run() {
    local stamp=/var/lib/apt/periodic/unattended-upgrades-stamp t
    if [[ -e "$stamp" ]] && t="$(run stat -c %Y "$stamp")"; then
        json_num_or_unknown "$t"
    else
        printf '"unknown"'
    fi
}

# ---------------------------------------------------------------------------
# The operating system, and the release upgrade Ubuntu offers it.
#
# id, version and codename come from os-release. version is the point release
# (24.04.4) where VERSION carries one, else VERSION_ID.
#
# The upgrade answer is Ubuntu's own: update-motd's release-upgrade step runs
# check-new-release once a day and writes what it printed to a cache file.
# Empty means no release is offered to this machine (under its own Prompt=
# setting); "New release '26.04.1 LTS' available." names one. The cache is
# refreshed only when someone logs in, so its time travels with the answer and
# the reader judges its age. Reading it is the whole of this: the script never
# runs the check, because the check fetches from the network and writes.
# ---------------------------------------------------------------------------
RELEASE_UPGRADE_CACHE=/var/lib/ubuntu-release-upgrader/release-upgrade-available

os_release_field() {
    local f
    for f in /etc/os-release /usr/lib/os-release; do
        [[ -r "$f" ]] || continue
        run head -c 4096 "$f" | awk -F= -v k="$1" '$1==k { v=$2; gsub(/"/, "", v); print v; exit }'
        return
    done
}

# The version a check-new-release line offers (26.04.1), none for an empty
# cache, or unknown for text it does not recognise. Reads the cache on stdin.
release_offered_from() {
    local text version
    text="$(head -c 4096)"
    if [[ -z "${text//[[:space:]]/}" ]]; then printf 'none'; return; fi
    version="$(printf '%s\n' "$text" | grep -o -E "New release '[0-9]+(\.[0-9]+)*" | head -n 1)"
    version="${version#New release \'}"
    if [[ "$version" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then printf '%s' "${version:0:16}"; else printf 'unknown'; fi
}

emit_os() {
    local id version codename offered=unknown checked=""
    id="$(os_release_field ID)"
    version="$(os_release_field VERSION)"
    version="$(printf '%s' "$version" | grep -o -E '^[0-9]+(\.[0-9]+)*' | head -n 1)"
    [[ -n "$version" ]] || version="$(os_release_field VERSION_ID)"
    codename="$(os_release_field VERSION_CODENAME)"
    [[ -n "$id" ]] || id=unknown
    [[ -n "$version" ]] || version=unknown
    [[ -n "$codename" ]] || codename=unknown
    if [[ -r "$RELEASE_UPGRADE_CACHE" ]]; then
        offered="$(run head -c 4096 "$RELEASE_UPGRADE_CACHE" | release_offered_from)"
        checked="$(run stat -c %Y "$RELEASE_UPGRADE_CACHE")"
    fi
    printf '{"id":%s,"version":%s,"codename":%s,"release_upgrade":{"offered":%s,"checked_at":%s}}' \
        "$(json_str "$id")" "$(json_str "$version")" "$(json_str "$codename")" \
        "$(json_str "$offered")" "$(json_num_or_unknown "$checked")"
}

# ---------------------------------------------------------------------------
# Does each service ANSWER, not merely run (the service_health recipe of
# specs/agent_recipes_and_vocabulary.md, Settled 2026-09-23). systemd already
# restarts a crashed unit; what it cannot see is one that runs and does not
# answer.
#
#   apache2    - any HTTP response on the loopback, for this site's own name
#   php-fpm    - that response came through PHP: serve.php sets
#                X-Joinery-Version on every request it handles, and Apache
#                answers a request it could not hand to FPM without it
#   postgresql - pg_isready: the server accepts connections. No credential is
#                read (the site's config is a secret), so this is the
#                credential-free form of SELECT 1.
#
# Each is yes, no, or unknown (no site on this machine, no tool). On a quiet
# site php-fpm is "quiet": the quiet state answers every page itself with a
# 503, so the probe cannot reach PHP, and sending the look cookie to get past it
# would write request rows into a copy whose rows must match its source's.
# Only the verdicts are printed; the site's name is read to address the request and
# never reaches the object.
# ---------------------------------------------------------------------------
site_vhost() {
    local vhost="/etc/apache2/sites-available/$(basename "$SITE_ROOT").conf"
    [[ -r "$vhost" ]] && run head -c 65536 "$vhost"
}
site_server_name() {
    site_vhost | awk 'tolower($1)=="servername" {print $2; exit}'
}
# The address the site's vhost is bound to: install.sh binds it to the
# server's own IP (default_virtualhost.conf), a proxy vhost to *. A request to
# the loopback would reach only the default site on the first shape.
site_vhost_address() {
    local a
    a="$(site_vhost | grep -m1 -oE '<VirtualHost[[:space:]]+[^:>]+:' | sed -E 's/<VirtualHost[[:space:]]+//; s/:$//')"
    if [[ "$a" =~ ^[0-9.]{7,15}$ ]]; then printf '%s' "$a"; else printf '127.0.0.1'; fi
}

# HTTP headers from this machine for NAME at ADDR: https first (the plain
# vhost of a site with a certificate redirects without reaching PHP), plain
# http when https does not answer. Prints nothing when neither answered.
loopback_headers() {
    local name="$1" addr="$2" h
    h="$(run curl -sk --max-time 8 -o /dev/null -D - --resolve "${name}:443:${addr}" "https://${name}/")"
    if [[ ! "$h" =~ ^HTTP/ ]]; then
        h="$(run curl -s --max-time 8 -o /dev/null -D - --resolve "${name}:80:${addr}" "http://${name}/")"
    fi
    printf '%s' "$h"
}

# Whether this site is quiet: its state file exists (_site_state.sh).
site_is_quiet() {
    [[ -e "/etc/joinery/sites/$(basename "$SITE_ROOT")/state" ]]
}

emit_answers() {
    local name headers code apache=unknown fpm=unknown pg=unknown
    name="$(site_server_name)"
    if [[ "$name" =~ ^[A-Za-z0-9.-]{1,253}$ ]] && command -v curl >/dev/null 2>&1; then
        headers="$(loopback_headers "$name" "$(site_vhost_address)")"
        if [[ "$headers" =~ ^HTTP/ ]]; then
            apache=yes
            # PHP answered when serve.php's header is there. Without it, only
            # Apache's own gateway failures (502, 503, 504: it could not hand
            # the request to FPM) say FPM does not answer; a redirect, a 403
            # or anything else without the header says nothing about FPM.
            code="$(printf '%s\n' "$headers" | awk 'NR==1 {print $2}')"
            if printf '%s\n' "$headers" | grep -q -i '^x-joinery-version:'; then fpm=yes
            elif site_is_quiet; then fpm=quiet
            elif [[ "$code" == 502 || "$code" == 503 || "$code" == 504 ]]; then fpm=no
            else fpm=unknown
            fi
        else
            apache=no
        fi
    fi
    if command -v pg_isready >/dev/null 2>&1 && [[ -n "$name" ]]; then
        if run pg_isready -q -t 5; then pg=yes
        else
            case $? in 1|2) pg=no ;; *) pg=unknown ;; esac
        fi
    fi
    printf '{"apache2":"%s","php-fpm":"%s","postgresql":"%s"}' "$apache" "$fpm" "$pg"
}

# ---------------------------------------------------------------------------
# The certificate each of this site's names SERVES, read over this machine's
# own connection to its vhost address, with SNI: what a visitor is handed, not
# what certbot holds (check_status reads the held ones). The certificate_expiry
# recipe reads it: fewer than 14 days left means certbot's own timer failed.
#
# The names are the site vhost's own ServerName and ServerAlias lines (its
# configuration; the recipe's repair takes the domain from this list, never
# from the wire). A name whose handshake does not complete is left out, not
# guessed at. Only the name and whole days left are printed.
# ---------------------------------------------------------------------------
MAX_CERT_NAMES=10
site_names() {
    local site f
    site="$(basename "$SITE_ROOT")"
    for f in "/etc/apache2/sites-available/${site}.conf" "/etc/apache2/sites-available/${site}-le-ssl.conf"; do
        [[ -r "$f" ]] || continue
        run head -c 65536 "$f" | awk 'tolower($1)=="servername" || tolower($1)=="serveralias" { for (i = 2; i <= NF; i++) print $i }'
    done | grep -E '^[A-Za-z0-9.-]{1,253}$' | grep -v '^\*' | awk '!seen[$0]++' | head -n "$MAX_CERT_NAMES"
}

emit_served_certificates() {
    local addr name cert end subj iss self end_s now days n=0 first=1 primary
    command -v openssl >/dev/null 2>&1 || { printf '"unknown"'; return; }
    addr="$(site_vhost_address)"
    now="$(date -u +%s)"
    primary="$(site_server_name)"
    printf '['
    while read -r name; do
        [[ -n "$name" ]] || continue
        cert="$(run openssl s_client -connect "${addr}:443" -servername "$name" < /dev/null | run openssl x509 -noout -enddate -subject -issuer -nameopt RFC2253)"
        end="$(printf '%s\n' "$cert" | sed -n 's/^notAfter=//p' | head -n 1)"
        subj="$(printf '%s\n' "$cert" | sed -n 's/^subject=//p' | head -n 1)"
        iss="$(printf '%s\n' "$cert" | sed -n 's/^issuer=//p' | head -n 1)"
        [[ -n "$end" ]] || continue
        self=false
        [[ -n "$subj" && "$subj" == "$iss" ]] && self=true
        end_s="$(date -u -d "$end" +%s 2>/dev/null)" || continue
        [[ "$end_s" =~ ^[0-9]+$ ]] || continue
        days=$(( (end_s - now) / 86400 ))
        (( first )) || printf ','
        first=0
        printf '{"domain":%s,"days_left":%s,"primary":%s,"self_signed":%s}' "$(json_str "$name")" "$days" "$([[ "$name" == "$primary" ]] && echo true || echo false)" "$self"
        n=$((n+1))
    done < <(site_names)
    printf ']'
}

# ---------------------------------------------------------------------------
# Outbound limits (outbound_limits.sh). The unit writes its state into a status
# file and its drops into named counters in nftables (drops_site_SITE,
# drops_web_user), cumulative since the status file's since. Both are read
# once, by limits_read, before the object is printed.
#   state: on | off | refused (reason says why, a code) | absent (no unit on
#   this machine) | none (inside a container, whose limits are its host's) |
#   unknown (a status file that does not parse).
# The counters need root; without it every drop figure is unknown.
# ---------------------------------------------------------------------------
LIMITS_STATE=""; LIMITS_REASON=""; LIMITS_SINCE=""; LIMITS_SITES=""; LIMITS_WEB=""
LIMITS_FILE_LINES=""
LIMITS_COUNTS=""; LIMITS_COUNTS_READ=0
limits_read() {
    local f=/run/joinery/outbound_limits.status
    if in_container; then LIMITS_STATE=none; return; fi
    if [[ ! -f "$f" ]]; then
        if [[ -f /etc/systemd/system/joinery-limits.service ]]; then LIMITS_STATE=unknown; else LIMITS_STATE=absent; fi
        return
    fi
    LIMITS_STATE="$(sed -n 's/^state=//p' "$f" | head -1)"
    [[ "$LIMITS_STATE" =~ ^(on|off|refused)$ ]] || LIMITS_STATE=unknown
    LIMITS_REASON="$(sed -n 's/^reason=//p' "$f" | head -1)"
    LIMITS_SINCE="$(sed -n 's/^since=//p' "$f" | head -1)"
    LIMITS_SITES=" $(sed -n 's/^sites=//p' "$f" | head -1) "
    LIMITS_WEB="$(sed -n 's/^web_user=//p' "$f" | head -1)"
    LIMITS_FILE_LINES="$(head -c 65536 "$f")"
    if [[ "$LIMITS_STATE" == on ]] && LIMITS_COUNTS="$(run nft list counters table inet joinery_limits)"; then
        LIMITS_COUNTS="$(awk '$1 == "counter" { n = $2 } $1 == "packets" && n != "" { print n "=" $2; n = "" }' <<< "$LIMITS_COUNTS")"
        LIMITS_COUNTS_READ=1
    fi
}
# The packets one counter dropped: a number, none where that sender is not
# limited, or unknown where the counters could not be read.
limits_dropped() {  # COUNTER_NAME LIMITED(0|1)
    if [[ "$LIMITS_STATE" != on || "$2" != 1 ]]; then printf '"none"'; return; fi
    [[ "$LIMITS_COUNTS_READ" == 1 ]] || { printf '"unknown"'; return; }
    json_num_or_unknown "$(awk -F= -v n="$1" '$1 == n { print $2; exit }' <<< "$LIMITS_COUNTS")"
}
# One status key's value from the limits status file.
limits_key() { sed -n "s/^$1=//p" <<< "$LIMITS_FILE_LINES" | head -1; }
# A ceiling: a number of Mbit/s, or none.
json_ceiling() {
    if [[ "$1" =~ ^[1-9][0-9]{0,5}$ ]]; then printf '%s' "$1"; else printf '"none"'; fi
}
# The figures in force (off: what turning the limits on brings back): the
# machine's, the web server's user's ceiling, and each limited site's
# (SITE:CEILING:RATE:BURST:OPEN in the status file), plane where the
# management node set the machine's or that site's own (site_set_by).
limits_figures() {
    local set_by='""' entry site c r b o first=1 n=0 site_by by_sites
    [[ "$(limits_key set_by)" == plane ]] && set_by='"plane"'
    by_sites=" $(limits_key site_set_by) "
    printf '"figures":{"ceiling_mbit":%s,"conn_rate":%s,"conn_burst":%s,"open_conns":%s,"set_by":%s}' \
        "$(json_ceiling "$(limits_key ceiling_mbit)")" "$(json_num_or_unknown "$(limits_key conn_rate)")" \
        "$(json_num_or_unknown "$(limits_key conn_burst)")" "$(json_num_or_unknown "$(limits_key open_conns)")" "$set_by"
    printf ',"web_ceiling_mbit":%s,"sites":{' "$(json_ceiling "$(limits_key web_ceiling_mbit)")"
    for entry in $(limits_key site_figures); do
        (( n < MAX_SITES )) || break
        IFS=: read -r site c r b o <<< "$entry"
        [[ "$site" =~ ^[A-Za-z0-9_-]{1,50}$ ]] || continue
        (( first )) || printf ','
        first=0
        n=$((n+1))
        site_by="$set_by"
        [[ "$by_sites" == *" ${site} "* ]] && site_by='"plane"'
        printf '%s:{"ceiling_mbit":%s,"conn_rate":%s,"conn_burst":%s,"open_conns":%s,"set_by":%s}' "$(json_str "$site")" \
            "$(json_ceiling "$c")" "$(json_num_or_unknown "$r")" "$(json_num_or_unknown "$b")" "$(json_num_or_unknown "$o")" "$site_by"
    done
    printf '}'
}
emit_outbound_limits() {
    local since='"none"' reason='"none"' figures=''
    [[ "$LIMITS_STATE" == on ]] && since="$(json_num_or_unknown "$LIMITS_SINCE")"
    if [[ "$LIMITS_STATE" == refused ]]; then
        reason="$(json_str "${LIMITS_REASON:-unknown}")"
    elif [[ "$LIMITS_STATE" == on && -n "$LIMITS_REASON" ]]; then
        reason="$(json_str "$LIMITS_REASON")"
    fi
    # Off, only from a status that keeps them (1.3 writes site_set_by): an
    # older one left the ceiling empty, which would read as none.
    if [[ "$LIMITS_STATE" == on ]] || { [[ "$LIMITS_STATE" == off ]] && grep -q '^site_set_by=' <<< "$LIMITS_FILE_LINES"; }; then
        figures=",$(limits_figures)"
    fi
    printf '{"state":"%s","reason":%s,"since":%s,"web_user_dropped":%s%s}' "$LIMITS_STATE" "$reason" "$since" \
        "$(limits_dropped drops_web_user "$([[ "$LIMITS_WEB" == yes ]] && echo 1 || echo 0)")" "$figures"
}

# ---------------------------------------------------------------------------
# This host's Joinery containers: every container whose name is its SITENAME,
# the shape install.sh creates. Each with docker's state and health, and
# whether the site inside answers through PHP on its published web port.
# "none" where the machine has no docker; "unknown" where docker would not
# answer (not root). Names are site slugs, the same list restart_container
# accepts.
# ---------------------------------------------------------------------------
# One site container's figures, read from its cgroup and its network
# namespace through the PID docker names. Each counter is cumulative since the
# container started (started_at), so the plane turns two reports into a rate.
# A container that is not running has none of them: every key says unknown.
container_figures() {  # NAME STATE PID STARTED_AT, the last two as docker inspect printed them
    local c="$1" state="$2" pid="$3" started="$4" started_at="" cgpath="" cg="" tx=""
    local used="" peak="" mlimit="" ooms="" usec="" climit="" pcur="" plimit=""
    if [[ "$state" == "running" ]]; then
        [[ "$pid" =~ ^[1-9][0-9]*$ ]] || pid=""
        if [[ "$started" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+(Z|[+-][0-9:]+)$ ]]; then
            started_at="$(run date -u -d "$started" +%s)"
        fi
        if [[ -n "$pid" ]]; then
            cgpath="$(awk -F: '$1=="0" {print $3; exit}' "/proc/$pid/cgroup" 2>/dev/null)"
            [[ "$cgpath" =~ ^/[A-Za-z0-9._@:/-]+$ && "$cgpath" != *..* ]] && cg="/sys/fs/cgroup$cgpath"
            # Bytes sent on every interface but loopback: column 9 after the name.
            tx="$(awk 'NR > 2 { split($0, a, ":"); if (a[1] ~ /^ *lo$/) next; split(a[2], f, " "); s += f[9]; seen = 1 }
                       END { if (seen) printf "%.0f", s }' "/proc/$pid/net/dev" 2>/dev/null)"
        fi
        if [[ -n "$cg" && -d "$cg" ]]; then
            used="$(cg_mem_used "$cg")"
            peak="$(cg_num "$cg/memory.peak")"
            mlimit="$(cg_limit "$cg/memory.max")"
            ooms="$(cg_key "$cg/memory.events" oom_kill)"
            usec="$(cg_key "$cg/cpu.stat" usage_usec)"
            climit="$(cg_cpu_limit "$cg/cpu.max")"
            pcur="$(cg_num "$cg/pids.current")"
            plimit="$(cg_limit "$cg/pids.max")"
        fi
    fi
    printf '"started_at":%s,"memory":{"used_bytes":%s,"peak_bytes":%s,"limit_bytes":%s,"oom_kills":%s},' \
        "$(json_num_or_unknown "$started_at")" "$(json_num_or_unknown "$used")" "$(json_num_or_unknown "$peak")" \
        "$(json_limit "$mlimit")" "$(json_num_or_unknown "$ooms")"
    printf '"cpu":{"usage_usec":%s,"limit_millicores":%s},"pids":{"current":%s,"limit":%s},"net_tx_bytes":%s' \
        "$(json_num_or_unknown "$usec")" "$(json_limit "$climit")" \
        "$(json_num_or_unknown "$pcur")" "$(json_limit "$plimit")" "$(json_num_or_unknown "$tx")"
    printf ',"outbound_dropped":%s' "$(limits_dropped "drops_site_${c}" "$([[ "$LIMITS_SITES" == *" ${c} "* ]] && echo 1 || echo 0)")"
}

# What one docker inspect prints for each container, on one line, |-separated:
# its name, its SITENAME entry, state, health, the PID and start time its
# figures are read through, its published web port, and its named volumes'
# sources. The environment is filtered inside docker, so no other variable (a
# password) ever reaches this script.
#
# Every part of it must run against docker's typed container, not its raw JSON.
# docker runs a template that fails on the typed one again on the raw JSON, and
# there a container that never started has no Health key (the template fails,
# and the site drops out of the list) and a PID of a million or more prints as
# 1e+06. So the web port is found by ranging over the port map: indexing it by
# the string "80/tcp" fails on the typed map, whose keys are not strings.
INSPECT_FORMAT='{{.Name}}|{{range .Config.Env}}{{if eq (index (split . "=") 0) "SITENAME"}}{{.}}{{end}}{{end}}|{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.State.Pid}}|{{.State.StartedAt}}|{{range $p, $b := .NetworkSettings.Ports}}{{if eq (printf "%s" $p) "80/tcp"}}{{range $i, $x := $b}}{{if eq $i 0}}{{$x.HostPort}}{{end}}{{end}}{{end}}{{end}}|{{range .Mounts}}{{if eq .Type "volume"}}{{.Source}} {{end}}{{end}}'

# A list of capability names as a JSON array, each checked as one.
json_caps() {
    local cap first=1
    printf '['
    for cap in $1; do
        [[ "$cap" =~ ^[A-Za-z_]{2,40}$ ]] || continue
        [[ "$first" == 1 ]] || printf ','
        first=0
        printf '"%s"' "$cap"
    done
    printf ']'
}

# The system call filter this host's sites are made under, by its sha256, or none.
site_filter_sum() {
    local f="${HOST_REPORT_ETC:-/etc}/containers/joinery-site-seccomp.json" sum
    [[ -s "$f" ]] || { printf 'none'; return; }
    sum="$(sha256sum "$f" 2>/dev/null | cut -d' ' -f1)"
    [[ "$sum" =~ ^[0-9a-f]{64}$ ]] && printf '%s' "$sum" || printf 'unknown'
}

# The capabilities a site adds, from the run spec (_site_run_spec.sh's
# RUN_SPEC_CAPS, read, never sourced), space-separated, or nothing.
run_spec_caps() {
    local f line
    f="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)/../install_tools/_site_run_spec.sh"
    line="$(sed -n 's/^RUN_SPEC_CAPS=(\(.*\))$/\1/p' "$f" 2>/dev/null | head -1)"
    [[ "$line" =~ ^[A-Z_\ ]+$ ]] && printf '%s' "$line"
}

emit_site_security() {
    local sum
    sum="$(site_filter_sum)"
    printf '{"filter":"%s","caps":%s}' "$sum" "$(json_caps "$(run_spec_caps)")"
}

# Whether each running site answers through PHP: serve.php's header on its
# published port. Every site is asked at once, so a host's report costs its
# slowest site (curl's --max-time bounds each), not the sum of them. Reads
# "NAME PORT" lines; prints "NAME yes|no" lines, in whatever order they finish.
probe_sites() {
    local c port
    while read -r c port; do
        [[ -n "$c" ]] || continue
        {
            if run curl -s --max-time 8 -o /dev/null -D - "http://127.0.0.1:${port}/" | grep -q -i '^x-joinery-version:'; then
                printf '%s yes\n' "$c"
            else
                printf '%s no\n' "$c"
            fi
        } < /dev/null &
    done
    wait
}

# Whether a site's code is what was shipped, from config/code_check, which
# site_housekeeping.sh writes on a host that refuses unsigned code
# (specs/host_restrictions.md D8): {"state":"ok"|"failed","at":...,"failures":[...]},
# "none" where the site keeps no such file, "unknown" where its config volume
# was not found or the file is not in the form the site writes.
code_check_json() {  # CONFIG_DIR (on the host), or empty
    local f="$1/code_check" first state at line kind name verdict file n=0 sep=""
    [[ -n "$1" ]] || { printf '"unknown"'; return; }
    [[ -e "$f" || -L "$f" ]] || { printf '"none"'; return; }
    # The file is the site's own: a link it planted is not followed (this runs as root).
    [[ -f "$f" && ! -L "$f" ]] || { printf '"unknown"'; return; }
    first="$(head -n 1 "$f" 2>/dev/null)"
    [[ "$first" =~ ^(ok|failed)\ ([0-9TZ:.-]{1,40})$ ]] || { printf '"unknown"'; return; }
    state="${BASH_REMATCH[1]}"; at="${BASH_REMATCH[2]}"
    printf '{"state":"%s","at":%s,"failures":[' "$state" "$(json_str "$at")"
    # Tabs become \037 first: a tab is whitespace to read, which would fold the
    # empty name of a core line into the next field.
    while IFS=$'\037' read -r line kind name verdict file; do
        [[ "$line" == "fail" ]] || continue
        (( n < MAX_LIST )) || break
        printf '%s{"kind":%s,"name":%s,"verdict":%s,"file":%s}' "$sep" \
            "$(json_str "$kind")" "$(json_str "$name")" "$(json_str "$verdict")" "$(json_path "$file")"
        sep=","; n=$((n+1))
    done < <(tail -n +2 "$f" 2>/dev/null | head -n 200 | tr '\t' '\037')
    printf ']}'
}

# What this machine refuses to let its sites do (host_restrictions.sh,
# specs/host_restrictions.md): {name: value} from its restrictions file, "none"
# where it has none, "unknown" where the file is there and cannot be read as
# written - which the site side reads as every restriction on.
emit_host_restrictions() {
    local f="${HOST_REPORT_RESTRICTIONS:-/etc/joinery/host_restrictions/restrictions}" line sep="" n=0
    if [[ ! -e "$f" ]]; then
        if [[ -d "$(dirname "$f")" ]]; then printf '"unknown"'; else printf '"none"'; fi
        return
    fi
    [[ -f "$f" && -r "$f" ]] || { printf '"unknown"'; return; }
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line//[[:space:]]/}"
        [[ -z "$line" || "$line" == \#* ]] && continue
        [[ "$line" =~ ^[a-z][a-z0-9_]*=[A-Za-z0-9_-]+$ ]] || { printf '"unknown"'; return; }
    done < "$f"
    printf '{'
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line//[[:space:]]/}"
        [[ -z "$line" || "$line" == \#* ]] && continue
        (( n < MAX_LIST )) || break
        printf '%s%s:%s' "$sep" "$(json_str "${line%%=*}")" "$(json_str "${line#*=}")"; sep=","; n=$((n+1))
    done < "$f"
    printf '}'
}

# The engine this host's sites run on: install_tools/_site_engine.sh beside
# this script's tree names it. Without the helper Docker is the engine, as the
# helper itself answers, except on a machine with the site container engine.
# Prints its name and the command to run it by (site_engine_bin: the site
# container engine's podman by path, whatever PATH holds).
host_engine() {
    local helper
    helper="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../install_tools/_site_engine.sh"
    if [[ -f "$helper" ]]; then
        bash -c '. "$1" && k="$(site_engine_kind)" && b="$(site_engine_bin)" && echo "$k $b"' _ "$helper"
        return
    fi
    [[ -L /usr/local/lib/joinery-site-engine/current || -e /usr/local/lib/joinery-site-engine/current ]] && return 1
    echo "docker docker"
}

# The kernel and network settings every machine pins (install_tools/
# _kernel_settings.sh beside this script's tree, which host housekeeping
# writes and applies; specs/multi_tenant_host_hardening.md D1), against the
# running kernel: {"state":"ok"|"drift","pinned":N,"unread":N,"drift":[{"key",
# "live","want"}]}. unread counts a setting this kernel lacks or this run cannot
# read (one is root's alone). "unknown" in a container, whose kernel is its
# host's, or where the helper is not beside this script.
emit_hardening() {
    local helper lines state key live want pinned=0 unread=0 n=0 sep="" drift=""
    in_container && { printf '"unknown"'; return; }
    helper="${HOST_REPORT_KERNEL_SETTINGS:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../install_tools/_kernel_settings.sh}"  # tests only: another file
    [[ -f "$helper" ]] || { printf '"unknown"'; return; }
    lines="$(bash -c '. "$1" && kernel_settings_state' _ "$helper" 2>/dev/null)" || { printf '"unknown"'; return; }
    [[ -n "$lines" ]] || { printf '"unknown"'; return; }
    while read -r state key live want; do
        pinned=$((pinned+1))
        case "$state" in
            unread) unread=$((unread+1)) ;;
            drift)
                n=$((n+1))
                [[ $n -le $MAX_LIST ]] || continue
                drift+="${sep}{\"key\":$(json_str "$key"),\"live\":$(json_str "$live"),\"want\":$(json_str "$want")}"
                sep="," ;;
        esac
    done <<< "$lines"
    printf '{"state":"%s","pinned":%d,"unread":%d,"drift":[%s]}' "$([[ $n -eq 0 ]] && echo ok || echo drift)" "$pinned" "$unread" "$drift"
}

# The security fixes this machine is waiting for, from its hourly check
# (security_check.sh, specs/fleet_security_patching.md WP1): "none" where it
# has never run. Every value is read again here, as the refresh's state is.
json_version() { local drop='[^A-Za-z0-9.+:~-]' s; s="${1//$drop/}"; printf '"%s"' "${s:0:128}"; }
emit_security_pending() {
    local dir f k v item sep="" list="" name inst cand crit first n=0
    dir="${HOST_REPORT_SECURITY_CHECK_DIR:-/var/lib/joinery/security-check}"  # tests only: another folder
    f="${dir}/state"
    [[ -f "$f" && ! -L "$f" ]] || { printf '"none"'; return; }
    local -A st=()
    while IFS='=' read -r k v; do
        [[ "$k" =~ ^[a-z_]{1,30}$ ]] && st[$k]="$v"
    done < <(head -n 40 "$f" 2>/dev/null)
    printf '{'
    for k in installed_at checked_at checked_ok_at check check_error lists_at lists_error at_once_at at_once_outcome at_once_refresh_at; do
        printf '"%s":%s,' "$k" "$(json_text "${st[$k]:-}")"
    done
    for k in pending critical; do
        printf '"%s":%s,' "$k" "$(json_num_or_unknown "${st[$k]:-}")"
    done
    for item in ${st[packages]:-}; do
        (( n < MAX_PACKAGES )) || break
        IFS='|' read -r name inst cand crit first <<< "$item"
        [[ -n "$name" ]] || continue
        n=$((n+1))
        list+="${sep}{\"package\":$(json_version "$name"),\"installed\":$(json_version "$inst"),\"candidate\":$(json_version "$cand"),\"critical\":$([[ "$crit" == 1 ]] && echo true || echo false),\"first_seen\":$(json_text "${first:-}")}"
        sep=","
    done
    printf '"packages":[%s],' "$list"
    if [[ "$(run systemctl show -p UnitFileState --value joinery-security-check.timer)" == enabled ]]; then printf '"scheduled":true}'; else printf '"scheduled":false}'; fi
}

# The services still running code an upgrade replaced on disk (WP1): needrestart's
# own list where it is installed (it also sees a replaced library), else every
# process of this machine's own whose binary /proc names "(deleted)" (a
# container's processes, in another mount namespace, are their own report's).
# {"source":"needrestart"|"proc","services":[...]}, or "unknown".
emit_services_needing_restart() {
    local out line sep="" list="" n=0 p exe self_ns
    if command -v needrestart > /dev/null 2>&1; then
        out="$(run needrestart -b -r l)" || { printf '"unknown"'; return; }
        while IFS= read -r line; do
            [[ "$line" == NEEDRESTART-SVC:* ]] || continue
            (( n < MAX_LIST )) || break
            n=$((n+1)); list+="${sep}$(json_str "${line#NEEDRESTART-SVC: }")"; sep=","
        done <<< "$out"
        printf '{"source":"needrestart","services":[%s]}' "$list"
        return
    fi
    self_ns="$(readlink /proc/self/ns/mnt 2>/dev/null)"
    [[ -n "$self_ns" ]] || { printf '"unknown"'; return; }
    local -A seen=()
    for p in /proc/[0-9]*; do
        exe="$(readlink "$p/exe" 2>/dev/null)" || continue
        [[ "$exe" == *" (deleted)" ]] || continue
        [[ "$(readlink "$p/ns/mnt" 2>/dev/null)" == "$self_ns" ]] || continue
        exe="${exe% (deleted)}"
        [[ -z "${seen[$exe]:-}" ]] || continue
        seen[$exe]=1
        (( n < MAX_LIST )) || break
        n=$((n+1)); list+="${sep}$(json_path "$exe")"; sep=","
    done
    printf '{"source":"proc","services":[%s]}' "$list"
}

# The nightly replacement of this host's site containers (site_refresh.sh,
# specs/multi_tenant_host_hardening.md D9), from its state file: "none" where it
# has never run. Every value is read again here as text or a number; the file
# is root's, but a report is never built from what it has not checked.
json_text() {
    local s
    s="$(printf '%s' "$1" | tr -cd "A-Za-z0-9 ,.:;/()_'=+@-" | cut -c1-300)"
    printf '"%s"' "$s"
}
emit_site_refresh() {
    local dir f k v item sep list
    dir="${HOST_REPORT_SITE_REFRESH_DIR:-/var/lib/joinery/site-refresh}"  # tests only: another folder
    f="${dir}/state"
    [[ -f "$f" && ! -L "$f" ]] || { printf '"none"'; return; }
    local -A st=()
    while IFS='=' read -r k v; do
        [[ "$k" =~ ^[a-z_]{1,30}$ ]] && st[$k]="$v"
    done < <(head -n 40 "$f" 2>/dev/null)
    printf '{'
    for k in installed_at checked_at checked_ok_at check check_error base_version base_id base_built_at base_due ran_at run run_error; do
        printf '"%s":%s,' "$k" "$(json_text "${st[$k]:-}")"
    done
    for k in pending pending_security sites sites_due; do
        printf '"%s":%s,' "$k" "$(json_num_or_unknown "${st[$k]:-}")"
    done
    list=""; sep=""
    for item in ${st[security_packages]:-}; do
        list+="${sep}$(json_str "$item")"; sep=","
    done
    printf '"security_packages":[%s],' "$list"
    list=""; sep=""
    for item in ${st[replaced]:-}; do
        v="${item#*:}"; [[ "$v" =~ ^[0-9]{1,5}(\.[0-9])?$ ]] || v=null
        list+="${sep}{\"site\":$(json_str "${item%%:*}"),\"down_seconds\":${v}}"; sep=","
    done
    printf '"replaced":[%s],' "$list"
    list=""; sep=""
    for item in ${st[passed_over]:-}; do
        list+="${sep}{\"site\":$(json_str "${item%%:*}"),\"why\":$(json_str "${item#*:}")}"; sep=","
    done
    printf '"passed_over":[%s],' "$list"
    # Each site's hold, from its own file: clear and a successful replacement
    # remove the file and write no state.
    local hf site n=0
    local -A h=()
    list=""; sep=""
    for hf in "${dir}"/held/*; do
        [[ -f "$hf" && ! -L "$hf" ]] || continue
        site="${hf##*/}"
        [[ "$site" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || continue
        (( n < MAX_SITES )) || break
        n=$((n+1))
        h=()
        while IFS='=' read -r k v; do [[ "$k" =~ ^[a-z]{1,10}$ ]] && h[$k]="$v"; done < <(head -n 8 "$hf" 2>/dev/null)
        list+="${sep}{\"site\":$(json_str "$site"),\"outcome\":$(json_str "${h[outcome]:-}"),\"reason\":$(json_text "${h[reason]:-}"),\"at\":$(json_text "${h[at]:-}"),\"base\":$(json_str "${h[base]:-}")}"
        sep=","
    done
    printf '"holds":[%s],' "$list"
    if [[ "$(run systemctl show -p UnitFileState --value joinery-site-refresh.timer)" == enabled ]]; then printf '"scheduled":true,'; else printf '"scheduled":false,'; fi
    # A run under way now, the night's or one asked for (site_refresh.sh start):
    # the oneshot service is activating for as long as it runs.
    if [[ "$(run systemctl show -p ActiveState --value joinery-site-refresh.service)" == activating ]]; then printf '"running":true}'; else printf '"running":false}'; fi
}

# Which engine this host's sites run on, for the management node to choose a
# host by: "podman" (the site container engine), "docker", "none" (no engine
# here to run a site), or "unknown" (named, but the helper refused).
emit_site_engine() {
    local pair kind bin
    pair="$(host_engine 2>/dev/null)" || { printf '"unknown"'; return; }
    read -r kind bin <<< "$pair"
    case "$kind" in
        podman|docker) command -v "$bin" >/dev/null 2>&1 && printf '"%s"' "$kind" || printf '"none"' ;;
        *) printf '"unknown"' ;;
    esac
}

emit_containers() {
    local names c site state health pid started port vols answers n=0 i src sum bytes path engine fmt="$INSPECT_FORMAT"
    local -a sites=() states=() healths=() answered=() figures=() srcs=() helds=()
    local -A src_site=() disk=() missing=() reply=() ports=() cfg=() quiet=() replacing=()
    local engine_pair engine_kind
    engine_pair="$(host_engine)" || { printf '"unknown"'; return; }
    read -r engine_kind engine <<< "$engine_pair"
    command -v "$engine" >/dev/null 2>&1 || { printf '"none"'; return; }
    # Podman's start time is a time, printed in Go's own layout: asked for in
    # RFC 3339, as Docker prints it.
    if [[ "$engine_kind" == podman ]]; then
        local docker_started='{{.State.StartedAt}}' podman_started='{{.State.StartedAt.Format "2006-01-02T15:04:05.999999999Z07:00"}}'
        fmt="${fmt//"$docker_started"/"$podman_started"}"
    fi
    names="$(run "$engine" ps -a --format '{{.Names}}')" || { printf '"unknown"'; return; }
    names="$(grep -E '^[a-z0-9_-]{1,50}$' <<< "$names")"
    [[ -n "$names" ]] || { printf '[]'; return; }
    # One read for every container. Nothing back (it timed out, or docker
    # stopped answering) is unknown, never a host with no sites.
    local inspected
    # shellcheck disable=SC2086  # the names are checked above: one word each
    inspected="$(run "$engine" inspect -f "$fmt" $names)"
    [[ -n "$inspected" ]] || { printf '"unknown"'; return; }
    while IFS='|' read -r c site state health pid started port vols; do
        (( n < MAX_SITES )) || break
        c="${c#/}"
        [[ "$c" =~ ^[a-z0-9_-]{1,50}$ && "$site" == "SITENAME=$c" ]] || continue
        sites+=("$c"); states+=("${state:-unknown}"); healths+=("${health:-unknown}")
        # Quiet on this host (site_quiet_host.sh's record: a dormant copy, a
        # frozen source): every page is the quiet state's 503, so it is not
        # asked, and the look cookie that gets past it would write request rows
        # into a copy whose rows must match its source's.
        [[ -f "${HOST_REPORT_ETC:-/etc}/joinery/sites/${c}/quiet" ]] && quiet[$c]=1
        [[ "$state" == "running" && "$port" =~ ^[0-9]{1,5}$ && -z "${quiet[$c]:-}" ]] && ports[$c]="$port"
        # Held stopped by hold_container (a switch-over's old container): not
        # running is what it should be, and container_health leaves it alone.
        if [[ -f "${HOST_REPORT_ETC:-/etc}/joinery/sites/${c}/held" ]]; then helds+=(true); else helds+=(false); fi
        # Being replaced by recreate_site.sh: down on purpose until its new
        # container answers. A mark over an hour old is a killed replacement.
        local rmark="${HOST_REPORT_ETC:-/etc}/joinery/sites/${c}/replacing"
        [[ -f "$rmark" && -n "$(find "$rmark" -mmin -60 2>/dev/null)" ]] && replacing[$c]=1
        figures+=("$(container_figures "$c" "${state:-unknown}" "$pid" "$started")")
        # The site's data is its named volumes; a bind mount can name any
        # path on the server, and is never walked.
        missing[$c]=0
        for src in $vols; do
            [[ "$src" =~ ^/[A-Za-z0-9._/-]+$ && "$src" != *..* ]] || continue
            srcs+=("$src"); src_site[$src]="$c"; missing[$c]=$(( ${missing[$c]} + 1 ))
            [[ "$src" == */"${c}_config/_data" ]] && cfg[$c]="$src"
        done
        n=$((n+1))
    done <<< "$inspected"

    if (( ${#ports[@]} > 0 )); then
        while read -r c answers; do
            [[ -n "${ports[$c]:-}" ]] && reply[$c]="$answers"
        done <<< "$(for c in "${!ports[@]}"; do printf '%s %s\n' "$c" "${ports[$c]}"; done | probe_sites)"
    fi
    for i in "${!sites[@]}"; do
        c="${sites[$i]}"
        # Stopped: it does not answer. Running with no published web port:
        # there is nothing to ask, so unknown.
        if [[ "${states[$i]}" != "running" ]]; then answers=no
        elif [[ -n "${quiet[$c]:-}" ]]; then answers=quiet
        elif [[ -n "${ports[$c]:-}" ]]; then answers="${reply[$c]:-no}"
        else answers=unknown
        fi
        answered+=("$answers")
    done

    # Disk: ONE du over every site's volumes, under the one command timeout, so
    # a large upload tree costs the disk figures and never the minute the
    # agent gives this report. A site gets a figure only when every one of its
    # volumes was measured.
    if (( ${#srcs[@]} > 0 )); then
        while IFS=$'\t' read -r bytes path; do
            [[ "$bytes" =~ ^[0-9]+$ && -n "${src_site[$path]:-}" ]] || continue
            site="${src_site[$path]}"
            disk[$site]=$(( ${disk[$site]:-0} + bytes )); missing[$site]=$(( ${missing[$site]} - 1 ))
        done <<< "$(run du -s -B1 -- "${srcs[@]}")"
    fi

    printf '['
    for i in "${!sites[@]}"; do
        c="${sites[$i]}"
        sum=""
        (( ${missing[$c]:-1} == 0 )) && sum="${disk[$c]:-}"
        (( i == 0 )) || printf ','
        # replacing only when true: a hundred sites' report keeps its room.
        printf '{"name":%s,"state":%s,"health":%s,"answers":"%s","held":%s,%s%s,"disk_bytes":%s,"code_check":%s}' \
            "$(json_str "$c")" "$(json_str "${states[$i]}")" "$(json_str "${healths[$i]}")" "${answered[$i]}" \
            "${helds[$i]}" "$([[ -n "${replacing[$c]:-}" ]] && printf '"replacing":true,')" "${figures[$i]}" "$(json_num_or_unknown "$sum")" "$(code_check_json "${cfg[$c]:-}")"
    done
    printf ']'
}

# ---------------------------------------------------------------------------
# The mail apps gateway (plugins/mail_apps, install_mail_gateway.sh): one
# process on the machine serving every site turned on, each under its own mail
# host name (SNI). Asked the way a mail program asks: a TLS connection to the
# site's name on 993 and 465 must reach a greeting, which a process that runs
# but no longer answers does not give. "none" where it is not installed.
# ---------------------------------------------------------------------------
GATEWAY_DIR=/etc/joinery/mail-gateway
GATEWAY_BIN=/usr/local/lib/joinery/mail-gateway
GATEWAY_UNIT=joinery-mail-gateway.service
GATEWAY_IMAP_PORT=993
GATEWAY_SUBMISSION_PORT=465

# A plain string field of a site entry, however the JSON is laid out.
gateway_field() {
    grep -o "\"$2\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" "$1" 2>/dev/null | head -1 | sed 's/.*:[[:space:]]*"\([^"]*\)"$/\1/'
}

# ok when a TLS connection to 127.0.0.1:PORT under NAME greets with PREFIX, else no_answer.
gateway_greets() {
    local port="$1" name="$2" prefix="$3" say="$4" line
    line="$(printf '%s\r\n' "$say" | timeout "$CMD_TIMEOUT" openssl s_client -connect "127.0.0.1:${port}" \
        -servername "$name" -quiet 2>/dev/null | head -n 1)"
    [[ "$line" == "$prefix"* ]] && printf 'ok' || printf 'no_answer'
}

emit_mail_gateway() {
    local version restarts since since_s up connections f site name end end_s days now first=1 n=0
    [[ -e "$GATEWAY_BIN" || -d "$GATEWAY_DIR/sites" ]] || { printf '"none"'; return; }
    now="$(date -u +%s)"
    version="$(run "$GATEWAY_BIN" -version | awk '{print $NF; exit}')"
    restarts="$(run systemctl show -p NRestarts --value "$GATEWAY_UNIT")"
    up=""
    since="$(run systemctl show -p ActiveEnterTimestamp --value "$GATEWAY_UNIT")"
    if [[ -n "$since" && "$since" != "n/a" ]] && since_s="$(date -u -d "$since" +%s 2>/dev/null)"; then
        up=$(( now - since_s ))
    fi
    connections="$(run ss -Htn state established "( sport = :${GATEWAY_IMAP_PORT} or sport = :${GATEWAY_SUBMISSION_PORT} )" | wc -l)"
    printf '{"version":%s,"state":"%s","restarts":%s,"up_seconds":%s,"connections":%s,"sites":[' \
        "$(json_str "${version:-unknown}")" "$(unit_state "$GATEWAY_UNIT")" "$(json_num_or_unknown "$restarts")" \
        "$(json_num_or_unknown "$up")" "$(json_num_or_unknown "$connections")"
    for f in "$GATEWAY_DIR"/sites/*.enabled; do
        [[ -e "$f" ]] || continue
        (( n < MAX_SITES )) || break
        site="$(basename "$f" .enabled)"
        name="$(gateway_field "$GATEWAY_DIR/sites/${site}.json" mail_hostname)"
        [[ -n "$name" ]] || name="$(gateway_field "$GATEWAY_DIR/sites/${site}.json" hostname)"
        days='"unknown"'
        if [[ -n "$name" ]]; then
            end="$(run openssl s_client -connect "127.0.0.1:${GATEWAY_IMAP_PORT}" -servername "$name" < /dev/null | run openssl x509 -noout -enddate)"
            end="${end#notAfter=}"
            if [[ -n "$end" ]] && end_s="$(date -u -d "$end" +%s 2>/dev/null)"; then
                days=$(( (end_s - now) / 86400 ))
            fi
        fi
        (( first )) || printf ','
        first=0
        printf '{"site":%s,"name":%s,"imap":"%s","submission":"%s","days_left":%s}' \
            "$(json_str "$site")" "$(json_str "$name")" \
            "$([[ -n "$name" ]] && gateway_greets "$GATEWAY_IMAP_PORT" "$name" '* OK' 'a LOGOUT' || printf 'no_answer')" \
            "$([[ -n "$name" ]] && gateway_greets "$GATEWAY_SUBMISSION_PORT" "$name" '220' 'QUIT' || printf 'no_answer')" "$days"
        n=$((n+1))
    done
    printf ']}'
}

# ---------------------------------------------------------------------------
# The object. One line, every key, in this order.
# ---------------------------------------------------------------------------
limits_read
printf '{'
printf '"failed_units":%s,' "$(emit_failed_units)"
printf '"expected_units":%s,' "$(emit_expected_units)"
printf '"fail2ban_jails":%s,' "$(emit_fail2ban_jails)"
printf '"ssh_auth_failures_24h":%s,' "$(emit_ssh_auth_failures)"
printf '"kernel_events_24h":%s,' "$(emit_kernel_events)"
printf '"sshd":%s,' "$(emit_sshd)"
printf '"root_ssh":%s,' "$(emit_root_ssh)"
printf '"disk":%s,' "$(emit_disk)"
printf '"disk_pool":%s,' "$(emit_disk_pool)"
printf '"data_root_encryption":%s,' "$(emit_data_root_encryption)"
printf '"data_root_script":%s,' "$(emit_data_root_script)"
printf '"data_root_stopped":%s,' "$(emit_data_root_stopped)"
printf '"memory":%s,' "$(emit_memory)"
printf '"swap":%s,' "$(emit_swap)"
printf '"cpus":%s,' "$(emit_cpus)"
printf '"php_workers":%s,' "$(emit_php_workers)"
printf '"reboot_required":%s,' "$(emit_reboot_required)"
printf '"reboot_required_since":%s,' "$(emit_reboot_required_since)"
printf '"boot_id":%s,' "$(emit_boot_id)"
printf '"unattended_upgrades_last_run":%s,' "$(emit_unattended_upgrades_last_run)"
printf '"os":%s,' "$(emit_os)"
printf '"answers":%s,' "$(emit_answers)"
printf '"served_certificates":%s,' "$(emit_served_certificates)"
printf '"host_restrictions":%s,' "$(emit_host_restrictions)"
printf '"site_engine":%s,' "$(emit_site_engine)"
printf '"hardening":%s,' "$(emit_hardening)"
printf '"containers":%s,' "$(emit_containers)"
printf '"sites":%s,' "$(emit_sites)"
printf '"site_security":%s,' "$(emit_site_security)"
printf '"site_refresh":%s,' "$(emit_site_refresh)"
printf '"security_pending":%s,' "$(emit_security_pending)"
printf '"services_needing_restart":%s,' "$(emit_services_needing_restart)"
printf '"outbound_limits":%s,' "$(emit_outbound_limits)"
printf '"mail_gateway":%s,' "$(emit_mail_gateway)"
printf '"generated_at":%s' "$(date -u +%s)"
printf '}\n'
exit 0
