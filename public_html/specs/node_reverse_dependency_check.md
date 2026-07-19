# Node Reverse-Dependency Check

## Overview

Before you change how a server is reachable — close a port, rebind a service,
retire an address — you want to know **who else is talking to it**. Today
nothing answers that question, so the only way to find out is to make the
change and see what breaks.

This adds that answer: ask any managed node "what else in the fleet points at
you?" and get back a list of machines, the files that reference it, and the
lines doing the referencing. It runs on demand from the node detail page, and
a change job can call it before touching network exposure.

The check works off **configuration**, not traffic. That distinction is the
whole design, and it is not a preference — it was measured. See *Why not
traffic inspection*.

## What motivated this (grounding)

On 2026-07-19 a sanctioned hardening job (`specs/deferred_fixes.md` entry 24)
rebound every docker site's Postgres publish on the shared host from all
interfaces to `127.0.0.1`. Correct for eight of nine containers. The ninth,
`scrolldaddy`, is read over the Linode private network by the two ScrollDaddy
DNS resolvers, which lost their database for 63 minutes. The resolvers kept
answering queries from cached data, so the failure was invisible in DNS
responses; only `/health` and admin-side config propagation showed it.

The job verified its own success thoroughly — all nine ports dark from
outside, all nine sites serving 200. Both true. Neither question was "who
connects to these ports from another machine?"

## Why not traffic inspection

The obvious implementation is to look at live connections and see who shows
up. It does not work, and it fails in the worst possible way: it returns an
empty list, which reads as "nothing depends on this."

Measured on the shared host while both resolvers were actively polling:

| Method | Result |
|---|---|
| `ss` snapshot of established connections to 9080–9099 | nothing |
| `ss` sampled once per second for 75s (a full reload cycle) | nothing |
| Kernel `nf_conntrack` table for port 9087 | nothing |
| Grep node configs for the host's addresses | **found it on both resolvers instantly** |

The resolvers open a connection, run a lightweight reload, and close — every
60 seconds, for a fraction of a second. Any poll-based consumer behaves this
way, and they are exactly the consumers most likely to be forgotten, because
they are unattended. A sampling check would have to run continuously for
longer than the longest polling interval in the fleet to be trustworthy, and
it still could not see a consumer that polls daily or is currently stopped.

Configuration does not have this problem. The intent to connect is written
down whether or not a connection is open:

```
/etc/scrolldaddy/scrolldaddy.env      SCD_DB_HOST=192.168.206.198
/etc/scrolldaddy/OPS_GUIDE.md         ScrollDaddy DB ... port 9087
```

**Consequence for the design:** the check reports *declared* dependencies. It
will miss a consumer that hardcodes an address in a compiled binary or fetches
it from somewhere unscanned. That limitation is stated rather than papered
over — see Non-goals.

## What exists today (grounding)

- **`mgn_managed_nodes`** (`plugins/server_manager/data/managed_node_class.php`)
  — 31 rows. Identity-ish fields: `mgn_host` (the SSH target, usually a public
  IP), `mgn_name`, `mgn_slug`, `mgn_site_url`, `mgn_health_check_url`,
  `mgn_container_name`, `mgn_port`, `mgn_wg_ip`, `mgn_mgh_host_id`.
- **`mgh_managed_hosts`** (`managed_host_class.php`) — the physical machine a
  node sits on; `mgh_host` is again a single address. Nine of the nodes share
  host `23.239.11.53`.
- **SSH execution** — `ManagementJob` (`data/management_job_class.php`) runs
  ordered steps including `{'type':'ssh', 'on_host':true, 'cmd':…}` against a
  node or its host, with per-step timeout and `continue_on_error`.
  `JobCommandBuilder` / `JobResultProcessor` build and consume those steps.
  Every managed node is already reachable this way with a working key.
- **`RunNodeUptimeChecks`** (`plugins/server_manager/tasks/`) — the model for a
  fleet-wide scheduled sweep: iterate enabled nodes, probe each, persist state
  on the row, alert only on transition.

**The gap that matters most:** a node record stores the address you typed into
the form, not the addresses the machine actually has. The shared host's
private IP `192.168.206.198` — the exact string every dependent config names —
appears nowhere in `mgn_managed_nodes` or `mgh_managed_hosts` (verified: zero
rows match). A reverse-dependency scan keyed on stored fields alone would have
missed today's outage entirely. Address discovery is therefore not an
enhancement to this feature; it is a precondition.

## Design

Three pieces, in dependency order.

### 1. Node address inventory

Each enabled node learns and records its own identifiers, rather than relying
on what an admin typed.

Collected over the existing SSH path:

- all IPv4/IPv6 addresses on non-loopback interfaces (`ip -o addr`) — this is
  what surfaces private/VLAN/WireGuard addresses
- system hostname and FQDN
- docker container names and their published `host_ip:host_port` bindings,
  where docker is present
- listening TCP sockets with their bind addresses

Stored as a jsonb blob on the node (`mgn_address_inventory`) with a
`mgn_address_inventory_time`, refreshed by a scheduled sweep on the
`RunNodeUptimeChecks` pattern. Configured fields (`mgn_host`, `mgn_site_url`,
`mgn_container_name`, `mgn_wg_ip`) are merged in, so a node that is
unreachable at scan time still contributes the identifiers we already know.

