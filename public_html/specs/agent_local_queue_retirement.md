# Retire the agent's local job queue

**Status: AUDITED 2026-08-30. The agent's SSH transport was REMOVED the same
day — thirteen operations now fail loudly and the plane-side executor is
blocking, not planned.**
Written 2026-08-30. WP2 is done and found two real gates; see "WP2 — the audit,
done" below. The earlier status line ("gated on nothing but its own audit") is
withdrawn.

## What changed on 2026-08-30

SSH and SCP were deleted from the agent as a deliberate forcing function — 397
lines across `ssh.go`, `scp.go`, `runner.go`, `db.go`, `server.go`. `ssh`/`scp`
steps now return "SSH and SCP capability is deprecated". Running on the dev
management node since 14:47 UTC (1.12.0, unsigned local build).

Two consequences. Every one of the thirteen operations in the audit below now
fails visibly rather than lingering — the tripwire is the schedule. And the
plane-side executor moved from a sub-item of this spec to the thing everything
waits on: it has its own spec, `plane_side_executor.md`.

**A caution about durability.** The removal holds only because the binary is
unsigned and unpublished. The self-update compares versions for **equality, not
order** (`update.go:279`: `m.Version == u.running`), so the published 1.10.0
manifest is "different" from the running 1.12.0 and would be installed — a
silent downgrade restoring SSH. It stopped at the signature check
(`update_state=unsigned_build`). Whether equality-not-ordering is deliberate
(the manifest as desired version, enabling rollback) is unresolved; either way,
a stale manifest rolls a node backwards without saying so.

> **Status and ordering live in `agent_management_first_principles.md`.**
> This spec is an annex: it carries the design and nothing else. If this
> document and the programme disagree about what is done, the programme is
> right.

## The problem

The management node's `joinery-agent` runs as root. It takes any pending row
from `mjb_management_jobs` whose commands carry no `primitive` key
(`db.go:189`), and runs a `local` step as `exec.CommandContext(ctx, "bash",
"-c", resolved)` (`runner.go:207`, `:291`). Nothing in `runner.go` verifies a
signature, HMAC or manifest.

The web application writes that table as its normal dispatch path. So write
access to the site database is root on the management node, and from there the
fleet.

It was not an oversight. The design assumed the database *is* the plane and its
writer is the operator. That held while the only writer was an admin screen. It
stopped holding when the same database user began serving the whole public
application.

## Why this is small

The agent already supports the end state. It runs two independent job sources:

```go
localQueue := NewLocalQueue(cfg.LocalJobs, db.MissingLocalJobTables, ...)   // legacy
if remote := startRemoteSource(cfg, db, &jobLock, version); remote != nil    // channel
```

The local queue is gated on a config flag and on the tables existing. The remote
source polls the plane over the signed channel. `startRemoteSource` notes the DB
handle is "nil on a machine with no site, and that nil is load-bearing" —
siteless agents run with no database today.

Every DB call hangs off the local queue: `ClaimNextJob` and its bookkeeping
(`AppendOutput`, `CompleteJob`, `FailJob`, `RecoverStaleJobs`); `GetNodeConnInfo`
and `GetNodeAPIInfo`, which exist to give legacy `ssh`/`api` steps their target's
coordinates; `UpdateHeartbeat`, which a claim already carries for remote agents.
Backup-target credentials have a channel equivalent in the `__SM_CREDS_`
placeholders resolved at claim time.

Turn the flag off and nothing is left needing a database.

## What gates it

The audit below is what says. **The three unpaired nodes are not among the
gates — they are disposable, by standing owner decision.** A8 (2026-08-26, reaffirmed 2026-08-30): only Joinery
instances run the agent. The two ScrollDaddy DNS resolvers and the relay are
rebuilt, not managed — the agent is never built for them, siteless posture
notwithstanding. An earlier draft of this spec proposed pairing them; that was
a re-litigation of a settled decision and is withdrawn.

The queue confirms it costs nothing: the entire job history against those three
nodes is a handful of `run_command` jobs (an operation already retired) and
check_status attempts stale since July. Uptime monitoring is plane-side probing,
not queue jobs. What management they need is manual SSH or a reprovision,
outside the plane.

## WP2 — the audit, done (2026-08-30)

