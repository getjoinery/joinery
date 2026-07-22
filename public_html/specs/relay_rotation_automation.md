# Relay Rotation Automation

## What this is

The mail relay is meant to be a disposable box: freshly provisioned every week
so a compromised or drifted relay has a short life. Today that cadence is
policy, not automation — a human opens the Setup tab, starts a cloud provision,
waits, repoints DNS, and deletes the predecessor. This spec makes rotation a
scheduled act the platform performs end to end: provision the replacement,
prove it healthy, move DNS, keep the old box until every message is drained
off it, then destroy it.

Everything here runs on the deployment that OWNS the relay (the relay row
lives in the served site's own `mrl_mailbox_relays` table — see
`docs/../plugins/mailbox/docs/overview.md`). Nothing involves the control
plane.

## What already exists (build on it, don't duplicate)

- **`RelayCloudProvisioner`** — the full provision pipeline: create instance
  (`instanceLabel()` naming: `{hostname-slug}-{run_id}`), boot, install,
  register the relay row, forget the stale SSH host key, mint transport keys.
  Runs advance via the `AdvanceRelayCloudProvisions` task.
- **`CloudComputeProvider`** (`includes/cloud_compute/`) — `createInstance`,
  `getInstance`, `deleteInstance`, and `setReverseDns` are all already in the
  interface; `LinodeComputeDriver` implements them.
- **`RelaySsh::forgetHostKey()`**, peer replacement in
  `provision_relay_main.sh`, helper convergence in `install_email.sh` — the
  rebuild-identity defects are already fixed.
- **Health checks** — WireGuard handshake, map freshness, spool draining, and
  SMTP probing all exist in `InboundEmailHealth` / `InboundEmailSetupCheck`.

What does NOT exist: a DNS client, a rotation trigger, drain semantics for a
second relay, and credentials that survive unattended.

## Decisions

### D1 — Standing scoped credentials (a deliberate departure from grant-per-act)

Cloud provisions today use grant-per-act custody: a short-lived token sealed
on the run row and erased at terminal state. An unattended weekly rotation
cannot work that way — nobody is present to grant. The decision: rotation uses
**standing tokens, sealed at rest with SecretBox** (`includes/SecretBox.php`),
stored in settings. NOT vault-gated — vault unlock requires owner presence,
and the whole point is that nobody is present (see the CLI VaultUnlock
limitation).

The blast radius is bounded by scoping, and the admin UI must say so where the
tokens are entered:

- **Compute token** (Linode): scoped to Linodes read/write only.
- **DNS token** (Cloudflare): scoped to `Zone.DNS Edit` on the one zone
  holding the relay hostname.

Settings: `mailbox_relay_compute_token_sealed`, `mailbox_relay_dns_token_sealed`
(both store SecretBox ciphertext; the admin form writes plaintext in, seals on
save, never renders the value back out).

`RelayCloudProvisioner` gains a token source: a run is constructed either with
a per-act grant (existing flow, unchanged) or with the standing compute token.
Same pipeline after that line.

### D2 — DNS through a provider abstraction, Cloudflare first

New `includes/cloud_dns/` mirroring `cloud_compute/`:

```
DnsProvider (interface)
    findZoneId(string $hostname): string        // longest-suffix zone match
    getARecord(string $zone_id, string $name): ?array   // {id, ip, ttl, proxied}
    updateARecord(string $zone_id, string $record_id, string $ip): void
CloudflareDnsDriver (implements it, bearer-token construction like LinodeComputeDriver)
```

Drivers are pure API wrappers — no models, no persistence. The relay A record
is always DNS-only (an MX target cannot be proxied); `updateARecord` preserves
the record's existing TTL and proxied=false, changing only the IP. Setting
`mailbox_relay_dns_provider` (default `cloudflare`) selects the driver.

Cutover changes exactly one record — the relay hostname's A record. MX records
at the mail domains point at the hostname and are never touched.

### D3 — Rotation is a state machine, not a script

New table `mrr_mailbox_relay_rotations` (data class `MailboxRelayRotation`),
one row per rotation attempt:

| column | meaning |
|---|---|
| `mrr_status` | `provisioning → verifying → cutover → draining → done`, or `failed` |
| `mrr_old_mrl_relay_id` / `mrr_new_mrl_relay_id` | the two relay rows involved |
| `mrr_rcp_provision_id` | the provision run doing the build |
| `mrr_cutover_time` / `mrr_drain_until` | when DNS moved; earliest predecessor destruction |
| `mrr_message` | last transition detail (human-readable) |

A new `RotateRelay` scheduled task advances the machine one cheap step per
cron pass (the `AdvanceRelayCloudProvisions` pattern — long work stays in the
provision task it already lives in):

1. **start** — eligibility gate: topology is `self_hosted`, rotation enabled,
   both tokens present, no rotation already in flight, no manual provision run
   in flight, and the active relay's `mrl_create_time` is older than the
   cadence. Creates the rotation row and a provision run seeded from the
   standing compute token.
2. **provisioning** — waits on the provision run. Run failed ⇒ rotation
   `failed` (the provisioner already destroys its half-made instance).
3. **verifying** — the new relay row exists (born enabled). Gates, all against
   the NEW box: fresh WireGuard handshake, alias map pushed and current, spool
   round-trip (pull runs clean), and the SMTP submission probe against the new
   public IP directly. Any gate failing for longer than
   `BOOT_TIMEOUT_SECONDS`-class patience ⇒ `failed` + cleanup.
4. **cutover** — Cloudflare update of the relay hostname's A record to the new
   IP, then `setReverseDns` on the new instance (providers require the forward
   record first — retry across passes until accepted). Stamps
   `mrr_cutover_time`, sets `mrr_drain_until = cutover + grace`.
