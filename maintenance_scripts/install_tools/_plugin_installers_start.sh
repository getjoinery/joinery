#!/usr/bin/env bash
#
# _plugin_installers_start.sh - run the platform's host installers: core's
# first, then every active plugin's.
#
# Version: 2.10 - When fix_permissions.sh fails, the log line carries the last
#                 lines of its stderr, not only the fact (review round 1, R4b).
# Version: 2.9 - Writes cache/certificates.json after the installers: every
#                lineage under /etc/letsencrypt/live (its names and dates,
#                the renewal conf's installer), whether certbot's timer is
#                active, and where the site's name and its www resolve. The
#                root-owned tree makes /etc/letsencrypt unreadable to the
#                pool, so this is how the admin notice and the health panel
#                learn that renewal has stopped before the site dies of it
#                (specs/implemented/tls_and_origin_trust.md WP11). Same mechanics as
#                host_converger.last: written on every run, world-readable.
# Version: 2.8 - The change hash names the templates as default_*.conf. The
#                earlier glob default_*vhost.conf matched only the proxy
#                template, so an edit to default_virtualhost.conf never
#                converged a box until something else changed.
# Version: 2.7 - The change hash covers every script in install_tools and the
#                vhost templates (incl. vhost_history/), not only install_*.sh:
#                a renderer or trust-helper change converged only at the daily
#                floor before.
#          2.6 - Runs fix_permissions.sh on a converging run. Nothing else did,
#                so on a self-hosted box that upgraded from the browser the
#                ownership model never actually arrived: the data set kept its
#                old modes and config/tree_owner was never written. The mode is
#                derived from who owns public_html AFTER the assertion, so a
#                developer box stays the developer's.
#          2.5 - Refreshes the root timer's out-of-tree entry point itself. The
#                installer used to, and the installer is found through the site,
#                so a stale copy could not reach the thing that would replace it
#                - it stayed stale for good. Only ever from a source file that
#                passes the same trust check the installers get.
#          2.4 - Resolves the installers and helpers it runs from the SITE's
#                maintenance_scripts, not from its own directory. The converger's
#                timer runs a root-owned copy of this script outside the tree
#                (install_host_converger.sh 1.1), where SCRIPT_DIR is
#                /usr/local/sbin - so every sibling tool resolved against it was
#                missing, and a converging run silently did nothing but say so
#                once about the secrets helper.
#          2.3 - Mints the per-site secrets that live in config/ (secret_box_key,
#                the backup key, the backup ledger directory) when they are
#                absent. They were minted on first use inside a web request, and
#                config/ is no longer the pool's to write.
#          2.2 - The record is trusted only when root owns it (it is written
#                root:root and by nothing else, so any other owner is a forgery),
#                and the assertion gives config/ the recorded owner rather than
#                root, which is what fix_permissions.sh gives it - the two used
#                to take that directory off each other on a developer box.
#          2.1 - The tree owner is read from {site}/config/tree_owner rather
#                than assumed to be root. The assertion fires precisely when
#                public_html is owned by www-data, which is the one state in
#                which the tree cannot say who should own it — so 2.0 assumed
#                root and took a developer checkout to root the first time it
#                ran. fix_permissions.sh records the answer; this reads it, and
#                refuses a record it cannot attribute to root or to the owner it
#                names, a name that is not an account here, or www-data (which
#                would turn the assertion into a no-op, exactly what a forged
#                record would want). Any refusal falls back to root.
#          2.0 - The read-only tree (specs/read_only_tree.md, S10). Two things
#                happen before any installer runs, in this order:
#                  1. The ownership assertion. Where public_html is still owned
#                     by www-data - a container whose start command predates the
#                     new image, a box mid-upgrade - the executable set is
#                     re-owned to root here, inline, before anything is
#                     executed out of it.
#                  2. The refusal. An installer that is not owned by the tree
#                     owner, or that any account but its owner can write, is not
#                     run. Every one of these scripts runs AS ROOT, and they live
#                     in maintenance_scripts/ and plugins/ - so before this, a
#                     process running as the web user could put ten lines in
#                     one of them and be root at the next tick.
#          1.5 - The host converger (specs/host_converger.md): --when-changed runs
#                the installers only when the deployed release, the runner or
#                the set of installers changed since the stamp in cache/, or
#                once a day; every run records cache/host_converger.last for
#                the admin notice. --site-root=DIR names the site for a test.
#                install_host_converger.sh is the third core installer.
#          1.4 - The parser jail's installer is the second core installer: the
#                launcher belongs on every Joinery instance, and this is the one
#                root moment a node has (specs/parser_jail.md).
#          1.3 - Derives its own site root and reads its own database
#                credentials. It did neither, and under the run_plugin_installers
#                primitive - which passes no argument and inherits no environment -
#                each gap alone produced a clean-looking exit 0: with no SITENAME
#                it skipped outright, and with no PGPASSWORD in the environment the
#                plugin query failed and its empty output was indistinguishable
#                from a site that simply has no active plugins. The site root is
#                two levels above this file, and the credentials are in the site
#                config, read the way install_agent.sh already reads it. SITENAME
#                stays an optional argument, so the Dockerfile CMD, install.sh and
#                upgrade.php callers are unchanged.
#          1.2 - Runs core's own host installers before the plugin loop. The
#                joinery-agent is the first of them: it belongs on every
#                Joinery instance, so gating it on a plugin being active meant
#                it never reached a managed node at all — only management nodes,
#                where server_manager happens to be turned on. What the agent
#                does on a given machine is decided by the agent_enabled
#                setting, which the installer reads (specs/agent_on_node_architecture.md).
#                1.1 - Also installs declared PHP extensions when running as root.
#                A plugin uploaded/installed AFTER the site image was built can
#                declare requires.extensions the image never resolved; without
#                this step its only install moment would be the next code
#                upgrade. Container start is a root moment that precedes
#                apache, so newly installed extensions are picked up.
#                1.0 - Generalized from _mail_stack_start.sh (spec
#                plugin_dependency_installation). Any plugin may declare a
#                `host_installer` path in its plugin.json; this script runs
#                each active plugin's installer at the root moments that have
#                no systemd: container start (Dockerfile CMD), site build
#                (install.sh), and node upgrade (upgrade.php).
#
# Contract for host_installer scripts (see docs/plugin_developer_guide.md):
# idempotent (this runs on EVERY container start), root, non-interactive,
# exit 0 when not-applicable.
#
# It is deliberately fail-safe - plugin absent, plugin inactive, database
# unreachable, or an installer failure all exit 0, so a broken installer can
# never block the container from starting.
#
# Usage:  _plugin_installers_start.sh [--when-changed] [--site-root=DIR] [SITENAME] [SITE_ROOT]
#         All optional. SITENAME names a site OTHER than the one this copy
#         of the script was delivered in; with no argument the script works on
#         its own site. Nothing is required in the environment - the database
#         credentials are read from the site config, not inherited.
#         --when-changed is the host converger's mode: run nothing unless the
#         stamp says something changed (or is a day old). --site-root=DIR is
#         the explicit site, for a gate that runs this against a temp tree.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

