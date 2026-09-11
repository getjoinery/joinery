#!/usr/bin/env bash
#
# _placeholder_cert.sh - the certificate a site answers TLS with before it has
# a real one. Sourced by install.sh (write_universal_vhost) and render_vhost.sh
# (every converge); never run on its own.
#
# Version: 1.0 - specs/implemented/tls_and_origin_trust.md WP12 (B10). A fresh box behind
#                an edge can never get its first certificate otherwise: the
#                edge redirects the HTTP-01 challenge to https, and a box with
#                no certificate file has no :443 listener, so the edge's https
#                hop fails 525 in Full mode too and the challenge never
#                arrives. With a self-signed placeholder the handshake
#                completes (Full accepts it), the challenge arrives, the real
#                certificate lands, and the vhost's Define picks it up on the
#                next reload. Nothing trusts the placeholder and nothing is
#                meant to: the :80 -> https redirect stays guarded on the
#                Let's Encrypt path alone, and the retry timer's have_real_cert
#                refuses to count it as done.
#
# Contract: idempotent; root; writes only when neither the Let's Encrypt
# lineage nor the placeholder exists for the name. issue_origin_cert.sh and
# certbot never touch it, so a lineage that is deleted by hand falls back to
# it rather than to no TLS at all.
#
# JOINERY_PLACEHOLDER_ROOT and JOINERY_LETSENCRYPT_DIR let a test point this
# at a temp root.

# $1 = domain. Echoes one line when it minted; silent otherwise. Returns 0
# unless openssl is missing or the write failed.
mint_placeholder_cert() {
    local domain="$1"
    [ -n "${domain}" ] || return 0
    local le_dir="${JOINERY_LETSENCRYPT_DIR:-/etc/letsencrypt}"
    local root="${JOINERY_PLACEHOLDER_ROOT:-/etc/ssl/joinery}"
    local dir="${root}/${domain}"

    [ -f "${le_dir}/live/${domain}/fullchain.pem" ] && return 0
    [ -f "${dir}/fullchain.pem" ] && [ -f "${dir}/privkey.pem" ] && return 0
    command -v openssl >/dev/null 2>&1 || { echo "placeholder: openssl is not installed; ${domain} has no TLS listener until a certificate is issued" >&2; return 1; }

    mkdir -p "${dir}" 2>/dev/null || return 1
    chmod 755 "${root}" "${dir}" 2>/dev/null || true
    # Ten years, EC P-256, CN and SANs for the apex and www: the names the
    # real certificate will carry, so an edge that inspects the placeholder
    # sees the names it expects while refusing the issuer, which is right.
    if ! openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 3650 \
            -subj "/CN=${domain}" -addext "subjectAltName=DNS:${domain},DNS:www.${domain}" \
            -keyout "${dir}/privkey.pem.tmp" -out "${dir}/fullchain.pem.tmp" >/dev/null 2>&1; then
        rm -f "${dir}/privkey.pem.tmp" "${dir}/fullchain.pem.tmp"
        echo "placeholder: openssl could not mint a certificate for ${domain}" >&2
        return 1
    fi
    chmod 600 "${dir}/privkey.pem.tmp" 2>/dev/null || true
    chmod 644 "${dir}/fullchain.pem.tmp" 2>/dev/null || true
    mv -f "${dir}/privkey.pem.tmp" "${dir}/privkey.pem" && mv -f "${dir}/fullchain.pem.tmp" "${dir}/fullchain.pem" || return 1
    echo "placeholder: minted a self-signed certificate for ${domain} and www.${domain} at ${dir} (answers TLS until the real one lands)"
    return 0
}
