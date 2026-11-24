#!/bin/bash
# _remove_refused_packages.sh - remove the PHP extensions the platform has
# decided it does not run (public_html/includes/RefusedDependencies.php:
# ImageMagick, GraphicsMagick), wherever an earlier install left one.
#
# The mirror of _install_declared_dependencies.sh: that script puts on a
# machine what the deployed code declares; this one takes off what the deployed
# code refuses. It runs at the same root moments (install.sh, every container
# start, and the converger's tick through _plugin_installers_start.sh), so a
# node loses the extension within a minute of taking this code, and one where
# it is put back loses it again.
#
# What it removes: the extension's own package (php*-imagick), and nothing
# else. The libraries it pulled in are loaded by nothing once it is gone and
# leave with the machine's own unused-package cleanup
# (Unattended-Upgrade::Remove-Unused-Dependencies, install.sh); a site
# container is replaced without them. The removal is simulated first, and a
# package whose removal would take anything else with it is left alone and
# reported: the list of what apt offers to remove differs from one machine to
# the next, so only the named package is ever trusted to go
# (specs/imagemagick_package_removal.md, owner Q1).
#
# Cost when nothing is installed: one php invocation and one dpkg query per
# refused extension. apt is touched only when a package has to go.
#
# Never fatal: a refused package that cannot be removed is reported and left.
#
# VERSION: 1.1 - reads the resolver only when it exits 0 and only lines it can recognise (refuse-extension NAME):
#                a stray word on its output was a package name to remove as root. A held package is left,
#                with its own line. A warning is said once and again only when it changes, so a state
#                nobody has dealt with does not write a line a minute (reviewer2 B5, B6).
# VERSION: 1.0
#
# Usage: _remove_refused_packages.sh /var/www/html/SITENAME/public_html

set -u

PUBLIC_HTML="${1:-}"
RESOLVER="${PUBLIC_HTML}/utils/list_dependencies.php"

say() { echo "refused-packages: $*"; }

if [ -z "$PUBLIC_HTML" ] || [ ! -d "$PUBLIC_HTML" ]; then
    say "no public_html given - skipping"
    exit 0
fi
if [ "$(id -u)" != "0" ] && [ "${JOINERY_REFUSED_TEST:-}" != "1" ]; then
    say "not running as root - skipping"
    exit 0
fi
[ -f "$RESOLVER" ] || { say "resolver not found at ${RESOLVER} - skipping"; exit 0; }
command -v php >/dev/null 2>&1 || { say "php-cli not available - skipping"; exit 0; }
command -v apt-get >/dev/null 2>&1 || { say "apt-get not available - skipping"; exit 0; }
command -v dpkg-query >/dev/null 2>&1 || { say "dpkg-query not available - skipping"; exit 0; }

# The refused extensions, as lines this script can recognise and nothing else:
# "refuse-extension NAME". The resolver's answer is used only when it exits 0,
# with PHP's own messages sent to stderr: anything it printed in passing must
# never be read as the name of a package to remove. A resolver from before the
# list existed exits non-zero for --refused, and nothing is removed.
if ! ANSWER="$(php -d display_errors=stderr "$RESOLVER" --refused 2>/dev/null)"; then
    exit 0
fi
EXTENSIONS="$(printf '%s\n' "$ANSWER" | sed -n 's/^refuse-extension \([a-z0-9_][a-z0-9_]*\)$/\1/p')"
[ -n "$EXTENSIONS" ] || exit 0

