# _host_files.sh - the one definition of the host files the platform tunes,
# sourced by install.sh (first install) and host_housekeeping.sh (every
# converge, and the repair of reclaim_managed_file). Functions only; sourcing
# it runs nothing.
#
# Version: 1.4 - host_files_write_release_verify_keys() also writes config/release_statement_keys and
#                config/transparency_log_keys from the bundle's lists, takes release_keys as
#                well as signing_public_key, and creates config/release_log_required once the
#                node holds keys to check the log with (spec release_transparency, D5, D6).
# Version: 1.3 - host_files_heal_renewal_confs(): certbot's renewal configs, healed for every
#                lineage the machine renews through Apache (from render_vhost.sh, which
#                healed only the name its vhost carried and never ran on a Docker host's
#                proxy vhosts; specs/fleet_ubuntu_2604_postgres_upgrade.md B23).
# Version: 1.2 - host_files_write_release_verify_keys(), the converger's release-key writer,
#                shared with _site_init.sh so a fresh site's plugin bundle can verify.
# Version: 1.1 - host_files_tune_php_ini() no longer enables pdo_pgsql and pgsql in
#                php.ini. Ubuntu's php-pgsql package loads both from conf.d, so the
#                php.ini lines loaded pgsql twice and pdo_pgsql before PDO itself:
#                two startup warnings on every PHP start, the modules working only
#                because conf.d loaded them again.
# Version: 1.0 - specs/agent_recipes_and_vocabulary.md, "Host files": the files
#                install.sh wrote once move into a re-runnable installer, so
#                install day and repair day run the same code (requirement 5).
#
# Each writer takes the file's path, so a test can point it at a fixture.

# The event MPM, sized for a low-traffic site: PHP work happens in the FPM
# pool, so Apache's threads only shuttle requests and static files.
host_files_write_mpm_event() {
    cat > "$1" << 'MPM'
# event MPM
StartServers             2
MinSpareThreads         10
MaxSpareThreads         25
ThreadsPerChild         25
MaxRequestWorkers       50
MaxConnectionsPerChild  2000
MPM
    chmod 644 "$1"
}

# The systemd journal, capped so logs cannot fill a small disk.
host_files_write_journald_limit() {
    mkdir -p "$(dirname "$1")"
    cat > "$1" << 'JOURNAL'
[Journal]
SystemMaxUse=100M
JOURNAL
    chmod 644 "$1"
}

# The platform's PHP-FPM settings, applied to a php.ini in place: upload and
# post limits, execution time, memory, and UTC (every stored time is UTC;
# display conversion is per user). The PostgreSQL extensions are not enabled
# here: the php-pgsql package loads them from conf.d, and naming them in php.ini
# as well loads pgsql twice and pdo_pgsql before PDO.
host_files_tune_php_ini() {
    local ini="$1"
    sed -i 's/upload_max_filesize = .*/upload_max_filesize = 32M/' "$ini"
    sed -i 's/post_max_size = .*/post_max_size = 32M/' "$ini"
    sed -i 's/max_execution_time = .*/max_execution_time = 300/' "$ini"
    sed -i 's/memory_limit = .*/memory_limit = 128M/' "$ini"
    sed -i 's/;date.timezone =/date.timezone = UTC/' "$ini"
}

