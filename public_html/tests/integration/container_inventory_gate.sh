#!/bin/bash
# @joinery-test
# name: container_inventory
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/container_inventory.sh]
#
# container_inventory.sh is what the agent's container_inventory observe word
# runs as root (specs/agent_recipes_and_vocabulary.md, the running list,
# 2026-10-06). This gate pins its contract against a stubbed docker: every
# container listed, each saying whether install.sh made it (a plain name whose
# SITENAME is its name), its image, state, mounts (type and volume name, never
# a bind's source) and whether its run spec exists; a name that is not a word
# listed reduced and never inspected; one inspect for all of them; the list
# held under its byte budget; none without docker and unknown when it does not
# answer; and no docker verb but ps and inspect.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/container_inventory.sh"
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
        $v = $o; if ($argv[2] !== "") { foreach (explode(".", $argv[2]) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "ABSENT"; exit; } $v = $v[$k]; } }
        if ($argv[3] === "count") { echo is_array($v) ? count($v) : "NOTLIST"; exit; }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

mkdir -p "$T/bin" "$T/etc/joinery/sites/siteone"
printf 'image=joinery/site:0.8.494\n' > "$T/etc/joinery/sites/siteone/run_spec"
cat > "$T/bin/docker" <<'STUB'
#!/bin/bash
echo "docker $1" >> "$GATE_LOG"
case "$1 $2" in
    "ps -a") printf 'siteone\nsitetwo\nimpostor\npostgres\nEvil;Name\n' ;;
    "inspect -f")
        shift 3
        for c in "$@"; do
            case "$c" in
                siteone)  echo "/siteone|SITENAME=siteone|running|joinery/site:0.8.494|volume:siteone_data volume:siteone_config bind: |$(printf 'a%.0s' $(seq 1 64))|CAP_CHOWN CAP_SETUID |CAP_ALL |0|sha256:abc" ;;
                sitetwo)  echo "/sitetwo|SITENAME=sitetwo|exited|joinery/site:0.8.490|volume:sitetwo_data " ;;
                impostor) echo "/impostor|SITENAME=siteone|running|evil/image:latest|bind: tmpfs: " ;;
                *)        echo "/$c||running|postgres:16|volume:pgdata " ;;
            esac
        done ;;
esac
STUB
chmod 755 "$T/bin/docker"
export GATE_LOG="$T/log"; : > "$GATE_LOG"
# The engine is named, not found: this box may or may not have the site engine.
export JOINERY_SITE_ENGINE_KIND=docker
PATH="$T/bin:$PATH" CONTAINER_INVENTORY_ETC="$T/etc" bash "$SCRIPT" > "$T/out.json" 2>/dev/null; rc=$?

echo "=== Every container, and which install.sh made ==="
chk "exit 0, every key, the engine named" "$rc/$(jv "$T/out.json" "" keys)/$(jv "$T/out.json" engine)" "0/engine,containers,truncated,generated_at/docker"
chk "five containers" "$(jv "$T/out.json" containers count)" "5"
chk "a name that is not a word: listed reduced, never inspected" \
    "$(jv "$T/out.json" containers.0.name)/$(jv "$T/out.json" containers.0.install_sh)/$(jv "$T/out.json" containers.0.image)/$(jv "$T/out.json" containers.0.mounts)" \
    "EvilName/false/unknown/unknown"
chk "install.sh's sites say so; the impostor and postgres do not" \
    "$(jv "$T/out.json" containers.1.install_sh)/$(jv "$T/out.json" containers.2.install_sh)/$(jv "$T/out.json" containers.3.install_sh)/$(jv "$T/out.json" containers.4.install_sh)" \
    "true/true/false/false"
chk "each entry's keys" "$(jv "$T/out.json" containers.1 keys)" "name,install_sh,image,state,mounts,run_spec,security"
chk "a site of install.sh's: the filter it was made under, the capabilities it adds as X, and that it drops every other" \
    "$(python3 -c "import json,sys; d=[c for c in json.load(open(sys.argv[1]))['containers'] if c['name']=='siteone'][0]['security']; print(d['filter'][:4], ','.join(d['caps']), d['drop_all'], d['setuid'])" "$T/out.json")" "aaaa CHOWN,SETUID True unknown"
chk "anything install.sh did not make carries no security" \
    "$(python3 -c "import json,sys; print([c['security'] for c in json.load(open(sys.argv[1]))['containers'] if c['name']=='impostor'][0])" "$T/out.json")" "None"
