#!/bin/bash
# @joinery-test
# name: proxy_default_site
# tier: safe
# env: any
# needs: []
# timeout: 90
# covers: [maintenance_scripts/install_tools/proxy_default_site.sh, maintenance_scripts/sysadmin_tools/suspended_page.sh, maintenance_scripts/install_tools/default_proxy_vhost.conf, maintenance_scripts/install_tools/host_housekeeping.sh, maintenance_scripts/install_tools/install.sh]
#
# What a multi-tenant Docker host's proxy answers for a name that is not one
# of its sites, and for a site that is suspended
# (specs/multi_tenant_docker_hosts.md WP8). A real Apache runs on spare ports
# here, with two sites rendered from default_proxy_vhost.conf in front of two
# small web servers, so every answer below is Apache's own:
#
#   - proxy_default_site.sh install turns Ubuntu's default site off, puts the
#     default site first, and fails unless Apache then names it the default on
#     both ports; an unknown name, a bare address and an unknown TLS name all
#     get the plain page and its certificate, never a site's. A configuration
#     Apache refuses is taken back out.
#   - The default site stays the default after the host is installed: host
#     housekeeping installs it again on every run, which turns a returned
#     000-default back off, and install.sh refuses a site whose name would
#     load ahead of it.
#   - suspended_page.sh show puts the suspended page on one site's name, on
#     both ports, and on that site alone; clear takes it off; a site whose
#     vhost has no switch, a site that is not enabled, and any other input are
#     refused; a configuration Apache refuses puts the mark back.
#
# apt-get, a2enmod and systemctl are stubs; apache2ctl runs the real Apache
# against this gate's configuration.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PDS="$ROOT/maintenance_scripts/install_tools/proxy_default_site.sh"
SUS="$ROOT/maintenance_scripts/sysadmin_tools/suspended_page.sh"
TPL="$ROOT/maintenance_scripts/install_tools/default_proxy_vhost.conf"
T=$(mktemp -d)
PIDS=()
cleanup() {
    [ -f "$T/logs/pid" ] && /usr/sbin/apache2 -f "$T/main.conf" -k stop >/dev/null 2>&1
    for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done
    rm -rf "${T:?}"
}
trap cleanup EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
jv() {
    php -r '
        $o = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($o)) { echo "NOTJSON"; exit; }
        if ($argv[2] === "") { echo implode(",", array_keys($o)); exit; }
        $v = $o[$argv[2]] ?? "ABSENT";
        echo is_bool($v) ? ($v ? "true" : "false") : $v;
    ' "$1" "$2"
}

echo "=== the scripts parse ==="
chk "proxy_default_site.sh: bash -n" "$(bash -n "$PDS" 2>&1; echo $?)" "0"
chk "suspended_page.sh: bash -n" "$(bash -n "$SUS" 2>&1; echo $?)" "0"

MODS=""
for m in mpm_event authz_core ssl rewrite headers proxy proxy_http alias dir mime socache_shmcb; do
    MODS+="LoadModule ${m}_module /usr/lib/apache2/modules/mod_${m}.so"$'\n'
done
if [ ! -x /usr/sbin/apache2 ] || ! command -v python3 >/dev/null || ! command -v openssl >/dev/null \
   || ! command -v curl >/dev/null || [ ! -f /usr/lib/apache2/modules/mod_proxy_http.so ]; then
    echo "  FAIL: this gate needs Apache with mod_ssl, mod_rewrite and mod_proxy_http, python3, openssl and curl"
    echo "proxy_default_site gate: $passed passed, 1 failed"
    exit 1
fi

free_port() { python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])'; }
P80=$(free_port); P443=$(free_port); B1=$(free_port); B2=$(free_port)
R="$T/root"
mkdir -p "$T/logs" "$T/bin" "$T/le" "$R/etc/apache2/sites-available" "$R/etc/apache2/sites-enabled" "$T/ba" "$T/bb"
echo "SITE A" > "$T/ba/index.html"; echo "SITE B" > "$T/bb/index.html"
(cd "$T/ba" && exec python3 -m http.server "$B1" --bind 127.0.0.1 >/dev/null 2>&1) & PIDS+=($!)
(cd "$T/bb" && exec python3 -m http.server "$B2" --bind 127.0.0.1 >/dev/null 2>&1) & PIDS+=($!)

