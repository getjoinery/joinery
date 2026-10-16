#!/usr/bin/env bash
#
# _vhost_install.sh - put a site's vhost in place for a domain after the
# install, as a converge would render it. Sourced by manage_domain.sh (set,
# clear) and reconcile_site.sh (a restore); never run on its own.
#
# Version: 1.0 - B7: a domain change rendered its own vhost (manage_domain.sh's
#                heredoc, virtualhost_update_script.sh), which every later
#                converge read as an operator's edit and stopped keeping current.
#
# Needs _vhost_render.sh (the render itself) and _placeholder_cert.sh beside it.

# shellcheck source=_vhost_render.sh
. "${BASH_SOURCE[0]%/*}/_vhost_render.sh"

# Put a site's vhost in place for a domain: render $1 (the template) for the
# site at $2 (its root folder) and $3 (the domain) over
# /etc/apache2/sites-available/<site>.conf, and enable it. PORT from the
# environment fills the proxy template's port. Used where a site's domain
# changes after the install (manage_domain.sh set/clear, reconcile_site.sh on
# a restore), so the file is the render render_vhost.sh would make.
#
#   - the file it replaces is kept beside it (<site>.conf.before-domain.<time>);
#   - a render Apache cannot parse is refused and the previous file put back
#     (exit 1), since a vhost that does not parse stops every site on the
#     machine at the next reload;
#   - the render is recorded as this site's last
#     (sites-available/.<site>.conf.rendered), the record render_vhost.sh
#     compares the file with. Without it the next converge reads the new
#     domain as an operator's edit and stops keeping the file current;
#   - the placeholder certificate is minted for the domain when it has none,
#     so the :443 host answers from the first reload.
#
# Does not reload Apache: the caller does, once everything is in place.
vhost_install() {
    local template="$1" root="${2%/}" domain="$3" sitename conf state rendered previous=""
    sitename="${root##*/}"
    conf="/etc/apache2/sites-available/${sitename}.conf"
    state="/etc/apache2/sites-available/.${sitename}.conf.rendered"
    [[ -f "${template}" ]] || { echo "vhost: template missing at ${template}" >&2; return 1; }
    rendered="$(mktemp)" || return 1
    vhost_render "${template}" "${root}" "${domain}" > "${rendered}"
    # A render that failed part-way is empty or short, and an empty file parses:
    # copied over the vhost and recorded, it would leave the site unserved with
    # the converger content. Every template names the domain.
    if ! grep -qE "^[[:space:]]*ServerName[[:space:]]+${domain//./\\.}[[:space:]]*$" "${rendered}"; then
        rm -f "${rendered}"
        echo "vhost: the render of ${template##*/} for ${domain} names no ServerName ${domain}; nothing was changed" >&2
        return 1
    fi
    if [[ -f "${conf}" ]]; then
        previous="${conf}.before-domain.$(date -u +%Y%m%d%H%M%S)"
        cp -p "${conf}" "${previous}" || { rm -f "${rendered}"; echo "vhost: could not keep a copy of ${conf}" >&2; return 1; }
    fi
    cp "${rendered}" "${conf}" && chmod 644 "${conf}"
    if [[ -f "${BASH_SOURCE[0]%/*}/_placeholder_cert.sh" ]]; then
        # shellcheck source=_placeholder_cert.sh
        . "${BASH_SOURCE[0]%/*}/_placeholder_cert.sh"
        mint_placeholder_cert "${domain}" || true
    fi
    a2ensite "${sitename}.conf" > /dev/null 2>&1 || true
    local parse_out
    if ! parse_out="$(apache2ctl -t 2>&1)"; then
        echo "vhost: the render for ${domain} does not parse; the previous file is back" >&2
        echo "vhost:   $(printf '%s' "${parse_out}" | grep -viE 'AH00558|Syntax' | head -3 | tr '\n' ' ')" >&2
        if [[ -n "${previous}" ]]; then
            cp -p "${previous}" "${conf}"
        else
            a2dissite "${sitename}.conf" > /dev/null 2>&1 || true
            rm -f "${conf}"
        fi
        rm -f "${rendered}"
        return 1
    fi
    cp "${rendered}" "${state}" 2>/dev/null && chmod 600 "${state}" 2>/dev/null
    rm -f "${rendered}"
    [[ -n "${previous}" ]] && echo "vhost: ${conf} rendered for ${domain} (previous kept at ${previous##*/})" \
        || echo "vhost: ${conf} rendered for ${domain}"
    return 0
}
