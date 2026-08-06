# Upgrading a relay the platform cannot log in to

## Problem

A relay runs code — a tenant shell, a sealing binary, an rspamd configuration —
that ships with the platform and changes when the platform changes. There is
exactly one control for putting new code on it: **Rebuild**, on the Setup tab's
Relay section, which fires a Server Manager `rebuild_relay` job.

That control covers one of the four ways a relay comes into existence.

| Relay origin | Rebuild button | What happens |
|---|---|---|
| Server Manager managed node | shown | works |
| The customer's own cloud account | shown when Server Manager is active | **dead-ends** |
| `provision_relay.sh` run by hand | same as cloud | same |
| Hosted fleet slot | hidden by design | tenant has no way to ask |

The dead-end is mechanical: `admin_mailbox_relay_dispatch_job()` resolves its
target from `mrl_mgn_managed_node_id`, and `RelayCloudProvisioner::registerRelayRow()`
never sets it — a cloud relay is reached by tunnel address, not through a managed
node. So the button renders, the job builder gets node id 0, and the customer is
told *"Select a managed node to provision onto."* On a deployment without Server
Manager the button does not render at all.

This matters more than a missing button, because **the customer's own cloud
account is the documented primary provisioning path**. The relay most likely to
exist is the relay with no upgrade control.

### Nobody holds a login to these machines

Not the platform, and not the customer either. Three facts in the tree:

- `RelayCloudProvisioner.php:110` — `root_pass` is `random_bytes(20)`, commented
  *never stored*, never shown to anyone.
- `provision_relay.sh:818,820` — `PasswordAuthentication no`,
  `PermitRootLogin prohibit-password`.
- The only key in root's `authorized_keys` is the platform's per-run public half,
  and `eraseCredentials()` wipes the private half at every terminal state.

Nothing anywhere collects a customer SSH key. What survives on the platform side
is the tenant pull key, locked to the `joinery-tenant-shell` forced command:
rsync of its own spool and fragment drop, `joinery-ack`, `joinery-merge`,
`joinery-ping`. It cannot open a shell and cannot sudo anything but the map merge.

So a provisioned cloud relay is a machine with no shell credential in existence.
That is grant-per-act custody working exactly as designed, and this spec does not
weaken it.

## Doctrine

**A relay is reachable only by the tenant forced command — the mail path and the
health ping. No root credential exists, is minted, or is retained. Code changes
reach a relay by replacing the machine's contents, never by logging in.**

Three designs are ruled out by that sentence, and it is worth naming them because
each will look attractive to whoever revisits this:

- **A standing key for the platform.** Any key that survives a run is a credential
  the platform holds, and the whole custody model is that it holds none.
- **A key for the customer, collected at provisioning.** Cheaper and tempting —
  one form field, injected beside the platform's at instance creation. Rejected:
  it makes every relay permanently shell-reachable by whoever holds that key, and
  the security argument for the tenancy model stops being "there is no credential"
  and becomes "the credential is in careful hands."
- **A `joinery-upgrade` verb on the tenant shell.** Upgrading means running new
  code as root, so the verb turns a tenant credential into root on the machine.
  On a shared fleet shard that is a cross-tenant compromise: one tenant's pull key
  would reach every other tenant's spool, the domain allowlists that enforce claim
  boundaries, and the sealing keys. A fleet-of-one has no other tenant to harm,
  but the mechanism would be one boolean away from the shard case, and a security
  boundary that depends on a flag staying correct is not one.

## Change

**An upgrade is a second kind of cloud run: drain the relay, wipe the instance,
build it again.** It reuses the provisioning ceremony end to end — the customer
approves at Linode (or pastes a scoped token), the platform mints a fresh per-run
keypair, does the whole build over that key, and erases it at the terminal state.
No credential outlives the run, exactly as on the first provision.

`rcp_kind` already exists on the run row and already carries an
`allowed_values` list. It gains `'upgrade'`. The state machine gains one state
before the wipe and one after:

```
awaiting_grant → draining → rebuilding → booting → provisioning → done
```

