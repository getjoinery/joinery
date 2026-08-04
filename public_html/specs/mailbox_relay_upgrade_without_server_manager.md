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
Manager the button does not render at all, and there is no in-product way to
upgrade the relay by any route.

This matters more than a missing button, because **the customer's own cloud
account is the documented primary provisioning path**. The relay most likely to
exist is the relay with no upgrade control.

### Why this cannot be fixed by pointing Rebuild at the tunnel

The obvious repair — reach the cloud relay over SSH the way the map push and
spool pull already do — does not work, and the reason is a security property
worth stating plainly rather than discovering later.

**The platform holds no root credential to a cloud relay.** Provisioning injects
a per-run keypair at instance creation (`authorized_keys` = `rcp_ssh_public_key`),
sets a random `root_pass` that is never stored, and `eraseCredentials()` wipes the
sealed private half at every terminal state — grant-per-act custody, working
exactly as designed. What survives is the tenant pull key, and that key is locked
to the `joinery-tenant-shell` forced command: rsync of its own spool and fragment
drop, `joinery-ack`, `joinery-merge`, `joinery-ping`. It cannot run a shell, and
it cannot sudo anything but the map merge.

So there is nothing to log in with. Any design that assumes a root session on
these relays is assuming a credential the platform deliberately threw away.

### Why not simply let the tenant shell upgrade itself

The tempting alternative — a `joinery-upgrade` verb plus a second sudoers rule,
mirroring how `joinery-merge` already triggers one root action — is rejected, and
not narrowly.

Upgrading means running new code as root. A verb that fetches and executes a
bundle turns the tenant credential into root on the machine. **On a shared fleet
shard that is a cross-tenant compromise**: one tenant's pull key would reach every
other tenant's spool, the domain allowlists that enforce claim boundaries, and the
sealing keys. The tenancy model exists precisely to prevent that, and a self-upgrade
verb would hand it back for the convenience of skipping a copy-paste.

A self-hosted fleet-of-one has no other tenant to compromise, so the objection is
weaker there — but the mechanism would be one flag away from the shard case, and
a security boundary that depends on a boolean staying correct is not one.

## Change

**The customer runs the upgrade, and the platform makes that a guided one-line
action rather than a documentation exercise.**

The Relay section grows an **Upgrade** control for every relay it cannot reach
with a job. It does not attempt a connection. It renders a single copy-paste
command the customer runs as root on the relay — on a machine they already have
access to, using a credential the platform never sees:

```
curl -fsSLo /tmp/joinery-relay.tgz 'https://<this site>/relay-bundle?expires=…&sig=…' \
  && echo '<sha256>  /tmp/joinery-relay.tgz' | sha256sum -c - \
  && sudo tar xzf /tmp/joinery-relay.tgz -C /tmp/joinery-relay \
  && cd /tmp/joinery-relay && sudo bash provision_relay.sh mx.example.com
```

The digest is printed inside the command, so the download verifies itself and a
tampered bundle stops before anything runs as root. The hostname and the
`smarthost` argument are filled in from the relay row and
`mailbox_relay_outbound_mode`, because getting either wrong silently changes what
the relay is — omitting `smarthost` on a smarthost relay closes the submission
listener that compose sends leave through.

**Only the skeleton run is needed.** The tenant shell, the rspamd configuration,
the contract digest and the version marker are all written by the skeleton path,
and every part of that path that would disturb a live relay is guarded: the
WireGuard keypair and `wg0.conf` are written only if absent (so tenant `[Peer]`
blocks survive), `routing.json` likewise, and the tenant registry and spool are
untouched. `add-tenant` does not need re-running. This is what makes a
customer-run upgrade safe to hand over: it is idempotent, and the destructive
carry-aside dance `rebuild_relay` performs is not required for a code upgrade.

### Knowing when to offer it

An Upgrade control that is always present is a permanent to-do item on a relay
that is fine. `joinery-ping` already returns `provisioned`
(specs/mailbox_relay_scanner_health.md) and nothing reads it. This reads it.

The shipped version is parsed from `RELAY_VERSION` in `provision_relay.sh` — one
regex against the file that is already the source of truth, so there is no second
place to bump. Three states:

- **Current** — no Upgrade control; a quiet line naming the version.
- **Behind, or unknown** (a relay answering `PONG` predates the version marker) —
  the Upgrade control, with what changes.
- **Ahead** — a note, no control. A relay newer than the deployment means the
  deployment is the thing that is behind.

