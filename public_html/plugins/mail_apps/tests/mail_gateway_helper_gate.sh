#!/bin/bash
# @joinery-test
# name: mail_gateway_helper
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [public_html/plugins/mail_apps/provisioning/install_mail_gateway.sh]
#
# The root helper install_mail_gateway.sh writes to /usr/local/sbin, driven
# through its remove verb (the plugin's uninstall). Every absolute path it
# touches is moved under a throwaway root, and systemctl, ufw, id and userdel
# are stubs that record their calls: the gate never touches the machine.
#
# What it pins, through a relay: csr makes the key here and prints a request
# for the relay's name; install-cert takes only a chain that names that name
# and matches that key; on SITE MAILHOST RELAY_IPS serves the site via_relay
# with the relay's certificate, opens 993/465 to the relay alone and lists it
# in proxy_from; off takes the relay's rules away again.
#
# What it pins on a Docker host (converge): a container site's request in its
# own storage turns it on under its web name through the front door, a second
# pass changes nothing, another name is refused, off turns it off, a link the
# container planted (folder or file) is never followed and nothing is written
# outside its folder, a failed request waits an hour, a gone container's entry
# is forgotten, and an entry that is not a container's is left alone.
#
# What it pins, on removal: removing one site of two forgets that site only and keeps the
# gateway for the other; removing the last takes everything the installer put
# on the machine away (unit, drop-in, binary, user, renewal hook, sudoers
# rule, the helper itself) and closes the ports; the mail host's certificate
# lineage is never touched; a second run is done, not an error; and a site
# name that is not a name is refused before anything runs.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
INSTALLER="$ROOT/public_html/plugins/mail_apps/provisioning/install_mail_gateway.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# The helper, as the installer writes it, with every absolute path under $T/root.
R="$T/root"
awk '/^cat > "\$\{CANDIDATE\}" <<.HELPERSCRIPT./{f=1;next} /^HELPERSCRIPT$/{f=0} f' "$INSTALLER" \
    | sed -e "s#/etc/joinery#$R/etc/joinery#g" -e "s#/etc/systemd#$R/etc/systemd#g" \
          -e "s#/usr/local/lib/joinery#$R/usr/local/lib/joinery#g" -e "s#/usr/local/sbin#$R/usr/local/sbin#g" \
          -e "s#/etc/letsencrypt#$R/etc/letsencrypt#g" -e "s#/etc/sudoers.d#$R/etc/sudoers.d#g" \
          -e "s#/etc/apache2#$R/etc/apache2#g" > "$T/helper"
chk "the helper was extracted" "$(grep -c 'GATEWAY_REMOVED' "$T/helper" | awk '{print ($1 > 0)}')" "1"
chk "no absolute path is left outside the sandbox" \
    "$(sed "s#$R#SANDBOX#g" "$T/helper" | grep -o -E '(SANDBOX)?/(etc/(joinery|systemd|letsencrypt|sudoers.d|apache2)|usr/local/(lib|sbin))' | grep -v -c '^SANDBOX' | tr -d ' ')" "0"

mkdir -p "$T/bin"
for cmd in systemctl ufw userdel; do
    printf '#!/bin/bash\necho "%s $*" >> "%s/calls"\n[ "%s" = ufw ] && [ "$1" = status ] && echo "Status: active"\nexit 0\n' \
        "$cmd" "$T" "$cmd" > "$T/bin/$cmd"
done
printf '#!/bin/bash\n[ "$1" = joinery-mailgw ] && exit 0\nexec /usr/bin/id "$@"\n' > "$T/bin/id"
printf '#!/bin/bash\necho "docker $*" >> "%s/calls"\n[ "$1" = info ] && echo "%s/docker"\nexit 0\n' "$T" "$T" > "$T/bin/docker"
chmod +x "$T/bin/"*