cat > "$T/main.conf" <<EOF
ServerRoot "$T"
ServerName localhost
PidFile $T/logs/pid
ErrorLog $T/logs/error.log
Mutex file:$T/logs
${MODS}Listen 127.0.0.1:$P80
Listen 127.0.0.1:$P443
TypesConfig /etc/mime.types
SSLSessionCache shmcb:$T/logs/scache(512000)
IncludeOptional $R/etc/apache2/sites-enabled/*.conf
EOF

# Ubuntu's own default site, enabled, as a fresh Apache package leaves it.
cat > "$R/etc/apache2/sites-available/000-default.conf" <<EOF
<VirtualHost *:$P80>
    DocumentRoot $T/ba
</VirtualHost>
EOF
ln -s ../sites-available/000-default.conf "$R/etc/apache2/sites-enabled/000-default.conf"

# A site the way install.sh renders one, with the gate's paths and ports.
render_site() {  # SITE DOMAIN BACKEND_PORT
    sed -e "s|{{DOMAIN_NAME}}|$2|g" -e "s|{{SITE_NAME}}|$1|g" -e "s|{{PORT}}|$3|g" \
        -e "s|/etc/joinery/sites|$R/etc/joinery/sites|g" -e "s|/etc/ssl/joinery|$T/ph|g" -e "s|/etc/letsencrypt|$T/le|g" \
        -e "s|\*:80>|*:$P80>|" -e "s|\*:443>|*:$P443>|" "$TPL" > "$R/etc/apache2/sites-available/$1.conf"
    ln -sfn "../sites-available/$1.conf" "$R/etc/apache2/sites-enabled/$1.conf"
    JOINERY_PLACEHOLDER_ROOT="$T/ph" JOINERY_LETSENCRYPT_DIR="$T/le" \
        bash -c ". '$ROOT/maintenance_scripts/install_tools/_placeholder_cert.sh'; mint_placeholder_cert '$2'" >/dev/null
}
render_site sitea a.test "$B1"
render_site siteb b.test "$B2"

cat > "$T/bin/apache2ctl" <<STUB
#!/bin/bash
echo "apache2ctl \$*" >> "$T/calls"
case "\$1" in
    configtest) [ -n "\${STUB_CONFIGTEST_RC:-}" ] && exit "\$STUB_CONFIGTEST_RC"
                exec /usr/sbin/apache2 -t -f "$T/main.conf" ;;
    graceful)   exec /usr/sbin/apache2 -k graceful -f "$T/main.conf" ;;
    -S)         exec /usr/sbin/apache2 -S -f "$T/main.conf" ;;
esac
STUB
cat > "$T/bin/systemctl" <<STUB
#!/bin/bash
echo "systemctl \$*" >> "$T/calls"
[ "\$1" = reload-or-restart ] && { [ -f "$T/logs/pid" ] && exec /usr/sbin/apache2 -k graceful -f "$T/main.conf"; exec /usr/sbin/apache2 -k start -f "$T/main.conf"; }
exit 0
STUB
printf '#!/bin/bash\necho "a2enmod $*" >> "%s/calls"\n' "$T" > "$T/bin/a2enmod"
printf '#!/bin/bash\necho "apt-get $*" >> "%s/calls"\n' "$T" > "$T/bin/apt-get"
chmod +x "$T/bin/"*
export PATH="$T/bin:$PATH" JOINERY_PDS_ROOT="$R" JOINERY_PLACEHOLDER_ROOT="$T/ph" JOINERY_LETSENCRYPT_DIR="$T/le" \
       JOINERY_PDS_HTTP_PORT="$P80" JOINERY_PDS_HTTPS_PORT="$P443"
calls() { cat "$T/calls" 2>/dev/null | cut -d' ' -f1-2 | tr '\n' '|'; }

# What the proxy answers: the code, then which page.
ask() {  # NAME [https]
    local url="http://127.0.0.1:$P80/" extra=()
    if [ "${2:-}" = https ]; then
        url="https://$1:$P443/"; extra=(--resolve "$1:$P443:127.0.0.1")
    else
        extra=(-H "Host: $1")
    fi
    [ "$1" = "-" ] && { extra=(); [ "${2:-}" = https ] && url="https://127.0.0.1:$P443/"; }
    local code
    code="$(curl -sk --max-time 5 -o "$T/body" -w '%{http_code}' "${extra[@]}" "$url")"
    printf '%s ' "$code"
    if grep -q 'There is no site at this address' "$T/body"; then echo none
    elif grep -q 'This site is suspended' "$T/body"; then echo suspended
    else grep -oE 'SITE [AB]' "$T/body" | head -1 | tr ' ' '_'; fi
}
cert_of() {  # SNI or -
    local sni=(-servername "$1"); [ "$1" = "-" ] && sni=()
    echo | openssl s_client -connect "127.0.0.1:$P443" "${sni[@]}" 2>/dev/null | openssl x509 -noout -subject 2>/dev/null | sed 's/.*CN *= *//'
}