WHEN_CHANGED=0
EXPLICIT_ROOT=""
POSITIONAL=()
for arg in "$@"; do
    case "${arg}" in
        --when-changed) WHEN_CHANGED=1 ;;
        --site-root=*)  EXPLICIT_ROOT="${arg#--site-root=}" ;;
        *)              POSITIONAL+=("${arg}") ;;
    esac
done
set -- "${POSITIONAL[@]+"${POSITIONAL[@]}"}"

# Where this file LIVES is the site it belongs to: it ships inside the tree, at
# {site root}/maintenance_scripts/install_tools/. Deriving the root instead of
# being told it is what lets the script run with no arguments at all - which is
# how the run_plugin_installers primitive invokes it - and it retires the
# hardcoded /var/www/html, which is wrong on any node installed elsewhere.
DERIVED_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# ...unless this copy does not ship inside a tree at all. The converger's timer
# runs a root-owned copy from /usr/local/sbin, and two levels above that is
# /usr, which is not a site however confidently the arithmetic says so. The unit
# always passes the site explicitly, so this only guards a bare hand-run.
[[ -d "${DERIVED_ROOT}/public_html" ]] || DERIVED_ROOT=""

# An explicit SITENAME keeps its historical meaning wherever that meaning still
# resolves, so no existing invocation changes on a standard box. It matters that
# the argument wins there: install.sh runs this from the SOURCE tree it is
# installing FROM, which can carry the same basename as the site it is building,
# and deriving would then converge the installers on the build directory instead
# of the installed site. The derived root is for the two cases the convention
# does not cover - no argument at all (how the run_plugin_installers primitive
# invokes it), and a node installed somewhere other than /var/www/html.
SITENAME="${1:-}"
if [[ -n "${EXPLICIT_ROOT}" ]]; then
    SITE_ROOT="${EXPLICIT_ROOT}"
elif [[ -n "${2:-}" && -d "${2}" ]]; then
    # The resolved root, from a caller that already knows it (the converger's
    # unit, install.sh on an off-convention site).
    SITE_ROOT="${2}"
elif [[ -z "${SITENAME}" && -n "${DERIVED_ROOT}" ]]; then
    SITE_ROOT="${DERIVED_ROOT}"
elif [[ -z "${SITENAME}" ]]; then
    echo "plugin installers: no site named and this copy does not ship inside one - skipping" >&2
    exit 0
elif [[ -d "/var/www/html/${SITENAME}" ]]; then
    SITE_ROOT="/var/www/html/${SITENAME}"
elif [[ -n "${DERIVED_ROOT}" && "$(basename "${DERIVED_ROOT}")" == "${SITENAME}" ]]; then
    # The named site is the tree this copy ships in, installed off the
    # convention. Answering "site not initialised" here would be a lie about a
    # site that is sitting around this very script.
    SITE_ROOT="${DERIVED_ROOT}"
else
    # Nothing to work with but the convention. Keep the old answer, and with it
    # the old, legible "site not initialised yet" skip.
    SITE_ROOT="/var/www/html/${SITENAME}"
fi
SITENAME="$(basename "${SITE_ROOT}")"

PUBLIC_HTML="${SITE_ROOT}/public_html"
CONFIG_FILE="${SITE_ROOT}/config/Globalvars_site.php"

# Where the installers and helpers live: the SITE's copy, not this script's
# directory. The two are the same file when the runner is invoked out of the
# tree, and are not when it is invoked from the root-owned copy the converger's
# timer runs (/usr/local/sbin/joinery-host-converger, install_host_converger.sh
# 1.1). SCRIPT_DIR is /usr/local/sbin there, and everything resolved against it
# silently went missing - the core installers skipped, the config-secret mints
# never ran, and the converge hash covered nothing. What is deliberately outside
# the tree is the ENTRY POINT, so nobody but root chooses what root starts; the
# installers it then runs are the deployed release's, and the refusal below is
# what makes running them safe.
TOOLS_DIR="${SITE_ROOT}/maintenance_scripts/install_tools"
[[ -d "${TOOLS_DIR}" ]] || TOOLS_DIR="${SCRIPT_DIR}"

if [[ ! -d "${PUBLIC_HTML}/plugins" ]]; then
    echo "plugin installers: no plugins directory - skipping"
    exit 0
fi
if [[ ! -f "${CONFIG_FILE}" ]]; then
    echo "plugin installers: site not initialised yet - skipping"
    exit 0
fi

