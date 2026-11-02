# Sourced by the iOS gates (fortress_gate.sh, phase2_gate.sh, phase3_gate.sh).
#
# mini_sim_off NAME: shut it down again. Each gate calls it on exit, so a
# booted simulator never slows the next gate's emulator or simulator.
#
# mini_sim NAME: make sure the mini has a simulator called NAME, creating it
# from the newest iOS runtime installed and that runtime's newest iPhone Pro when
# it is missing, and boot it. A gate names its own simulator rather than a
# device model, so an Xcode update that drops a model never strands it.

mini_sim() {
    local name="$1"
    ssh macmini "/usr/bin/python3 - '$name'" <<'PYEOF'
import json, re, subprocess, sys
name = sys.argv[1]
def simctl(*a):
    return subprocess.run(["xcrun", "simctl", *a], capture_output=True, text=True)
devices = [d for ds in json.loads(simctl("list", "devices", "-j").stdout)["devices"].values() for d in ds
           if d["name"] == name]
# One whose runtime an Xcode update removed is still listed, unavailable: it
# goes, or the new one would share its name and the destination match both.
for d in devices:
    if not d.get("isAvailable", False):
        gone = simctl("delete", d["udid"])
        if gone.returncode != 0:
            sys.exit(f"could not delete the unavailable {name} ({d['udid']}): {gone.stderr.strip()}")
udid = next((d["udid"] for d in devices if d.get("isAvailable", False)), None)
if udid is None:
    runtimes = [r for r in json.loads(simctl("list", "runtimes", "available", "-j").stdout)["runtimes"]
                if r.get("platform") == "iOS"]
    if not runtimes:
        sys.exit("no iOS simulator runtime is installed on the mini")
    # The newest version; of runtimes sharing it, the general one (a
    # single-device runtime ships beside it for a new model).
    runtime = max(runtimes, key=lambda r: ([int(x) for x in r["version"].split(".")],
                                           len(r.get("supportedDeviceTypes", []))))
    phones = [t for t in runtime.get("supportedDeviceTypes", []) if t.get("productFamily") == "iPhone"]
    if not phones:
        sys.exit(f"{runtime['name']} lists no iPhone")
    # The plain Pro is the shape the UI tests are written against: the one
    # with the highest model number.
    pros = [t for t in phones if re.fullmatch(r"iPhone \d+ Pro", t["name"])]
    phone = max(pros, key=lambda t: int(t["name"].split()[1])) if pros else phones[-1]
    made = simctl("create", name, phone["identifier"], runtime["identifier"])
    if made.returncode != 0:
        sys.exit(made.stderr.strip())
    udid = made.stdout.strip()
    print(f"made simulator {name}: {phone['name']}, {runtime['name']}")
simctl("boot", udid)   # already booted is fine
PYEOF
}

mini_sim_off() {
    ssh macmini "xcrun simctl shutdown '$1' 2>/dev/null; true"
}