echo "=== before: Apache hands an unknown name to whichever site loads first ==="
/usr/sbin/apache2 -k start -f "$T/main.conf" 2>"$T/start.err"; sleep 1
chk "Apache started on the gate's ports" "$([ -f "$T/logs/pid" ] && echo up || cat "$T/start.err")" "up"
chk "an unknown TLS name gets a customer's certificate" "$(cert_of evil.example)" "a.test"

echo "=== install ==="
: > "$T/calls"
out="$(bash "$PDS" install 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "Apache's modules, a configuration test, one reload" "$(calls)" "a2enmod ssl|apache2ctl configtest|systemctl reload-or-restart|apache2ctl -S|"
chk "says it answers both ports" "$(printf '%s\n' "$out" | grep -c ': answers$')" "2"
chk "Ubuntu's default site is off" "$([ -e "$R/etc/apache2/sites-enabled/000-default.conf" ] && echo on || echo off)" "off"
chk "the default site is enabled, and sorts first" "$(ls "$R/etc/apache2/sites-enabled" | head -1)" "000-joinery-no-site.conf"
sleep 1
for n in evil.example a.test.evil.example; do
    chk "an unknown name ($n) on http: the plain page, 404" "$(ask "$n")" "404 none"
    chk "an unknown name ($n) over TLS: the plain page" "$(ask "$n" https)" "404 none"
    chk "and its certificate names no customer" "$(cert_of "$n")" "no-site.invalid"
done
chk "an unknown name, any path, any method" "$(curl -s -o "$T/body" -w '%{http_code}' -X POST -H 'Host: evil.example' "http://127.0.0.1:$P80/wp-login.php?x=1"):$(grep -c 'There is no site at this address' "$T/body")" "404:1"
chk "the bare address on http" "$(ask -)" "404 none"
chk "the bare address over TLS" "$(ask - https)" "404 none"
chk "TLS with no name at all" "$(cert_of -)" "no-site.invalid"
chk "a.test still answers as itself" "$(ask a.test):$(ask a.test https)" "200 SITE_A:200 SITE_A"
chk "b.test still answers as itself" "$(ask b.test):$(ask b.test https)" "200 SITE_B:200 SITE_B"
chk "www.b.test over TLS: b's certificate, redirected to the apex" "$(cert_of www.b.test):$(ask www.b.test https)" "b.test:308 "

echo "=== install again: nothing changes, nothing reloads ==="
: > "$T/calls"
out="$(bash "$PDS" install 2>&1)"; rc=$?
chk "exit 0, unchanged, no reload" "$rc:$(calls)" "0:a2enmod ssl|apache2ctl -S|"
chk "check: exit 0" "$(bash "$PDS" check >/dev/null; echo $?)" "0"

echo "=== a site that sorts ahead of the default site is caught ==="
render_site 000-a ahead.test "$B1"
out="$(bash "$PDS" install 2>&1)"; rc=$?
chk "install fails" "$rc" "1"
chk "and names the site that answers instead" "$(printf '%s\n' "$out" | grep -c 'NOT the default (ahead.test)')" "2"
out="$(bash "$PDS" check)"; rc=$?
chk "check: exit 1" "$rc" "1"
rm -f "$R/etc/apache2/sites-enabled/000-a.conf" "$R/etc/apache2/sites-available/000-a.conf"