## Decisions

- **D1 — Who performs the upgrade. RESOLVED: the customer, guided.** The
  platform holds no root credential to these relays and should not acquire one;
  see the two rejections above. What the platform owes is not access but
  precision: the exact command, with the hostname and smarthost argument already
  correct, and a digest that makes the download self-verifying.

  **Minting a fresh cloud grant was rejected.** It is the obvious symmetry with
  provisioning, and it does not work: a provider API token manages instances, it
  does not open a shell. Verified against Linode's API rather than assumed:

  - There is **no endpoint that injects an authorized key into a running
    instance.** `authorized_keys` is accepted at instance creation, which is past.
  - `POST /linode/instances/{id}/rebuild` does accept `authorized_keys` — and
    *"shuts down the Linode, deletes all of its disks and configuration profiles,
    then deploys a new image."* That is a destroy-and-restore of the whole
    machine, not a code upgrade.
  - **`POST /profile/sshkeys` is the trap.** It is titled "Add an SSH key" and
    will look like the answer to whoever revisits this. It manages keys stored on
    the *account*, for injection into *future* instances. It touches no running
    machine's `authorized_keys`.
  - The remaining route is the Lish console — a human typing at a serial console.
    Not automatable, and it is the customer's console, not the platform's.

  So a fresh grant buys a token that can bill, resize, reimage and destroy the
  relay, and still cannot open a shell on it. Paying for a credential ceremony
  that cannot deliver a root session is worse than asking for a copy-paste — and
  the token it mints would be strictly more dangerous than the thing it replaces.

- **D2 — How the relay gets the bundle. RESOLVED: a signed download route.**
  A short-lived HMAC-signed URL on this deployment, served without a session,
  the same shape as `/uploads` signed URLs (`docs/file_signed_urls.md`) and
  validated in `serve.php` before any ownership gate. The relay has outbound
  internet (it installs packages), so this works whether or not the tunnel is up
  — which matters, because a relay that needs upgrading may be one whose tunnel
  is the problem.

  Fetching over the WireGuard tunnel was rejected: the relay listens and the main
  box dials out, the main box's web server is not bound to the tunnel interface,
  and it would make the upgrade path depend on the link most likely to be broken
  when someone reaches for it.

  Reusing `File::mintSignedUrl()` was rejected: it is bound to a `File` row, and
  the bundle is built from the filesystem on demand, not uploaded. The route
  borrows the signing contract, not the model.

  **It also gets its own key**, `relay_bundle_signing_key` — 32 random bytes,
  SecretBox-sealed in `stg_settings`, minted by `update_database` the way
  `File::provisionSigningKey()` already is. Borrowing the file-URL key would
  destroy the one property that key's own docblock claims for it: *"deleting the
  row rotates the key, invalidating all outstanding signed URLs and nothing
  else."* Shared, rotating it would break in-flight relay upgrades, and rotating
  it for a relay reason would break every outstanding attachment link. Key
  separation is the existing doctrine here; this follows it rather than being the
  first exception.

  **TTL is one hour**, matching the reader's inline-image precedent, and the
  Relay section re-mints on every page load. The command is copied by a person
  who may paste it into a terminal on another machine, look up a root password,
  or hand it to a colleague — five minutes would turn an ordinary interruption
  into a confusing failure. An expired link is not a dead end: reload the page.

  **The bundle is shipped source, not a secret.** A signed link discloses
  `provision_relay.sh` and the relay-sealer Go source for its TTL. That is the
  same code already sitting on every relay this deployment provisioned, so the
  route is not a new disclosure — but it is a deliberate one, recorded here so
  nobody later assumes the signature is protecting a secret. It is protecting
  bandwidth and intent, not confidentiality.

- **D7 — Comparing versions. RESOLVED: `version_compare()`, never string
  comparison.** `RELAY_VERSION` is a dotted string, and `'2.10' < '2.9'` is true
  as text — a relay would report itself current one minor bump after the tenth.
  A version that does not parse reads as unknown, which offers the upgrade.

  **The route serves exactly one artifact** — the same
  `relay-sealer` + `provision_relay.sh` tarball `build_provision_relay()` already
  packages — built fresh per request from the provisioning directory. It is not a
  general file-serving endpoint, and the signature covers the expiry so a link
  cannot be extended.

