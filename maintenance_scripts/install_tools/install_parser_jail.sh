#!/usr/bin/env bash
#
# install_parser_jail.sh - install or converge the parser jail's launcher on
# this machine (specs/parser_jail.md, docs/document_text.md).
#
# Version: 2.3 - ships launcher 2.1.0 (--watch-fd, JOINERY_JAIL_FEATURES=watch-fd): core ends a sandboxed
#              plugin's run by closing a pipe, since it cannot signal the supervisor (reviewer2 E2)
# Version: 2.2 - the read probe opens the file (head -c 0) instead of test -r, which on Ubuntu
#              26.04 ignores ACLs: a granted 770 tree read as ungranted and the launcher was
#              never installed; acl is installed for the plugin users' grant too
# Version: 2.1 - the plugin uid mark and the gone records live on the site's state volume in a
#              container, so a container rebuild keeps them
# Version: 2.0 - the allowlist fence (specs/sandboxed_php_plugins.md WP1): no util-linux
#              prlimit (the launcher sets the address-space cap itself); one Unix user per
#              sandboxed PHP plugin, jpx-NAME, created with the next uid above a root-owned
#              high-water mark and removed when no site on this machine has the plugin
# Version: 1.3 - the grant on cache/ is the directory and its default ACL alone, no recursive walk of the
#                web user's tree as root (specs/shell_command_audit.md D3)
# Version: 1.2 - the grant on public_html and vendor states group r-x, mask and defaults, so it can
#              never leave the executable set group-writable; a wider grant made earlier is narrowed.
# Version: 1.1 - checks for util-linux prlimit, which the launcher uses to apply
#              the address-space cap as the last hop before the command.
#          1.0
#
# What it leaves behind, every time it runs:
#   - a system user `joinery-jail` with no shell, no home and no groups;
#   - /usr/local/sbin/joinery-jail, mode 4755 root:root, byte-identical to the
#     prebuilt joinery_jail/bin/joinery-jail-<uname -m> shipped beside this
#     script (nothing is ever compiled on a node);
#   - read access for that user to the code tree, the vendor directory and the
#     cache — as an ACL, and only where the user cannot already read them (a
#     container's 755/644 tree needs none). The config directory, uploads and
#     everything else stay exactly as unreadable as they were.
#
# Runs at the platform's root moments — container start, site build, node
# upgrade, the Run Plugin Installers action — through _plugin_installers_start.sh,
# and by hand on a box those never reached:
#
#     sudo bash /var/www/html/SITE/maintenance_scripts/install_tools/install_parser_jail.sh
#
# Contract (docs/plugin_developer_guide.md): idempotent, root, non-interactive,
# exit 0 when not applicable. When the read grant cannot be made, the launcher
# is NOT installed: DocumentText keeps its advisory fallback (parsing as the web
# user, reported in VaultHealth and the admin notice) rather than gaining a jail
# whose every extraction fails.
#
# Usage:  install_parser_jail.sh [SITENAME] [SITE_ROOT]

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JAIL_USER="joinery-jail"
DEST="/usr/local/sbin/joinery-jail"

# The site is where this copy of the script ships, unless the caller says
# otherwise (install.sh runs from the source tree it is installing FROM).
SITE_ROOT="${2:-}"
if [[ -z "${SITE_ROOT}" ]]; then
    SITENAME="${1:-}"
    if [[ -n "${SITENAME}" && -d "/var/www/html/${SITENAME}" ]]; then
        SITE_ROOT="/var/www/html/${SITENAME}"
    else
        SITE_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
    fi
fi

if [[ "$(id -u)" != "0" ]]; then
    echo "parser jail: not root - skipping (run: sudo bash ${SCRIPT_DIR}/install_parser_jail.sh)"
    exit 0
fi

MACHINE="$(uname -m)"
SRC="${SCRIPT_DIR}/joinery_jail/bin/joinery-jail-${MACHINE}"
if [[ ! -f "${SRC}" ]]; then
    echo "parser jail: no prebuilt launcher for ${MACHINE} at ${SRC} - skipping" >&2
    exit 0
fi
if ! head -c 4 "${SRC}" | grep -q 'ELF'; then
    echo "parser jail: ${SRC} is not an executable - skipping" >&2
    exit 0
