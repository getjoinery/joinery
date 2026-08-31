# The Docker host becomes a paired node, and decommission crosses off SSH

**Status: DRAFT 2026-08-31 — awaiting owner + c3 review. Nothing here is built.**

## What this is for

Removing one site from the shared Docker host currently requires SSH: the
teardown runs at *host* scope (docker rm, volumes, the Apache vhost, the web
root), the agent lives *inside* the container being destroyed, and the host has
no agent identity a primitive could be addressed to. The owner's rule is that
SSH goes away, including for the operator's own manual ceremonies — so
`decommission_node` cannot be retired to a runbook step; it has to move onto
the channel.

The fix is not decommission-shaped. It is **giving the Docker host an agent
identity**, after which decommission is one small destructive primitive — and
the same door is the already-identified blocker for Docker-host certificate
work (`provision_ssl` has *no path at all* to a container's host once the SSH
key dies) and, later, container install on the host. One enrollment serves all
three; only decommission is built in this round.

Scope boundaries already decided elsewhere, restated so this spec cannot creep:

- **A dedicated one-site VPS is never decommissioned through the plane.** The
  owner deletes the instance at the provider and deletes the node record. The
  op exists only for the shared-host container case.
- **Relays are excluded** — `build_decommission_node` already refuses them,
  and relays/DNS boxes are disposable and never agent-managed (settled).

## What already exists (inventory — verified 2026-08-31)

1. **Machine posture is SHIPPED** (agent 1.10.0, R1 of
   `specs/agent_machine_posture_and_relay_converge.md`): the agent starts with
   no site config as a posture, not an error; CLI `join` / `status` /
   `enable` / `disable` exist; `install_agent.sh` has a siteless install path.
2. **The owner decided the host's identity shape** (2026-08-28, that spec
   §7.2): a Docker host is **a plain `ManagedNode` in machine posture** —
   paired, addressed and versioned like any node. No pairing columns on
   `mgh_managed_hosts`, no second job subject, no exactly-one-of enforcement.
   `mgh_managed_hosts` stays what it is: the placement record for containers.
3. **The destructive approval mechanism is built and proven live** (restore on
   joinerydemo, 2026-08-30): agent composes its own statement, seals a
   job-bound challenge to the proven recovery public key, stages it in the
   site's settings table, the operator answers on the site's own admin, the
   agent verifies. The plane is not in the path, enforced as wire shape.
   Plane gate: `node_can_dispatch_destructive()` = paired + agent ≥ the
   restore floor.
4. **The R2 signed bundle is built, green and dormant** (support bundle +
   artifact channel; `SupportBundlePublisher::hasConsumer() === false`). It
   exists precisely to hand a verified script to a machine with no site tree,
   and has never had a live consumer.
5. **`remove_account.sh` 2.1 is the tested teardown** — detects docker vs
   bare-metal, idempotent (`REMOVE_ACCOUNT_OK` / `REMOVE_ACCOUNT_NOTHING`
   markers, nothing-to-remove exits 0).
6. **One finding from §7.2 outlives its deferral and blocks this work:**
   siblings on a host are found two ways that disagree — the `mgn_host` string
   and the `mgn_mgh_host_id` FK, with `next_container_port()` hedging across
   both. The host cannot become a node until one of those is the identity.

## The design

### WP0 — settle the sibling lookup

`mgn_mgh_host_id` becomes the only way a container node names its host;
`mgn_host` string comparison dies everywhere it is used for grouping
(`next_container_port()` included). Without this, the host is paired under one
identity and addressed under the other — §7.2 called this out as the
prerequisite and it still is.

### WP1 — enroll the host

Install the agent on the Docker host via the siteless path, run
`joinery-agent join --management-node=...`, compare the printed fingerprint
against the pending request, approve on the plane. All of that exists.

New: **`mgh_mgn_host_node_id` on `mgh_managed_hosts`** — a nullable FK from
the placement record to the host's own node identity. That is the routing
link: victim container node → `mgn_mgh_host_id` → host record →
`mgh_mgn_host_node_id` → the paired node a host-scope primitive is addressed
to. One column; the job table is untouched (a job's subject is a node, and the
host *is* a node).

The host's node row has no web root and no container name. Fleet surfaces
already tolerate that: `FleetBackupRun` skips web-root-less nodes by design,
and `check_status` degrades sitelessly (memory, load, uptime, and — exactly
what a Docker host should report — `/etc/letsencrypt/live` certificates).
Acceptance below pins that pairing the host alarms nothing.

### WP2 — script delivery: the R2 bundle gets its first consumer

`ScriptSpec` resolves compiled-in script paths against the node's own
`SiteRoot` and verifies them against the signed release manifest. The host has
neither. The §4 bundle (`ToolRoot`) was designed for exactly this machine and
is already built and green — decommission reactivates it and becomes its first
live consumer, shipping `remove_account.sh` as signed bundle content.

Rejected alternative: `go:embed` the script into the agent binary. Catch: it
freezes a copy at agent build time in a second repository, diverging from the
tested script in `maintenance_scripts/sysadmin_tools/` that manual use and
git history keep honest. The bundle keeps one source of truth.

### WP3 — the primitive: `destructive_decommission_site`

One parameter: the victim's **site name**, validated on the host against
`^[a-z0-9_-]{1,50}$`. A name, never a path (the `delete_backup` /
X-Forwarded-Proto rule): every path is composed host-side from compiled-in
patterns. The plane cannot express a path.

The primitive runs the bundled `remove_account.sh <site> -y`, then re-probes
(container, volumes, vhost, web root all absent) and carries the verified /
failed-verify verdict **inside its own result** — the SSH job's separate
verify step folds into the primitive, and the plane's record finalize keeps
gating on it.