# certbot's Apache installer edits the vhosts the platform renders: on every
# renewal ApacheConfigurator._deploy_cert adds `Include /etc/letsencrypt/options-ssl-apache.conf`
# wherever the line is missing, and older installs also got its http->https
# redirect block. The templates state both themselves, so the edit only ever
# made a vhost stop matching its render. The renewal conf is where certbot
# decides to do that (`installer = apache`); with `installer = None` it writes
# the certificate files and runs the hook, which is all a renewal has to do.
#
# $1 = one lineage's renewal conf. Idempotent: a second run changes nothing.
# The first run that changes something keeps a copy beside the file (a name
# not ending in .conf, so certbot never reads it as a lineage). A renew_hook
# already there is the owner's and is left alone.
host_files_heal_renewal_conf() {
    local conf="$1" tmp changed=0
    [[ -f "${conf}" ]] || return 0
    tmp="$(mktemp)"
    cp "${conf}" "${tmp}"
    if grep -qE '^[[:space:]]*installer[[:space:]]*=[[:space:]]*apache[[:space:]]*$' "${tmp}"; then
        sed -i -E 's/^([[:space:]]*installer[[:space:]]*=[[:space:]]*)apache[[:space:]]*$/\1None/' "${tmp}"
        changed=1
    fi
    if grep -q '^\[renewalparams\]' "${tmp}" \
       && ! grep -qE '^[[:space:]]*renew_hook[[:space:]]*=' "${tmp}"; then
        sed -i '/^\[renewalparams\]/a renew_hook = systemctl reload apache2' "${tmp}"
        changed=1
    fi
    if [[ "${changed}" == 1 ]]; then
        if ! ls "${conf}".before-render.* >/dev/null 2>&1; then
            cp -p "${conf}" "${conf}.before-render.$(date -u +%Y%m%d%H%M%S)" 2>/dev/null || true
        fi
        cat "${tmp}" > "${conf}"
        echo "renewal: ${conf} says installer = None with a reload hook; certbot will not edit a vhost again"
    fi
    rm -f "${tmp}"
    return 0
}

