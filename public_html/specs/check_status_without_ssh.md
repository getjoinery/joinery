# check_status without SSH — the machine reports on itself

**Status: BUILT 2026-08-31. Platform side complete and green; the resolver
change builds and tests but is NOT yet deployed to nodes 27 and 28, so those two
report service facts only until it is.**

> Annex. Status and ordering live in `agent_management_first_principles.md`.
> Successor to the deleted `plane_executor_round_one.md`, which tried to keep
> `check_status_ssh` runnable instead of making check_status not need SSH.

## The goal this serves

Deprecate SSH by closing vocabulary gaps, one operation at a time. `check_status`
is the first because it is the only operation whose SSH path is still *selected*
on live nodes, and because it is already SSH-free everywhere else.

## Where check_status stands today

| Nodes | Path | SSH-free |
|---|---|---|
| 9 paired nodes | `check_status` primitive, agent 1.13.1 | yes |
| Joinery sites without an agent | `/api/v1/management/stats` | yes |
| **DNS Primary 27, DNS Secondary 28, Relay 1800** | **`build_check_status_ssh`** | **no** |

The trio are siteless, `skip_joinery_checks` on, and will never carry an agent
(settled — `agent_machine_posture_and_relay_converge.md`, and the relay is
health-check-plus-reprovision only). They are the entire remaining consumer of
`build_check_status_ssh`, and since SSH was removed from the agent on 2026-08-30
that builder produces a job no runner can claim. Node 27 and 28's status figures
are dated 2026-08-28; node 1800's blob has been `[]` since July.

## What already exists, unused

`RunNodeUptimeChecks` probes all three nodes every cycle, SSH-free, and is
currently reporting all three up:

- `check_http_status()` fetches the node's health URL with `CURLOPT_NOBODY`,
  pinned to the node's own IP with SNI preserved, and **discards the body**. Its
  docblock states the limit plainly: *this probe reads no status data at all.*
- `check_tcp_port()` establishes reachability on a port (relay: 25).
- `check_cert_expiry()` already answers certificate expiry without SSH — the
  same fact `check_status_ssh`'s fourth step was reaching in for.

And the DNS boxes already answer with structured facts we throw away:

    {"db_connected":true,"last_reload":"2026-08-31T12:06:35Z",
     "status":"ok","uptime_seconds":2958346}

## What this round builds

**A `probe` transport for check_status: read what the machine publishes about
itself, instead of shelling in to take it.**

### 1. `NodeHealthProbe` — one probe implementation, plane-side PHP, no shell

Extracted from `RunNodeUptimeChecks` so there is exactly one implementation of
each probe, not two that can drift. Probe selection reuses the node's existing
monitoring configuration; this spec introduces **no new node field and no new
setting**.

- `http_status` — GET rather than HEAD, keeping the existing IP pinning, TLS
  and redirect behaviour byte for byte. A JSON object body contributes its
  recognised keys; a non-JSON body contributes nothing but reachability.
- `tcp_port` — reachability and latency.
- certificate expiry — the existing check, unchanged.

**Up/down semantics do not change.** The extraction must leave
`RunNodeUptimeChecks`'s conclusions identical, including the
name-resolution-inconclusive path — see `project_monitoring_false_down_dns`
for why a regression here is expensive.

### 2. The machine publishes its own disk and memory

`collectDisk` and `collectMemory` in the agent's `observe_check_status.go` are
~45 lines of dependency-free Go — `syscall.Statfs` and `/proc/meminfo` — and
they already emit exactly the keys `mgn_last_status_data` holds:
`disk_usage_percent`, `disk_total`, `disk_used`, `disk_available`,
`memory_total_mb`, `memory_free_mb`, `memory_used_mb`.

The ScrollDaddy resolver is our Go service and its health handler
(`internal/doh/handler.go:265`) is a six-line map literal. **The same collectors
go there**, as `internal/machine/facts.go`, and `/health` reports the machine as
well as the service. No SSH, no agent, no new daemon: the box already runs a
process of ours that answers HTTP, and it starts answering this too.

The two copies are ~45 lines of syscall reads against interfaces that do not
change. Each file names the other. The plane folds ONE key set regardless of
which source produced it, so a name that drifts shows up immediately as a fact
gone unknown rather than as a wrong number.

**Nothing is carried forward.** A fact the probe cannot get is recorded as
unknown, never left showing an SSH reading taken in August.

### 2a. The relay keeps reachability, and that is its whole health check

Node 1800 runs Postfix, not a Go service of ours, and answers on tcp/25 only.
It gets reachability and latency — which is precisely what
`agent_machine_posture_and_relay_converge.md` says a relay is owed (health check
plus full reprovision). It is not a gap to close later; it is the policy.

### 3. Routing, and the deletion

`build_check_status` becomes primitive → api → probe → throw.
**`build_check_status_ssh` is deleted**, with `get_db_credentials_script`
retained if other builders still use it.

A probe runs in the request that asked for it — an HTTP GET, no queue, no
worker, no steps, no claim. The job row is written already complete, because
the work is genuinely done by then, and `ProvisionCustomerCloud::handle_installing`
polls for a `check_status` job row on the bare-node path and must keep finding one.

### 4. The uptime task folds the same facts on its own pass

Same probe, same folding, so the trio's figures stop being three days old
without anyone pressing a button.

## What this round does NOT do

- **No executor, no worker, no queue, no new job machinery of any kind.** The
  lesson of the rollback stands: a restored SSH capability the deprecation
  cannot see is not a migration step.
- **No agent on the trio.** Settled; not reopened.
- **No other operation's SSH path is touched.** `install_node`, `enable_agent`,
  `provision_ssl`, `decommission_node`, `run_plugin_installers` and the relay
  builders keep whatever they have today.

## Acceptance

1. `build_check_status_ssh` no longer exists; nothing references it.
1a. `scrolldaddy-dns` builds and its `/health` carries the machine keys; the
   deploy to nodes 27 and 28 is the operator's, per `docs/OPS_GUIDE.md`.
2. Check Status on nodes 27, 28 and 1800 completes and records fresh figures,
   with no SSH and no job queue involved.
3. Node 1800 gets a non-empty status blob for the first time since July.
4. Nodes 27 and 28 report **live disk and memory**, in the same keys and the
   same formats the agent primitive produces, with no SSH and no agent.
   Node 1800 reports reachability and latency, and no invented figures.
5. `RunNodeUptimeChecks` reaches the same up/down conclusion for every node and
   every failure mode it does today, resolver failure included.
6. A `safe`-tier test asserts the exact set of operations whose only transport is
   `ssh`. `check_status` is absent from it. The set may shrink; the test fails if
   it grows.
7. `safe` green; `db` green before checkin.

## Why this is the right size

One new class extracted from code that already exists, ~45 lines of collectors
copied into a service we own, one builder deleted, one routing line changed, one
test that turns the remaining gap into a number. It removes a transport rather
than adding one, it loses no fact that SSH was giving us, and it leaves
check_status with no SSH implementation anywhere on the platform.