setup() {
    rm -rf "$R" "$T/calls"
    mkdir -p "$R/etc/joinery/mail-gateway/sites" "$R/etc/systemd/system/joinery-mail-gateway.service.d" \
        "$R/usr/local/lib/joinery" "$R/usr/local/sbin" "$R/etc/letsencrypt/renewal-hooks/deploy" \
        "$R/etc/letsencrypt/live/mail.one.example" "$R/etc/sudoers.d"
    for s in "$@"; do
        printf '{\n    "site": "%s",\n    "hostname": "%s.example",\n    "mail_hostname": "mail.%s.example",\n    "cert_file": "/nonexistent",\n    "key_file": "/nonexistent"\n}\n' \
            "$s" "$s" "$s" > "$R/etc/joinery/mail-gateway/sites/$s.json"
        touch "$R/etc/joinery/mail-gateway/sites/$s.enabled" "$R/etc/joinery/mail-gateway/sites/$s.mailhost"
    done
    touch "$R/etc/systemd/system/joinery-mail-gateway.service" "$R/etc/systemd/system/joinery-mail-gateway.service.d/credentials.conf" \
        "$R/usr/local/lib/joinery/mail-gateway" "$R/etc/letsencrypt/renewal-hooks/deploy/joinery-mail-gateway" \
        "$R/etc/letsencrypt/live/mail.one.example/fullchain.pem" "$R/etc/sudoers.d/joinery-mail-gateway"
    cp "$T/helper" "$R/usr/local/sbin/joinery-mail-gateway"
    chmod 755 "$R/usr/local/sbin/joinery-mail-gateway"
}
helper() { PATH="$T/bin:$PATH" bash "$R/usr/local/sbin/joinery-mail-gateway" "$@" 2>&1; }

echo "=== Removing one site of two ==="
setup one two
out="$(helper remove one)"; rc=$?
chk "exit 0 with the marker" "$rc/$(grep -c GATEWAY_REMOVED <<< "$out")" "0/1"
chk "the site is forgotten" "$(ls "$R/etc/joinery/mail-gateway/sites" | grep -c '^one\.')" "0"
chk "the other site stays" "$(ls "$R/etc/joinery/mail-gateway/sites" | grep -c '^two\.')" "3"
chk "the gateway stays for it" "$( [ -e "$R/usr/local/lib/joinery/mail-gateway" ] && [ -e "$R/etc/systemd/system/joinery-mail-gateway.service" ] && [ -e "$R/usr/local/sbin/joinery-mail-gateway" ]; echo $? )" "0"
chk "and is reloaded, not stopped" "$(grep -c 'systemctl reload joinery-mail-gateway' "$T/calls")/$(grep -c 'disable' "$T/calls")" "1/0"
chk "the ports stay open" "$(grep -c 'ufw delete' "$T/calls")" "0"

echo "=== Removing the last site ==="
setup one
out="$(helper remove one)"; rc=$?
chk "exit 0 with the marker" "$rc/$(grep -c GATEWAY_REMOVED <<< "$out")" "0/1"
chk "the unit is stopped and disabled" "$(grep -c 'systemctl disable --now joinery-mail-gateway' "$T/calls")" "1"
chk "both ports are closed" "$(grep -c 'ufw delete allow 993/tcp' "$T/calls")/$(grep -c 'ufw delete allow 465/tcp' "$T/calls")" "1/1"
for gone in etc/systemd/system/joinery-mail-gateway.service etc/systemd/system/joinery-mail-gateway.service.d \
        usr/local/lib/joinery/mail-gateway etc/letsencrypt/renewal-hooks/deploy/joinery-mail-gateway \
        etc/sudoers.d/joinery-mail-gateway usr/local/sbin/joinery-mail-gateway etc/joinery/mail-gateway; do
    chk "removed: /$gone" "$( [ -e "$R/$gone" ]; echo $? )" "1"
done
chk "the user is deleted" "$(grep -c 'userdel joinery-mailgw' "$T/calls")" "1"
chk "systemd is told the unit is gone" "$(grep -c 'systemctl daemon-reload' "$T/calls")" "1"
chk "the mail host's certificate is kept" "$( [ -e "$R/etc/letsencrypt/live/mail.one.example/fullchain.pem" ]; echo $? )" "0"

echo "=== A second run, and a bad name ==="
setup two
rm -f "$R/etc/joinery/mail-gateway/sites/one."*
out="$(helper remove one)"; rc=$?
chk "removing a site already forgotten is done, not an error" "$rc/$(grep -c GATEWAY_REMOVED <<< "$out")" "0/1"
chk "  and leaves the other site served" "$( [ -e "$R/etc/joinery/mail-gateway/sites/two.enabled" ]; echo $? )" "0"
setup one
out="$(helper remove '../one')"; rc=$?
chk "a site that is not a name is refused" "$rc" "2"
chk "  and nothing ran" "$( [ -e "$T/calls" ] && wc -l < "$T/calls" | tr -d ' ' || echo 0)" "0"
out="$(helper off two)"; rc=$?
chk "off for a site with no entry is still refused" "$rc" "2"