# --- The executable set belongs to root (specs/read_only_tree.md) ------------
# This runs before the stamp check on purpose. A container started from an image
# whose CMD still chowns the tree to www-data hands us a writable executable set
# on every boot, and on such a box nothing about the release has "changed" — so
# a run that consulted the stamp first would exit early and leave the tree open
# until the image is rebuilt. One stat is what a correct tree costs.
#
# It is inline rather than a call to fix_permissions.sh because this is the step
# that makes it safe to execute anything out of the tree, and fix_permissions.sh
# is in the tree.
EXEC_ROOTS=()
for _d in public_html maintenance_scripts vendor; do
    [[ -d "${SITE_ROOT}/${_d}" ]] && EXEC_ROOTS+=("${SITE_ROOT}/${_d}")
done

# --- Who owns this tree ------------------------------------------------------
# Written by fix_permissions.sh, which is told --dev or --production and so
# knows the answer; never by anything on the web side. Read here before the
# assertion, with no database and without including a line of the tree.
#
# The reading itself, and the refusal below, live in _tree_trust.sh, because
# install_host_converger.sh has to reach the identical answer when it decides
# whether to refresh the root timer's entry point. Two copies would drift.
if [[ -f "${TOOLS_DIR}/_tree_trust.sh" ]]; then
    # shellcheck source=_tree_trust.sh
    . "${TOOLS_DIR}/_tree_trust.sh"
else
    # Nothing below this point is safe without it: every installer runs as root,
    # and the check that says which ones may is in that file.
    echo "plugin installers: _tree_trust.sh missing from ${TOOLS_DIR} - refusing to run anything as root" >&2
    exit 0
fi

TREE_OWNER_TARGET="$(joinery_tree_owner_record "${SITE_ROOT}")"
TREE_OWNER_GROUP="$(id -gn "${TREE_OWNER_TARGET}" 2>/dev/null || echo "${TREE_OWNER_TARGET}")"

