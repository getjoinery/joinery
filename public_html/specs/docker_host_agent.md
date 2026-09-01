# The Docker host becomes a paired node, and decommission crosses off SSH

**Status: BUILT 2026-09-01 (WP0–WP6, both repos; agent 1.15.0, artifact
rebuilt and signed; db gate 133/133). Reviewed READY FOR BUILD 2026-08-31 —
c3 findings (B1–B5, Q1–Q2) folded; Q3 resolved by the owner (rebuild-in-place
from backup, in the container-install round). REMAINING before this moves to
implemented/: the live acceptance run — enroll the real host's agent
(siteless install + join + link on the host edit page), publish the release
carrying the panel (0.8.357) and the bundle, upgrade the victim fleet, then
acceptance 1–2 (pairing alarms nothing; a live scratch decommission with the
SSH key proven unused). Acceptance 3–6 are pinned by tests and green.**

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

1. **Machine posture is SHIPPED** (in agent 1.10.0; the tree is at 1.14.0 —
   R1 of
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

### WP3 — the primitive: `decommission_site`

Vocabulary name `decommission_site`, class destructive — the `destructive_`
prefix lives in the filename only, per convention (`restore_database` /
`destructive_restore_database.go`).

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
to render its own admin does not take this path (see the open question below).

**This is a generalization of the restore approval design, not a reuse of its
code** (c3 review, 2026-08-31). What exists is bound to the agent's own
machine by construction: `SettingsApproval` is built once from the agent's own
config and states its own contract — "this machine's own operator, through
this machine's own site, using this machine's own recovery key"; the agent has
no docker capability in any non-test code; and `parseGlobalvars` extracts
dbname/user/password/secret_box_key but no host or port. WP4 therefore builds:

- **Victim location and connection.** The victim's config is read from the
  host filesystem (overlay/volume path) — **never `docker exec`**: a teardown
  must not execute a binary inside a possibly-compromised container as its
  first act. The connection goes to the victim's **published loopback port**
  (`-p 127.0.0.1:{DB_PORT}:5432`, install.sh — every site container publishes
  one), which the userland proxy delivers sourced from the gateway, exactly
  what the pg_hba bridge-subnet scram rule admits. No bridge-IP discovery.
  The victim's DB credential is held in memory for the connection only —
  never at rest, never in argv or environment.
- **A per-victim approval instance.** The approval type takes a connection
  and an identity instead of assuming its own; statement composition moves
  out of the agent's own primitive dispatch path.
- **A decommission-scoped approval surface on the victim's site.** The
  pending-approval panel is restore-hardcoded today: copy ("A restore is
  waiting for your approval"), `approve_restore`/`decline_restore` actions,
  the `restore_approval_request`/`restore_approval_answer` setting names, and
  the `joinery-restore-approval:` HKDF context. Staging a decommission into
  those rows would render consent copy for the wrong act — the binding would
  hold cryptographically, but informed consent is the point of the ceremony.
  So: decommission-scoped setting names, its **own HKDF context string**
  (domain separation is the restore spec's own rule), and panel copy that
  says what is true: this site will be **destroyed, permanently**.
- **The statement's load-bearing fact, with provenance.** The last offsite
  backup time is read as the newest row in the victim's own backup history
  that records a **completed upload** — and the statement says exactly that:
  it is the site's own record of its last completed offsite upload, not the
  bucket's testimony (the host holds no bucket credential and must not).

The operator answers on the victim's own admin with the victim's recovery
key; the host agent polls the staged rows, verifies, and only then tears
down.

**Trust boundary, named:** the host parses container-controlled bytes — the
victim's config file and the victim's DB rows. The config parse stays a
narrow regex; every victim-sourced string rendered in the statement or logged
is treated as untrusted display data.

**Sequencing consequence:** the victim's admin can only render an approval
its code knows about, so the platform release carrying the decommission panel
must be **on the victim** before the host can stage one — the plane refuses
dispatch to a victim below that core version, with the fix in the message.

**Interaction with `deferred_destructive_approval.md` (unbuilt):** this WP is
its second consumer. Its "re-derive the statement on answer" step means
re-reading the victim's DB at answer time; and until it lands, the hold-open
window deafens the **host's** queue — an hour during which no cert or install
work runs on that host. Acceptable for now, recorded so the deferred design
accounts for it.

The plane is not in the path — same wire-shape enforcement as restore: the
primitive declares **no parameter that could carry an approval answer**, and
answers are read only through the container boundary the host itself owns.

Rejected alternative: a two-job choreography where the victim's own agent runs
a consent job first and the host verifies the consent artifact. Catch: consent
issued under one job must bind to a teardown running as another, which
re-invents challenge binding across a job boundary — more machinery for the
same trust, and the victim's agent gains nothing by being involved in its own
demolition.

**Scope rule** (parallel to restore's): there is no unattended path to
destroying a site — a victim that cannot render its own approval does not
take this path. What removes such a victim is Q3 below, and this spec does
not pretend the rule covers it.

### WP5 — plane side: route or refuse, delete the SSH body

`build_decommission_node`:

- victim is a container node → resolve the host's node via the WP0/WP1 chain;
  `has_primitive(host_node, 'decommission_site')` or refuse naming the fix
  ("Pair the host's agent");
- victim is bare-metal → refuse: "Delete the instance at the provider, then
  delete this node record" (the settled answer, now stated where the operator
  acts);
- relay → existing refusal, unchanged;
- victim has pending or running jobs → refuse: finish or cancel them first.
  A dispatch that goes through also marks the victim **quiet**
  (`mgn_agent_quiet_time` — the posture exists for agents being switched
  off), so the plane stops feeding a machine scheduled for demolition and
  the container agent's death is an expected quiet, not a job that died
  strangely.

**Version floors, both sides.** `decommission_site` gets its own
`PRIMITIVE_MIN_AGENT_VERSION` entry and membership in
`DESTRUCTIVE_PRIMITIVES` — the restore floor must not vouch for it (a 1.14.0
agent passes `node_can_dispatch_destructive()` today while lacking this
primitive entirely). And per the standing old-executor tripwire rule, a
pinned test proves an older agent **refuses the op loudly** rather than
no-opping it as success.

The scp/ssh step bodies are deleted; `JobResultProcessor` reads the verdict
from the primitive envelope (`extract_api_envelope_data()` first — the known
class of envelope bug); the type-to-confirm guard in
`node_detail_actions_logic` stays. `node_health_probe_test`'s SSH-only
inventory drops from 9 ops to 8.

### WP6 — doctrine bookkeeping

`agent_management_first_principles.md` is the programme's only status source,
and two of its claims are amended by this spec (edits land with it, not
after):

- Its "deliberately never becomes a primitive" list includes
  `decommission_node` "(a provider API call on the customer's grant, not a
  script on a dying box)" — and `fleet_ssh_credential_custody.md` WP3 repeats
  it. That doctrine **stands for whole machines** and this spec does not
  touch it. What it never contemplated is a container site on a shared host,
  where the script runs on the host — which is not dying. Both passages gain
  that carve-out and a pointer here.
- The custody table's "a managed node never holds a database credential"
  gains the explicit transient exception: a host-posture agent, while staging
  a decommission approval, holds the **victim's** DB credential in memory for
  the life of the connection — never at rest, never its own.

## Decisions

All decisions are made: host-as-plain-node (owner, 2026-08-28),
manual-provider-deletion for dedicated VPSes (owner, 2026-08-31), the
doctrine carve-out in WP6 (owner, 2026-08-31), and Q3 below (owner,
2026-08-31). The two rejected alternatives above (embed-vs-bundle, consent
choreography) are recorded with their catches.

**Q3 (RESOLVED by owner 2026-08-31 — the c3 review's one standing question):
what removes a wedged container once SSH is gone?** A container whose web
tier is down cannot
approve its own removal — and cannot approve a restore either, since both
ceremonies render on the same admin. The dedicated-machine answer (provider
deletion) would take the healthy siblings with it. This is exactly the
broken-thing corner a removal tool exists for, so the spec must not wave at
it.

Resolution: the wedged container takes **rebuild-in-place from
backup** — remove the wreck and immediately reinstall `from_backup`, the
container analog of the trade the owner already made for restore ("site down
⇒ rebuild-and-restore; no approval, because there is no node yet to ask").
Data at risk is bounded to since-last-backup on a site that is already
broken, and the site comes back — this is recovery, not removal, which is
why it does not need the consent ceremony that permanent removal does. It
belongs to the host-agent **container install** round (the third consumer of
this door), not to decommission. Until that round is built, the corner is a
recorded gap handled manually — a gap this spec narrows but does not close.

## Open questions

**Q1 — RESOLVED 2026-08-31 (verified in the container build).** Peer auth is
deliberately unavailable: the generated `pg_hba.conf` sets
`local all postgres scram-sha-256` (the install.sh 2.36 changelog entry
records the hardening), and Dockerfile.template
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
**no delete action at all** (the hosts admin is add-and-edit), so "delete the
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
   bare-metal dispatch refuses naming the provider-deletion answer; a victim
   below the panel-carrying core version refuses naming the upgrade; a victim
   with pending or running jobs refuses.
4. The approval panel says the site will be destroyed permanently — never
   restore copy — and the statement carries the site's own record of its
   last completed offsite upload, labeled as such.
5. A pre-`decommission_site` agent refuses the op loudly (pinned test — the
   old-executor tripwire rule).
6. `remove_account.sh` remains byte-identical between the bundle source and
   `maintenance_scripts/sysadmin_tools/` (gate, not convention).