echo "=== Through a relay ==="
setup one
SB="$R/etc/joinery/mail-gateway"
out="$(helper csr one mail.one.example)"; rc=$?
chk "csr prints a request and the marker" "$rc/$(grep -c 'BEGIN CERTIFICATE REQUEST' <<< "$out")/$(grep -c GATEWAY_CSR <<< "$out")" "0/1/1"
chk "  the request names the relay's name" "$(sed -n '/BEGIN CERTIFICATE REQUEST/,/END CERTIFICATE REQUEST/p' <<< "$out" | openssl req -noout -text 2>/dev/null | grep -c 'DNS:mail.one.example')" "1"
chk "  the key stays here, root only" "$(stat -c %a "$SB/certs/one/privkey.pem.new")" "600"
# What the relay would answer: a certificate for that key and name.
sed -n '/BEGIN CERTIFICATE REQUEST/,/END CERTIFICATE REQUEST/p' <<< "$out" > "$T/req.pem"
openssl x509 -req -in "$T/req.pem" -signkey "$SB/certs/one/privkey.pem.new" -days 30 \
    -extfile <(echo "subjectAltName=DNS:mail.one.example") -out "$T/good.pem" 2>/dev/null
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -keyout "$T/other.key" -subj "/" \
    -addext "subjectAltName=DNS:mail.one.example" -days 30 -out "$T/otherkey.pem" 2>/dev/null
openssl x509 -req -in "$T/req.pem" -signkey "$SB/certs/one/privkey.pem.new" -days 30 \
    -extfile <(echo "subjectAltName=DNS:mail.other.example") -out "$T/othername.pem" 2>/dev/null
out="$(helper install-cert one mail.one.example < "$T/othername.pem")"; rc=$?
chk "a chain for another name is refused" "$rc/$( [ -e "$SB/certs/one/fullchain.pem" ]; echo $? )" "5/1"
out="$(helper install-cert one mail.one.example < "$T/otherkey.pem")"; rc=$?
chk "a chain for another key is refused" "$rc/$( [ -e "$SB/certs/one/fullchain.pem" ]; echo $? )" "5/1"
out="$(helper install-cert one mail.one.example < "$T/good.pem")"; rc=$?
chk "the relay's chain is installed" "$rc/$(grep -c GATEWAY_CERT_INSTALLED <<< "$out")/$(stat -c %a "$SB/certs/one/privkey.pem")" "0/1/600"
helper on one mail.one.example 198.51.100.7,2001:db8::7 > /dev/null 2>&1   # waits for 993/465, which nothing here answers
chk "the relay's addresses are recorded" "$(tr '\n' ' ' < "$SB/sites/one.relay")" "198.51.100.7 2001:db8::7 "
chk "  and are the gateway's proxy_from" "$(tr '\n' ' ' < "$SB/proxy_from")" "198.51.100.7 2001:db8::7 "
chk "the entry is via_relay with the relay's certificate" \
    "$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo json_encode([$j["via_relay"],$j["mail_hostname"],basename(dirname($j["cert_file"]))]);' "$SB/sites/one.json")" \
    '[true,"mail.one.example","one"]'
chk "993 and 465 open to each relay address" "$(grep -c 'ufw allow proto tcp from 198.51.100.7 to any port 993\|ufw allow proto tcp from 198.51.100.7 to any port 465\|ufw allow proto tcp from 2001:db8::7 to any port 993\|ufw allow proto tcp from 2001:db8::7 to any port 465' "$T/calls")" "4"
chk "  and not to everyone" "$(grep -c 'ufw allow 993/tcp\|ufw allow 465/tcp' "$T/calls")" "0"
chk "  10465 open to each relay address (the relay cannot dial 465 onward)" "$(grep -c 'ufw allow proto tcp from 198.51.100.7 to any port 10465\|ufw allow proto tcp from 2001:db8::7 to any port 10465' "$T/calls")" "2"
chk "  and never to everyone" "$(grep -c 'ufw allow 10465' "$T/calls")" "0"
: > "$T/calls"
out="$(helper refresh one)"; rc=$?
chk "refresh re-applies the relay's rules, 10465 included (an update opens a new port with no Stop and Allow)" \
    "$rc/$(grep -c 'ufw allow proto tcp from 198.51.100.7 to any port 10465\|ufw allow proto tcp from 2001:db8::7 to any port 10465' "$T/calls")" "0/2"