fi

# --- 1. the user -------------------------------------------------------------
if ! id -u "${JAIL_USER}" >/dev/null 2>&1; then
    if useradd --system --no-create-home --home-dir /nonexistent \
            --shell /usr/sbin/nologin --user-group "${JAIL_USER}" 2>/dev/null; then
        echo "parser jail: created user ${JAIL_USER}"
    else
        echo "parser jail: WARNING - could not create user ${JAIL_USER} - not installed" >&2
        exit 0
    fi
fi

# --- 2. read access to the tree ---------------------------------------------
# Only what the extraction subprocess needs: the code, the vendor directory,
# and the class-map cache. Granted as an ACL, and only when the probe says the
# user cannot already read it — a container's tree is world-readable and needs
# nothing; a node's 770 tree needs the grant, re-checked at every root moment
# because an upgrade swaps a fresh public_html into place.
# An open, not test -r: Ubuntu 26.04's test (the Rust coreutils) answers -r
# from the mode bits and ignores the ACL this script grants, so a granted
# tree read as ungranted and the launcher was never installed.
user_can_read() {
    runuser -u "$1" -- /usr/bin/head -c 0 -- "$2" >/dev/null 2>&1
}
can_read() {
    user_can_read "${JAIL_USER}" "$1"
}

PUBLIC_HTML="${SITE_ROOT}/public_html"

# The ACL that grants the jail read access to one directory. public_html and
# vendor are the executable set, 755/644 and writable by their owner alone
# (fix_permissions.sh), so their grant states the group, the mask and the
# defaults outright: setfacl otherwise recalculates the mask from the group
# entry, and a tree that was 770 when the grant landed came out group-writable
# (775), the state read_only_tree exists to refuse. cache is data, the web
# user's 0770, and keeps the ordinary grant.
# An optional second argument names who the grant is for (default the jail
# user): "g:joinery-jpx" for the plugin users' shared group.
jail_acl() {
    local who="${2:-u:${JAIL_USER}}"
    local acl="${who}:rX,d:${who}:rX"
    [[ "$1" == "cache" ]] || acl="${acl},g::rX,m::rX,d:g::rX,d:m::rX,d:o::rX"
    printf '%s' "${acl}"
}

# A grant made before jail_acl, on a tree that was 770 at the time, left the
# executable set's mask or defaults group-writable. Put them right wherever
# they are, whether or not a grant is needed now.
for d in public_html vendor; do
    [[ -d "${SITE_ROOT}/${d}" ]] && command -v getfacl >/dev/null 2>&1 || continue
    if getfacl -p "${SITE_ROOT}/${d}" 2>/dev/null | grep -q -E '^(mask|default:group|default:mask)::rw'; then
        setfacl -R -m "$(jail_acl "${d}")" "${SITE_ROOT}/${d}" 2>/dev/null \
            && echo "parser jail: closed group write the jail grant had opened on ${SITE_ROOT}/${d}" \
            || echo "parser jail: WARNING - could not close group write on ${SITE_ROOT}/${d}" >&2
    fi
done
PROBE_CODE="${PUBLIC_HTML}/utils/extract_document_text.php"
if [[ ! -f "${PROBE_CODE}" ]]; then
    echo "parser jail: ${PROBE_CODE} not found under ${SITE_ROOT} - skipping" >&2
    exit 0
fi

grant_needed=0
for probe in "${PROBE_CODE}" "${SITE_ROOT}/vendor/autoload.php"; do
    [[ -e "${probe}" ]] || continue
    can_read "${probe}" || grant_needed=1
done

