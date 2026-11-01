#!/bin/bash
# @joinery-test
# name: largest_files
# tier: safe
# env: any
# needs: []
# timeout: 180
# covers: [maintenance_scripts/sysadmin_tools/largest_files.sh]
#
# largest_files.sh is what the agent's largest_files observe word runs as root
# (agent 1.100.0). This gate pins its contract: it takes nothing, an argument
# changes nothing, config/ is never walked, the list is capped here and sorted
# biggest first, a name keeps only safe characters, a file under a megabyte is
# counted and never named, and nothing writes.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/largest_files.sh"
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

jv() {
    php -r '
        $o = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($o)) { echo "NOTJSON"; exit; }
        $path = $argv[2]; $v = $o;
        if ($path !== "") { foreach (explode(".", $path) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "ABSENT"; exit; } $v = $v[$k]; } }
        if ($argv[3] === "type") { echo is_array($v) ? (array_is_list($v) ? "list" : "object") : gettype($v); exit; }
        if ($argv[3] === "count") { echo is_array($v) ? count($v) : "NOTLIST"; exit; }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        if ($argv[3] === "paths") { echo implode("|", array_column($v, "path")); exit; }
        if ($argv[3] === "sorted") { $b = array_column($v, "bytes"); $s = $b; rsort($s); echo ($b === $s) ? "yes" : "no"; exit; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

KEYS="roots,files,files_total,files_over_min,bytes_total,min_bytes,max_files,partial,generated_at"

echo "=== The real run on this box ==="
bash "$SCRIPT" > "$T/real.json" 2> "$T/real.err"; rc=$?
chk "exit 0" "$rc" "0"
chk "one line" "$(wc -l < "$T/real.json")" "1"
chk "nothing on stderr" "$(wc -c < "$T/real.err")" "0"
chk "every key present, in order" "$(jv "$T/real.json" "" keys)" "$KEYS"
chk "the first root is this site" "$(jv "$T/real.json" roots.0.path)" "$ROOT"
chk "at most twenty-five files" "$( [ "$(jv "$T/real.json" files count)" -le 25 ]; echo $? )" "0"
chk "biggest first" "$(jv "$T/real.json" files sorted)" "yes"
chk "nothing under config/" "$(jv "$T/real.json" files paths | tr '|' '\n' | grep -c '^config/')" "0"

echo "=== A site built here ==="
S="$T/fakesite"
mkdir -p "$S/maintenance_scripts/sysadmin_tools" "$S/config" "$S/static_files" "$S/public_html"
cp "$SCRIPT" "$S/maintenance_scripts/sysadmin_tools/largest_files.sh"
head -c 3145728 /dev/zero > "$S/config/backup_site_key_big"
for i in $(seq 1 30); do head -c $((1048576 + i * 1024)) /dev/zero > "$S/static_files/archive-$i.tar.gz"; done
head -c 2097152 /dev/zero > "$S/static_files/my \"odd\" name.mp4"
for i in $(seq 1 5); do echo small > "$S/public_html/small-$i.txt"; done
before="$(cd "$S" && find . -type f -printf '%p %s %T@\n' | sort | md5sum)"

bash "$S/maintenance_scripts/sysadmin_tools/largest_files.sh" > "$T/fake.json" 2>/dev/null
chk "twenty-five files named, the cap" "$(jv "$T/fake.json" files count)" "25"
chk "the 2 MB file is first" "$(jv "$T/fake.json" files.0.path)" 'static_files/my??odd??name.mp4'
chk "a name keeps only safe characters, the rest shown as ?" "$(jv "$T/fake.json" files paths | grep -c '"')" "0"
chk "config/ is never walked, not even a big file in it" "$(jv "$T/fake.json" files paths | tr '|' '\n' | grep -c '^config/')" "0"
chk "small files are counted, not named" "$(jv "$T/fake.json" files paths | tr '|' '\n' | grep -c 'small-')" "0"
chk "every file is counted (31 big + 5 small + the script)" "$(jv "$T/fake.json" files_total)" "37"
chk "thirty-one over a megabyte" "$(jv "$T/fake.json" files_over_min)" "31"
chk "each file says which root it is under" "$(jv "$T/fake.json" files.0.root)" "site"
chk "a day, not a time" "$(jv "$T/fake.json" files.0.modified | grep -cE '^[0-9]{4}-[0-9]{2}-[0-9]{2}$')" "1"

bash "$S/maintenance_scripts/sysadmin_tools/largest_files.sh" /etc --root=/ > "$T/args.json" 2>/dev/null
chk "an argument changes nothing" "$(jv "$T/args.json" files paths)" "$(jv "$T/fake.json" files paths)"
after="$(cd "$S" && find . -type f -printf '%p %s %T@\n' | sort | md5sum)"
chk "nothing in the site was written" "$after" "$before"

echo
echo "largest_files: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