`draining` and `rebuilding` are new. `booting`, `provisioning` and the terminal
handling are the existing code paths, unchanged: `handleProvision()` already runs
the skeleton, adds this deployment as tenant `main`, parses the
`RELAY_WG_PUBKEY=` / `RELAY_PUBLIC_IP=` markers, and re-peers the main box's
WireGuard interface — all of which a wiped relay needs again.

Two touches make it an upgrade rather than a first provision:
`registerRelayRow()` updates the existing relay row instead of inserting one, and
`RelaySsh::forgetHostKey()` runs before the first connection. That helper already
exists, and its docblock already says why: *"a rebuilt relay reuses the tunnel
address."* The design anticipated this.

### Knowing when to offer it

An Upgrade control that is always present is a permanent to-do on a relay that is
fine. `joinery-ping` already returns `provisioned`
(specs/mailbox_relay_scanner_health.md) and nothing reads it. This reads it.

The shipped version is parsed from `RELAY_VERSION` in `provision_relay.sh` — one
regex against the file that is already the source of truth, so there is no second
place to bump. Three states:

- **Current** — no control; a quiet line naming the version.
- **Behind, or unknown** (a relay answering `PONG` predates the version marker) —
  the Upgrade control, with what changes.
- **Ahead** — a note, no control. A relay newer than the deployment means the
  deployment is the thing that is behind.

## Decisions

- **D1 — Who performs the upgrade. RESOLVED: the platform, by replacing the
  machine's contents.** See the Doctrine section for the three rejected
  alternatives. The customer's contribution is one approval, the same one
  provisioning already asks for.

  **A guided copy-paste command was rejected**, and it is worth recording why,
  because it is the obvious answer: it assumes the customer can log in, and on a
  cloud-provisioned relay nobody can. Their only route is a provider console
  recovery drill — on Linode, power off, Reset Root Password, boot, Lish — which
  is a poor thing to discover while your MX is behind.

- **D2 — How the wipe happens. RESOLVED: the provider's rebuild endpoint, not
  destroy-and-create.** `POST /linode/instances/{id}/rebuild` *"shuts down the
  Linode, deletes all of its disks and configuration profiles, then deploys a new
  image"* and accepts `authorized_keys` — which is precisely the shape wanted:
  every byte on the disk replaced, the fresh per-run key injected at the moment
  the new image lands, and no window in which the machine is reachable by a
  credential nobody chose.

  **The instance survives, so its IPv4 does.** That is the load-bearing property.
  Destroy-and-create would hand back a new address, and the address is the one
  thing about a relay that must not move: it is the A record an MX points at.
  Changing it turns a five-minute upgrade into a DNS change plus propagation, on
  the record whose entire job is to be stable.

  `mrl_cloud_instance_id` and `mrl_cloud_provider` are already on the relay row,
  so the platform knows what to rebuild without asking.

  The interface gains one method: `CloudComputeProvider::rebuildInstance()`,
  implemented on `LinodeComputeDriver`. A provider that cannot rebuild in place
  cannot host a relay under this doctrine, and the interface should say so rather
  than degrade to destroy-and-create silently.