sed 's/"one"/"two"/' "$SB/sites/one.json" > "$SB/sites/two.json"
out="$(helper status one)"
chk "status says when the relay's certificate expires" "$(grep -c -E '^relay_cert_expires=[0-9]{10}$' <<< "$out")" "1"
out="$(helper on two mail.two.example 198.51.100.8 2>&1)"; rc=$?
chk "on through a relay with no certificate yet is refused" "$rc" "5"
: > "$T/calls"
out="$(helper off one)"; rc=$?
chk "off takes the relay's rules away" "$rc/$(grep -c 'ufw delete allow proto tcp from 198.51.100.7' "$T/calls")/$(wc -c < "$SB/proxy_from" | tr -d ' ')" "0/3/0"
out="$(helper on one 'mail.one.example' 'not-an-address' 2>&1)"; rc=$?
chk "a relay address that is not an address is refused" "$rc" "2"

echo "=== A Docker host: container sites ask in their own storage (converge) ==="
setup
SB="$R/etc/joinery/mail-gateway"
# Nothing listens on 993/465 here: the helper is told they answer.
sed -i 's/^listening() {$/listening() { return 0;/' "$R/usr/local/sbin/joinery-mail-gateway"
DR="$T/docker"
site_fixture() {   # site, web name
    mkdir -p "$R/etc/joinery/sites/$1" "$R/etc/apache2/sites-available" "$DR/volumes/$1_storage/_data/mail_apps" \
        "$R/etc/letsencrypt/live/$2"
    echo "# joinery-managed" > "$R/etc/joinery/sites/$1/run_spec"
    printf '<VirtualHost *:443>\n    ServerName %s\n</VirtualHost>\n' "$2" > "$R/etc/apache2/sites-available/$1.conf"
    touch "$R/etc/letsencrypt/live/$2/fullchain.pem" "$R/etc/letsencrypt/live/$2/privkey.pem"
}
ask() { printf '%s' "$2" > "$DR/volumes/$1_storage/_data/mail_apps/request.json"; }
said() { python3 -c 'import json,sys; j=json.load(open(sys.argv[1])); print(j.get("state"), j.get("name"), (j.get("error") or "")[:40])' \
    "$DR/volumes/$1_storage/_data/mail_apps/answer.json" 2>/dev/null; }
field() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get(sys.argv[2]))' "$SB/sites/$1.json" "$2"; }
site_fixture alpha alpha.example
site_fixture beta beta.example
ask alpha '{"on": true, "name": "Alpha.Example"}'
out="$(helper converge)"; rc=$?
chk "converge runs, with its marker" "$rc/$(grep -c GATEWAY_CONVERGED <<< "$out")" "0/1"
chk "the asking site is served" "$( [ -e "$SB/sites/alpha.enabled" ]; echo $? )/$(cat "$SB/sites/alpha.mailhost")" "0/alpha.example"
chk "  through the host's front door, as a container's entry" \
    "$(field alpha upstream) $(field alpha hostname) $(field alpha container) $(field alpha via_relay)" "https://127.0.0.1:443 alpha.example True False"
