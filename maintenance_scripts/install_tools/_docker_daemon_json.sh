#!/usr/bin/env bash
#
# _docker_daemon_json.sh - Docker's daemon.json, merged as JSON (sourced by
# install.sh and docker_disk_pool.sh).
#
# Version: 1.0 - Moved out of install.sh so docker_disk_pool.sh can set
#                Docker's data-root on the data root with the same merge
#                (specs/one_data_root.md WP1).
#
# daemon.json is merged as JSON, one top-level key at a time, never edited as
# text: a key set is replaced whole, every other key is kept, and a file that
# is not a JSON object is refused and left as it was.

# The file; a test points DOCKER_DAEMON_JSON into a scratch root.
docker_daemon_json_path() { printf '%s' "${DOCKER_DAEMON_JSON:-/etc/docker/daemon.json}"; }

docker_daemon_json_set() {  # KEY JSON_VALUE
    local DAEMON_JSON
    DAEMON_JSON="$(docker_daemon_json_path)"
    mkdir -m 0755 -p "$(dirname "$DAEMON_JSON")"
    python3 - "$DAEMON_JSON" "$1" "$2" <<'PY'
import json, os, sys
p, key, value = sys.argv[1], sys.argv[2], json.loads(sys.argv[3])
d = {}
if os.path.exists(p) and os.path.getsize(p) > 0:
    try:
        with open(p) as f:
            d = json.load(f)
    except ValueError as e:
        sys.exit("%s: %s" % (p, e))
    if not isinstance(d, dict):
        sys.exit("%s does not hold a JSON object" % p)
d[key] = value
tmp = p + ".tmp"
with open(tmp, "w") as f:
    json.dump(d, f, indent=2)
    f.write("\n")
os.replace(tmp, p)
PY
}

# One top-level key's value as JSON, or nothing when the file or key is absent.
docker_daemon_json_get() {  # KEY
    local DAEMON_JSON
    DAEMON_JSON="$(docker_daemon_json_path)"
    [[ -s "$DAEMON_JSON" ]] || return 0
    python3 - "$DAEMON_JSON" "$1" <<'PY' 2>/dev/null || true
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except ValueError:
    sys.exit(0)
if isinstance(d, dict) and sys.argv[2] in d:
    print(json.dumps(d[sys.argv[2]]))
PY
}
