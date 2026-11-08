#!/usr/bin/env bash
#
# never_do_check.sh - the never-do list of specs/node_unexplained_root.md
# (section 5.1), as ONE JSON object on stdout: the things that have no
# legitimate source on a machine Joinery runs, checked as they stand now.
#
# Version: 1.7 - the alarm and the review list (specs/never_do_false_alarms.md). The object says
#                "format":2. A setuid-root program no package installed is split from the rest
#                (setgid, or setuid to another user: setid_other), and each setuid item is "path hash"
#                so a Mark safe remembers its content. A program inside a directory that holds a whole
#                root filesystem of its own (a copied image, a build tree) is listed apart, wherever
#                it is (setuid_copied_root). Each ld.so.preload library is reported packaged or not (ld_preload_unpackaged,
#                ld_preload_packaged); ld_preload keeps its old string for an older management node.
#                Review (reviewer2): a copied root filesystem must look like one (a root line in its
#                passwd, a shell in its bin) and is never a top-level directory or /usr/local, and
#                its setuid programs are listed (setuid_copied_root), never dropped; a setuid file
#                at a packaged path with no recorded checksum counts as no package's; a preload
#                entry with $LIB or $PLATFORM is expanded, and one that still names nothing alarms;
#                an engine's configured storage is skipped only outside the system's own trees,
#                and its three configuration files are in the startup list; the alarm's lists
#                keep up to 200 items; an item whose hash could not be read is its path alone.
#                Second review: the walk reads NUL-separated records, so a name ending in a space or
#                holding a newline is its own file, never the one beside it; $LIB is expanded without
#                a glob against the working directory; /usr/share/containers/storage.conf is in the
#                startup list too; the preload scan reads PRELOAD_FILE.
# Version: 1.6 - a port is named after the holder that started first, by start time: pids wrap, and a
#                postfix smtpd forked after the wrap outranked its master (getjoinery, xandy).
# Version: 1.5 - Allow mail apps leaves the rest of the gateway's footprint out too: its enable link to
#                its own unit file (still hashed), and its ports 993, 465 and 10465 while the shipped
#                gateway, byte for byte, holds them.
# Version: 1.4 - the mail gateway's credentials drop-in is one fixed entry while it only hands the
#                gateway certificate files (LoadCredential=): Allow and Stop mail apps rewrite it.
# Version: 1.3 - the setuid walk skips each container engine's storage where its own configuration puts it
#                (Docker and Podman on the data root) and the site engine's build tree.
# Version: 1.2 - a port is named after the holder with the lowest pid, not whichever ss lists first
#                (a postfix master and its smtpd children flapped one port between two names).
# Version: 1.1 - skips containerd's image layers (rootful and rootless); a merged-/usr path matches its
#                package under either name.
# Version: 1.0 - the never_do_check observe word (agent 1.106.0). The agent runs
#                this file as root, verified against the release manifest, with
#                no argv and no stdin; the management node asks every five
#                minutes (RunNodeUptimeChecks).
#
# TWO HALVES, and the difference between them is the whole design:
#
#   findings   seen as they stand, no history needed: a setuid-root program no
#              package installed, a setuid or setgid program a package installed
#              whose bytes changed, an account other than root with uid 0, a
#              library in /etc/ld.so.preload no package installed (these four are
#              the management node's alarm), and, for its review list, other
#              setgid or setuid programs no package installed, a kernel tainted by
#              an out-of-tree, unsigned or forced module, a program running from
#              /tmp, /var/tmp, /dev/shm or from memory, a root program whose file
#              is gone and a packaged library in /etc/ld.so.preload.
#   inventory  lists that are only wrong when they CHANGE: SSH keys, login
#              accounts, sudoers, the files that start things (systemd units,
#              cron), listening TCP ports, and the size of the login logs.
#              This machine keeps no memory of them: the management node
#              keeps the last accepted list and compares (IncidentSourceNeverDo),
#              so an attacker who owns the machine cannot rewrite what it was.
#
# THE CONTRACT, which tests/integration/never_do_check_gate.sh pins:
#
#   - It takes NOTHING. No argument changes what it reads.
#   - READ ONLY. Every command reads; nothing is written but its own temp dir.
#   - It never prints content: a key is its fingerprint (never its comment), a
#     file is its path and the first 16 hex of its sha256, a process is its
#     uid and program path (never its arguments), a log is its inode and size.
#     Every string is reduced to [A-Za-z0-9._/@:+=,*%-] and capped.
#   - Bounded: every list is capped and says so (truncated), the setuid walk
#     has a time limit and a walk that did not finish says so (partial).
#   - Run as a user other than root it still answers, marked partial: it
#     cannot read other users' processes or homes.
#
# Runs on: Ubuntu/Debian (dpkg, iproute2, coreutils, findutils, openssh-client).