5. **draining** — both relays keep working (see D4). Ends when
   `mrr_drain_until` has passed AND the most recent pull from the OLD relay
   returned zero messages. Then: destroy the old instance via
   `deleteInstance`, soft-delete the old relay row, mark `done`.

**Failure policy.** Before cutover, failure is safe by construction: DNS still
points at the old box, which never stopped serving; the new instance is
destroyed and the rotation marked `failed`. After cutover there is no
automatic DNS rollback — the old box is kept, the rotation parks in `failed`,
and the owner is notified. A failed rotation does not retry itself; the next
cadence tick starts a fresh one only after the failed row is acknowledged
(dismissed) in the admin UI.

### D4 — Draining is a first-class concept

The platform currently assumes one relay: `MailboxRelay::active()` returns
the LOWEST-id enabled row, and `PullRelaySpool` / `SyncRelayMap` act on that
single row. During DNS propagation the old box still receives mail; if
nothing pulls its spool, that mail dies with the box. Changes:

- **`MailboxRelay::active()` orders by id DESC** — the newest enabled row is
  THE relay. Setup checks, doctrine consequences, and smarthost sends follow
  the successor from the moment it registers. (Between registration and
  cutover the MX still resolves to the predecessor; setup checks may show a
  transient mismatch — the rotation status row on the Setup tab is the
  explanation. Do not special-case the checks.)
- **`PullRelaySpool` and `SyncRelayMap` iterate ALL enabled, non-deleted
  relay rows**, not just `active()`. Pulling both spools is what makes the
  drain real; pushing the map to both keeps a mid-drain alias change from
  bouncing on whichever box a stale resolver still reaches.
- No new status column on the relay row. "Draining" is simply an enabled row
  that is not the newest — the rotation row records the semantics.

### D5 — Cadence and enablement

Rotation is OFF until the owner turns it on; there is nothing to derive it
from (standing tokens cannot be conjured).

| setting | default | meaning |
|---|---|---|
| `mailbox_relay_rotation_days` | `0` | rotate when the active relay is older than this; `0` disables |
| `mailbox_relay_rotation_grace_hours` | `48` | minimum predecessor lifetime after cutover |

Declared in `plugins/mailbox/plugin.json` `settings` (no migration).

## Admin surface

All on the existing Relay tab (`relay_admin.php`), self-documenting controls,
no explainer prose:

- **Rotation card**: cadence select (Off / weekly / biweekly / monthly →
  writes days), the two token fields (write-only, sealed on save, presence
  shown as a badge), and a **Rotate now** button (creates a rotation row
  immediately; same machine, skips only the age gate).
- **Status**: the in-flight rotation's state and `mrr_message`; a `failed`
  rotation shows red with a Dismiss action.
- **History**: recent rotation rows (old IP → new IP, cutover time, drain
  count pulled off the predecessor, duration).

Setup-tab provisioner row `relay_rotation` (in `plugin.json` `provisioners`):
green when rotation is enabled and the active relay is younger than the
cadence; yellow when disabled; red when a rotation is `failed` or the relay
has outlived the cadence with rotation enabled.

Owner notification: one email per terminal state (done or failed) via
`EmailSender`, carrying the same facts as the history row.

## Integration points (complete list — decide once)

| touchpoint | change |
|---|---|
| `MailboxRelay::active()` | order DESC (newest enabled row wins) |
| `PullRelaySpool` | loop all enabled relays; report per-relay counts |
| `SyncRelayMap` | loop all enabled relays |
| `RelayCloudProvisioner` | token source parameter (grant OR standing) |
| `InboundEmailHealth::checkRelaySpoolDraining` | "a pull ran" means the newest relay; predecessor pulls tracked on the rotation row |
| `relay_admin.php` | rotation card, status, history, Rotate now, Dismiss |
| `plugin.json` | 2 settings + `relay_rotation` provisioner + task registration |
| new: `includes/cloud_dns/` | `DnsProvider` + `CloudflareDnsDriver` |
| new: `plugins/mailbox/data/mailbox_relay_rotation_class.php` | rotation runs |
| new: `plugins/mailbox/tasks/RotateRelay.php` (+ `.json`) | the state machine driver |

Explicitly unchanged: MX records, `provision_relay_main.sh`,
`install_email.sh`, the fleet code paths (hosted mode never rotates via this
machine), grant-per-act custody for owner-initiated provisions.

## Tests

- `plugins/mailbox/tests/relay_rotation_state_test.php` (tier `db`) — the
  machine end to end with a fake compute/DNS driver pair: eligibility gates
  (each one individually blocks), verify-gate failure destroys the new
  instance and leaves the old relay active, cutover updates exactly one
  record and preserves TTL/proxied, drain refuses to end while the old spool
  is non-empty, `done` leaves exactly one enabled relay row.
- `plugins/mailbox/tests/relay_drain_selection_test.php` (tier `safe`) —
  `active()` DESC ordering; pull/map enumeration returns every enabled row
  and skips soft-deleted ones. Real rows, not hand-built facts (the unloaded
  `->count()` class of bug).
- `includes/cloud_dns/` driver: unit test of zone longest-suffix matching and
  of the update payload (IP changes; TTL and proxied echoed back unchanged).

## Documentation

Update `plugins/mailbox/docs/overview.md` (relay section): rotation cadence,
the drain model, token scoping, and the failure policy — written as current
state only. This spec, not the docs, holds the rationale.

## Out of scope

- Rotating fleet shards (hosted mode) — different custody, different owner.
- Multi-relay topologies (more than one live relay by design).
- Automatic DNS rollback after cutover.
- Rotating the WireGuard tunnel address or SSH pull identity — those are the
  fixed identity slots the rebuild fixes already handle.