echo "=== a configuration Apache refuses is taken back out ==="
rm -f "$R/etc/apache2/sites-enabled/000-joinery-no-site.conf" "$R/etc/apache2/sites-available/000-joinery-no-site.conf"
ln -s ../sites-available/000-default.conf "$R/etc/apache2/sites-enabled/000-default.conf"
: > "$T/calls"
out="$(STUB_CONFIGTEST_RC=1 bash "$PDS" install 2>&1)"; rc=$?
chk "install fails, saying nothing was changed" "$rc:$(printf '%s\n' "$out" | grep -c 'nothing was changed')" "1:1"
chk "no reload" "$(calls | grep -c systemctl)" "0"
chk "the default site is gone again" "$(ls "$R/etc/apache2/sites-available" "$R/etc/apache2/sites-enabled" | grep -c joinery-no-site)" "0"
chk "and Ubuntu's is back" "$([ -L "$R/etc/apache2/sites-enabled/000-default.conf" ] && echo on)" "on"
bash "$PDS" install >/dev/null 2>&1
sleep 1
chk "installed again over a returned 000-default: it is off, and an unknown name gets the default page" \
    "$([ -e "$R/etc/apache2/sites-enabled/000-default.conf" ] && echo on || echo off):$(ask evil.example)" "off:404 none"

echo "=== the default site stays the default after the host is installed ==="
HK="$ROOT/maintenance_scripts/install_tools/host_housekeeping.sh"
INSTALL="$ROOT/maintenance_scripts/install_tools/install.sh"
HK9="$(awk '/^# --- 9\. /,/^if \[\[ "\$\{FAILED\}" == 1 \]\]; then$/' "$HK")"
chk "housekeeping installs it again, on a host (not a container) whose proxy has it" \
    "$(printf '%s\n' "$HK9" | grep -c 'IN_CONTAINER}" == 0 && "${RUN_SYSTEM}" == 1 && -f "${APACHE_DIR}/sites-available/000-joinery-no-site.conf" ]]')" "1"
chk "by running proxy_default_site.sh install beside itself" "$(printf '%s\n' "$HK9" | grep -c 'bash "${SCRIPT_DIR}/proxy_default_site.sh" install')" "1"
chk "and fails the run when it fails" "$(printf '%s\n' "$HK9" | grep -c 'FAILED=1')" "1"
eval "$(awk '/^site_displaces_default_site\(\) \{$/,/^}$/' "$INSTALL")"
for n in 0 0-x 00 00-a 000-a 000-joinery-no-site; do
    chk "install.sh refuses a site named $n" "$(site_displaces_default_site "$n" && echo refused)" "refused"
done
for n in 000 000a 000-joinery-no-site2 000-k 1 a getjoinery starter7; do
    chk "install.sh takes a site named $n" "$(site_displaces_default_site "$n" && echo refused || echo taken)" "taken"
done
chk "and the refusal is the first thing a Docker site does, on a host with the default site" \
    "$(awk '/^do_site_docker\(\) \{$/,/^}$/' "$INSTALL" | grep -m1 -n 'site_displaces_default_site' | cut -d: -f2- | grep -c '000-joinery-no-site.conf ] && site_displaces_default_site "$SITENAME"')" "1"

echo "=== suspended_page: anything but a served, switched site is refused ==="
sus() { SUSPENDED_PAGE_ETC="$R/etc" SUSPENDED_PAGE_PROXY="http://127.0.0.1:$P80" bash "$SUS" "$@"; }
# sitec: rendered from the template before 1.05, with no switch.
render_site sitec c.test "$B2"
sed -i '/suspended>$/,/<\/IfFile>/d' "$R/etc/apache2/sites-available/sitec.conf"
render_site sited d.test "$B1"; rm -f "$R/etc/apache2/sites-enabled/sited.conf"
for bad in "show sitec" "show sited" "show sitez" "show 000-joinery-no-site" "show Sitea" "show sitea;reboot" "show ../x" \
           "show" "hide sitea" "" "suspend sitea"; do
    : > "$T/calls"
    # shellcheck disable=SC2086
    out="$(sus $bad 2>"$T/err")"; rc=$?
    chk "refused: '$bad' (exit 2, no object, said why)" "$rc:$(printf '%s' "$out" | wc -c):$(grep -c '^suspended_page: ' "$T/err")" "2:0:1"
    chk "refused: '$bad' changed nothing" "$(find "$R/etc/joinery" -name suspended 2>/dev/null | wc -l):$(calls | grep -c graceful)" "0:0"