# Warnings are collected and said at the end, once per state: this runs every
# minute on every node, and a warning nobody has acted on yet is one line in
# the log, not one a minute.
STATE_FILE="${JOINERY_REFUSED_STATE:-/var/lib/joinery/refused-packages.warned}"
WARNINGS=""
warn() { WARNINGS="${WARNINGS}WARNING - $*
"; }

APT_LOCK_WAIT=(-o DPkg::Lock::Timeout=300)
PHP_ETC="${JOINERY_REFUSED_PHP_ETC:-/etc/php}"
REMOVED_ANY=0

for ext in $EXTENSIONS; do
    # The name becomes part of a package pattern handed to dpkg and apt as root.
    case "$ext" in
        ''|*[!a-z0-9_]*) warn "'${ext}' is not a plain extension name; skipped"; continue ;;
    esac
    # Every PHP version's package of it: php8.5-imagick, php-imagick, and an
    # older PHP's left behind by an OS upgrade.
    PACKAGES="$(dpkg-query -W -f='${Package} ${Status}\n' "php*-${ext}" 2>/dev/null \
        | awk '$2 ~ /^(install|hold)$/ && $4 != "not-installed" && $4 != "config-files" {print $1 ":" $2}')"
    for entry in $PACKAGES; do
        pkg="${entry%%:*}"
        want="${entry##*:}"
        case "$pkg" in
            php-"${ext}"|php[0-9]*-"${ext}") ;;
            *) continue ;;
        esac
        # Someone held it on purpose, and apt will not remove a held package.
        if [ "$want" = "hold" ]; then
            warn "${pkg} is held (apt-mark hold); left in place. The platform does not run ${ext}: apt-mark unhold ${pkg} lets it go"
            continue
        fi
        # What would go with it. Only this extension's own packages may.
        PLAN="$(apt-get -s remove --purge "$pkg" 2>/dev/null | awk '$1 == "Purg" || $1 == "Remv" {print $2}')"
        if [ -z "$PLAN" ]; then
            warn "could not work out what removing ${pkg} would remove; left in place"
            continue
        fi
        OTHER=""
        for name in $PLAN; do
            case "$name" in
                php-"${ext}"|php[0-9]*-"${ext}") ;;
                *) OTHER="${OTHER} ${name}" ;;
            esac
        done
        if [ -n "$OTHER" ]; then
            warn "removing ${pkg} would also remove:${OTHER}; left in place for a person to decide"
            continue
        fi
        if out="$(apt-get "${APT_LOCK_WAIT[@]}" remove --purge -y "$pkg" 2>&1)"; then
            say "removed ${pkg} (the platform does not run ${ext})"
            REMOVED_ANY=1
        else
            warn "could not remove ${pkg}: $(printf '%s\n' "$out" | grep -v '^\s*$' | tail -1); tried again at the next root moment"
        fi
    done
    # Proof by the files PHP reads, for the web PHP and the command-line PHP
    # alike: php -m would answer for the command line only.
    LEFT="$(ls "${PHP_ETC}"/*/fpm/conf.d/*-"${ext}".ini "${PHP_ETC}"/*/cli/conf.d/*-"${ext}".ini 2>/dev/null | tr '\n' ' ')"
    [ -z "$LEFT" ] || warn "${ext} is still enabled: ${LEFT}"
done

# A running PHP-FPM keeps an extension it loaded at start. Under systemd,
# try-restart, so a version installed but stopped stays stopped; in a site
# container the supervisor runs PHP-FPM and USR2 to its master is its own
# graceful reload. At a container's start PHP-FPM is not running yet, and
# neither branch finds anything to do.
if [ "$REMOVED_ANY" = "1" ] && [ "${JOINERY_REFUSED_TEST:-}" != "1" ]; then
    for dir in "${PHP_ETC}"/*/fpm; do
        [ -d "$dir" ] || continue
        ver="$(basename "$(dirname "$dir")")"
        if [ -d /run/systemd/system ]; then
            systemctl try-restart "php${ver}-fpm" >/dev/null 2>&1 \
                || warn "php${ver}-fpm could not be restarted; it drops the extension at its next start"
        elif [ -s "/run/php/php${ver}-fpm.pid" ]; then
            kill -USR2 "$(cat "/run/php/php${ver}-fpm.pid")" 2>/dev/null \
                || warn "php${ver}-fpm could not be reloaded; it drops the extension at its next start"
        fi
    done
fi

# Say the warnings when they are new or have changed; stay quiet while they are
# the ones already said; forget them when there are none.
if [ -n "$WARNINGS" ]; then
    if [ "$(cat "$STATE_FILE" 2>/dev/null)" != "$(printf '%s' "$WARNINGS")" ]; then
        printf '%s' "$WARNINGS" | while IFS= read -r line; do [ -n "$line" ] && say "$line"; done
        mkdir -p "$(dirname "$STATE_FILE")" 2>/dev/null
        printf '%s' "$WARNINGS" > "$STATE_FILE" 2>/dev/null || true
    fi
else
    rm -f "$STATE_FILE" 2>/dev/null
fi

exit 0