chk "image and state" "$(jv "$T/out.json" containers.1.image)/$(jv "$T/out.json" containers.2.state)" "joinery/site:0.8.494/exited"
chk "mounts: type and volume name, a bind with no name" \
    "$(jv "$T/out.json" containers.1.mounts count)/$(jv "$T/out.json" containers.1.mounts.0.type):$(jv "$T/out.json" containers.1.mounts.0.name)/$(jv "$T/out.json" containers.1.mounts.2.type):$(jv "$T/out.json" containers.1.mounts.2.name)" \
    "3/volume:siteone_data/bind:"
chk "run_spec: the site that has one, not the one that does not" "$(jv "$T/out.json" containers.1.run_spec)/$(jv "$T/out.json" containers.2.run_spec)" "true/false"
chk "docker inspect runs once" "$(grep -c '^docker inspect' "$GATE_LOG")" "1"

echo "=== Bounds ==="
cat > "$T/bin/docker" <<'STUB'
#!/bin/bash
case "$1 $2" in
    "ps -a") for i in $(seq 1 400); do printf 'site%03d-%s\n' "$i" "$(printf 'x%.0s' $(seq 1 42))"; done ;;
    "inspect -f")
        shift 3
        for c in "$@"; do
            m=""; for v in $(seq 1 40); do m+="volume:${c}_v${v} "; done
            echo "/$c|SITENAME=$c|running|registry.example.com/joinery/site-with-a-long-image-name:0.8.494-$(printf 'y%.0s' $(seq 1 40))|$m"
        done ;;
esac
STUB
PATH="$T/bin:$PATH" CONTAINER_INVENTORY_ETC="$T/etc" bash "$SCRIPT" > "$T/many.json" 2>/dev/null
chk "400 large containers: truncated, and the whole object under the agent's 64 KiB" \
    "$(jv "$T/many.json" truncated)/$( [ "$(wc -c < "$T/many.json")" -lt 60000 ]; echo $? )" "true/0"
chk "32 mounts at most per container" "$(jv "$T/many.json" containers.0.mounts count)" "32"
# A site's sixteen volumes (B65: ten were kept, and truncated said false).
cat > "$T/bin/docker" <<'STUB'
#!/bin/bash
case "$1 $2" in
    "ps -a") printf 'siteone\n' ;;
    "inspect -f")
        m=""; for v in config uploads static storage code scripts vendor logs cache backups pg data mail spool run tmp; do m+="volume:siteone_${v} "; done
        echo "/siteone|SITENAME=siteone|running|joinery/site:0.8.495|$m" ;;
esac
STUB
PATH="$T/bin:$PATH" CONTAINER_INVENTORY_ETC="$T/etc" bash "$SCRIPT" > "$T/site16.json" 2>/dev/null
chk "a site's sixteen volumes: every one listed, the last among them, and nothing said cut" \
    "$(jv "$T/site16.json" containers.0.mounts count)|$(jv "$T/site16.json" containers.0.mounts.15.name)|$(jv "$T/site16.json" truncated)" "16|siteone_tmp|false"
cat > "$T/bin/docker" <<'STUB'
#!/bin/bash
case "$1 $2" in
    "ps -a") printf 'siteone\n' ;;
    "inspect -f")
        m=""; for v in $(seq 1 33); do m+="volume:siteone_v${v} "; done
        echo "/siteone|SITENAME=siteone|running|joinery/site:0.8.495|$m" ;;
esac
STUB
PATH="$T/bin:$PATH" CONTAINER_INVENTORY_ETC="$T/etc" bash "$SCRIPT" > "$T/site33.json" 2>/dev/null
chk "one container with a mount more than are listed: the list says it was cut" \
    "$(jv "$T/site33.json" containers.0.mounts count)|$(jv "$T/site33.json" truncated)" "32|true"

echo "=== No docker, and a docker that does not answer ==="
mkdir -p "$T/nodocker"
for b in bash grep sed head date timeout cat tr dirname; do ln -sf "$(command -v "$b")" "$T/nodocker/$b"; done
PATH="$T/nodocker" "$T/nodocker/bash" "$SCRIPT" > "$T/none.json" 2>/dev/null; rc=$?
chk "no docker: exit 0, containers none" "$rc/$(jv "$T/none.json" containers)" "0/none"
cat > "$T/bin/docker" <<'STUB'
#!/bin/bash
[ "$1 $2" = "ps -a" ] && printf 'siteone\n'
exit 1
STUB
PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/unk.json" 2>/dev/null
chk "an inspect that answers nothing: unknown, never an empty host" "$(jv "$T/unk.json" containers)" "unknown"

