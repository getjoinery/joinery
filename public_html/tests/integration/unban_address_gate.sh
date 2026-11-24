#!/bin/bash
# @joinery-test
# name: unban_address
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/unban_address.sh]
#
# unban_address.sh is what the agent's unban_address operate word runs as
# root. This gate pins its contract: a jail fail2ban does not list here, a
# jail name or an address that is not one, is refused and nothing runs; the
# only changing call is unbanip with the one jail and the one address, IPv4
# or IPv6; an address that was not banned answers 0; and the object always
# has every key. Every fail2ban-client call here goes to a stub.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/unban_address.sh"
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

# A stub fail2ban-client that records every call. It lists two jails and has
# 203.0.113.9 and 2001:db8::9 banned in apache-badbots.
mkdir -p "$T/bin"
cat > "$T/bin/fail2ban-client" <<STUB
#!/bin/bash
echo "\$*" >> "$T/calls"
[ -n "\${STUB_DOWN:-}" ] && exit 255
case "\$1" in
    status) printf 'Status\n|- Number of jail:\t2\n\`- Jail list:\tapache-badbots, sshd\n'; exit 0 ;;
    set)
        [ "\$3" = unbanip ] || exit 1
        [ -n "\${STUB_GARBLED:-}" ] && { echo "something else"; exit 0; }
        case "\$2/\$4" in apache-badbots/203.0.113.9|apache-badbots/2001:db8::9) echo 1 ;; *) echo 0 ;; esac
        exit 0 ;;
esac
exit 1
STUB
chmod 755 "$T/bin/fail2ban-client"
go() { PATH="$T/bin:$PATH" bash "$SCRIPT" "$@" 2>"$T/err"; }

echo "=== Lifting a ban ==="
out="$(go apache-badbots 203.0.113.9)"; rc=$?
chk "an IPv4 ban lifted" "$rc/$out" '0/{"jail":"apache-badbots","address":"203.0.113.9","fail2ban":"answered","unbanned":1}'
chk "the one changing call names the jail and the address" "$(grep -c '^set apache-badbots unbanip 203.0.113.9$' "$T/calls")/$(grep -c '^set' "$T/calls")" "1/1"
out="$(go apache-badbots 2001:db8::9)"
chk "an IPv6 ban lifted" "$out" '{"jail":"apache-badbots","address":"2001:db8::9","fail2ban":"answered","unbanned":1}'
out="$(go sshd 198.51.100.7)"; rc=$?
chk "an address that was not banned answers 0, not an error" "$rc/$out" '0/{"jail":"sshd","address":"198.51.100.7","fail2ban":"answered","unbanned":0}'
out="$(go sshd ::ffff:192.0.2.1)"
chk "an IPv6 address with an IPv4 tail is an address" "$out" '{"jail":"sshd","address":"::ffff:192.0.2.1","fail2ban":"answered","unbanned":0}'

echo "=== Refusals: nothing runs ==="
: > "$T/calls"
for bad in "recidive 203.0.113.9" "apache-badbots 203.0.113.999" "apache-badbots 203.0.113" "apache-badbots example.com" \
           "apache-badbots 2001:db8::9/64" "apache-badbots --all" "apache-badbots" "--all 203.0.113.9" "sshd;reboot 203.0.113.9" \
           "apache-badbots 1:2" "apache-badbots ::ffff:300.1.1.1" "apache-badbots 203.0.113.9 sshd"; do
    # shellcheck disable=SC2086
    go $bad >/dev/null; rc=$?
    chk "refused: $bad" "$rc" "2"
done
chk "no refusal reached unbanip" "$(grep -c '^set' "$T/calls")" "0"
go 'sshd' '203.0.113.9 --all' >/dev/null; chk "an address with a space is refused" "$?" "2"
go '' '' >/dev/null; chk "no arguments is refused" "$?" "2"

echo "=== fail2ban that is not there or does not answer ==="
out="$(PATH="/usr/bin:/bin" env -i PATH="$T/empty:/usr/bin:/bin" bash -c 'command -v fail2ban-client >/dev/null && echo present')"
if [ -z "$out" ]; then
    out="$(PATH="$T/empty:/usr/bin:/bin" bash "$SCRIPT" sshd 203.0.113.9 2>/dev/null)"; rc=$?
    chk "not installed: absent, unknown, exit 0" "$rc/$out" '0/{"jail":"sshd","address":"203.0.113.9","fail2ban":"absent","unbanned":"unknown"}'
fi
out="$(STUB_DOWN=1 go sshd 203.0.113.9)"; rc=$?
chk "not answering: no_answer, unknown, exit 0" "$rc/$out" '0/{"jail":"sshd","address":"203.0.113.9","fail2ban":"no_answer","unbanned":"unknown"}'
out="$(STUB_GARBLED=1 go sshd 203.0.113.9)"
chk "an answer that is not a count is unknown" "$out" '{"jail":"sshd","address":"203.0.113.9","fail2ban":"answered","unbanned":"unknown"}'

echo "=== Static pins ==="
chk "the only fail2ban verbs are status and set ... unbanip" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -o 'fail2ban-client [a-z][a-z]*' | sort -u | tr '\n' ',')" "fail2ban-client set,fail2ban-client status,"
chk "never bans, stops, reloads or touches the firewall" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E '(^|[^n])banip|\b(stop|reload|restart|iptables|nft|ufw|systemctl)\b')" "0"

echo
echo "unban_address gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