if [[ "${grant_needed}" == "1" ]]; then
    if ! command -v setfacl >/dev/null 2>&1; then
        echo "parser jail: installing acl for setfacl"
        (apt-get update -qq >/dev/null 2>&1 || true; apt-get install -y acl >/dev/null 2>&1) || true
    fi
    if ! command -v setfacl >/dev/null 2>&1; then
        echo "parser jail: WARNING - setfacl unavailable and the tree is not readable by ${JAIL_USER}; launcher NOT installed" >&2
        exit 0
    fi
    # Traverse the site root, read the three directories (and, by default ACL,
    # whatever an upgrade adds to them later).
    setfacl -m "u:${JAIL_USER}:x" "${SITE_ROOT}" 2>/dev/null || true
    for d in public_html vendor; do
        [[ -d "${SITE_ROOT}/${d}" ]] || continue
        setfacl -R -m "$(jail_acl "${d}")" "${SITE_ROOT}/${d}" 2>/dev/null \
            || echo "parser jail: WARNING - setfacl failed on ${SITE_ROOT}/${d}" >&2
    done
    # cache/ is the web user's: a recursive walk there as root would follow an
    # entry swapped for a link between the walk and the call. The directory
    # alone gets the grant and the default, so what the pool writes there later
    # (the class map, rewritten through a temp file and a rename, so it inherits
    # the default) is readable; nothing already inside is touched, not even by
    # name: setfacl follows a link argument, and a name in the web user's
    # directory can be a link by the time it is reached
    # (specs/shell_command_audit.md D3, reviewer1 B9).
    if [[ -d "${SITE_ROOT}/cache" && ! -L "${SITE_ROOT}/cache" ]]; then
        setfacl -m "$(jail_acl cache)" "${SITE_ROOT}/cache" 2>/dev/null \
            || echo "parser jail: WARNING - setfacl failed on ${SITE_ROOT}/cache" >&2
    fi
    echo "parser jail: granted ${JAIL_USER} read access under ${SITE_ROOT}"
    for probe in "${PROBE_CODE}" "${SITE_ROOT}/vendor/autoload.php"; do
        [[ -e "${probe}" ]] || continue
        if ! can_read "${probe}"; then
            echo "parser jail: WARNING - ${JAIL_USER} still cannot read ${probe}; launcher NOT installed" >&2
            exit 0
        fi
    done
fi

# --- 3. the launcher ---------------------------------------------------------
if [[ -f "${DEST}" ]] && cmp -s "${SRC}" "${DEST}"; then
    # Same bytes; make sure the mode is what a jail needs and nothing more.
    chown root:root "${DEST}"
    chmod 4755 "${DEST}"
    echo "parser jail: ${DEST} already current"
else
    if install -o root -g root -m 4755 "${SRC}" "${DEST}.tmp" && mv -f "${DEST}.tmp" "${DEST}"; then
        echo "parser jail: installed ${DEST}"
    else
        rm -f "${DEST}.tmp"
        echo "parser jail: WARNING - could not install ${DEST}" >&2
        exit 0
    fi
fi

# --- 4. one run through the fence ---------------------------------------------
want="$(id -u "${JAIL_USER}")"
got="$("${DEST}" --timeout=5 -- /usr/bin/id -u 2>/dev/null || true)"
if [[ "${got}" == "${want}" ]]; then
    echo "parser jail: ok - commands run as ${JAIL_USER} (uid ${want})"
else
    echo "parser jail: WARNING - a jailed command reported uid '${got}', expected ${want}" >&2
fi

# --- 5. one user per sandboxed PHP plugin ------------------------------------
# specs/sandboxed_php_plugins.md design 2. A plugin runs as jpx-NAME, which
# owns no file anywhere: the Unix permission is the only guard for what
# Landlock does not cover (a mode or a time on a file the user owns), and
# only an owner of nothing keeps that set empty. The users share one primary
# group, joinery-jpx, which owns nothing either and is what the tree's read
# grant names on a box whose tree is not world-readable.
#
# A uid is never reused: a removed user's IPC objects or files would belong to
# the next plugin, and useradd hands out a freed uid. The next uid is the one
# above a high-water mark kept by root outside every site tree.
JPX_GROUP="joinery-jpx"
# The mark must outlive the machine's /etc/passwd. In a site container that
# file is in the writable layer a rebuild replaces, and so is /var/lib; the
# site's state volume (/var/lib/joinery/sites, root's own, mounted) is kept.
# The users are made again at the next start, above the kept mark: they own
# nothing, so a new uid costs nothing, and no uid is ever handed out twice.
JPX_LEGACY_STATE_DIR="/var/lib/joinery-jail"
if [[ -d /var/lib/joinery/sites && ! -L /var/lib/joinery/sites ]] && mountpoint -q /var/lib/joinery/sites 2>/dev/null; then
    JPX_STATE_DIR="/var/lib/joinery/sites/.joinery-jail"