# Every lineage on the machine that renews through Apache (its installer or its
# authenticator is apache). The rule belongs to the machine, not to one site: a
# lineage whose name no site's vhost file or DOMAIN_NAME carries
# (demo.getjoinery.com, served for joinerydemo) was never reached while each
# site healed only its own, and a Docker host's proxy vhosts are rendered by
# install.sh alone. A lineage issued another way (webroot, DNS) is not touched.
host_files_heal_renewal_confs() {  # $1 the letsencrypt directory
    local conf
    for conf in "${1:-/etc/letsencrypt}"/renewal/*.conf; do
        [[ -f "${conf}" ]] || continue
        grep -qE '^[[:space:]]*(installer|authenticator)[[:space:]]*=[[:space:]]*apache[[:space:]]*$' "${conf}" || continue
        host_files_heal_renewal_conf "${conf}"
    done
    return 0
}

# The release verification keys (specs/package_signing.md WP1) and the release
# log's keys (spec release_transparency, D5, D6). Root puts code on a box only
# after PackageSignature has matched the package against the keys in
# config/release_verify_keys and, where the log is required, found its release
# statement in the public log by the keys in config/release_statement_keys and
# config/transparency_log_keys. All three come from the agent bundle's manifest
# in the tree: root-owned, installed by root, derived from the repository's
# release_keys/ and nothing else (release_keys, statement_keys, log_keys; the
# release file also takes signing_public_key, the key the agent binary was
# built with). Each is written when absent and appended when the bundle
# carries a line the file lacks, never replaced - a key from an earlier bundle
# survives, so what was signed or logged under it keeps verifying. root:root
# 0644 so the pool can read them and nobody but root can write them
# (PackageSignature refuses a key file anyone else could have written).
#
# config/release_log_required (D6) is created here once the node holds a
# statement key and a log key, never before: a node required to check the log
# with no key to check it by would refuse every release. It is never removed,
# and not created when config/release_log_optional exists - a fork owner's
# opt-out, which nothing writes. Monotone: a release can only tighten.
#
# The converger calls this on every tick; _site_init.sh calls it before a
# fresh site installs its plugin bundle, whose packages are verified against
# these keys.
_host_files_append_key_lines() {  # $1 file, $2 label, then the lines
    local file="$1" label="$2" line added=0
    shift 2
    for line in "$@"; do
        [[ -n "${line}" ]] || continue
        if [[ -f "${file}" ]] && grep -qxF "${line}" "${file}" 2>/dev/null; then
            continue
        fi
        if printf '%s\n' "${line}" >> "${file}" 2>/dev/null; then
            added=$((added + 1))
        else
            echo "release key: WARNING - could not write ${file}" >&2
            return 0
        fi
    done
    if [[ -f "${file}" ]]; then
        # Asserted every time, so a sweep that loosened it is undone here
        # rather than at the next converge.
        chown root:root "${file}" 2>/dev/null || true
        chmod 644 "${file}" 2>/dev/null || true
    fi
    if (( added > 0 )); then
        echo "release key: config/$(basename "${file}") carries ${added} new ${label} from the agent bundle"
    fi
    return 0
}

host_files_write_release_verify_keys() {  # $1 the site root
    local site_root="$1"
    [[ "$(id -u)" == "0" ]] || return 0
    local manifest="${site_root}/public_html/agent_dist/manifest.json"
    [[ -f "${manifest}" ]] || return 0
    [[ -d "${site_root}/config" ]] || return 0
    command -v php >/dev/null 2>&1 || return 0
    local lines
    lines="$(php -r '
        $m = json_decode((string)@file_get_contents($argv[1]), true);
        if (!is_array($m)) { exit(0); }
        $release = array();
        foreach (array_merge(array((string)($m["signing_public_key"] ?? "")), is_array($m["release_keys"] ?? null) ? $m["release_keys"] : array()) as $k) {
            $raw = base64_decode(trim((string)$k), true);
            if ($raw !== false && strlen($raw) === 32) { $release[base64_encode($raw)] = true; }
        }
        foreach (array_keys($release) as $k) { echo "R " . $k . "\n"; }
        foreach (is_array($m["statement_keys"] ?? null) ? $m["statement_keys"] : array() as $k) {
            $der = base64_decode(trim((string)$k), true);
            $pem = $der === false ? "" : "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
            $key = $pem === "" ? false : @openssl_pkey_get_public($pem);
            $d = $key === false ? false : openssl_pkey_get_details($key);
            if ($d && ($d["type"] ?? null) === OPENSSL_KEYTYPE_EC && ($d["ec"]["curve_name"] ?? "") === "prime256v1") {
                echo "S " . base64_encode($der) . "\n";
            }
        }
        foreach (is_array($m["log_keys"] ?? null) ? $m["log_keys"] : array() as $p) {
            $origin = (string)($p["origin"] ?? "");
            $der = base64_decode(trim((string)($p["key"] ?? "")), true);
            if (preg_match("/^[a-z0-9.-]+$/", $origin) && $der !== false && strlen($der) === 44) {
                echo "L " . $origin . " " . base64_encode($der) . "\n";
            }
        }
    ' "${manifest}" 2>/dev/null || true)"
    [[ -n "${lines}" ]] || return 0

    local -a release=() statement=() log=()
    local kind rest
    while IFS=' ' read -r kind rest; do
        case "${kind}" in
            R) release+=("${rest}") ;;
            S) statement+=("${rest}") ;;
            L) log+=("${rest}") ;;
        esac
    done <<< "${lines}"

    _host_files_append_key_lines "${site_root}/config/release_verify_keys" "release key(s)" "${release[@]+"${release[@]}"}"
    _host_files_append_key_lines "${site_root}/config/release_statement_keys" "statement key(s)" "${statement[@]+"${statement[@]}"}"
    _host_files_append_key_lines "${site_root}/config/transparency_log_keys" "log key(s)" "${log[@]+"${log[@]}"}"

    local required="${site_root}/config/release_log_required"
    if [[ ! -f "${required}" && ! -f "${site_root}/config/release_log_optional" ]] \
       && [[ -s "${site_root}/config/release_statement_keys" && -s "${site_root}/config/transparency_log_keys" ]]; then
        if : > "${required}" 2>/dev/null; then
            chown root:root "${required}" 2>/dev/null || true
            chmod 644 "${required}" 2>/dev/null || true
            echo "release log: config/release_log_required written - this machine now installs only releases in the public log"
        else
            echo "release log: WARNING - could not write ${required}" >&2
        fi
    elif [[ -f "${required}" ]]; then
        chown root:root "${required}" 2>/dev/null || true
        chmod 644 "${required}" 2>/dev/null || true
    fi
}