Thirteen operations still reach a node through the management node's own agent
running `local` / `ssh` / `scp` steps. Every one was traced to its caller and
its live use measured against the job table.

| Operation | Steps | Live use | Disposition |
|---|---|---|---|
| `publish_upgrade` | local | 33 runs / 14d, last 08-28 | **Re-home plane-side (G1)** |
| `provision_ssl` | local + ssh | issuance only; no renewal traffic | To the agent via the host node — G2 is open now that hosts run an agent. `ssh_single_bootstrap.md` |
| `install_node` | local + ssh + scp | last 08-09 | The one bootstrap, on `InstallJobExecutor`; collapses to one session. `ssh_single_bootstrap.md` |
| `discover_nodes` | local + ssh | last 04-20 | **Delete.** `ssh_single_bootstrap.md` |
| `enable_agent` | ssh | UI-reachable | **Delete.** `ssh_single_bootstrap.md` |
| `provision_relay` | local + ssh + scp | last 07-19 | Provisioning runner, or dies with the relay |
| `decommission_node` | ssh + scp | last 07-24, UI-reachable | Becomes a provider API call |
| `backup_database` | ssh | last 07-31 | **Delete** |
| `backup_project` | ssh | last 08-05 | **Delete** |
| `rebuild_relay` | ssh | last 07-08 | Delete at the relay cutover |
| `relay_add_tenant` | ssh | last 07-19 | Delete at the relay cutover |
| `relay_set_domains` | ssh | — | Delete at the relay cutover |
| `relay_remove_tenant` | ssh | last 07-19 | Delete at the relay cutover |

### The two gates

**G1 — `publish_upgrade` has no executor after the flip.** It is created with
`ManagementJob::createJob(null, …)` (`views/admin/publish_upgrade.php:133`) —
a job with no node, whose `local` steps run on the publisher, about the
publisher. Calling it "publisher-local, never a primitive" is right and also
beside the point: it never needed a *node* transport, and it is the only thing
in the table that the local queue is genuinely the executor for rather than
merely the dispatcher. It needs a plane-side executor — the build run in PHP,
or a plane-side worker — before `cfg.LocalJobs` can go false. Without one, the
flip stops all publishing.

**G2 — container nodes cannot ISSUE certificates over the channel.**
*(Opened 2026-09-01: a Docker host is a paired machine-posture node
reporting `provision_certificate`, and `mgh_mgn_host_node_id` routes to it.
The disposition is in `ssh_single_bootstrap.md` WP3; the measurements below
stand.)*
`ProvisionPendingSsl::uses_primitive_route()` takes the agent SSL chain only
when `is_bare_metal($node) && has_primitive($node, 'provision_certificate')`.
The measured fleet is **8 of 9 paired nodes containerised**; only
jeremytunnell is bare metal. The container gate is correct and deliberate — a
certificate issued inside a container is written to a filesystem the next
rebuild discards, after spending one of five per domain per week.

**Renewal is not what breaks** (peer review, verified 2026-08-30). Every
certbot step in `build_provision_ssl` carries `'on_host' => $is_docker`
(`JobCommandBuilder.php:3181-3190`), so on a container node certbot runs on
the Docker **host**, apt-installed alongside its own systemd renew timer. An
issued certificate therefore renews with no management-node involvement at
all, flag or no flag.

**Confirmed on the host, 2026-08-30.** All eight containerised nodes share one
Docker host (23.239.11.53). There, `certbot.timer` is `enabled` and `active`,
last fired 07:07 UTC and next at 13:30 — a 12-hourly cadence — with
`/etc/cron.d/certbot` present as a second path. Renewal is host-side and
plane-independent, as read.

**And it matters less than that.** Of the eight sites on that host, **seven
are behind Cloudflare** and serve a Google Trust Services edge certificate;
their DNS points at Cloudflare, not at the origin. Only
`developers.getjoinery.com` resolves to 23.239.11.53 and serves a Let's
Encrypt certificate from the host itself. The host carries three certbot
certificates (demo, developers, orgs).

**Correction, same day: these are not vestigial and must not be deleted.**
An earlier draft of this section called demo and orgs leftovers outside any
serving path, and proposed `certbot delete` for them. That was wrong. Probing
the origin directly per-SNI shows they are the certificates for the
**Cloudflare-to-origin leg**, which is a live serving path — deleting them
would downgrade those two sites, not tidy the host.