else
    JPX_STATE_DIR="${JPX_LEGACY_STATE_DIR}"
fi
JPX_MARK="${JPX_STATE_DIR}/jpx_uid_mark"
JPX_UID_BASE=50000
JPX_UID_MAX=59999
# Folders are the web user's to make, and the mark only rises, so a web user
# making and removing folders could spend the range for good (reviewer2 D9).
# New users are rationed per run and in total, and a user outlives its folder
# by a week, so a folder that comes back gets its old uid.
JPX_GONE_DIR="${JPX_STATE_DIR}/gone"
JPX_MAX_NEW_PER_RUN=20
JPX_MAX_USERS=200
JPX_KEEP_GONE_SECONDS=$((7 * 24 * 3600))

# The folder name is the manifest name pattern (SandboxManifest::NAME_PATTERN)
# and the folder holds exactly manifest.json (a file) and src/ (a directory),
# neither a link (SandboxPlugins::namesAreSandboxed). Checked here, as root,
# because the tree is the web user's.
jpx_plugin_dir() {
    local d="$1" name
    name="$(basename "$d")"
    [[ ${#name} -ge 2 && ${#name} -le 20 && "${name}" =~ ^[a-z][a-z0-9]*(_[a-z0-9]+)*$ ]] || return 1
    [[ -d "$d" && ! -L "$d" ]] || return 1
    [[ "$(ls -A "$d" 2>/dev/null | LC_ALL=C sort | tr '\n' '/')" == "manifest.json/src/" ]] || return 1
    [[ -f "$d/manifest.json" && ! -L "$d/manifest.json" && -d "$d/src" && ! -L "$d/src" ]] || return 1
    return 0
}

converge_plugin_users() {
    # Every site on this machine, so one site's run never removes a user
    # another site still needs.
    # want: a sandboxed plugin folder in that shape, which gets a user.
    # present: any real folder of that name, which keeps one (a stray file
    # in it is not the plugin leaving).
    local -A want=() present=()
    local d name
    for d in "${PUBLIC_HTML}/plugins"/* /var/www/html/*/public_html/plugins/*; do
        name="$(basename "$d")"
        [[ -d "$d" && ! -L "$d" ]] && present["jpx-${name}"]=1
        jpx_plugin_dir "$d" && want["jpx-${name}"]=1
    done

    if [[ ${#want[@]} -gt 0 ]] && ! getent group "${JPX_GROUP}" >/dev/null 2>&1; then
        groupadd --system "${JPX_GROUP}" 2>/dev/null \
            || { echo "parser jail: WARNING - could not create group ${JPX_GROUP}; no plugin users" >&2; return; }
    fi
    if [[ -L "${JPX_STATE_DIR}" ]]; then
        echo "parser jail: WARNING - ${JPX_STATE_DIR} is a link; no plugin users" >&2
        return
    fi
    install -d -o root -g root -m 0700 "${JPX_STATE_DIR}" "${JPX_GONE_DIR}"

    local mark uid legacy
    mark="$(cat "${JPX_MARK}" 2>/dev/null || true)"
    [[ "${mark}" =~ ^[0-9]+$ ]] || mark=$((JPX_UID_BASE - 1))
    # A mark kept before the state volume was used carries over.
    if [[ "${JPX_STATE_DIR}" != "${JPX_LEGACY_STATE_DIR}" ]]; then
        legacy="$(cat "${JPX_LEGACY_STATE_DIR}/jpx_uid_mark" 2>/dev/null || true)"
        [[ "${legacy}" =~ ^[0-9]+$ ]] && (( legacy > mark )) && mark="${legacy}"
    fi
    # Never below a plugin user that exists already (a mark lost with its disk).
    while IFS=: read -r _ _ uid _; do
        [[ "${uid}" =~ ^[0-9]+$ ]] && (( uid > mark )) && mark="${uid}"
    done < <(getent passwd | awk -F: '$1 ~ /^jpx-/')
    # Kept every run, not only when a user is made: a mark that moved, or
    # fell behind the users that exist, must be on disk before the next
    # rebuild takes /etc/passwd and the old location with it.
    if [[ "$(cat "${JPX_MARK}" 2>/dev/null || true)" != "${mark}" ]] && (( mark >= JPX_UID_BASE )); then
        printf '%s\n' "${mark}" > "${JPX_MARK}.tmp" && mv -f "${JPX_MARK}.tmp" "${JPX_MARK}"
    fi

    local u next made=0 total
    total="$(getent passwd | grep -c '^jpx-' || true)"
    for u in "${!want[@]}"; do
        id -u "$u" >/dev/null 2>&1 && continue
        if (( made >= JPX_MAX_NEW_PER_RUN || total >= JPX_MAX_USERS )); then
            echo "parser jail: WARNING - plugin user limit reached (${JPX_MAX_NEW_PER_RUN} per run, ${JPX_MAX_USERS} in all); ${u} waits" >&2
            continue
        fi
        next=$((mark + 1))
        while getent passwd "${next}" >/dev/null 2>&1; do next=$((next + 1)); done
        if (( next > JPX_UID_MAX )); then
            echo "parser jail: WARNING - no plugin uid left below ${JPX_UID_MAX}; ${u} not created" >&2
            continue
        fi
        # Written before the user exists: a crash between the two wastes a
        # uid, never reuses one.
        printf '%s\n' "${next}" > "${JPX_MARK}.tmp" && mv -f "${JPX_MARK}.tmp" "${JPX_MARK}"
        mark="${next}"
        if useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin \
                --uid "${next}" --gid "${JPX_GROUP}" "$u" 2>/dev/null; then
            echo "parser jail: created plugin user ${u} (uid ${next})"
            made=$((made + 1)); total=$((total + 1))
        else
            echo "parser jail: WARNING - could not create plugin user ${u}" >&2
        fi
    done

    # A plugin whose folder has been gone from every site for a week loses its
    # user. userdel refuses while a run of it is still going; the next root
    # moment finishes the job.
    local now first gone
    now="$(date +%s)"
    while IFS=: read -r u _; do
        [[ "$u" =~ ^jpx-[a-z0-9_]+$ ]] || continue
        gone="${JPX_GONE_DIR}/${u}"
        if [[ -n "${present[$u]:-}" ]]; then
            rm -f "${gone}"
            continue
        fi
        first="$(cat "${gone}" 2>/dev/null || true)"
        if [[ ! "${first}" =~ ^[0-9]+$ ]]; then
            printf '%s\n' "${now}" > "${gone}"
            continue
        fi
        (( now - first >= JPX_KEEP_GONE_SECONDS )) || continue
        userdel "$u" 2>/dev/null && rm -f "${gone}" \
            && echo "parser jail: removed plugin user ${u} (its plugin has been gone a week)"
    done < <(getent passwd)

    # The tree's read grant for plugin users, where the tree is not already
    # world-readable (a node's 770 tree): the same grant the jail user has.
    local probe_user=""
    for u in "${!want[@]}"; do id -u "$u" >/dev/null 2>&1 && { probe_user="$u"; break; }; done
    if [[ -n "${probe_user}" ]] && ! user_can_read "${probe_user}" "${PROBE_CODE}"; then
        if ! command -v setfacl >/dev/null 2>&1; then
            echo "parser jail: installing acl for setfacl (plugin users)"
            (apt-get update -qq >/dev/null 2>&1 || true; apt-get install -y acl >/dev/null 2>&1) || true
        fi
        if command -v setfacl >/dev/null 2>&1; then
            # A recursive walk as root is safe here, as it is for the jail
            # user's grant above, only because public_html is the executable
            # set: 755/644 and writable by its owner alone, never by the web
            # user (specs/implemented/read_only_tree.md; fix_permissions.sh).
            # cache/, which the web user writes, never gets this walk.
            setfacl -m "g:${JPX_GROUP}:x" "${SITE_ROOT}" 2>/dev/null || true
            setfacl -R -m "$(jail_acl public_html "g:${JPX_GROUP}")" "${PUBLIC_HTML}" 2>/dev/null \
                && echo "parser jail: granted ${JPX_GROUP} read access to ${PUBLIC_HTML}" \
                || echo "parser jail: WARNING - setfacl failed on ${PUBLIC_HTML} for ${JPX_GROUP}" >&2
        else
            echo "parser jail: WARNING - plugin users cannot read ${PUBLIC_HTML} and setfacl is missing" >&2
        fi
    fi
}
converge_plugin_users

exit 0
