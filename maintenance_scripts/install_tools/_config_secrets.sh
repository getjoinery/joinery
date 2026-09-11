#!/usr/bin/env bash
#
# _config_secrets.sh - the per-site secrets that live in {site}/config, and the
# one definition of how each is minted.
#
# Version: 1.0 - Extracted so the install and the root moments mint identically
#                (specs/read_only_tree.md). config/ belongs to the tree owner
#                now: the PHP pool reads what it needs and creates nothing
#                there, because directory write is enough to unlink
#                Globalvars_site.php and leave a different one in its place, and
#                that file is `require`d PHP. So every first-use mint that used
#                to happen inside a web request happens here, as root, at the
#                host installers' moments - container start, install, and the
#                converger's timer.
#
# Sourced, never run. _site_init.sh sources it to mint at install;
# _plugin_installers_start.sh sources it to mint on a site that predates a key.
#
# Every function is idempotent and refuses to overwrite: a key that already
# exists is the one this site's data was encrypted with, and minting over it
# orphans every secret at rest.

# A fresh 32-byte key, base64. The secret_box_key format.
joinery_generate_secret_box_key() {
    openssl rand -base64 32
}

# Does the site config already carry a secret_box_key?
joinery_config_has_secret_box_key() {
    local config_file="$1"
    [[ -f "${config_file}" ]] || return 1
    grep -Eq "settings\[['\"]secret_box_key['\"]\]\s*=\s*['\"][^'\"]+['\"]" "${config_file}"
}

# Append a secret_box_key to a config file that has none. Root only: the file is
# root:www-data 0640 and the pool must not be able to rewrite the code it boots.
#
# Content after a PHP closing tag would be emitted as page output, so the block
# goes before a trailing `?>` where one exists.
joinery_mint_secret_box_key() {
    local config_file="$1"
    [[ -f "${config_file}" ]] || return 1
    joinery_config_has_secret_box_key "${config_file}" && return 0
    [[ "$(id -u)" == "0" ]] || return 1

    local key tmp
    key="$(joinery_generate_secret_box_key)" || return 1
    tmp="${config_file}.mint.$$"

    if grep -q '?>' "${config_file}"; then
        awk -v key="${key}" '
            /\?>/ && !done {
                print "";
                print "// Key for SecretBox (secrets at rest). Minted for a site installed";
                print "// before this key existed. 32 random bytes, base64-encoded.";
                print "$this->settings[\"secret_box_key\"] = \"" key "\";";
                print "";
                done = 1;
            }
            { print }
        ' "${config_file}" > "${tmp}" || { rm -f "${tmp}"; return 1; }
    else
        cp "${config_file}" "${tmp}" || return 1
        {
            echo ""
            echo "// Key for SecretBox (secrets at rest). Minted for a site installed"
            echo "// before this key existed. 32 random bytes, base64-encoded."
            echo "\$this->settings[\"secret_box_key\"] = \"${key}\";"
        } >> "${tmp}" || { rm -f "${tmp}"; return 1; }
    fi

    # Parse before installing: a config file that does not parse takes the whole
    # site down, and this one is minted unattended on a timer.
    if command -v php >/dev/null 2>&1 && ! php -l "${tmp}" >/dev/null 2>&1; then
        rm -f "${tmp}"
        return 1
    fi

    chown root:www-data "${tmp}" 2>/dev/null || true
    chmod 640 "${tmp}"
    mv -f "${tmp}" "${config_file}"
}

# This site's disposable backup keypair. Minted on first use by whatever ran a
# backup, which was the web user; it lands in config/, which the pool no longer
# writes. sodium via php -r, not a platform class: this must not boot settings.
joinery_mint_backup_site_key() {
    local config_dir="$1"
    local key_file="${config_dir}/backup_site_key"
    [[ -d "${config_dir}" ]] || return 1
    [[ -e "${key_file}" ]] && return 0
    [[ "$(id -u)" == "0" ]] || return 1
    command -v php >/dev/null 2>&1 || return 1

    local tmp="${key_file}.mint.$$"
    if ! php -r 'echo base64_encode(sodium_crypto_box_keypair());' > "${tmp}" 2>/dev/null \
       || [[ ! -s "${tmp}" ]]; then
        rm -f "${tmp}"
        return 1
    fi
    # 640 www-data:www-data, the mode fix_permissions.sh pins: backups run as
    # the web user on the schedule and as root or the deploy account by hand.
    chown www-data:www-data "${tmp}" 2>/dev/null || true
    chmod 640 "${tmp}"
    mv -f "${tmp}" "${key_file}"
}

# The ledger a restore consults before it loads bytes over live data. Created on
# demand by the backup runner, which is the web user; the directory it needs is
# in config/. 700 www-data:www-data - the agent refuses a ledger anyone else
# could write, because its whole job is vouching.
joinery_mint_backup_ledger_dir() {
    local config_dir="$1"
    local ledger="${config_dir}/backup-ledger"
    [[ -d "${config_dir}" ]] || return 1
    [[ -d "${ledger}" ]] && return 0
    [[ "$(id -u)" == "0" ]] || return 1

    mkdir -p "${ledger}" || return 1
    chown www-data:www-data "${ledger}" 2>/dev/null || true
    chmod 700 "${ledger}"
}

# Everything above, for one site. Each part reports for itself; one failing does
# not stop the others.
joinery_mint_site_secrets() {
    local site_root="$1"
    local config_dir="${site_root}/config"
    local config_file="${config_dir}/Globalvars_site.php"

    if [[ -f "${config_file}" ]] && ! joinery_config_has_secret_box_key "${config_file}"; then
        if joinery_mint_secret_box_key "${config_file}"; then
            echo "config secrets: minted secret_box_key"
        else
            echo "config secrets: WARNING - could not mint secret_box_key; secrets at rest stay unavailable" >&2
        fi
    fi

    if [[ ! -e "${config_dir}/backup_site_key" ]]; then
        if joinery_mint_backup_site_key "${config_dir}"; then
            echo "config secrets: minted backup_site_key"
        else
            echo "config secrets: WARNING - could not mint backup_site_key; backups will refuse to run" >&2
        fi
    fi

    if [[ ! -d "${config_dir}/backup-ledger" ]]; then
        if joinery_mint_backup_ledger_dir "${config_dir}"; then
            echo "config secrets: created backup-ledger"
        else
            echo "config secrets: WARNING - could not create backup-ledger" >&2
        fi
    fi
}