assert_tree_ownership() {
    local ph_owner
    ph_owner="$(stat -c '%U' "${PUBLIC_HTML}" 2>/dev/null || true)"
    [[ "${ph_owner}" == "www-data" ]] || return 0

    if [[ "$(id -u)" != "0" ]]; then
        # Not root, so nothing is changed — but say exactly what would be, so a
        # box in this state is legible from a log and a test can read the plan.
        echo "ownership: ${PUBLIC_HTML} is owned by www-data; would chown ${TREE_OWNER_TARGET}:${TREE_OWNER_GROUP} 755/644 ${SITE_ROOT} ${EXEC_ROOTS[*]-} and root:www-data 0640 ${SITE_ROOT}/config/*.php (not root - nothing changed)"
        return 0
    fi

    chown "${TREE_OWNER_TARGET}:${TREE_OWNER_GROUP}" "${SITE_ROOT}" 2>/dev/null || true
    chmod 755 "${SITE_ROOT}" 2>/dev/null || true
    for _mf in RELEASE_MANIFEST RELEASE_MANIFEST.sig; do
        if [[ -f "${SITE_ROOT}/${_mf}" ]]; then
            chown "${TREE_OWNER_TARGET}:${TREE_OWNER_GROUP}" "${SITE_ROOT}/${_mf}" 2>/dev/null || true
            chmod 644 "${SITE_ROOT}/${_mf}" 2>/dev/null || true
        fi
    done

    if (( ${#EXEC_ROOTS[@]} )); then
        local prune=( -not -path "*/.git" -not -path "*/.git/*" )
        find "${EXEC_ROOTS[@]}" "${prune[@]}" \( -type f -o -type d \) \
             \( -not -user "${TREE_OWNER_TARGET}" -o -not -group "${TREE_OWNER_GROUP}" \) \
             -exec chown "${TREE_OWNER_TARGET}:${TREE_OWNER_GROUP}" {} + 2>/dev/null || true
        find "${EXEC_ROOTS[@]}" "${prune[@]}" -type d \
             -not -perm 755 -exec chmod 755 {} + 2>/dev/null || true
        find "${EXEC_ROOTS[@]}" "${prune[@]}" -type f -name '*.sh' \
             -not -perm 755 -exec chmod 755 {} + 2>/dev/null || true
        find "${EXEC_ROOTS[@]}" "${prune[@]}" -type f -not -name '*.sh' \
             -not -perm 644 -exec chmod 644 {} + 2>/dev/null || true
    fi

    if [[ -d "${SITE_ROOT}/config" ]]; then
        # The recorded owner, not root: fix_permissions.sh gives this directory
        # {tree owner}:www-data, and hardcoding root here would have the two
        # taking it off each other on every tick of a developer box.
        chown "${TREE_OWNER_TARGET}:www-data" "${SITE_ROOT}/config" 2>/dev/null || true
        chmod 750 "${SITE_ROOT}/config" 2>/dev/null || true
        find "${SITE_ROOT}/config" -maxdepth 1 -type f -name '*.php' \
             -exec chown root:www-data {} + -exec chmod 640 {} + 2>/dev/null || true
    fi

    echo "ownership: the executable set was owned by www-data; re-owned to ${TREE_OWNER_TARGET}"
}

assert_tree_ownership

# --- Keep the root timer's entry point current -------------------------------
# The converger's timer runs a root-owned copy of this script from
# /usr/local/sbin, so that nobody who can write the tree can choose what root
# starts (specs/read_only_tree.md). Something has to carry a new release's
# runner into that copy, and it has to be something that works from a copy that
# is already stale - which rules out the installer, since the copy finds the
# installer through the site and a copy that cannot do that cannot repair
# itself. This is that something, and it runs after the ownership assertion, so
# what it copies has already been established as the tree owner's.
refresh_converger_entry() {
    # Overridable for a test only; the timer's unit names the real path and
    # nothing in the environment of a root timer sets this.
    local entry="${JOINERY_CONVERGER_ENTRY:-/usr/local/sbin/joinery-host-converger}"
    local source="${TOOLS_DIR}/_plugin_installers_start.sh"

    [[ "$(id -u)" == "0" ]] || return 0
    [[ -f "${source}" ]] || return 0
    [[ -x "${entry}" ]] || return 0          # first install is the installer's job
    cmp -s "${source}" "${entry}" && return 0

    # Only from a file this box would be willing to run. Refusing here is the
    # difference between "root runs the deployed release" and "root runs
    # whatever last landed in the tree".
    installer_is_trusted "${source}" || {
        echo "converger entry: refusing to refresh from an untrusted ${source}" >&2
        return 0
    }

    if install -o root -g root -m 755 "${source}" "${entry}" 2>/dev/null; then
        echo "converger entry: refreshed ${entry} from the deployed release"
    else
        echo "converger entry: WARNING - could not refresh ${entry}" >&2
    fi
}

# --- The permissions the deployed release expects -----------------------------
# The rollout depends on this: a self-hosted box takes the new code through the
# browser upgrade, still as the web user, and the ownership model only arrives
# when something runs fix_permissions.sh as root. Without it the data set keeps
# whatever modes it had (uploads readable by every local account), and
# config/tree_owner is never written, so the root actor has no recorded answer
# to who owns this tree.
#
# Only on a converging run, never on a quiet tick: the sweep is a find over the
# tree, and it corrects only what is already wrong.
#
# The mode is derived, not assumed. Reading it off public_html is safe by this
# point because the ownership assertion above has already run: on a node that
# means root, and --production; on a developer box it is the developer's
# account, and --dev keeps it there. Guessing --production on a developer box
# would hand the whole checkout to root, which is exactly what happened the
# first time this was tried.
apply_tree_permissions() {
    [[ "${WHEN_CHANGED}" == "1" ]] || return 0
    [[ "$(id -u)" == "0" ]] || return 0

    local script="${TOOLS_DIR}/fix_permissions.sh"
    [[ -f "${script}" ]] || return 0
    installer_is_trusted "${script}" || return 0

    local owner mode
    owner="$(stat -c '%U' "${PUBLIC_HTML}" 2>/dev/null || echo root)"
    if [[ "${owner}" == "root" ]]; then
        mode="--production"
    else
        mode="--dev"
    fi

    local why
    if why="$(bash "${script}" "${SITENAME}" "${mode}" 2>&1 >/dev/null)"; then
        echo "permissions: applied ${mode} (tree owner ${owner})"
    else
        # The reason, not only the fact: the last lines of the script's stderr.
        why="$(printf '%s' "${why}" | tail -3 | tr '\n' ' ')"
        echo "permissions: WARNING - fix_permissions.sh ${mode} failed; the tree may still be writable by the web user${why:+ - ${why}}" >&2
        CONVERGE_OUTCOME="permissions-failed"
    fi
}

# --- The per-site secrets that live in config/ -------------------------------
# config/ belongs to the tree owner: the pool reads what it needs and creates
# nothing there, because directory write is enough to unlink Globalvars_site.php
# and leave a different one in its place. So the first-use mints that used to
# happen inside a web request happen here, as root
# (specs/read_only_tree.md). Each is idempotent and refuses to overwrite - a key
# that already exists is the one this site's data was encrypted with.
if [[ -f "${TOOLS_DIR}/_config_secrets.sh" ]]; then
    # shellcheck source=_config_secrets.sh
    . "${TOOLS_DIR}/_config_secrets.sh"
    if [[ "$(id -u)" == "0" ]]; then
        joinery_mint_site_secrets "${SITE_ROOT}"
    fi
else
    echo "config secrets: _config_secrets.sh missing - keys will not be minted" >&2
fi

# --- An installer this box did not author is not run -------------------------
# Every script below is executed AS ROOT. The tree owner is whoever owns
# public_html — root on a node, the developer account on the developer box — and
# an installer owned by anyone else, or writable by anyone but its owner, is a
# script we cannot say where it came from. Refusing is loud: the reason goes to
# stderr and the outcome to cache/host_converger.last, so a box whose ownership
# has drifted reports it instead of running whatever it finds.
# The recorded owner is the authority where there is one. Reading it off
# public_html instead would let whoever owns the tree vouch for the scripts in
# it. A box with no record keeps the old reading, so an installer still runs on
# a site that has not been through the new fix_permissions.sh yet.
if [[ -f "${SITE_ROOT}/config/tree_owner" ]]; then
    TREE_OWNER="${TREE_OWNER_TARGET}"
else
    TREE_OWNER="$(stat -c '%U' "${PUBLIC_HTML}" 2>/dev/null || echo root)"
fi

# The tree owner is whoever owns public_html - root on a node, the developer
# account on the developer box. Binding that global here keeps all the call
# sites below reading as the question they are actually asking.
installer_is_trusted() {
    joinery_file_is_trusted "$1" "${TREE_OWNER}"
}

# Only here, below installer_is_trusted() and below TREE_OWNER. Bash resolves a
# function at CALL time, so calling this above the definition was `command not
# found` — rc 127, which the guard read as "untrusted", and the copy never
# refreshed. A stale copy then stayed stale for good.
refresh_converger_entry
apply_tree_permissions

# --- The host converger's stamp (--when-changed) ------------------------------
# What a run converges is a function of the deployed release, this runner and
# the installers it ships, and which plugins are active. Hash those; when the
# hash matches the last run's and that run is under a day old, there is
# nothing to do and the tick costs a few file reads. The stamp and the record
# of the last run live in cache/, root-owned, readable by the site so the
# admin notice and the health check can say when the converger last ran.
STAMP_FILE="${SITE_ROOT}/cache/host_converger.stamp"
LAST_FILE="${SITE_ROOT}/cache/host_converger.last"
CONVERGE_MAX_AGE=86400

converge_hash() {
    {
        cat "${PUBLIC_HTML}/VERSION" 2>/dev/null
        # Every script this runner executes or sources, and the vhost
        # templates render_vhost.sh applies: a change to any of them is a
        # reason to converge, not something to wait a day for.
        cat "${TOOLS_DIR}"/*.sh "${TOOLS_DIR}"/default_*.conf "${TOOLS_DIR}"/vhost_history/*.conf 2>/dev/null
        for m in "${PUBLIC_HTML}"/plugins/*/plugin.json; do [[ -f "${m}" ]] && cat "${m}"; done
        echo "${ACTIVE_PLUGINS_FOR_HASH:-}"
    } | sha256sum | cut -d' ' -f1
}

record_last() {
    mkdir -p "${SITE_ROOT}/cache" 2>/dev/null || true
    printf '%s %s\n' "$(date -u +%s)" "$1" > "${LAST_FILE}.tmp" 2>/dev/null && chmod 644 "${LAST_FILE}.tmp" 2>/dev/null && mv -f "${LAST_FILE}.tmp" "${LAST_FILE}" 2>/dev/null || true
}

# --- Declared PHP extensions (root only) -------------------------------------
# Covers plugins installed after the site image was built, whose
# requires.extensions the image-build resolver never saw. Cheap when nothing
# is missing: dpkg checks only, no apt update.
#
# The check is the package's Status, not whether dpkg has heard of it: `dpkg -s`
# exits 0 for a removed-but-not-purged package whose files are gone, so a
# name-only test silently skips reinstalling an extension that is not there.
pkg_installed() {
    [[ -n "${1:-}" ]] || return 1
    dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q '^install ok installed$'
}

RESOLVER="${PUBLIC_HTML}/utils/list_dependencies.php"
if [[ -f "${RESOLVER}" ]] && [[ "$(id -u)" == "0" ]] && command -v php >/dev/null 2>&1; then
    APT_UPDATED=0
    while read -r SPEC; do
        [[ -n "${SPEC}" ]] || continue
        PRIMARY="${SPEC%%|*}"
        FALLBACK="${SPEC##*|}"
        if pkg_installed "${PRIMARY}" || pkg_installed "${FALLBACK}"; then
            continue
        fi
        if [[ "${APT_UPDATED}" == "0" ]]; then
            apt-get update -qq >/dev/null 2>&1 || true
            APT_UPDATED=1
        fi
        if apt-get install -y "${PRIMARY}" >/dev/null 2>&1 || apt-get install -y "${FALLBACK}" >/dev/null 2>&1; then
            echo "plugin installers: installed declared extension package ${PRIMARY}"
        else
            echo "plugin installers: WARNING - could not install ${PRIMARY} (or ${FALLBACK})" >&2
        fi
    done < <(php "${RESOLVER}" --apt 2>/dev/null || true)
fi

read_active_plugins() {
    php -r '
        $config = file_get_contents($argv[1]);
        $val = function ($key) use ($config) {
            return preg_match("/settings\[.".$key.".\]\s*=\s*.([^\x27\"]*)/", $config, $m) ? $m[1] : "";
        };
        $name = $val("dbname");
        $user = $val("dbusername");
        $pass = $val("dbpassword");
        $host = $val("dbhost") ?: "localhost";
        if ($name === "" || $user === "") { fwrite(STDERR, "no-db-config\n"); exit(3); }
        try {
            $pdo = new PDO("pgsql:host={$host};dbname={$name}", $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $q = $pdo->prepare("SELECT plg_name FROM plg_plugins WHERE plg_status = ?");
            $q->execute(["active"]);
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
                echo $row[0] . "\n";
            }
        } catch (Exception $e) {
            fwrite(STDERR, "db-unreachable\n");
            exit(3);
        }
    ' "${CONFIG_FILE}" 2>/dev/null
}

# --- Converge only when something changed (--when-changed) -------------------
if [[ "${WHEN_CHANGED}" == "1" ]]; then
    ACTIVE_PLUGINS_FOR_HASH="$(read_active_plugins 2>/dev/null || echo "db-unreachable")"
    CONVERGE_NOW_HASH="$(converge_hash)"
    last_hash="$(cat "${STAMP_FILE}" 2>/dev/null || true)"
    last_time="$(cut -d' ' -f1 "${LAST_FILE}" 2>/dev/null || echo 0)"
    now="$(date -u +%s)"
    # A queued root request is its own reason to run, and it must be checked
    # BEFORE the stamp: the hash covers the release, this runner and the
    # installers, and none of those change when somebody presses Upgrade. Behind
    # the stamp a request on a healthy box sat at Queued until the next release
    # or the daily tick — including under the .path unit, which fires this very
    # script the moment a request lands.
    REQUESTS_WAITING=0
    for _req in "${SITE_ROOT}"/cache/root_requests/*.json; do
        [[ -f "${_req}" ]] && { REQUESTS_WAITING=1; break; }
    done

    if [[ "${REQUESTS_WAITING}" == "0" ]] \
       && [[ "${last_hash}" == "${CONVERGE_NOW_HASH}" ]] \
       && (( now - ${last_time:-0} < CONVERGE_MAX_AGE )); then
        # Nothing changed, nothing queued, the last run is fresh: this tick
        # costs a hash and one directory read.
        exit 0
    fi
    if [[ "${REQUESTS_WAITING}" == "1" ]]; then
        CONVERGE_REASON="a root request is queued"
    elif [[ "${last_hash}" == "${CONVERGE_NOW_HASH}" ]]; then
        CONVERGE_REASON="daily"
    else
        CONVERGE_REASON="release or installers changed"
    fi
    echo "host converger: $(date -u '+%Y-%m-%d %H:%M:%S') converging ${SITENAME} (${CONVERGE_REASON})"
    # Written up front so a run that dies mid-way is not retried every tick
    # for a day; the record on exit says what happened.
    printf '%s\n' "${CONVERGE_NOW_HASH}" > "${STAMP_FILE}" 2>/dev/null || true
    record_last "running"
    CONVERGE_OUTCOME="converged"
    trap 'record_last "${CONVERGE_OUTCOME}"' EXIT
fi

# --- Core host installers ----------------------------------------------------
# Core's own installers run before any plugin's, and unconditionally: nothing
# about them is a plugin's business. Each is idempotent and decides for itself
# whether it applies here, the same contract plugin installers work under.
CORE_INSTALLERS="install_agent.sh install_parser_jail.sh install_host_converger.sh render_vhost.sh"

for CORE_INSTALLER in ${CORE_INSTALLERS}; do
    CORE_PATH="${TOOLS_DIR}/${CORE_INSTALLER}"
    if [[ ! -f "${CORE_PATH}" ]]; then
        echo "core installers: ${CORE_INSTALLER} missing - skipping" >&2
        continue
    fi
    if ! installer_is_trusted "${CORE_PATH}"; then
        CONVERGE_OUTCOME="installer-refused"
        continue
    fi
    echo "core installers: running ${CORE_INSTALLER}"
    # Both: the name for an older core installer that only reads argument one,
    # the resolved root for one that can use it. An off-convention site is only
    # correct if this second value survives the call.
    if bash "${CORE_PATH}" "${SITENAME}" "${SITE_ROOT}"; then
        echo "core installers: ${CORE_INSTALLER}: ok"
    else
        echo "core installers: WARNING - ${CORE_INSTALLER} failed" >&2
        CONVERGE_OUTCOME="installer-failed"
    fi
done

# The database is the only persistent signal of which plugins are active:
# after a rebuild /etc carries base defaults, but the database (on the config
# volume) still knows.
#
# Its credentials come out of the site config, read exactly the way
# install_agent.sh reads it, and the connection is PDO rather than psql. This
# script inherits NOTHING. It used to grep out the database name and then run
# `psql -U postgres` on a PGPASSWORD it hoped was in the environment: true under
# the container CMD, false under every other caller, and the failure was silent
# because an authentication error and a site with no active plugins both produce
# no output. PDO also drops the assumption that a psql client is installed at
# all, which on a node whose database is elsewhere it need not be.
#
# The two outcomes are now reported separately. "Could not reach the database"
# and "there is nothing to run" are different facts about a machine, and reading
# the first as the second is how a partial run looks like a clean one.

# Wrapped in a function so that "nothing to run here" returns rather than ends
# the script: the queued root requests below are a separate job, and a site with
# no active plugins is still a site that can have asked for an upgrade.
run_plugin_installers() {
if ! command -v php >/dev/null 2>&1; then
    echo "plugin installers: php-cli not available - skipping" >&2
    CONVERGE_OUTCOME="no-php"
    return 0
fi

if ! ACTIVE_PLUGINS="$(read_active_plugins)"; then
    echo "plugin installers: could not read the site database - skipping" >&2
    CONVERGE_OUTCOME="db-unreachable"
    # Transient: drop the stamp so the next tick tries again rather than
    # waiting a day with the plugin installers unrun.
    [[ "${WHEN_CHANGED}" == "1" ]] && rm -f "${STAMP_FILE}"
    return 0
fi
if [[ -z "${ACTIVE_PLUGINS}" ]]; then
    echo "plugin installers: no active plugins - nothing to run"
    return 0
fi

for PLUGIN in ${ACTIVE_PLUGINS}; do
    MANIFEST="${PUBLIC_HTML}/plugins/${PLUGIN}/plugin.json"
    [[ -f "${MANIFEST}" ]] || continue

    # Extract the host_installer path; php-cli is always present on a
    # Joinery host and is the only reliable JSON parser we can assume.
    INSTALLER_REL="$(php -r '
        $m = json_decode(file_get_contents($argv[1]), true);
        echo isset($m["host_installer"]) && is_string($m["host_installer"]) ? $m["host_installer"] : "";
    ' "${MANIFEST}" 2>/dev/null || true)"
    [[ -n "${INSTALLER_REL}" ]] || continue

    INSTALLER="${PUBLIC_HTML}/plugins/${PLUGIN}/${INSTALLER_REL}"

    # Refuse path escapes (host_installer must stay inside the plugin dir).
    case "$(realpath -m "${INSTALLER}")" in
        "$(realpath -m "${PUBLIC_HTML}/plugins/${PLUGIN}")"/*) : ;;
        *)
            echo "plugin installers: ${PLUGIN}: host_installer escapes plugin directory - refused" >&2
            continue
            ;;
    esac

    if [[ ! -f "${INSTALLER}" ]]; then
        echo "plugin installers: ${PLUGIN}: declared installer missing (${INSTALLER_REL}) - skipping" >&2
        continue
    fi

    if ! installer_is_trusted "${INSTALLER}"; then
        CONVERGE_OUTCOME="installer-refused"
        continue
    fi

    echo "plugin installers: ${PLUGIN}: running ${INSTALLER_REL}"
    if bash "${INSTALLER}"; then
        echo "plugin installers: ${PLUGIN}: ok"
    else
        echo "plugin installers: WARNING - ${PLUGIN} installer failed; its services may be down." >&2
        CONVERGE_OUTCOME="installer-failed"
    fi
done
}

run_plugin_installers

# --- The certificate summary (root only) -------------------------------------
# certbot records everything needed to say whether renewal is on schedule, in
# a directory the web user cannot read. Written here on every run so the
# admin notice (includes/CertificateNotice.php) and the health panel read a
# stored fact and never probe. The shape is documented in that class.
#
# JOINERY_LETSENCRYPT_DIR and JOINERY_APACHE_SITES_DIR let a test point this
# at a fixture tree; the gate does, with a self-signed certificate.
json_string() {
    # One plain string (a DNS name, an address, a word) as a JSON string.
    local v="${1:-}"
    v="${v//\\/\\\\}"; v="${v//\"/\\\"}"
    printf '"%s"' "${v}"
}
json_string_list() {
    # $@ = plain strings; echoes a JSON array of them.
    local out="" v
    for v in "$@"; do
        [[ -n "${v}" ]] || continue
        out="${out:+${out},}$(json_string "${v}")"
    done
    printf '[%s]' "${out}"
}
resolve_v4() {
    # A records for a name (through CNAMEs), one per line; nothing when it
    # does not resolve. getent rather than dig: present on every box.
    [[ -n "${1:-}" ]] || return 0
    getent ahostsv4 "$1" 2>/dev/null | awk '{print $1}' | sort -u
}
write_certificate_summary() {
    [[ "$(id -u)" == "0" ]] || return 0
    local le="${JOINERY_LETSENCRYPT_DIR:-/etc/letsencrypt}"
    local sites="${JOINERY_APACHE_SITES_DIR:-/etc/apache2/sites-available}"
    local out="${SITE_ROOT}/cache/certificates.json"
    local in_container=false letsencrypt=false timer_active=false
    local site_name=""
    local -a own_a=() apex_a=() www_a=()

    if [[ -f /.dockerenv ]] || grep -q docker /proc/1/cgroup 2>/dev/null; then
        in_container=true
    fi
    [[ -d "${le}" ]] && letsencrypt=true
    if systemctl is-active --quiet certbot.timer 2>/dev/null || [[ -f /etc/cron.d/certbot ]]; then
        timer_active=true
    fi

    if [[ -f "${sites}/${SITENAME}.conf" ]]; then
        site_name="$(grep -m1 -oE '^[[:space:]]*ServerName[[:space:]]+\S+' "${sites}/${SITENAME}.conf" | awk '{print $2}')"
    fi
    mapfile -t own_a < <(hostname -I 2>/dev/null | tr ' ' '\n' | grep -v '^$' | sort -u)
    if [[ -n "${site_name}" ]]; then
        mapfile -t apex_a < <(resolve_v4 "${site_name}")
        mapfile -t www_a < <(resolve_v4 "www.${site_name}")
    fi

    local lineages="" count=0 dir name cert names nb na installer
    for cert in "${le}"/live/*/cert.pem; do
        [[ -f "${cert}" ]] || continue
        dir="$(dirname "${cert}")"; name="$(basename "${dir}")"
        names="$(openssl x509 -in "${cert}" -noout -ext subjectAltName 2>/dev/null \
            | tr ',' '\n' | sed -n 's/.*DNS:[[:space:]]*//p' | sed 's/[[:space:]]*$//')"
        [[ -n "${names}" ]] || names="$(openssl x509 -in "${cert}" -noout -subject 2>/dev/null | sed -n 's/.*CN[[:space:]]*=[[:space:]]*//p')"
        nb="$(date -u -d "$(openssl x509 -in "${cert}" -noout -startdate 2>/dev/null | cut -d= -f2)" +%s 2>/dev/null || echo 0)"
        na="$(date -u -d "$(openssl x509 -in "${cert}" -noout -enddate 2>/dev/null | cut -d= -f2)" +%s 2>/dev/null || echo 0)"
        installer="$(sed -n 's/^[[:space:]]*installer[[:space:]]*=[[:space:]]*//p' "${le}/renewal/${name}.conf" 2>/dev/null | head -1)"
        local -a names_a=()
        mapfile -t names_a <<< "${names}"
        lineages="${lineages:+${lineages},}$(printf '{"name":%s,"names":%s,"not_before":%s,"not_after":%s,"renewal_installer":%s}' \
            "$(json_string "${name}")" "$(json_string_list "${names_a[@]}")" "${nb:-0}" "${na:-0}" "$(json_string "${installer:-}")")"
        count=$((count + 1))
    done

    mkdir -p "${SITE_ROOT}/cache" 2>/dev/null || true
    printf '{"written":%s,"in_container":%s,"letsencrypt":%s,"timer_active":%s,"site":{"name":%s,"own_addresses":%s,"apex_addresses":%s,"www_addresses":%s},"lineages":[%s]}\n' \
        "$(date -u +%s)" "${in_container}" "${letsencrypt}" "${timer_active}" \
        "$(json_string "${site_name}")" "$(json_string_list "${own_a[@]}")" \
        "$(json_string_list "${apex_a[@]}")" "$(json_string_list "${www_a[@]}")" "${lineages}" \
        > "${out}.tmp" 2>/dev/null || { rm -f "${out}.tmp"; return 0; }
    # Readable by the PHP pool, which is what reads it; nobody else needs to.
    chown www-data:www-data "${out}.tmp" 2>/dev/null || true
    chmod 640 "${out}.tmp" 2>/dev/null || true
    mv -f "${out}.tmp" "${out}" 2>/dev/null || true
    echo "certificates: summary written to cache/certificates.json (${count} lineage(s))"
}
write_certificate_summary

# --- Carry out queued root requests ------------------------------------------
# The PHP pool cannot write the code tree, so the operator actions that used to
# do so from inside a web request - the upgrade, plugin and theme installs, the
# agent-files and docs editors - are queued as small JSON files and carried out
# here (specs/read_only_tree.md).
#
# What crosses is a NAME. `kind` is matched against a fixed list below; the
# arguments stay in the file and are read by the PHP that acts on them, so
# nothing a web request wrote ever becomes a shell word.
#
# One at a time, oldest first: these are installs and upgrades, and two of them
# at once on one machine is not a thing anybody wants to debug.
# The exit code recorded against a request no run ever finished. 75 is
# EX_TEMPFAIL: it is not an exit code any handler produces, and it is a number,
# which is what the page reads.
REQUEST_ABANDONED_CODE=75

run_root_requests() {
    local queue="${SITE_ROOT}/cache/root_requests"
    local logs="${SITE_ROOT}/logs/root_requests"
    [[ -d "${queue}" ]] || return 0
    [[ "$(id -u)" == "0" ]] || return 0

    mkdir -p "${queue}/running" "${queue}/done" "${queue}/failed" "${logs}" 2>/dev/null || true

    # One runner at a time, kernel-held. Under systemd the service is a oneshot
    # and the timer will not overlap it, but the cron form has no such promise —
    # and an upgrade takes minutes. Two ticks inside one upgrade meant the second
    # declared the first's live request abandoned while it was still running.
    #
    # A fixed descriptor, and NO redirection on the exec itself: `exec 9>f
    # 2>/dev/null` applies that 2>/dev/null to the whole shell, permanently, and
    # every later message on stderr disappears. 9 rather than a {var} allocation
    # because descriptors at 10 and above are inherited by PHP subprocesses here
    # and have killed a child before.
    exec 9>"${queue}/.runner.lock" || return 0
    if ! flock -n 9; then
        echo "root request: another run holds the queue - leaving it to that one"
        return 0
    fi

    # A request left in running/ by a killed run. Retrying it blindly could
    # repeat a half-finished install, so it is reported and set aside instead.
    #
    # Aged by the mtime of the file IN running/, which is set when it is moved
    # there — mv preserves the original mtime, which is submission time, so
    # ageing on that made a request that waited an hour before starting look
    # abandoned the moment it began.
    local now stamp
    now="$(date -u +%s)"
    for stale in "${queue}"/running/*.json; do
        [[ -f "${stale}" ]] || continue
        stamp="$(stat -c '%Y' "${stale}" 2>/dev/null || echo "${now}")"
        if (( now - stamp > 3600 )); then
            echo "root request: $(basename "${stale}" .json) abandoned (a run was killed while it held it)" >&2
            # A number, because status() reads this as an exit code: the word
            # `abandoned` cast to 0 and the page said "Failed - exit 0".
            printf '%s\n' "${REQUEST_ABANDONED_CODE}" > "${stale%.json}.exit" 2>/dev/null || true
            mv -f "${stale}" "${queue}/failed/" 2>/dev/null || true
            mv -f "${stale%.json}.exit" "${queue}/failed/" 2>/dev/null || true
        fi
    done

    # Carried-out requests and their transcripts are kept for thirty days and
    # then removed. They are the record an operator reads to find out what the
    # Upgrade button actually did, so they are not deleted on the way out - but
    # a site that upgrades weekly and saves a doc daily accumulates them
    # forever otherwise, in a directory every queue scan walks.
    find "${queue}/done" "${queue}/failed" -maxdepth 1 -type f -mtime +30 -delete 2>/dev/null || true
    find "${logs}" -maxdepth 1 -type f -name '*.log' -mtime +30 -delete 2>/dev/null || true

    local req id kind rc
    for req in $(ls -1 "${queue}"/*.json 2>/dev/null | sort); do
        [[ -f "${req}" ]] || continue
        id="$(basename "${req}" .json)"

        # The id shape is fixed by RootRequest::submit. Anything else in this
        # directory was not put there by the platform.
        if [[ ! "${id}" =~ ^[0-9]{9,12}-[0-9a-f]{8}$ ]]; then
            echo "root request: ignoring ${id} (not a request id)" >&2
            continue
        fi

        kind="$(php -r '
            $r = json_decode(file_get_contents($argv[1]), true);
            echo is_array($r) && isset($r["kind"]) && is_string($r["kind"]) ? $r["kind"] : "";
        ' "${req}" 2>/dev/null || true)"

        case "${kind}" in
            # The same six as RootRequest::KINDS, and no more. There is
            # deliberately no kind that installs an uploaded package: the queue
            # is www-data-writable, so such a request would prove only that
            # something running as the web user wrote it, and root would move a
            # staged directory into the tree on the strength of it.
            upgrade|install_plugin|reconcile_composer|\
            write_agent_files|save_doc|set_receives_upgrades) : ;;
            *)
                echo "root request: ${id} names no known kind - refused" >&2
                printf '2\n' > "${queue}/failed/${id}.exit" 2>/dev/null || true
                mv -f "${req}" "${queue}/failed/" 2>/dev/null || true
                continue
                ;;
        esac

        echo "root request: ${id} (${kind})"
        mv -f "${req}" "${queue}/running/${id}.json" || continue
        # mv keeps the submission mtime; the stale sweep above ages by when a
        # request STARTED, so stamp it now.
        touch "${queue}/running/${id}.json" 2>/dev/null || true

        # The transcript is what the admin page polls, so it is written as the
        # run goes rather than collected at the end.
        : > "${logs}/${id}.log"
        chmod 640 "${logs}/${id}.log" 2>/dev/null || true
        chown root:www-data "${logs}/${id}.log" 2>/dev/null || true

        php "${PUBLIC_HTML}/utils/root_request.php" "${kind}" "--request=${id}" \
            >> "${logs}/${id}.log" 2>&1
        rc=$?

        printf '%s\n' "${rc}" > "${queue}/running/${id}.exit" 2>/dev/null || true
        if (( rc == 0 )); then
            mv -f "${queue}/running/${id}.json" "${queue}/done/" 2>/dev/null || true
            mv -f "${queue}/running/${id}.exit" "${queue}/done/" 2>/dev/null || true
            echo "root request: ${id} done"
        else
            mv -f "${queue}/running/${id}.json" "${queue}/failed/" 2>/dev/null || true
            mv -f "${queue}/running/${id}.exit" "${queue}/failed/" 2>/dev/null || true
            echo "root request: ${id} failed (exit ${rc})" >&2
            CONVERGE_OUTCOME="request-failed"
        fi
    done
}

run_root_requests

exit 0