set -u
export LC_ALL=C

MAX_ITEMS=400           # entries kept in any inventory list
MAX_FINDINGS=20         # entries kept in any findings list
MAX_ALARM=200           # entries kept in a list the management node alarms on
MAX_STR=200             # characters kept of any string
FIND_TIMEOUT=150        # seconds for the whole setuid walk

safe() {
    local drop='[^A-Za-z0-9._/@:+=,*%-]' s
    s="${1//$drop/?}"
    printf '%s' "${s:0:$MAX_STR}"
}

T="$(mktemp -d)"
trap 'rm -rf "${T:?}"' EXIT
partial=false
[[ "$(id -u)" == 0 ]] || partial=true

# A JSON array from a file of lines (already safe), capped. Runs in a
# command substitution, so which lists were cut is counted by note_cap first.
json_list() {  # NAME FILE CAP
    local file="$2" cap="$3" first=1 line
    printf '['
    while IFS= read -r line; do
        [[ -n "$line" ]] || continue
        (( first )) || printf ','
        first=0
        printf '"%s"' "$line"
    done < <(awk -v n="$cap" 'NF && NR<=n' "$file")
    printf ']'
}

# Every file a package installed, and every packaged file's md5. On a
# merged-/usr machine /bin is a link to usr/bin, and a package built before the
# merge names its file /bin/x where a walk finds /usr/bin/x (fuse3 on Ubuntu
# 24.04 lists /bin/fusermount3), so each such path is recorded under both names.
merged=()
for d in bin sbin lib lib32 lib64 libx32; do
    [[ -L "/$d" && "$(readlink "/$d")" == "usr/$d" ]] && merged+=("$d")
done
usr_twins() {  # PREFIX: "/" for .list paths, "" for .md5sums paths
    awk -v pre="$1" -v dirs="${merged[*]}" '
        BEGIN { n = split(dirs, d, " ") }
        { print
          for (i = 1; i <= n; i++) {
              a = pre d[i] "/"; b = pre "usr/" d[i] "/"
              if (index(line_of($0), a) == 1) { print swap($0, a, b); break }
              if (index(line_of($0), b) == 1) { print swap($0, b, a); break }
          } }
        function line_of(s) { return pre == "" ? substr(s, index(s, " ") + 1) : s }
        function swap(s, from, to,    p) {
            if (pre != "") return to substr(s, length(from) + 1)
            p = index(s, " ")
            return substr(s, 1, p) to substr(s, p + length(from) + 1)
        }'
}
cat /var/lib/dpkg/info/*.list 2>/dev/null | usr_twins / | sort -u > "$T/pkgfiles"
awk '{ $1 = $1; print }' /var/lib/dpkg/info/*.md5sums 2>/dev/null | usr_twins "" > "$T/pkgmd5"
# A name holding a newline is never a package's: grep -F would read it as two names.
is_packaged() { [[ "$1" != *$'\n'* ]] && grep -Fxq -- "$1" "$T/pkgfiles"; }
hash16() { sha256sum -- "$1" 2>/dev/null | cut -c1-16; }
# An item: "path hash", or the path alone when the file cannot be read.
item_line() {  # PATH [FILE TO HASH]
    local h
    h="$(hash16 "${2:-$1}")"
    if [[ -n "$h" ]]; then printf '%s %s\n' "$(safe "$1")" "$h"; else printf '%s\n' "$(safe "$1")"; fi
}
# A package installed this file and its bytes are still the package's.
packaged_intact() {
    local want have
    is_packaged "$1" || return 1
    want="$(awk -v p="${1#/}" '$2 == p { print $1; exit }' "$T/pkgmd5")"
    [[ -n "$want" ]] || return 1
    have="$(md5sum -- "$1" 2>/dev/null | cut -d' ' -f1)"
    [[ "$have" == "$want" ]]
}

SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
jail_is_shipped() {
    local have shipped
    have="$(sha256sum -- "$1" 2>/dev/null | cut -d' ' -f1)"
    [[ -n "$have" ]] || return 1
    for shipped in "$SITE_ROOT"/maintenance_scripts/install_tools/joinery_jail/bin/joinery-jail-"$(uname -m)" \
                   /var/www/html/*/maintenance_scripts/install_tools/joinery_jail/bin/joinery-jail-"$(uname -m)"; do
        [[ -f "$shipped" ]] || continue
        [[ "$(sha256sum -- "$shipped" 2>/dev/null | cut -d' ' -f1)" == "$have" ]] && return 0
    done
    return 1
}