- **D3 — Mail sitting on the relay. RESOLVED: drain to empty first, and refuse
  the wipe if anything is held.** This is the sharp edge of the whole design.

  A wipe destroys the tenant spool, and the spool holds sealed blobs the platform
  has not pulled yet. `RelaySpoolConsumer::pull()` already returns exactly the
  counts needed: it pulls, stores, and acks a batch, reporting `stored`,
  `pending`, `held` and `acked`.

  The `draining` state pulls in a loop until a pass returns nothing left. Then:

  - **`held > 0` blocks the wipe.** A held blob is one whose Fortress owner is not
    yet resolvable, deliberately left un-acked so a later pull can store it. Those
    are the blobs a wipe would silently destroy, and they are held precisely
    because something about them is unresolved. The run stops with a plain message
    naming the count; the customer fixes the cause or waits for the grace window
    to age them out.
  - **A drain that will not finish blocks the wipe.** If successive passes stop
    making progress, the run fails rather than proceeding — an upgrade is elective,
    and losing mail to it is not a trade the platform gets to make on the
    customer's behalf.

  **Postfix's deferred queue is accepted as lost**, and this is a real if narrow
  cost. It holds mail Postfix accepted but could not hand to the sealer — a
  transient state, usually empty, and not reconstructible from the platform side.
  The drain cannot reach it, because the tenant credential cannot read the queue.
  The alternative is a verb that can, which is the cross-tenant root the Doctrine
  section rejects. Named here so it is a known cost rather than a later surprise.

  **So the queue is made visible instead**, since an unseen cost cannot be
  weighed. `joinery-ping` gains a `queue` count, the Relay section shows it, and a
  non-zero count blocks nothing but is stated plainly beside the Upgrade control:
  *"3 messages are still queued on the relay and will be lost."* That turns an
  invisible risk into the customer's decision, which is the most the tenancy model
  allows.

  **It is reported only on a fleet-of-one**, and the constraint is not incidental.
  The tenant shell's own comment forbids exactly this — *"never queue depth,
  message counts, spool sizes or anything per-tenant: several deployments share
  this shard, and one tenant's mail volume is not another's to read."* On a shared
  shard the Postfix queue is shared, so its depth is a readout of every other
  tenant's volume. The ping therefore emits `queue` only when `tenant_count` is 1
  — the self-hosted case, where the queue is wholly the asker's. On a shard the
  field is absent and the surface says nothing rather than guessing zero. The
  operator, who has root on the shard, reads the queue directly and needs no help
  from the ping.

  Counted with `postqueue -p`, which is setgid `postdrop` and readable by an
  unprivileged user — so this adds no sudoers rule and no root reach. A relay
  whose `postqueue` fails or is missing reports the field absent, never zero:
  "cannot tell" and "nothing queued" must not render alike when the difference is
  destroyed mail.

- **D9 — Wiping a relay somebody else lives on. RESOLVED: the relay answers, and
  only an explicit yes proceeds.**

  `mrl_is_hosted` is not enough. It records what this deployment's own row
  believes, and a relay that grew a second tenant after provisioning does not
  update anybody's row. A deployment can see only its own tenancy — **the nodes do
  not know about each other** — so nothing on this side can answer the question.

  What a wrong answer costs is total: the rebuild destroys every other tenant's
  account, domain allowlist, WireGuard peer and un-pulled sealed mail, and the
  drain (D3) empties only *this* tenant's spool subdirectory, so nothing else is
  preserved even in passing. One tenant clicking Upgrade would silently destroy
  every other tenant on the box.

  So `joinery-ping` answers **`sole`**: is the asking tenant the only one here?
  The relay already counts its tenants to gate the queue depth; this makes the
  fact explicit rather than leaving callers to infer it from a field's absence.
  It reveals whether the asker is alone, not who or how many — nothing a tenant
  should not know.

  **Anything short of a confirmed count of one answers false**, including an
  unreadable registry. "Cannot tell" must never authorise a wipe.

  Three states, kept distinct end to end:

  - **`true`** — proceed.
  - **`false`** — the control is not rendered, a hand-posted upgrade is refused,
    and the drain refuses again before anything is touched.
  - **`null`** (a relay too old to answer, including every relay answering the
    legacy `PONG`) — the platform cannot prove it is safe and does not decide. It
    asks, carrying an explicit acknowledgement, and refuses without one.

  **The guard is re-asked live at drain time**, not read from the cached health
  answer: a tenant can have been added since the relay last spoke.

- **D4 — Downtime. RESOLVED: acceptable, because SMTP retries.** Port 25 is gone
  for the whole run: the rebuild, the boot, and the build. Several minutes, and on
  a slow provider more.

  That would be unacceptable for a web front end and is routine for a mail
  exchanger. A sending server that cannot connect queues and retries, typically
  for days; the relay's own MX record is unchanged throughout, so there is nothing
  to re-resolve. The user-visible effect of a well-run upgrade is mail arriving a
  few minutes later than it would have.

  The Upgrade control says this in one line before the customer commits, because
  "your mail server will be offline for several minutes" is a fact someone may
  want to act on at 2pm on a Tuesday.