chk "  with the web name's certificate" "$(field alpha cert_file)" "$R/etc/letsencrypt/live/alpha.example/fullchain.pem"
chk "  the answer says so" "$(said alpha)" "on alpha.example "
chk "  the ports open to everyone" "$(grep -c 'ufw allow 993/tcp' "$T/calls")" "1"
chk "a site that did not ask is not served" "$( [ -e "$SB/sites/beta.enabled" ]; echo $? )/$( [ -e "$DR/volumes/beta_storage/_data/mail_apps/answer.json" ]; echo $? )" "1/1"
: > "$T/calls"
helper converge > /dev/null
chk "a second pass changes nothing" "$(grep -c 'systemctl \(restart\|reload\)' "$T/calls")" "0"
ask beta '{"on": true, "name": "mail.beta.example"}'
helper converge > /dev/null
chk "another name than the web name is refused" "$( [ -e "$SB/sites/beta.enabled" ]; echo $? )/$(said beta | cut -c1-6)" "1/failed"
ask alpha '{"on": false}'
: > "$T/calls"
helper converge > /dev/null
chk "off turns the site off" "$( [ -e "$SB/sites/alpha.enabled" ]; echo $? )/$(said alpha)" "1/off None "
chk "  and closes the ports with no site left" "$(grep -c 'ufw delete allow 993/tcp' "$T/calls")" "1"
echo "=== A Docker host: what the container plants is never followed ==="
mkdir -p "$T/outside"
printf '{"on": true, "name": "alpha.example"}' > "$T/outside/request.json"
rm -rf "$DR/volumes/alpha_storage/_data/mail_apps"
ln -s "$T/outside" "$DR/volumes/alpha_storage/_data/mail_apps"
helper converge > /dev/null
chk "a linked folder is not read" "$( [ -e "$SB/sites/alpha.enabled" ]; echo $? )" "1"
chk "  and nothing is written through it" "$(ls "$T/outside")" "request.json"
rm "$DR/volumes/alpha_storage/_data/mail_apps"; mkdir "$DR/volumes/alpha_storage/_data/mail_apps"
ln -s "$T/outside/request.json" "$DR/volumes/alpha_storage/_data/mail_apps/request.json"
helper converge > /dev/null
chk "a linked request is not followed" "$( [ -e "$SB/sites/alpha.enabled" ]; echo $? )/$(said alpha | cut -c1-6)" "1/failed"
echo "=== A Docker host: failures wait, gone containers go, others stay ==="
rm -f "$DR/volumes/alpha_storage/_data/mail_apps/request.json"
rm -f "$R/etc/letsencrypt/live/alpha.example/fullchain.pem"   # no certificate: on fails
ask alpha '{"on": true, "name": "alpha.example"}'
helper converge > /dev/null
chk "a request that cannot be met fails, with the reason" "$(said alpha | cut -c1-6)" "failed"
: > "$T/calls"
helper converge > /dev/null
chk "  and is not tried again within the hour" "$(grep -c 'systemctl restart\|certbot' "$T/calls")/$( [ -e "$SB/sites/alpha.failed" ]; echo $? )" "0/0"
touch "$R/etc/letsencrypt/live/alpha.example/fullchain.pem"
rm -f "$SB/sites/alpha.failed"
helper converge > /dev/null
chk "served once it can be met" "$( [ -e "$SB/sites/alpha.enabled" ]; echo $? )" "0"
ask beta '{"on": true, "name": "beta.example"}'
helper converge > /dev/null
chk "a second site on the same host is served beside the first" \
    "$( [ -e "$SB/sites/beta.enabled" ]; echo $? )/$( [ -e "$SB/sites/alpha.enabled" ]; echo $? )" "0/0"
rm -f "$DR/volumes/beta_storage/_data/mail_apps/request.json" "$DR/volumes/beta_storage/_data/mail_apps/answer.json"
mkdir -p "$SB/certs/beta" && touch "$SB/certs/beta/cert.pem"
helper converge > /dev/null
chk "a site that took its request back (plugin uninstalled) is forgotten: entry and certificate" \
    "$(ls "$SB/sites" | grep -c '^beta\.')/$( [ -e "$SB/certs/beta" ]; echo $? )" "0/1"
chk "  and the other site is still served" "$( [ -e "$SB/sites/alpha.enabled" ]; echo $? )" "0"
printf '{\n    "site": "gamma",\n    "hostname": "gamma.example",\n    "cert_file": "/nonexistent",\n    "key_file": "/nonexistent"\n}\n' > "$SB/sites/gamma.json"
touch "$SB/sites/gamma.enabled"
rm -rf "$R/etc/joinery/sites/alpha"
helper converge > /dev/null
chk "a gone container's entry is forgotten" "$(ls "$SB/sites" | grep -c '^alpha\.')" "0"
chk "an entry that is not a container's is left alone" "$( [ -e "$SB/sites/gamma.json" ] && [ -e "$SB/sites/gamma.enabled" ]; echo $? )" "0"

echo
echo "=== The installer on a Docker host with no sites yet ==="
chk "--machine installs on any Docker host, sites or not (a host is built before its sites)" \
    "$(grep -c 'command -v docker >/dev/null 2>&1 || \[\[ ! -d /etc/joinery/sites' "$INSTALLER")/$(grep -c 'not a Docker host (no docker) - nothing to install' "$INSTALLER")" "0/1"

echo "mail_gateway_helper gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