# The mail gateway (plugins/mail_apps): the program a site's Allow mail apps
# starts, byte for byte the one this release ships.
GATEWAY_BIN=/usr/local/lib/joinery/mail-gateway
gateway_is_shipped() {
    local have shipped
    [[ "$1" == "$GATEWAY_BIN" ]] || return 1
    have="$(sha256sum -- "$1" 2>/dev/null | cut -d' ' -f1)"
    [[ -n "$have" ]] || return 1
    for shipped in "$SITE_ROOT"/public_html/plugins/mail_apps/provisioning/bin/mail-gateway-"$(uname -m)" \
                   /var/www/html/*/public_html/plugins/mail_apps/provisioning/bin/mail-gateway-"$(uname -m)"; do
        [[ -f "$shipped" ]] || continue
        [[ "$(sha256sum -- "$shipped" 2>/dev/null | cut -d' ' -f1)" == "$have" ]] && return 0
    done
    return 1
}

# ---------------------------------------------------------------------------
# findings
# ---------------------------------------------------------------------------

# Where the setuid walk does not go: the kernel's own trees, and every place
# a container engine or our image build keeps whole filesystem trees. Their
# images carry their own setuid programs (su, passwd, crontab); what runs in a
# container is the engine's business, not this list's. An engine's storage is
# read from its own configuration, wherever the install put it (Docker and the
# site engine move it onto the data root), beside the engines' defaults.
#
# A configured place is skipped only when it is outside the system's own trees:
# those files are root's to write, and "data-root": "/usr" must not take /usr
# out of the walk. The files themselves are in the startup list (ENGINE_CONFIGS).
ENGINE_CONFIGS=(/etc/docker/daemon.json /etc/containers/storage.conf /usr/share/containers/storage.conf
                /etc/containerd/config.toml)
system_tree() {  # PATH: the machine's own trees, or a top-level directory itself
    case "$1" in
        /|/usr|/usr/*|/etc|/etc/*|/bin|/bin/*|/sbin|/sbin/*|/lib*|/boot|/boot/*|/opt|/var|/srv|/home|/root|/tmp) return 0 ;;
    esac
    [[ "$1" == /* && "$1" != */../* && "$1" != */.. && "${1#/}" == */* ]] || return 0
    return 1
}
configured_storage() {
    sed -n 's/.*"data-root"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' /etc/docker/daemon.json 2>/dev/null
    sed -n 's/^[[:space:]]*graphroot[[:space:]]*=[[:space:]]*"\([^"]*\)".*/\1/p' \
        /etc/containers/storage.conf /usr/share/containers/storage.conf 2>/dev/null
    sed -n 's/^[[:space:]]*root[[:space:]]*=[[:space:]]*"\([^"]*\)".*/\1/p' /etc/containerd/config.toml 2>/dev/null
}
walk_skips() {
    local d
    printf '%s\n' /proc /sys /snap /var/lib/docker /var/lib/containerd /var/lib/containers \
        /var/lib/joinery-site-engine-build '/var/tmp/site-engine-build.*' \
        '*/.local/share/containers' '*/.local/share/containerd'
    while IFS= read -r d; do
        d="${d%/}"
        [[ -n "$d" ]] && ! system_tree "$d" && printf '%s\n' "$d"
    done < <(configured_storage)
}

