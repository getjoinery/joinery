#!/usr/bin/env bash
#
# suspended_page.sh - show the plain "This site is suspended" page for one of
# this Docker host's sites in place of the site, or take it down again, and say
# what the host's proxy now answers, as ONE JSON object on stdout.
#
# Version: 1.0 - the suspended_page operate word (specs/multi_tenant_docker_hosts.md
#                WP8). Suspending a site on a shared host stops its container
#                (hold_container) and shows this page for its name, so whoever
#                reported it sees it was acted on. The agent runs this file with
#                two argv elements it has already validated: show|clear, and the
#                site name.
#
# THE CONTRACT, which tests/integration/proxy_default_site_gate.sh pins:
#
#   - The site's proxy vhost (/etc/apache2/sites-available/{site}.conf,
#     enabled) must carry the suspended switch, the <IfFile> on the mark that
#     default_proxy_vhost.conf 1.05 renders. A site without one is refused: a
#     mark it does not read would report a page that is not shown.
#   - show writes the mark /etc/joinery/sites/{site}/suspended; clear removes
#     it. Apache reads the mark when it loads its configuration, so the
#     configuration is tested and Apache reloaded. A configuration Apache
#     refuses puts the mark back as it was and reloads nothing.
#   - What the proxy answers is then asked over http on this host, for the
#     site's own name: "suspended" when it is the suspended page, "site" when
#     it is anything else, "none" when nothing answered.
#   - It writes the mark and reloads Apache, and only that: the container, its
#     volumes and the vhost file are not touched.
#   - Every key is ALWAYS present; the exit code is 0 whenever the object was
#     printed. Only compiled facts are printed, reduced to a safe character set.
#
# Runs on: a Docker host.

set -u
export LC_ALL=C

ETC="${SUSPENDED_PAGE_ETC:-/etc}"
PROXY_URL="${SUSPENDED_PAGE_PROXY:-http://127.0.0.1}"
MARKER="This site is suspended"

json_safe() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '"%s"' "${s:0:64}"
}
refuse() {
    printf 'suspended_page: %s\n' "$1" >&2
    exit 2
}

ACTION="${1:-}"
NAME="${2:-}"
[[ "$ACTION" == "show" || "$ACTION" == "clear" ]] || refuse "the action is show or clear"
[[ "$NAME" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || refuse "${NAME:0:64} is not a site this host serves"
command -v apache2ctl >/dev/null 2>&1 || refuse "this host has no Apache, so no site is served through it"

CONF="${ETC}/apache2/sites-available/${NAME}.conf"
[[ -f "$CONF" && -e "${ETC}/apache2/sites-enabled/${NAME}.conf" ]] \
    || refuse "${NAME} has no enabled site on this host's proxy"
grep -q 'ProxyPass' "$CONF" || refuse "${NAME} is not a container site behind this host's proxy"
MARK="${ETC}/joinery/sites/${NAME}/suspended"
grep -qF "<IfFile ${MARK}>" "$CONF" \
    || refuse "${NAME}'s proxy vhost has no suspended page (it predates default_proxy_vhost.conf 1.05); render it again from the template first"
DOMAIN="$(grep -m1 -oE '^[[:space:]]*ServerName[[:space:]]+[^[:space:]]+' "$CONF" | awk '{print $2}')"
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]{1,253}$ ]] || refuse "${NAME}'s proxy vhost names no site"

had_mark=false; [[ -f "$MARK" ]] && had_mark=true

set_mark() {  # true|false
    if [[ "$1" == true ]]; then
        mkdir -p "$(dirname "$MARK")" \
            && printf 'since=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "${MARK}.tmp" && mv -f "${MARK}.tmp" "$MARK"
    else
        rm -f "$MARK"
    fi
}

# What the proxy answers for the site's own name. A reload is graceful: the
# children finish what they are serving first, so the answer is asked again
# for a few seconds before it counts.
page_now() {
    local body code
    body="$(curl -s --max-time 5 -H "Host: ${DOMAIN}" -w '\n%{http_code}' "${PROXY_URL}/" 2>/dev/null)" || { echo none; return; }
    code="${body##*$'\n'}"
    [[ "$code" =~ ^[1-5][0-9][0-9]$ ]] || { echo none; return; }
    if [[ "$code" == 503 && "$body" == *"$MARKER"* ]]; then echo suspended; else echo site; fi
}

want=true; [[ "$ACTION" == "clear" ]] && want=false
expect="site"; [[ "$want" == true ]] && expect="suspended"
DONE=false
REASON=""
if [[ "$had_mark" == "$want" ]] && [[ "$(page_now)" == "$expect" ]]; then
    # Already so, and already served: the mark keeps its date, and nothing is
    # reloaded.
    :
elif [[ "$had_mark" != "$want" ]] && ! set_mark "$want"; then
    REASON="mark_not_written"
elif ! apache2ctl configtest >/dev/null 2>&1; then
    [[ "$had_mark" != "$want" ]] && set_mark "$had_mark"
    REASON="config_refused"
elif ! { apache2ctl graceful >/dev/null 2>&1 || systemctl reload apache2 >/dev/null 2>&1; }; then
    REASON="reload_failed"
fi

PAGE="$(page_now)"
for _ in 1 2 3 4 5; do
    [[ -n "$REASON" || "$PAGE" == "$expect" ]] && break
    sleep 1
    PAGE="$(page_now)"
done
if [[ -z "$REASON" ]]; then
    if [[ "$PAGE" == "$expect" ]]; then DONE=true; else REASON="not_answering"; fi
fi

printf '{'
printf '"site":%s,' "$(json_safe "$NAME")"
printf '"action":%s,' "$(json_safe "$ACTION")"
printf '"done":%s,' "$DONE"
printf '"suspended":%s,' "$([[ -f "$MARK" ]] && echo true || echo false)"
printf '"page":%s,' "$(json_safe "$PAGE")"
printf '"reason":%s' "$(json_safe "$REASON")"
printf '}\n'
exit 0