echo "=== A host whose sites run on the site container engine (Podman) ==="
# Podman names a container without the slash, and reads an image built here
# back as localhost/NAME; a stopped one may say stopped.
# Each field of the format is answered as Podman answers it (read from a real
# site on an engine host): it names no capability as added, records the
# defaults it dropped (never ALL), and states the set kept as BoundingCaps.
cat > "$T/bin/podman" <<'STUB'
#!/bin/bash
echo "podman $1" >> "$GATE_LOG"
case "$1 $2" in
    "ps -a") printf 'siteone\nhelper\n' ;;
    "inspect -f")
        fmt="$3"; shift 3
        IFS='|' read -r -a fields <<< "$fmt"
        for c in "$@"; do
            line=""; sep=""
            for f in "${fields[@]}"; do
                case "$c|$f" in
                    *'|{{.Name}}')                v="$c" ;;
                    siteone'|'*SITENAME*)         v="SITENAME=siteone" ;;
                    siteone'|{{.State.Status}}')  v="running" ;;
                    *'|{{.State.Status}}')        v="stopped" ;;
                    siteone'|{{.Config.Image}}')  v="localhost/joinery-siteone:latest" ;;
                    *'|{{.Config.Image}}')        v="docker.io/library/redis:7" ;;
                    siteone'|{{range .Mounts}}'*) v="volume:siteone_data " ;;
                    siteone'|'*joinery.seccomp*)  v="$(printf 'b%.0s' $(seq 1 64))" ;;
                    siteone'|'*.HostConfig.CapAdd*)  v="" ;;
                    siteone'|'*.HostConfig.CapDrop*) v="CAP_FSETID CAP_NET_BIND_SERVICE CAP_SETFCAP CAP_SETPCAP CAP_SYS_CHROOT " ;;
                    siteone'|'*.BoundingCaps*)    v="CAP_CHOWN CAP_DAC_OVERRIDE CAP_FOWNER CAP_KILL CAP_SETGID CAP_SETUID " ;;
                    siteone'|{{.State.Pid}}')     v="0" ;;
                    siteone'|{{.Image}}')         v="sha256:def" ;;
                    *'|{{'*)                      v="" ;;
                    *)                            v="$f" ;;  # a literal the format carries
                esac
                line+="$sep$v"; sep="|"
            done
            echo "$line"
        done ;;
esac
STUB
chmod 755 "$T/bin/podman"
: > "$GATE_LOG"
PATH="$T/bin:$PATH" JOINERY_SITE_ENGINE_KIND=podman CONTAINER_INVENTORY_ETC="$T/etc" bash "$SCRIPT" > "$T/pod.json" 2>/dev/null
chk "the engine is podman, and only podman is asked" "$(jv "$T/pod.json" engine)/$(grep -c '^docker' "$GATE_LOG")/$(grep -c '^podman' "$GATE_LOG")" "podman/0/2"
chk "a Podman site: install.sh's, its image as install.sh named it, its run spec" \
    "$(jv "$T/pod.json" containers.0.name)/$(jv "$T/pod.json" containers.0.install_sh)/$(jv "$T/pod.json" containers.0.image)/$(jv "$T/pod.json" containers.0.run_spec)" \
    "siteone/true/joinery-siteone:latest/true"
chk "a Podman site: the set it keeps, from Podman's own BoundingCaps, and every other dropped" \
    "$(python3 -c "import json,sys; d=json.load(open(sys.argv[1]))['containers'][0]['security']; print(d['filter'][:4], ','.join(d['caps']), d['drop_all'])" "$T/pod.json")" \
    "bbbb CHOWN,DAC_OVERRIDE,FOWNER,KILL,SETGID,SETUID True"
chk "another container on it: not install.sh's, stopped" "$(jv "$T/pod.json" containers.1.install_sh)/$(jv "$T/pod.json" containers.1.state)/$(jv "$T/pod.json" containers.1.image)" "false/stopped/docker.io/library/redis:7"

echo "=== Static pins ==="
chk "no engine verb but ps and inspect, and no engine named but through the helper" \
    "$(grep -o 'run "$ENGINE" [a-z]*' "$SCRIPT" | sort -u | tr '\n' ' ')|$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E '\b(docker|podman) ')" 'run "$ENGINE" inspect run "$ENGINE" ps |0'
chk "the engine comes from install_tools/_site_engine.sh, named by its kind and run by its command" "$(grep -c '_site_engine.sh"$' "$SCRIPT")/$(grep -c 'ENGINE_KIND="$(site_engine_kind)"' "$SCRIPT")/$(grep -c 'ENGINE="$(site_engine_bin)"' "$SCRIPT")" "1/1/1"
chk "the environment is filtered to SITENAME inside docker" "$(grep -cF '0) "SITENAME"}}{{.}}{{end}}' "$SCRIPT")" "1"
chk "a mount's source is never read" "$(grep '^INSPECT_FORMAT=' "$SCRIPT" | grep -c '\.Source')" "0"
chk "nothing writes" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E '\btee\b|>\s*/(etc|var|srv|usr|run)|\brm |\bmv ')" "0"

echo
echo "container_inventory gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