- **D5 — Relays provisioned by hand. RESOLVED: report the version, drive nothing.**
  A relay someone built by running `provision_relay.sh` themselves is a machine
  the platform has no provider token for and no instance id. It gets the version
  line and a plain statement that it is behind, plus the one-line instruction that
  applies: re-run `provision_relay.sh` on it.

  This is not the copy-paste D1 rejects. The difference is that this customer
  demonstrably *can* log in — they built the box — and the platform is not
  pretending to hold a credential or minting a download to make up for one.

- **D6 — What happens to Rebuild. RESOLVED: it stops dead-ending, and stays.**
  Server Manager relays keep it. There the platform genuinely holds root through
  the Go agent, which is a different custody model with its own justification, and
  `rebuild_relay`'s carry-aside and validating restore are the right tool. The fix
  is to stop rendering the button on a relay it cannot target: shown only when
  `mrl_mgn_managed_node_id` resolves to a live node, with the cloud Upgrade control
  taking its place otherwise.

- **D7 — Hosted fleet slots. RESOLVED: tell the tenant, and give the operator the
  control.** A tenant cannot wipe a shard they share with strangers. The slot shows
  its shard's version and a plain line saying the relay is operator-managed. No
  request button: a request the operator has no console to see is a message into
  nothing.

  **D7's tenant-facing half makes a promise on the operator's behalf**, so the
  operator's half ships with it or the feature is incoherent — the tenant is told
  to wait for something nobody can do. A shard IS a managed node
  (`mfs_mgn_managed_node_id`), so the operator already holds root SSH and
  `rebuild_relay` already builds. The fleet console gains a version column and a
  per-shard Rebuild control; nothing new is invented.

  **The operator cannot use `joinery-ping`, and this asymmetry is the design.**
  The operator's deployment is not a tenant of its own shards — shards are
  provisioned `skeleton_only`, with no tenant account for the operator — so there
  is no forced-command credential to ping with. The version is stamped instead from
  the job's marker block, which costs no script change: step 5 of
  `build_provision_relay()` already echoes `RELAY_WG_PUBKEY=` and
  `RELAY_PUBLIC_IP=`, and `RELAY_VERSION=$(sudo cat /opt/joinery-relay/version)`
  joins them. A new `mfs_provisioned_version` holds it.

  A per-pass SSH read of every shard's version was rejected as the wrong trade: one
  connection per shard per pass, to detect a drift only a hand-reprovisioned shard
  can cause. Such a shard reads stale until its next job, and that is acceptable —
  being job-managed is what makes a shard a shard rather than somebody's VPS.

- **D8 — Comparing versions. RESOLVED: `version_compare()`, never string
  comparison.** `RELAY_VERSION` is a dotted string, and `'2.10' < '2.9'` is true as
  text — a relay would report itself current one minor bump after the tenth. A
  version that does not parse reads as unknown, which offers the upgrade.

## Files

| File | Change | ~Lines |
|---|---|---|
| `plugins/mailbox/includes/RelayVersion.php` | **New** — shipped version from `provision_relay.sh`, running version from the cached health answer, the comparison (D8) | 55 |
| `plugins/mailbox/provisioning/provision_relay.sh` | 2.2 → 2.3: `joinery-ping` emits `queue`, on a fleet-of-one only (D3) | 15 |
| `plugins/mailbox/data/relay_cloud_provision_class.php` | `'upgrade'` in `rcp_kind`'s `allowed_values`; `rcp_mrl_mailbox_relay_id` naming the relay being upgraded | 10 |
| `plugins/mailbox/includes/RelayCloudProvisioner.php` | `handleDraining()` and `handleRebuilding()`; kind-aware `registerRelayRow()` (update, not insert); `forgetHostKey()` before the first connection | 120 |
| `includes/cloud_compute/CloudComputeProvider.php` | `rebuildInstance()` on the interface (D2) | 12 |
| `includes/cloud_compute/LinodeComputeDriver.php` | `rebuildInstance()` — `POST /linode/instances/{id}/rebuild` | 20 |
| `plugins/mailbox/includes/relay_admin.php` | `relay_upgrade` action opening an upgrade run; gate Rebuild on a live managed node (D6) | 50 |
| `plugins/mailbox/includes/relay_section.php` | Upgrade control, version line, downtime warning (D4), hand-built guidance (D5), hosted-slot notice (D7) | 80 |
| `plugins/mailbox/data/mailbox_relay_class.php` | `provisionedVersion()` reading the cached health answer; `readHealth()` parses `queue` — absent stays absent, never 0 (D3) | 25 |
| `plugins/mailbox/tasks/MailboxRelayReconcile.php` | Advance upgrade runs alongside provision runs in phase 4 | 10 |
| `plugins/mailbox/data/mailbox_fleet_shard_class.php` | `mfs_provisioned_version` (D7) | 5 |
| `plugins/server_manager/includes/JobResultProcessor.php` | Stamp `mfs_provisioned_version` from the `RELAY_VERSION=` marker (D7) | 15 |
| `plugins/mailbox/admin/admin_mailbox_fleet.php` | Version column + per-shard Rebuild (D7) | 25 |
| `plugins/mailbox/docs/overview.md` | Upgrade paths by relay origin; why no root credential exists | 45 |
| `plugins/mailbox/tests/relay_upgrade_test.php` | **New** — tier `safe`, `env: any`, `needs: []` | 110 |