# The directory holding a whole root filesystem of its own that PATH is inside,
# on stdout: a copied image or a build tree, wherever it is, which carries its
# own setuid programs the way the engines' storage does. One looks like a root
# filesystem: an etc/passwd (a file, not a link) with a root line, a usr/bin,
# and a shell in bin or usr/bin. A top-level directory and /usr/local never
# count, nor does the machine's own root. Its setuid programs are listed for
# review (setuid_copied_root), never dropped, so a planted "copy" is still seen.
# walk_skips stays the fast path: a skipped tree is never walked at all.
copied_root_of() {
    local d="${1%/*}"
    while [[ -n "$d" && "$d" != / ]]; do
        if [[ "${d#/}" == */* && "$d" != /usr/local \
              && -f "$d/etc/passwd" && ! -L "$d/etc/passwd" && -d "$d/usr/bin" \
              && ( -e "$d/bin/sh" || -e "$d/usr/bin/sh" ) ]] \
           && grep -q '^root:[^:]*:0:0:' "$d/etc/passwd" 2>/dev/null; then
            printf '%s' "$d"
            return 0
        fi
        d="${d%/*}"
    done
    return 1
}

# Setuid and setgid programs on every local filesystem that allows them.
: > "$T/setuid_unpackaged"; : > "$T/setuid_modified"; : > "$T/setid_other"; : > "$T/setuid_copied_root"
# Each filesystem once, from its own top: a data root bind-mounted in ten
# places is one walk, not ten (mountinfo field 4 is the root within the fs).
mounts=()
declare -A seen_dev=()
while read -r _id _parent devno root mnt opts rest; do
    fstype="${rest#*- }"; fstype="${fstype%% *}"
    case "$fstype" in ext2|ext3|ext4|xfs|btrfs|f2fs|jfs|reiserfs|zfs) ;; *) continue ;; esac
    [[ "$root" == / ]] || continue
    [[ ",$opts," == *,nosuid,* ]] && continue
    [[ -n "${seen_dev[$devno]:-}" ]] && continue
    seen_dev[$devno]=1
    mounts+=("$(printf '%b' "$mnt")")
done < /proc/self/mountinfo
if (( ${#mounts[@]} )); then
    nice_cmd=(nice -n 19)
    command -v ionice >/dev/null 2>&1 && nice_cmd=(ionice -c 3 nice -n 19)
    prune_expr=()
    while IFS= read -r d; do
        [[ -n "$d" ]] || continue
        (( ${#prune_expr[@]} )) && prune_expr+=(-o)
        prune_expr+=(-path "$d")
    done < <(walk_skips)
    timeout "$FIND_TIMEOUT" "${nice_cmd[@]}" find "${mounts[@]}" -xdev \
        \( "${prune_expr[@]}" \) -prune \
        -o -type f -perm /6000 -printf '%U %m %p\0' 2>/dev/null | sort -zu > "$T/setuid"
    (( ${PIPESTATUS[0]} == 0 )) || partial=true
    # NUL-separated: a name may end in a space or hold a newline, and read
    # would trim or split it into the name of the file beside it.
    while IFS= read -r -d '' rec; do
        owner="${rec%% *}"; rec="${rec#* }"
        mode="${rec%% *}"; f="${rec#* }"
        [[ -n "$f" && "$owner" =~ ^[0-9]+$ && "$mode" =~ ^[0-7]+$ ]] || continue
        # The parser jail's launcher is ours and setuid by design; it is
        # explained only while its bytes are the prebuilt a signed site tree on
        # this machine ships (install_parser_jail.sh copies it byte for byte).
        if [[ "$f" == /usr/local/sbin/joinery-jail ]] && jail_is_shipped "$f"; then
            continue
        fi
        # A packaged path with no recorded checksum (a conffile, a package
        # that ships no md5sums) proves nothing about the bytes there: it
        # counts as no package's.
        want=""
        is_packaged "$f" && want="$(awk -v p="${f#/}" '$2 == p { print $1; exit }' "$T/pkgmd5")"
        if [[ -z "$want" ]]; then
            if croot="$(copied_root_of "$f")"; then
                item_line "$f" >> "$T/setuid_copied_root"
            elif [[ "$owner" == 0 ]] && (( 8#$mode & 04000 )); then
                item_line "$f" >> "$T/setuid_unpackaged"
            else
                item_line "$f" >> "$T/setid_other"
            fi
            continue
        fi
        have="$(md5sum -- "$f" 2>/dev/null | cut -d' ' -f1)"
        if [[ -n "$have" && "$have" != "$want" ]]; then
            item_line "$f" >> "$T/setuid_modified"
        fi
    done < "$T/setuid"
fi

# Accounts other than root with uid 0.
awk -F: '$3 == "0" && $1 != "root" { print $1 }' /etc/passwd 2>/dev/null \
    | while IFS= read -r a; do safe "$a"; echo; done > "$T/uid0"

# Kernel taint by a module: F forced (bit 1), O out-of-tree (12), E unsigned (13).
taint="$(cat /proc/sys/kernel/tainted 2>/dev/null)"
taint_flags=""
if [[ "$taint" =~ ^[0-9]+$ ]]; then
    (( (taint >> 1) & 1 ))  && taint_flags+="F"
    (( (taint >> 12) & 1 )) && taint_flags+="O"
    (( (taint >> 13) & 1 )) && taint_flags+="E"
fi

# Programs running from a scratch directory or from memory, and root programs
# whose file is gone. A process is its uid and program path, never its argv
# beyond the script an interpreter was handed.
: > "$T/tmp_programs"; : > "$T/deleted_root"
scratch='^(/tmp/|/var/tmp/|/dev/shm/|/memfd:)'
for p in /proc/[0-9]*; do
    exe="$(readlink "$p/exe" 2>/dev/null)" || continue
    [[ -n "$exe" ]] || continue
    uid="$(awk '/^Uid:/ { print $3; exit }' "$p/status" 2>/dev/null)"
    [[ -n "$uid" ]] || continue
    # runc copies itself into memory to start a container (CVE-2019-5736).
    [[ "$exe" == /memfd:runc_cloned* ]] && continue
    if [[ "$exe" =~ $scratch ]]; then
        printf '%s %s\n' "$uid" "$(safe "$exe")" >> "$T/tmp_programs"
        continue
    fi
    case "$(basename -- "$exe")" in
        sh|bash|dash|zsh|python*|perl*|php*|ruby*|node)
            arg1="$(tr '\0' '\n' < "$p/cmdline" 2>/dev/null | sed -n 2p)"
            if [[ "$arg1" =~ $scratch ]]; then
                printf '%s %s %s\n' "$uid" "$(safe "$exe")" "$(safe "$arg1")" >> "$T/tmp_programs"
            fi
            ;;
    esac
    if [[ "$uid" == 0 && "$exe" == *" (deleted)" ]]; then
        # A program an update replaced still has a file at its path; one that
        # removed itself does not.
        orig="${exe% (deleted)}"
        [[ -e "$orig" ]] || printf '%s\n' "$(safe "$orig")" >> "$T/deleted_root"
    fi
done
sort -u -o "$T/tmp_programs" "$T/tmp_programs"
sort -u -o "$T/deleted_root" "$T/deleted_root"

# /etc/ld.so.preload loads its libraries into every program: a library no
# package installed there is a classic rootkit. One a package installed, bytes
# intact (snoopy's logger), or a name that is not there is only worth a look.
# ld.so splits the file on white space and colons; a bare name is looked up in
# the library directories, and $LIB and $PLATFORM (braced or not) are expanded.
# An entry that still names no file loads nothing, unless it carries a token
# this cannot expand ($ORIGIN, or any other $): that one alarms, since it
# cannot be checked. ld_preload is the whole file as one string, for a
# management node older than 1.7.
preload=""
: > "$T/preload_unpackaged"; : > "$T/preload_packaged"
preload_candidates() {  # ENTRY -> each path it can name, one per line
    local e="$1" d lib plat
    if [[ "$e" != */* ]]; then
        for d in /lib/*-linux-gnu /usr/lib/*-linux-gnu /lib64 /usr/lib64 /lib /usr/lib; do
            printf '%s\n' "$d/$e"
        done
        return
    fi
    # The values ld.so gives $LIB, from the machine's own library directories
    # (absolute globs, never against the working directory), plus the usual.
    local libs=(lib lib64 "lib/$(uname -m)-linux-gnu" "usr/lib/$(uname -m)-linux-gnu")
    for d in /lib/*-linux-gnu /usr/lib/*-linux-gnu; do [[ -d "$d" ]] && libs+=("${d#/}"); done
    for lib in "${libs[@]}"; do
        for plat in "$(uname -m)" haswell xeon_phi x86_64 i686 aarch64 v8 power9; do
            d="${e//\$\{LIB\}/$lib}"; d="${d//\$LIB/$lib}"
            d="${d//\$\{PLATFORM\}/$plat}"; d="${d//\$PLATFORM/$plat}"
            printf '%s\n' "$d"
        done
    done | sort -u
}
preload_scan() {  # FILE: sorts its entries into preload_unpackaged and preload_packaged
    local lib lp found bad stripped
    [[ -s "$1" ]] || return 0
    preload="$(grep -v '^[[:space:]]*#' "$1" | tr -s ' \n' ',' | sed 's/^,//; s/,$//')"
    [[ -n "$preload" ]] && preload="$(safe "$preload")"
    while IFS= read -r lib; do
        [[ -n "$lib" ]] || continue
        found=0; bad=""
        while IFS= read -r lp; do
            [[ -f "$lp" ]] || continue
            found=1
            packaged_intact "$lp" || packaged_intact "$(readlink -f -- "$lp")" || { bad="$lp"; break; }
        done < <(preload_candidates "$lib")
        if [[ -n "$bad" ]]; then
            item_line "$lib" "$bad" >> "$T/preload_unpackaged"
        elif (( found )); then
            safe "$lib" >> "$T/preload_packaged"; echo >> "$T/preload_packaged"
        else
            # $LIB and $PLATFORM were tried; any $ left is one this cannot check.
            stripped="${lib//\$\{LIB\}/}"; stripped="${stripped//\$LIB/}"
            stripped="${stripped//\$\{PLATFORM\}/}"; stripped="${stripped//\$PLATFORM/}"
            if [[ "$stripped" == *'$'* ]]; then
                safe "$lib" >> "$T/preload_unpackaged"; echo >> "$T/preload_unpackaged"
            else
                printf '%s missing\n' "$(safe "$lib")" >> "$T/preload_packaged"
            fi
        fi
    done < <(grep -v '^[[:space:]]*#' "$1" | tr -s ' \t:' '\n')
    sort -u -o "$T/preload_unpackaged" "$T/preload_unpackaged"
    sort -u -o "$T/preload_packaged" "$T/preload_packaged"
}
PRELOAD_FILE=/etc/ld.so.preload
preload_scan "$PRELOAD_FILE"

# ---------------------------------------------------------------------------
# inventory
# ---------------------------------------------------------------------------

# SSH keys: "user SHA256:..." for every key in every account's authorized_keys.
: > "$T/ssh_keys"
while IFS=: read -r user _ _ _ _ home _; do
    for f in "$home/.ssh/authorized_keys" "$home/.ssh/authorized_keys2"; do
        [[ -f "$f" ]] || continue
        ssh-keygen -lf "$f" 2>/dev/null | awk '{ print $2 }' \
            | while IFS= read -r fp; do printf '%s %s\n' "$(safe "$user")" "$(safe "$fp")"; done >> "$T/ssh_keys"
    done
done < /etc/passwd
sort -u -o "$T/ssh_keys" "$T/ssh_keys"

# Accounts that can log in: "name uid shell".
awk -F: '$7 !~ /(nologin|false|sync|halt|shutdown)$/ && $7 != "" { print $1 " " $3 " " $7 }' /etc/passwd 2>/dev/null \
    | while read -r n u s; do printf '%s %s %s\n' "$(safe "$n")" "$(safe "$u")" "$(safe "$s")"; done \
    | sort -u > "$T/accounts"

# sudoers: "path hash".
: > "$T/sudoers"
for f in /etc/sudoers /etc/sudoers.d/*; do
    [[ -f "$f" ]] || continue
    printf '%s %s\n' "$(safe "$f")" "$(hash16 "$f")" >> "$T/sudoers"
done
sort -u -o "$T/sudoers" "$T/sudoers"

# What starts things: unit files and cron, except what a package installed.
# A file is "path hash", a link (an enabled unit) is "path -> target".
#
# The mail gateway's credentials drop-in (install_mail_gateway.sh) is rewritten
# whenever a site's mail apps are allowed or stopped. While every line is
# [Service] or LoadCredential=NAME:/PATH it starts nothing, only hands the
# unprivileged gateway a certificate file, so it is one fixed entry; any other
# line and it is hashed like any unit file.
GATEWAY_CREDENTIALS=/etc/systemd/system/joinery-mail-gateway.service.d/credentials.conf
credentials_only() {
    [[ -f "$1" && ! -L "$1" ]] || return 1
    ! grep -qvE '^(\[Service\]|LoadCredential=[A-Za-z0-9._-]+:/[^[:space:]]+)?$' -- "$1"
}
: > "$T/startup"
# Allow mail apps enables the gateway's own unit, whose file is in this list by
# its hash: the link adds nothing that file does not say.
GATEWAY_LINK=/etc/systemd/system/multi-user.target.wants/joinery-mail-gateway.service
GATEWAY_UNIT=/etc/systemd/system/joinery-mail-gateway.service
startup_entry() {
    local f="$1"
    if [[ -L "$f" && "$f" == "$GATEWAY_LINK" && "$(readlink "$f")" == "$GATEWAY_UNIT" ]]; then
        return 0
    elif [[ -L "$f" ]]; then
        printf '%s -> %s\n' "$(safe "$f")" "$(safe "$(readlink "$f")")"
    elif [[ -f "$f" ]]; then
        is_packaged "$f" && return 0
        if [[ "$f" == "$GATEWAY_CREDENTIALS" ]] && credentials_only "$f"; then
            printf '%s credentials-only\n' "$(safe "$f")"
            return 0
        fi
        printf '%s %s\n' "$(safe "$f")" "$(hash16 "$f")"
    fi
}
while IFS= read -r f; do startup_entry "$f"; done < <(
    find /etc/systemd/system /etc/cron.d /etc/cron.hourly /etc/cron.daily /etc/cron.weekly \
         /etc/cron.monthly /var/spool/cron/crontabs -maxdepth 3 \( -type f -o -type l \) 2>/dev/null
    for f in /etc/crontab /etc/rc.local; do [[ -e "$f" ]] && echo "$f"; done
) >> "$T/startup"
# The engines' configuration, packaged or not: it says where the setuid walk
# does not go.
for f in "${ENGINE_CONFIGS[@]}"; do
    [[ -f "$f" ]] && printf '%s %s\n' "$(safe "$f")" "$(hash16 "$f")" >> "$T/startup"
done
sort -u -o "$T/startup" "$T/startup"

# Listening TCP ports: "tcp address:port program". A port in the kernel's
# ephemeral range is a program's own pick and changes at every start, so it
# reads "eph" and the program carries the meaning.
read -r eph_lo eph_hi < /proc/sys/net/ipv4/ip_local_port_range 2>/dev/null || { eph_lo=32768; eph_hi=60999; }
# ss lists every process holding a socket, in no fixed order (a master and
# the children it forks share it), so a port is named after the holder that
# started first: the one that opened it. Start time, not pid: pids wrap on a
# busy machine, and a child forked after the wrap has a lower pid than its
# master. A holder gone before its start time is read sorts last.
PROC=/proc
holders_by_age() {  # the ss users:(...) field -> "pid name" lines, oldest first
    local pid name stat start
    printf '%s' "$1" | grep -o '("[^"]*",pid=[0-9]*' \
        | sed 's/^("\([^"]*\)",pid=\([0-9]*\)$/\2 \1/' \
        | while read -r pid name; do
            stat="$(cat "$PROC/$pid/stat" 2>/dev/null)"
            start="$(printf '%s' "${stat##*) }" | awk '{ print $20 }')"
            [[ -n "$stat" && "$start" =~ ^[0-9]+$ ]] || start=999999999999999999
            printf '%s %s %s\n' "$start" "$pid" "$name"
        done | sort -n -k1,1 -k2,2 | cut -d' ' -f2-
}
port_holder() {  # the ss users:(...) field
    holders_by_age "$1" | awk 'NR==1 { sub(/^[0-9]+ /, ""); print }'
}
port_holder_pid() {  # the pid port_holder names
    holders_by_age "$1" | awk 'NR==1 { print $1 }'
}
# The mail gateway's ports, held by the shipped gateway (Allow mail apps).
gateway_port() {  # port, ss users:(...) field
    local pid
    [[ "$1" == 993 || "$1" == 465 || "$1" == 10465 ]] || return 1
    pid="$(port_holder_pid "$2")"
    [[ -n "$pid" ]] && gateway_is_shipped "$(readlink "/proc/$pid/exe" 2>/dev/null)"
}
: > "$T/ports"
if command -v ss >/dev/null 2>&1; then
    ss -Hltnp 2>/dev/null | while read -r _state _rq _sq local _peer proc; do
        port="${local##*:}"; addr="${local%:*}"; addr="${addr//[\[\]]/}"
        [[ "$port" =~ ^[0-9]+$ ]] || continue
        (( port >= eph_lo && port <= eph_hi )) && port=eph
        gateway_port "$port" "$proc" && continue
        prog="$(port_holder "$proc")"
        printf 'tcp %s:%s %s\n' "$(safe "$addr")" "$port" "$(safe "${prog:-unknown}")"
    done | sort -u > "$T/ports"
fi

# The login logs: "path inode size". Rotation makes a new file (a new inode);
# the same inode getting smaller is a log someone cut.
: > "$T/logs"
for f in /var/log/wtmp /var/log/btmp /var/log/lastlog /var/log/auth.log; do
    [[ -f "$f" ]] || continue
    stat -c '%n %i %s' -- "$f" 2>/dev/null >> "$T/logs"
done

# ---------------------------------------------------------------------------
# the object
# ---------------------------------------------------------------------------
truncated_lists=""
note_cap() {  # NAME FILE CAP
    local n
    n="$(grep -c . "$2" 2>/dev/null)"
    (( ${n:-0} > $3 )) && truncated_lists+="${truncated_lists:+,}\"$1\""
    return 0
}
note_cap setuid_copied_root "$T/setuid_copied_root" "$MAX_ALARM"
for x in setid_other scratch_programs deleted_root_programs logs ld_preload_packaged; do
    case $x in
        setid_other) f="$T/$x" ;;
        ld_preload_packaged) f="$T/preload_packaged" ;;
        scratch_programs) f="$T/tmp_programs" ;;
        deleted_root_programs) f="$T/deleted_root" ;;
        logs) f="$T/logs" ;;
    esac
    note_cap "$x" "$f" "$MAX_FINDINGS"
done
note_cap uid0_accounts "$T/uid0" "$MAX_ALARM"
note_cap setuid_unpackaged "$T/setuid_unpackaged" "$MAX_ALARM"
note_cap setuid_modified "$T/setuid_modified" "$MAX_ALARM"
note_cap ld_preload_unpackaged "$T/preload_unpackaged" "$MAX_ALARM"
for x in ssh_keys accounts sudoers startup ports; do note_cap "$x" "$T/$x" "$MAX_ITEMS"; done

printf '{"format":2,"findings":{'
printf '"setuid_unpackaged":%s,' "$(json_list setuid_unpackaged "$T/setuid_unpackaged" "$MAX_ALARM")"
printf '"setuid_modified":%s,' "$(json_list setuid_modified "$T/setuid_modified" "$MAX_ALARM")"
printf '"setid_other":%s,' "$(json_list setid_other "$T/setid_other" "$MAX_FINDINGS")"
printf '"setuid_copied_root":%s,' "$(json_list setuid_copied_root "$T/setuid_copied_root" "$MAX_ALARM")"
printf '"uid0_accounts":%s,' "$(json_list uid0_accounts "$T/uid0" "$MAX_ALARM")"
printf '"kernel_taint":"%s",' "$taint_flags"
printf '"scratch_programs":%s,' "$(json_list scratch_programs "$T/tmp_programs" "$MAX_FINDINGS")"
printf '"deleted_root_programs":%s,' "$(json_list deleted_root_programs "$T/deleted_root" "$MAX_FINDINGS")"
printf '"ld_preload":"%s",' "$preload"
printf '"ld_preload_unpackaged":%s,' "$(json_list ld_preload_unpackaged "$T/preload_unpackaged" "$MAX_ALARM")"
printf '"ld_preload_packaged":%s' "$(json_list ld_preload_packaged "$T/preload_packaged" "$MAX_FINDINGS")"
printf '},"inventory":{'
printf '"ssh_keys":%s,' "$(json_list ssh_keys "$T/ssh_keys" "$MAX_ITEMS")"
printf '"accounts":%s,' "$(json_list accounts "$T/accounts" "$MAX_ITEMS")"
printf '"sudoers":%s,' "$(json_list sudoers "$T/sudoers" "$MAX_ITEMS")"
printf '"startup":%s,' "$(json_list startup "$T/startup" "$MAX_ITEMS")"
printf '"ports":%s,' "$(json_list ports "$T/ports" "$MAX_ITEMS")"
printf '"logs":%s' "$(json_list logs "$T/logs" "$MAX_FINDINGS")"
printf '},"truncated":[%s],' "$truncated_lists"
printf '"partial":%s,' "$partial"
printf '"generated_at":%s}\n' "$(date -u +%s)"
exit 0