This piece has standalone value beyond this feature: the fleet currently has
no record of its own private addressing.

### 2. Reverse-dependency scan

Given a target node, build its identifier set from the inventory, then search
every *other* enabled node for those strings.

Search is a single `grep -rIl` style pass per node over a bounded path set,
returning file path, line number, and the matching line. Runs against nodes in
parallel where the job runner allows, since each is an independent SSH round
trip.

**Secret redaction is mandatory, not optional.** Config files matched by this
scan routinely contain credentials — the `scrolldaddy.env` line that proves
the dependency also carries a database password, and `SCD_API_KEY` sits three
lines away. Every returned line passes through redaction (`(PASSWORD|SECRET|
KEY|TOKEN|PASS)=\S+` → `\1=REDACTED`, plus DSN-embedded `password=…`) before
it is stored or displayed. Findings are persisted, so an unredacted secret
would be written to the database and rendered in admin — treat a redaction
miss as a security defect, and pin it with a test that plants a fake
credential and asserts it never appears in output.

Results are ranked, not filtered: a match in a config file outranks a match in
a `.md` file. Today's scan matched both `scrolldaddy.env` (a live dependency)
and `OPS_GUIDE.md` (documentation *about* the dependency). Both are worth
showing — the doc match is a true signal that a human wrote this relationship
down — but the operator should see the live one first. No attempt is made to
classify automatically beyond file-extension ranking; guessing wrong in the
direction of hiding a real dependency is the failure mode to avoid.

### 3. Two entry points

**Ad hoc.** A "Who depends on this node?" action on the node detail page,
rendering the ranked findings. This is the piece an operator reaches for
before doing something manual.

**Pre-change.** A callable the job builder invokes before a step that alters
network exposure, surfacing findings into the job record. Recommended
behavior is **warn and require acknowledgement**, not hard block — see D3.

## Decisions

Resolved with recommendations; D1 and D2 want owner confirmation because they
trade false positives against misses.

**D1 — which strings count as identifiers.** *Recommend:* every discovered IP,
the hostname and FQDN, container names, and `mgn_slug` where it is longer than
six characters. **Exclude bare port numbers** (`9087` alone matches far too
much) and short slugs. A port is only meaningful paired with an address, so
match `address` and separately `address:port`, never port alone.

**D2 — where to search.** *Recommend:* `/etc` plus each node's `mgn_web_root`,
plus an optional per-node extra-paths field for anything unusual. Explicitly
not the whole filesystem — too slow across 31 nodes, and `/proc`, `/var/lib/
docker`, and backup archives generate noise that would bury real findings.
Binary files are skipped (`grep -I`).

**D3 — does a pre-change finding block the change?** *Recommend:* warn and
require explicit acknowledgement, recorded on the job. Hard-blocking makes the
scan a single point of failure for all infrastructure work — an unreachable
node or a stale inventory would stop legitimate changes, and the pressure to
add a bypass flag would make the check advisory in practice anyway.

**D4 — what about the node being changed itself?** *Recommend:* exclude the
target from its own scan, but do **not** exclude sibling nodes sharing a
host. Today's case was cross-host, but nine nodes share `23.239.11.53` and a
container-to-container dependency is equally real.

**D5 — inventory refresh cadence.** *Recommend:* daily, plus on demand when a
scan is requested, so an operator about to make a change gets fresh data
rather than yesterday's. A stale inventory produces misses, which is the
failure mode this feature exists to prevent.

## Non-goals

- **Not a CMDB.** This records addresses to make text search possible, not to
  model services, ownership, or topology.
- **Not runtime traffic analysis.** Measured and rejected above.
- **Not automatic remediation.** It reports; a human decides.
- **Not a guarantee.** A dependency that is compiled in, held only in a remote
  secret store, or lives on an unmanaged machine will not be found. The output
  should say what was scanned so an empty result is read as "nothing declared
  in the scanned surface" rather than "safe."
- **Not restricted to database ports.** Today's break was Postgres, but the
  same blindness applies to any port, address, or hostname change.

## Testing

Follow `docs/testing.md` — `@joinery-test` header, shared harness.

- **safe tier:** identifier-set construction from a synthetic inventory (D1
  rules — port-alone excluded, short slugs excluded); redaction, including a
  planted fake credential asserted absent from output; result ranking putting
  config above docs.
- **db tier:** inventory persists and round-trips on the node row; a scan
  across seeded nodes with a planted reference returns the expected finding;
  the target node is excluded from its own results (D4).
- **Regression pin:** the concrete case — a node whose config names another
  node's private IP is found by a scan of that second node. This is the
  outage, encoded.

## Documentation

Per repo convention, developer docs land in the existing file rather than a
new one: extend `plugins/server_manager/docs/overview.md` with a
*Reverse-dependency checks* section covering the address inventory, what the
scan covers and cannot cover, the redaction guarantee, and how a job consumes
findings. It sits naturally beside the existing Uptime Monitoring and
certificate-expiry sections.

When this lands, `specs/deferred_fixes.md` entry 24's warning that a future
binding-convergence job must skip `scrolldaddy` becomes enforceable rather
than advisory — note that in the entry.

## Status

Spec only. Not implemented. D1–D5 recommendations stand unless the owner
overrides.