- **D3 — What happens to Rebuild. RESOLVED: it stops dead-ending, and stays.**
  Server Manager relays keep it; it is the right tool when the platform genuinely
  has a root session, and its carry-aside/restore is worth the downtime for a
  machine being rebuilt. The fix is to stop rendering it on a relay it cannot
  target: the button is shown only when `mrl_mgn_managed_node_id` resolves to a
  live node, and the Upgrade control takes its place otherwise.

- **D5 — A lighter path for Server Manager relays. RESOLVED: add
  `upgrade_relay`, beside Rebuild rather than replacing it.**

  **What a bare skeleton run actually costs, read off the script rather than
  assumed:**

  - It **never touches a tenant spool.** `SPOOL_ROOT` is created and chmod'd as a
    parent directory; the only `rm -rf` of a tenant spool is in `remove_tenant`,
    which the skeleton path does not call. The tenant registry is likewise
    untouched.
  - `ufw --force reset` **opens rather than closes.** The reset disables ufw
    before the deny-default and the allows are re-applied, so port 25 stays
    reachable throughout — the window is a few seconds of extra exposure on a box
    listening on 25/22/51820 only, not an outage.
  - The one real interruption is `systemctl restart postfix` at the end. Seconds,
    in-flight connections retried by the sender, queue preserved on disk.

  So `rebuild_relay`'s two sixty-second flushes, its carry-aside of the spool and
  deferred queue, and its validating restore are protecting against a machine
  being **replaced**. For a code upgrade they are pure cost: two minutes of
  deliberately closed port 25, plus moving every sealed blob out and back for no
  reason — a copy that can itself fail.

  **This is the same finding D1 rests on.** If a bare skeleton run were not safe
  on a live relay, the customer-run copy-paste would be unsafe too, and the whole
  change would need rethinking. It is safe, and one verification carries both.

  The builder is nearly free: `build_provision_relay()` already takes
  `skeleton_only`, which skips the add-tenant step and its pull-key precondition.
  `build_upgrade_relay()` delegates to it with that flag set. Post-processing
  re-polls health so the version line refreshes on completion instead of waiting
  for the next reconcile pass.

  Rebuild stays, because "this machine is sick, redo it" is a real and different
  act. The version comparison decides which is offered as the obvious one.

- **D4 — Hosted fleet slots. RESOLVED: tell the tenant, and stop there.**
  A tenant cannot upgrade a shard they share with strangers, and should not be
  offered a control that implies otherwise. The slot shows its shard's version
  and a plain line saying the relay is operator-managed and upgraded by the
  operator. No request button: a request the operator has no console to see is a
  message into nothing.

  The operator's half is D6.

- **D6 — The operator's view of their shards. RESOLVED: in scope, on the fleet
  console.**

  **D4 makes a promise on the operator's behalf** — it tells a tenant their relay
  is operator-managed and will be upgraded by the operator. Shipping that while
  the operator has no way to see a shard's version or upgrade it would be
  internally incoherent: the tenant is told to wait for something nobody can do.
  That, not convenience, is why this belongs here.

  It is also nearly free now that D5 exists. A shard IS a managed node
  (`mfs_mgn_managed_node_id`), so the operator already holds root SSH through the
  Go agent, and `upgrade_relay` already builds. The fleet console gains a version
  column and a per-shard Upgrade control; nothing new is invented.

  **The operator cannot use `joinery-ping`, and this asymmetry is the design, not
  a gap.** The operator's deployment is not a tenant of its own shards — shards
  are provisioned `skeleton_only`, with no tenant account for the operator — so
  there is no forced-command credential to ping with. The tenant learns the
  version through the health ping over its restricted credential; the operator
  learns it through root SSH on a managed node. Different credential, different
  mechanism, same fact.

  **The version is stamped from the job's marker block**, which costs no change to
  `provision_relay.sh`: step 5 of `build_provision_relay()` already echoes
  `RELAY_WG_PUBKEY=` and `RELAY_PUBLIC_IP=` for the result processor to parse, and
  `RELAY_VERSION=$(sudo cat /opt/joinery-relay/version)` joins them. A new
  `mfs_provisioned_version` on the shard row holds it.

  A per-pass SSH read of every shard's version file was rejected as the wrong
  trade: it costs one connection per shard per pass to detect a drift that can
  only be caused by someone reprovisioning a shard by hand, outside the job
  system. A shard reprovisioned by hand shows a stale version until its next job,
  and that is acceptable — being job-managed is what makes a shard a shard rather
  than somebody's VPS.