### WP4 — approval: the victim approves its own removal

The host has no site, no settings table and no recovery key of its own, so it
cannot stage an approval the way a restore does. But the party whose data is
destroyed has all three, and is alive at decommission time — a site too broken
to render its own admin does not take this path (see scope rule below).

The host agent is root on the machine the container runs on. It reaches the
victim's site directly — it reads the victim's own config through the
container and connects to the victim's Postgres over the docker bridge (the
pg_hba bridge-subnet rule exists for exactly this path; see Q1), **with no
credential in any argv or environment, ever** — and reuses the existing
staging contract:

- read the victim's **proven recovery public key** from where the restore flow
  reads it;
- compose its own statement: site name, host, what will be destroyed
  (container, volumes, vhost, web root), and the victim's **true last
  offsite backup time** read from the victim's own backup history — the fact
  the approver needs most;
- seal the job-bound one-time challenge to that key and stage it in the
  victim's settings table — the same rows the restore flow stages, so **the
  victim's existing pending-approval admin panel renders it unchanged**;
- the operator answers on the victim's own admin with the victim's recovery
  key; the host agent polls the same rows, verifies, and only then tears down.

The plane is not in the path — same wire-shape enforcement as restore: the
primitive declares **no parameter that could carry an approval answer**, and
answers are read only through the container boundary the host itself owns.

Rejected alternative: a two-job choreography where the victim's own agent runs
a consent job first and the host verifies the consent artifact. Catch: consent
issued under one job must bind to a teardown running as another, which
re-invents challenge binding across a job boundary — more machinery for the
same trust, and the victim's agent gains nothing by being involved in its own
demolition.

**Scope rule** (parallel to restore's): a victim whose web tier cannot render
the approval is not "site fine, remove it" — the operator restores or repairs
it first, or the machine-level answer applies (a whole-host teardown is a
provider deletion). There is no unattended path to destroying a site.

### WP5 — plane side: route or refuse, delete the SSH body

`build_decommission_node`:

- victim is a container node → resolve the host's node via the WP0/WP1 chain;
  `has_primitive(host_node, 'decommission_site')` or refuse naming the fix
  ("Pair the host's agent");
- victim is bare-metal → refuse: "Delete the instance at the provider, then
  delete this node record" (the settled answer, now stated where the operator
  acts);
- relay → existing refusal, unchanged.

The scp/ssh step bodies are deleted; `JobResultProcessor` reads the verdict
from the primitive envelope (`extract_api_envelope_data()` first — the known
class of envelope bug); the type-to-confirm guard in
`node_detail_actions_logic` stays. `node_health_probe_test`'s SSH-only
inventory drops from 9 ops to 8.

## Decisions

**D1 — nothing here needs a new owner decision on shape.** The two that looked
like decisions were already made: host-as-plain-node (owner, 2026-08-28) and
manual-provider-deletion for dedicated VPSes (owner, 2026-08-31). The two
rejected alternatives above (embed-vs-bundle, consent choreography) are
recorded with their catches; if either rejection is wrong, say so at review.

## Open questions

**Q1 — RESOLVED 2026-08-31 (verified in the container build).** Peer auth is
deliberately unavailable: the generated `pg_hba.conf` sets
`local all postgres scram-sha-256` (install.sh 2.36), and Dockerfile.template
flips `trust` on only long enough to set the postgres password, with a FATAL
check that it is not left behind. The design is better than the fallback: in
Docker mode the container's Postgres listens on `*` with the bridge subnet
(`172.16.0.0/12`) allowed under scram — a rule that exists for the host-side
path. So the host agent reads the victim's `config/Globalvars_site.php`
through the container (the same parse `config.go` does for the agent's own
config), connects over the docker bridge with the agent's existing `db.go`
code, and `SettingsApproval` runs unchanged on that connection. No psql
subprocess, no credential in any argv or environment, password in memory
only.

**Q2 — RESOLVED 2026-08-31 (one small gap found).** Retiring the host machine
is a provider deletion, like any dedicated machine — no plane mechanism. The
host's own node record retires through the existing `delete_node` /
`purge_node` admin actions; nothing new. The gap: `mgh_managed_hosts` has
**no delete action at all** (the hosts admin is add-only), so "delete the
placement record by hand" currently means a raw DB write. WP1 therefore adds
a host delete action (soft delete, POST button per the actions-are-buttons
rule) that refuses while `count_sites() > 0` (the method already exists) or
while `mgh_mgn_host_node_id` names a live node record — a host is deleted
last, after its containers and its own node identity.

## Sequencing and acceptance

Order: WP0 → WP1 → (WP2 ∥ WP4 design) → WP3 → WP5. Keyless provisioning
(`specs/keyless_provisioning.md`) is independent and can run first or
alongside.

Acceptance:

1. Pairing the Docker host alarms nothing: no backup-plan warning, no health
   false-down, dashboards render the machine-posture node honestly.
2. A live decommission of a scratch container site on the real host:
   dispatched from the plane, approved on the victim's own admin with its
   recovery key, verified-gone in the primitive result, node record finalized.
   The plane's SSH key unused throughout (proven, not assumed — e.g. key
   temporarily moved aside for the run).
3. An unpaired-host dispatch refuses at build time naming the fix; a
   bare-metal dispatch refuses naming the provider-deletion answer.
4. The approval statement shown on the victim's admin carries the true last
   offsite backup time.
5. `remove_account.sh` remains byte-identical between the bundle source and
   `maintenance_scripts/sysadmin_tools/` (gate, not convention).