**Two schema changes need `update_database`**: `rcp_kind`'s widened
`allowed_values` plus `rcp_mrl_mailbox_relay_id`, and `mfs_provisioned_version`.

**No signed download route, no new signing key, no new job type.** An earlier
draft of this spec carried all three to support a customer-run copy-paste; the
wipe-and-reinstall doctrine removes the need for every one of them.

## Tests

- Version comparison: current, behind, ahead, and unknown (a `PONG` relay) each
  produce the right control — **unknown offers the upgrade, ahead never does**.
- The shipped version parses out of `provision_relay.sh`, asserted against the
  file, so a bump that changes the declaration's shape fails here.
- **`version_compare()` semantics (D8):** `2.10` is ahead of `2.9`, not behind —
  the off-by-one-decade bug a string comparison ships silently.
- **A held blob blocks the wipe (D3)**, asserted against a drain result carrying
  `held > 0`. This is the check that stands between an elective upgrade and
  destroyed mail, so it is the one test that must never be quietly relaxed.
- **A drain making no progress blocks the wipe (D3)** rather than looping forever
  or proceeding.
- **A relay reporting `sole: false` is never wiped (D9)** — no control rendered, a
  hand-posted upgrade refused, and the drain refuses again. Asserted against a
  real second tenant staged on disk, because this is the check that stands between
  one tenant and every other tenant's mail.
- **`sole` absent reads NULL, never true (D9)**, including from a legacy `PONG`
  relay and from a non-boolean value — `"false"` as a string is truthy in PHP, so
  a sloppy cast would turn a shared relay into a sole one.
- **A relay with no readable tenant registry reports `sole: false`** — "cannot
  tell" may not authorise a wipe.
- **The drain re-asks the relay live (D9)** rather than trusting the cached health
  answer: a tenant can have been added since it last spoke.
- **The ping emits `queue` on a fleet-of-one and omits it on a multi-tenant shard
  (D3)**, asserted by running the extracted tenant shell against both tenant
  counts — the shard case is a cross-tenant leak, not a cosmetic difference.
- **An absent `queue` renders as unknown, never as zero (D3)** — a relay whose
  `postqueue` is missing must not read as "nothing will be lost".
- The rebuild call carries the fresh per-run public key and the relay's own
  instance id, and credentials are erased at both terminal states.
- **An upgrade run updates the existing relay row and mints no second one** — a
  duplicate relay row would split the alias map and the spool pull between two
  identities.
- `forgetHostKey()` runs before the first post-wipe connection; without it the
  reused tunnel address fails host-key verification.
- Rebuild renders only for a relay whose `mrl_mgn_managed_node_id` resolves to a
  live node, and the Upgrade control renders for a cloud relay — the dead-end this
  spec exists to remove.
- A hand-built relay offers guidance and no control (D5); a hosted slot offers
  neither (D7).
- **The shard version parses out of the job marker block (D7)**, and a shard whose
  job never emitted one reads as unknown rather than current — an absent marker
  must never render as "up to date".

## Open

Nothing outstanding.