done
chk "a vhost with no switch says it predates 1.05" "$(sus show sitec 2>&1 >/dev/null | grep -c '1.05')" "1"
rm -f "$R/etc/apache2/sites-enabled/sitec.conf" "$R/etc/apache2/sites-available/sitec.conf" "$R/etc/apache2/sites-available/sited.conf"

echo "=== show: the suspended page on a.test, on both ports, and nowhere else ==="
: > "$T/calls"
sus show sitea > "$T/out.json" 2>"$T/err"; rc=$?
chk "exit 0, nothing on stderr" "$rc:$(wc -c < "$T/err")" "0:0"
chk "every key present, in order" "$(jv "$T/out.json" "")" "site,action,done,suspended,page,reason"
chk "done, suspended, the page shown, no reason" "$(jv "$T/out.json" done):$(jv "$T/out.json" suspended):$(jv "$T/out.json" page):$(jv "$T/out.json" reason)" "true:true:suspended:"
chk "the mark carries the time it was placed" "$(grep -cE '^since=[0-9]{4}-[0-9]{2}-[0-9]{2}T' "$R/etc/joinery/sites/sitea/suspended")" "1"
chk "a configuration test, then a graceful reload" "$(calls | tr '|' '\n' | grep -v '^$' | tr '\n' '|')" "apache2ctl configtest|apache2ctl graceful|"
chk "a.test on http: 503, the suspended page" "$(ask a.test)" "503 suspended"
chk "a.test over TLS: the same" "$(ask a.test https)" "503 suspended"
chk "a POST gets it too" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d x=1 -H 'Host: a.test' "http://127.0.0.1:$P80/login")" "503"
chk "b.test is untouched" "$(ask b.test):$(ask b.test https)" "200 SITE_B:200 SITE_B"
chk "an unknown name still gets the default page" "$(ask evil.example)" "404 none"
first="$(cat "$R/etc/joinery/sites/sitea/suspended")"
: > "$T/calls"; sleep 1
sus show sitea > "$T/out.json" 2>/dev/null
chk "show again: done, no reload, the mark keeps its time" "$(jv "$T/out.json" done):$(calls | grep -c graceful):$([ "$(cat "$R/etc/joinery/sites/sitea/suspended")" = "$first" ] && echo kept)" "true:0:kept"

echo "=== clear: a.test is itself again ==="
: > "$T/calls"
sus clear sitea > "$T/out.json" 2>/dev/null; rc=$?
chk "exit 0, done, not suspended, the site answers" "$rc:$(jv "$T/out.json" done):$(jv "$T/out.json" suspended):$(jv "$T/out.json" page)" "0:true:false:site"
chk "the mark is gone" "$([ -e "$R/etc/joinery/sites/sitea/suspended" ] && echo present || echo gone)" "gone"
chk "a.test answers as itself on both ports" "$(ask a.test):$(ask a.test https)" "200 SITE_A:200 SITE_A"

echo "=== a stopped container is not the suspended page ==="
kill "${PIDS[1]}" 2>/dev/null; sleep 0.5
sus clear siteb > "$T/out.json" 2>/dev/null
chk "b.test's container gone: Apache's own 503, read as the site" "$(ask b.test):$(jv "$T/out.json" page)" "503 :site"

echo "=== a configuration Apache refuses puts the mark back ==="
: > "$T/calls"
STUB_CONFIGTEST_RC=1 SUSPENDED_PAGE_ETC="$R/etc" SUSPENDED_PAGE_PROXY="http://127.0.0.1:$P80" bash "$SUS" show sitea > "$T/out.json" 2>/dev/null; rc=$?
chk "exit 0 with the object: not done, config_refused" "$rc:$(jv "$T/out.json" done):$(jv "$T/out.json" reason)" "0:false:config_refused"
chk "no mark, no reload, a.test still itself" "$(jv "$T/out.json" suspended):$(calls | grep -c graceful):$(ask a.test)" "false:0:200 SITE_A"

echo
echo "proxy_default_site gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