## Files

| File | Change | ~Lines |
|---|---|---|
| `plugins/mailbox/includes/RelayVersion.php` | **New** — shipped version from `provision_relay.sh`, running version from the cached health answer, the comparison, and the bundle digest | 70 |
| `serve.php` | Signed `/relay-bundle` route: verify expiry + HMAC, stream the freshly built tarball, no session (D2) | 35 |
| `plugins/mailbox/includes/relay_admin.php` | Mint the signed URL + digest; render the command; gate Rebuild on a live managed node (D3) | 60 |
| `plugins/mailbox/includes/relay_section.php` | The Upgrade control, the version line, and the hosted-slot notice (D4) | 70 |
| `plugins/mailbox/data/mailbox_relay_class.php` | `provisionedVersion()` reading the cached health answer | 15 |
| `plugins/server_manager/includes/JobCommandBuilder.php` | `build_upgrade_relay()` — delegates to `build_provision_relay()` with `skeleton_only` (D5) | 15 |
| `plugins/server_manager/includes/JobResultProcessor.php` | `process_upgrade_relay()` — re-poll health so the version refreshes on completion (D5); stamp `mfs_provisioned_version` from the `RELAY_VERSION=` marker (D6) | 30 |
| `plugins/mailbox/data/mailbox_fleet_shard_class.php` | `mfs_provisioned_version` (D6) | 5 |
| `plugins/mailbox/includes/relay_admin.php` (operator half) | Per-shard `upgrade_shard` action; version into the shard view vars (D6) | 30 |
| `plugins/mailbox/admin/admin_mailbox_fleet.php` | Version column + Upgrade control per shard (D6) | 25 |
| `plugins/mailbox/docs/overview.md` | Upgrade paths by relay origin; why there is no root credential | 45 |
| `plugins/mailbox/tests/relay_upgrade_test.php` | **New** — tier `safe`, `env: any`, `needs: []` | 110 |

**Two schema additions need `update_database`** before either surface works:
`mfs_provisioned_version` on the shard row, and the `relay_bundle_signing_key`
setting minted at the same moment `File::provisionSigningKey()` is.

**No new job type and no new scheduled task.** The upgrade is an act the customer
performs; the platform's whole contribution is a correct command and an honest
statement of whether one is needed.

## Tests

- Version comparison: current, behind, ahead, and unknown (a `PONG` relay) each
  produce the right control — **unknown offers the upgrade, ahead never does**.
- The shipped version parses out of `provision_relay.sh`, asserted against the
  file, so a version bump that changes the declaration's shape fails here.
- The rendered command carries the relay's own hostname, and carries `smarthost`
  exactly when `mailbox_relay_outbound_mode` says so — the argument whose omission
  silently closes the submission listener.
- The digest in the command matches the bytes the route serves.
- **`version_compare()` semantics (D7):** `2.10` is ahead of `2.9`, not behind —
  the off-by-one-decade bug a string comparison ships silently. An unparseable
  version reads as unknown and offers the upgrade.
- The bundle route signs with `relay_bundle_signing_key` and **not** the file-URL
  key: rotating one must leave the other's links working.
- Signed-URL validation: a good link serves, an expired one does not, a link with
  an altered expiry does not, and **no link at all serves nothing** — the route
  must never fall through to an unauthenticated download.
- Rebuild renders only for a relay whose `mrl_mgn_managed_node_id` resolves to a
  live node, and the Upgrade control renders for every relay it does not — the
  dead-end this spec exists to remove.
- A hosted slot offers neither control.
- **`upgrade_relay` carries no add-tenant step and no carry-aside (D5)**, asserted
  against the built step list — the two things that make it lighter than
  `rebuild_relay` are exactly the two a regression would quietly restore.
- A Server Manager relay offers both Upgrade and Rebuild; the version comparison
  decides which reads as the obvious one.
- **The shard version parses out of the job marker block (D6)**, and a shard whose
  job never emitted one reads as unknown rather than as current — an absent marker
  must never render as "up to date".

## Open

Nothing outstanding. Every item raised against this spec has been resolved into a
decision above, except one that turned out to need no design at all: the
`MailboxRelayReconcile` docblock claiming destroy-kind cloud runs exist was simply
wrong — nothing sets `rcp_kind` to anything but `'provision'`, the model forbids
it, and the provisioner never branches on it. Corrected in place rather than
carried here.