The same probe found the real problem, which is the opposite of a surplus:

| Name | Certificate the ORIGIN presents for it |
|---|---|
| demo.getjoinery.com | its own (Let's Encrypt) |
| orgs.getjoinery.com | its own (Let's Encrypt) |
| developers.getjoinery.com | its own (direct-served, no Cloudflare) |
| getjoinery.com | `developers.getjoinery.com` — **name mismatch** |
| scrolldaddy.app | `developers.getjoinery.com` — **name mismatch** |
| galactictribune.net | `developers.getjoinery.com` — **name mismatch** |
| mapsofwisdom.org | `developers.getjoinery.com` — **name mismatch** |
| phillyzouk.org | `developers.getjoinery.com` — **name mismatch** |

Five of the eight have no origin certificate for their own name; Apache falls
back to its first SSL vhost. Cloudflare therefore cannot be validating those
five (Full **Strict** rejects a name mismatch), so that leg is encrypted but
unauthenticated at best. Ports 80 and 443 on the origin are open to the
internet, so the edge can also simply be bypassed by IP.

That is a live TLS defect, not a queue-retirement problem, and it is tracked
in its own right rather than here. Its only bearing on this spec is on G2: the
right end state for certificate issuance is DNS-01 through the platform's own
DNS drivers, which changes what R3 should be. Specified in
`origin_tls_and_certificate_issuance.md` (deferred by owner, 2026-08-30).

**What the flip breaks is new issuance** — the SSL phase of provisioning a
container or Docker-host node has no transport once the local queue is gone.
Still a gate; its fix home is `origin_tls_and_certificate_issuance.md`, whose
DNS-01 design needs nothing inbound and works identically inside a container.
R3 (Docker-host certificates) in `agent_machine_posture_and_relay_converge.md`
is what that supersedes, and should not be built as written.

The job table agrees, and now explains itself: all 189 `provision_ssl` jobs in
the last 30 days are a single domain — `developers.getjoinery.com` on node
10107 — 188 failed and 1 completed on 08-19 in a Cloudflare-routing-probe
retry loop. That is precisely the one node NOT behind Cloudflare, so it is the
only one that ever needed certbot. The other eight ran no `provision_ssl` jobs
at all. There is no recurring renewal traffic through this path to lose.

### What is not a gate

**Four provisioning operations** (`install_node`, `discover_nodes`,
`enable_agent`, `provision_relay`) run before a node has an agent, so a
primitive is impossible by definition.

For machines **we create**, they stop existing rather than moving: under
`keyless_provisioning.md` the provider API delivers a first-boot script, the
machine installs itself and asks to join, and nothing SSHes in at all. That is
the largest single reduction available to this spec — `install_node`'s sixteen
remote steps simply have no caller left on the provisioning path.

What remains is machines we did **not** create — `discover_nodes` and
`enable_agent` against a box whose owner supplies the credential (both
deleted under `ssh_single_bootstrap.md`: the owner enrolls from the node's
own Management Node page, and the plane needs no shell on it), and
`provision_relay`, whose target set is wider than the single relay node on
paper — it also installs a restricted pull key on relay *shards*, tracked as a
separate question in `keyless_provisioning.md`.

Note the direction of the dependency: `keyless_provisioning.md` WP1 needs G1's
plane-side executor, because provisioning authenticates with the instance's root
password and the Go agent's SSH client does public keys only. Same executor,
sequenced after. Those need
a plane-side executor. **This is a dependency, not a gate**, and it is the same
executor G1 needs.

**Two are already superseded.** `backup_database` and `backup_project` keep
POST handlers at `node_detail_actions_logic.php:206` and `:219`, but nothing
posts them: the backups tab offers only `backup_run`, which has a primitive.
Delete the handlers, the builders, and
`JobResultProcessor::process_backup_database` / `process_backup_project`.

**Five relay operations are live code over dead data.** They are dispatched
dynamically (`FleetService::dispatchJob` line 418, `relay_admin.php:1165`), so
a `build_relay_*` grep understates them — but this deployment has **no live
fleet data**: 1 shard and 3 slots exist, all soft-deleted 2026-07-22, the
slots marked `evicted`. Deleting them removes fleet relay tenant routing
outright. That is the settled consequence of "the relay is not managed"
(`agent_machine_posture_and_relay_converge.md`), not a surprise to discover
later.

**`run_command` is genuinely retired.** 840 rows inside the 14-day window make
it look alive; they are the tail before commit `b958921b` (2026-08-27) removed
`build_run_command`, the console tab and `node_exec.php`. The last row is
12:04 that same day and no emitter survives anywhere in the tree.

### One caveat that stands, and one that closed

No node reports a vocabulary — `mgn_agent_primitives` is empty fleet-wide and
every agent is at 1.10.0, which predates the report. So *all* routing today
falls through to `PRIMITIVE_MIN_AGENT_VERSION`. That is the designed fallback
rather than a fault, but it means every transport decision on this fleet is an
inference from a version string, not a fact from the node.

Where the primitive is chosen it is working: `backup_run` and `list_backups`
have been 100% primitive since 08-29. `apply_update`'s 08-28 split (10
primitive / 18 legacy, two legacy dispatches to every node) resolved on review
as the 1.10.0 delivery event, not a routing fault:

- 14:04 — a legacy wave, correctly routed: `build_apply_update_primitive` did
  not exist yet.
- 17:17:23 — commit `6898839b` wires apply_update onto the channel with no
  version gate.
- 17:19:44 — a primitive wave; all eight came back
  `mjb_agent_outcome = 'refused'`, the fielded agents' compiled vocabulary
  predating apply_update. The agent that ships it (1.10.0) is itself delivered
  by release 0.8.352, published at 17:29:43.
- 17:34 — node 30's legacy apply completes and its agent self-updates to
  1.10.0. At **17:38:13 node 30 runs a PRIMITIVE apply_update to completion**
  (job 7865, `mjb_agent_outcome = 'completed'`) while the other eight, still
  below the floor at dispatch, route legacy in the same instant.

That last line is the version gate working, and a live end-to-end proof of the
primitive on a container node. Today all nine report 1.10.0, the floor is
exactly 1.10.0, so apply_update routes primitive fleet-wide on next dispatch.
Status: **proven on node 30; the other eight route by version floor and have
not dispatched since.** One fleet dispatch closes it.


## Work

**WP1 — Record the exclusion where the plane can see it.** The three rows stay
in `mgn_managed_nodes` for monitoring, but nothing should ever try to dispatch
to them again. Whatever form this takes (a posture column, `mgn_enabled`, or
just this spec), the decision must stop being rediscoverable-as-open.

**WP2 — Audit the `local` step. DONE** — see the section above. Thirteen
operations; two gates, one hard dependency, seven deletions.

**WP3 — Flip `cfg.LocalJobs` false** (behind G1, G2 and the custody runner) on the management node, which pairs to
itself. `main.go:289` already contemplates this: "A control plane paired to
itself runs both job sources in one process."

**WP4 — Delete the local queue**, the `local`/`ssh`/`scp` step types from the
runner, and every `DB` method above. The agent then holds no database
credential.

## What it fixes, and what it does not

**Fixes:** arbitrary root code execution from a database write. The ceiling
drops from `bash -c <anything>` to a fixed vocabulary of named operations with
fixed parameters.

**Does not fix:** a compromised web stack is a compromised plane, and a
compromised plane can still dispatch primitives. The bound is the vocabulary,
not the authority.

**What makes the bound meaningful is now built** (2026-08-30,
`specs/implemented/restore_dispatch_approval_mechanism.md`): a destructive
primitive runs only behind an approval the node issues and verifies itself,
sealed to its own backup recovery key and answered by a human on that node's own
site. Not a passkey, and not sentinel's — sentinel's rungs are a separate case
it still has to settle. The vocabulary limits what can be asked for; this gate
stops the destructive half being asked for at all. A compromised plane can still
dispatch, and now it can dispatch a restore — which is the point of the gate,
not a hole in it.

## Acceptance

See the programme's single acceptance list — the criteria this spec is
responsible for are marked *(item 7)* there.

## Related

- `fleet_ssh_credential_custody.md` — removes SSH credentials from provisioned
  nodes. Independent of this, and does not reduce it: the plane still reaches
  every customer node, by primitive rather than by SSH.
- `project_agent_on_node_migration` — this is that migration's last piece.
