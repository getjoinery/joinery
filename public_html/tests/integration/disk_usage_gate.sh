#!/bin/bash
# @joinery-test
# name: disk_usage
# tier: safe
# env: any
# needs: []
# timeout: 180
# covers: [maintenance_scripts/sysadmin_tools/disk_usage.sh]
#
# disk_usage.sh is what the agent's disk_usage observe word runs as root
# (specs/disk_headroom_and_unit_diagnosis.md § 11). This gate pins its
# contract: it takes nothing, an argument changes nothing, the object always
# has every key, every entry is a path and a byte count and never a file, the
# lists are capped here, du never leaves the filesystem it started on, and
# nothing writes.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/disk_usage.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
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
        $path = $argv[2]; $v = $o;
        if ($path !== "") { foreach (explode(".", $path) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "ABSENT"; exit; } $v = $v[$k]; } }
        if ($argv[3] === "type") { echo is_array($v) ? (array_is_list($v) ? "list" : "object") : gettype($v); exit; }
        if ($argv[3] === "count") { echo is_array($v) ? count($v) : "NOTLIST"; exit; }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

KEYS="filesystem,tree,machine,depth,max_entries,generated_at"

echo "=== The real run on this box ==="
bash "$SCRIPT" > "$T/real.json" 2> "$T/real.err"; rc=$?
chk "exit 0" "$rc" "0"
chk "one line" "$(wc -l < "$T/real.json")" "1"
chk "nothing on stderr" "$(wc -c < "$T/real.err")" "0"
chk "a JSON object" "$(jv "$T/real.json" "" type)" "object"
chk "every key present, in order" "$(jv "$T/real.json" "" keys)" "$KEYS"
chk "the filesystem is this site's web root" "$(jv "$T/real.json" filesystem.path)" "$ROOT/public_html"
chk "the filesystem figures are integers" "$(jv "$T/real.json" filesystem.used_bytes type)/$(jv "$T/real.json" filesystem.avail_bytes type)" "integer/integer"
chk "the tree is this site root" "$(jv "$T/real.json" tree.path)" "$ROOT"
chk "the tree total is an integer" "$(jv "$T/real.json" tree.total_bytes type)" "integer"
chk "a walk that could not read everything says so" "$(jv "$T/real.json" tree.partial type)" "boolean"
chk "the tree entries are a list" "$(jv "$T/real.json" tree.entries type)" "list"
chk "at most twenty of them" "$( [ "$(jv "$T/real.json" tree.entries count)" -le 20 ]; echo $? )" "0"
chk "the depth and cap are reported" "$(jv "$T/real.json" depth)/$(jv "$T/real.json" max_entries)" "2/20"
chk "generated_at is now" "$( g=$(jv "$T/real.json" generated_at); n=$(date -u +%s); [ "$g" -le "$n" ] && [ "$g" -ge $((n-300)) ]; echo $? )" "0"

echo "=== Every entry is a directory and a size, and nothing else ==="
php -r '
    $o = json_decode(file_get_contents($argv[1]), true);
    $bad = 0;
    foreach (array_merge($o["tree"]["entries"], $o["machine"]) as $e) {
        if (array_keys($e) !== ["path", "bytes"]) { $bad++; continue; }
        if (!is_string($e["path"]) || $e["path"] === "") { $bad++; }
        if (!is_int($e["bytes"]) && $e["bytes"] !== "absent") { $bad++; }
    }
    echo $bad;
' "$T/real.json" > "$T/shape"
chk "no entry carries anything but a path and a byte count" "$(cat "$T/shape")" "0"
chk "biggest first" "$( php -r '
    $e = json_decode(file_get_contents($argv[1]), true)["tree"]["entries"];
    $last = PHP_INT_MAX;
    foreach ($e as $x) { if ($x["bytes"] > $last) { echo 1; exit; } $last = $x["bytes"]; }
    echo 0;
' "$T/real.json" )" "0"
chk "the machine list is the compiled one" "$( php -r '
    $m = json_decode(file_get_contents($argv[1]), true)["machine"];
    echo implode(",", array_column($m, "path"));
' "$T/real.json" )" "/var/log,/var/lib/postgresql,/var/cache,/var/backups,/var/lib/docker"

echo "=== An argument and stdin change nothing ==="
echo "some stdin the script must ignore" | bash "$SCRIPT" / --max-depth=9 /etc > "$T/arg.json" 2>/dev/null
chk "with arguments and stdin: the same keys" "$(jv "$T/arg.json" "" keys)" "$KEYS"
chk "the argument did not become the tree" "$(jv "$T/arg.json" tree.path)" "$ROOT"
chk "the argument did not become the depth" "$(jv "$T/arg.json" depth)" "2"
chk "no environment hook: the script reads none" "$(grep -c 'JOINERY_' "$SCRIPT")" "0"

echo "=== The caps are the script's, against a stubbed du ==="
mkdir -p "$T/bin"
cat > "$T/bin/du" <<STUB
#!/bin/bash
# The site root's own line, then more directories than the cap allows, in an
# order that is not the reported one.
echo -e "999999999\t$ROOT"
for i in \$(seq 1 40); do echo -e "\$((i * 1000))\t$ROOT/dir\$i"; done
echo -e "5\t$ROOT/weird\"name\\\$(reboot)"
STUB
chmod +x "$T/bin/du" 2>/dev/null || true
PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/stub.json" 2>/dev/null
chk "stubbed run: still a JSON object" "$(jv "$T/stub.json" "" type)" "object"
chk "capped at twenty entries" "$(jv "$T/stub.json" tree.entries count)" "20"
chk "the biggest is first" "$(jv "$T/stub.json" tree.entries.0.path)/$(jv "$T/stub.json" tree.entries.0.bytes)" "dir40/40000"
chk "the site root's own line became the total, not an entry" "$(jv "$T/stub.json" tree.total_bytes)" "999999999"
chk "paths are relative to the tree" "$(jv "$T/stub.json" tree.entries.1.path)" "dir39"
chk "a hostile directory name cannot break the JSON" "$(jv "$T/stub.json" "" type)" "object"

echo "=== Static pins ==="
# Every line that RUNS du (a timeout line) runs it with -x. Matched on the
# invocation rather than the word, so a sentence in a comment neither passes
# nor fails this.
chk "du never leaves the filesystem (-x, every invocation)" "$( a=$(grep -c 'timeout "\$DU_TIMEOUT" "\${nice_cmd\[@\]}" du -x -b' "$SCRIPT"); b=$(grep -c 'timeout "\$DU_TIMEOUT"' "$SCRIPT"); [ "$a" = "$b" ] && [ "$a" = "2" ]; echo $? )" "0"
chk "du runs under a timeout" "$(grep -c 'timeout "\$DU_TIMEOUT"' "$SCRIPT")" "2"
chk "du runs politely" "$(grep -c 'nice -n 19' "$SCRIPT")" "2"
chk "the entry cap is 20" "$(grep -c '^MAX_ENTRIES=20' "$SCRIPT")" "1"
chk "the depth is 2" "$(grep -c '^TREE_DEPTH=2' "$SCRIPT")" "1"
chk "no file is ever listed: no find, no ls, no stat" "$(grep -c -E '\bfind |\bls |\bstat ' "$SCRIPT")" "0"
chk "nothing writes: no tee, no redirect into /etc or /var, no rm, no mv" "$(grep -c -E '\btee\b|> */(etc|var)|\brm |\bmv |\bcp ' "$SCRIPT")" "0"
chk "exit 0 is the last thing it does" "$(tail -n 1 "$SCRIPT")" "exit 0"

echo
echo "disk_usage gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
