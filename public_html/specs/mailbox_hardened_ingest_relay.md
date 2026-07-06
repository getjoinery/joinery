# Mailbox — Hardened Ingest Relay (Seal at the Edge, Hide the Origin)

**Status:** Draft / awaiting implementation
**Version:** 1.2
**Builds on:** `specs/mailbox_encryption_at_rest.md` (user key hierarchy, sealed
envelopes, login-time index fold) and `specs/mailbox_outbound_send_protection.md`
(forwarding subdomain, relay-side SRS).
**Amends:** the *Ingest Pipeline* section of the encryption-at-rest spec — for
MX-hosted mail, full parsing/filtering/attachment-splitting moves from receive
time to the next login (see *Deferred Ingest*).

## Goal

Two problems, one architecture:

1. **The plaintext-arrival exposure.** Email arrives at the MX in readable form —
   TLS terminates there, and the MX must read the message to route it. Encryption
   at rest seals mail immediately *after* that moment, but an attacker with code
   on the box stands *at* that moment and reads every new message as it lands
   (e.g. trigger a bank password reset, read the confirmation code). Today that
   moment lives on the full Joinery application server — PHP, web UI, plugins,
   database, admin surface — the largest possible attack target.
2. **The MX signpost.** MX records cannot be proxied by Cloudflare; they must
   name a real, reachable IP. Today that IP is the Joinery box itself, so DNS
   publicly advertises exactly where the mail archive lives.

The fix for both: put a **minimal, hardened, disposable relay** at the public MX.
It accepts mail, verifies it, **seals it to the user's public key at the moment
of acceptance**, and spools ciphertext. Joinery dials out to the relay to
collect sealed blobs; its own IP appears nowhere in public DNS (web traffic
stays behind Cloudflare, which can proxy HTTP). The plaintext-arrival moment
still exists — it must, this is SMTP — but it now lives on a box that runs
almost no code and holds nothing else.

This is **trust relocation and attack-surface reduction, not elimination**.
State it plainly: whoever controls the relay (or the VPS provider hosting it)
can read new mail in transit. What changes is what that position is worth and
how hard it is to take.

## Threat Model

**What compromise of each box now yields:**

- **Joinery box (logged out):** sealed archive (unreadable), sealed incoming
  blobs (unreadable), no sending identity (per the outbound spec), no route to
  the plaintext moment — mail is ciphertext before Joinery ever holds it. The
  attacker must wait for an active session and win that race. The bank-reset
  attack from a compromised Joinery box is closed.
- **Relay box:** mail in its transit window, from compromise until rebuild —
  **both directions**: new inbound mail at acceptance, and compose sends
  passing through as smarthost (DKIM-signed but plaintext SMTP) — and nothing
  else. No archive, no history, no database, no credentials to
  Joinery (the WireGuard peering is initiated by Joinery and grants access only
  to the spool), no sending identity key. At rest its disk holds sealed blobs
  plus the spool's cleartext *metadata* (recipient, message-id, references,
  sizes, verdicts) until Joinery pulls and acks — bounded, but stated. The
  relay is rebuilt from its provisioning script in minutes and is treated as
  disposable.

**Explicitly accepted residuals:**

- **The relay's plaintext moment.** The relay sees every message in cleartext
  at acceptance. Its defense is smallness: an MTA, a sealing step, WireGuard —
  no PHP, no web UI, no plugins, no DB, no user accounts. Small enough to
  audit, boring enough to keep patched, cheap enough to burn and rebuild.
- **VPS-provider trust.** The relay host's provider occupies the position an
  inbound provider (Mailgun) occupies in provider mode — except the operator
  controls the box, it holds no other credentials, and it stores nothing
  readable at rest.
- **Active-session exposure on Joinery** — unchanged from the encryption-at-rest
  spec.
- **IMAP-source mailboxes** are out of scope: mail pulled from a remote IMAP
  provider (e.g. gmail.com feeds) arrives at Joinery directly via `ImapIngestor`;
  its plaintext exposure is the remote provider's anyway. Routing IMAP polling
  through the relay would put mailbox credentials on the relay and is
  deliberately not done.

## Architecture

```
Internet ──SMTP:25──▶ RELAY (public MX IP)          JOINERY (no public mail IP)
                      Postfix + verify milters       web UI behind Cloudflare
                      RBL / recipient map                    │
                      forward-mode aliases + SRS             │ WireGuard,
                      seal-to-public-key ──▶ spool ◀──pull───┘ outbound-initiated
                      outbound smarthost ◀──compose sends────┘
```

1. **MX / DNS**: the MX record and the mail hostname's A record point at the
   relay. SPF for the forwarding subdomain (outbound spec) names the relay IP.
   Joinery's origin IP is in no mail-related DNS record.
2. **Receive**: the relay runs the receiving stack that `install_email.sh`
   currently builds on the Joinery box — Postfix, opendkim (verify) + opendmarc
   milters stamping `Authentication-Results`, RBL restrictions, and recipient
   validation at SMTP time against a synced alias map (preserving
   `reject_unmatched` semantics; no backscatter).
3. **Seal**: the accepted message is piped, exactly as today, to a small sealing
   program instead of the PHP handler: extract the **header-level operational
   metadata** (envelope recipient, `Message-ID`, `In-Reply-To`/`References`
   (thread-key inputs), `Date`, size, the `Authentication-Results` verdict),
   then seal the **entire raw message** as one opaque blob with
   `crypto_box_seal` directly to the recipient's public key. No DEK indirection
   at this layer: the blob is opened exactly once (at deferred ingest, then
   re-sealed with the real per-message DEK and row-binding AD per the
   encryption-at-rest envelope), so a single sealed box to a single recipient is
   equivalent and is strictly less code on the box whose smallness *is* the
   security property. Cleartext metadata + ciphertext blob go into the spool.
   Plaintext is never written to the relay's disk.
4. **Forward-mode aliases** are executed on the relay at receive time (it holds
   the plaintext moment regardless, and forwarding must work while the user is
   logged out): SRS rewriting (`SRSRewriter` logic, relay-side) with the
   forwarding-subdomain envelope, then relay onward. Store-and-forward aliases
   both seal and forward.
5. **Transport**: Joinery initiates a WireGuard tunnel to the relay and pulls
   spooled items on a short poll (or long-poll), acking each after durable
   store. The relay never dials in; no inbound port on Joinery is involved.
6. **Outbound**: compose sends go out through the relay as smarthost over the
   same tunnel — otherwise every sent message's `Received:` chain would leak
   Joinery's IP. DKIM signing stays in-app per the outbound spec; the relay
   only transports. The relay's PTR/HELO present the mail hostname.

## The Load-Bearing Design Choice: a Dumb Relay + Deferred Ingest

The relay parses **headers only**. It does not split MIME parts, extract
attachments, match filter rules, or seal fields individually. The alternative —
a relay that runs the full ingest pipeline and hands Joinery a ready-to-store
lean record — was considered and rejected: full MIME parsing is precisely the
kind of complex, historically bug-prone code that must not run on the box whose
compromise means reading mail, and it would drag rules, attachment logic, and
more state onto the trusted edge.

The single-user model is what makes the dumb relay free: **nothing needs the
parsed form until the user looks at it, and the user can only look in-session.**

**Deferred ingest** therefore runs at the next login, alongside the
encryption-at-rest spec's index fold:

1. While logged out, Joinery stores each pulled item as: cleartext operational
   metadata (recipient, message-id header, thread key computed from the relayed
   header inputs, received time, size, auth verdicts) + the sealed raw blob, in
   a **pending-parse** state. Threading and unread counts work; subject, sender,
   body preview, and attachments do not exist yet as fields.
2. At login, before the index fold, the backlog of pending-parse messages is
   processed: unseal the raw blob (needs the session key), run the full existing
   pipeline — parse MIME, run filters/rules on plaintext, split attachments to
   private `File`s, seal fields and attachment bytes under a fresh per-message
   DEK per the encryption-at-rest spec — then discard the raw blob. The message
   leaves pending-parse and folds into the FTS index.
3. Store-mode filter/rule actions (move, mark, tag) thus apply at login rather
   than receive time. For a single-user mailbox this is invisible: the rules
   have always run by the time any mailbox view renders.

## The Relay Fronts Every MX-Hosted Domain

MX records are per-domain, so a mixed deployment would leak the origin: if the
Fortress domain's MX points at the relay but a Standard domain's MX still
points at the Joinery box, DNS advertises the box's IP anyway and the hiding
is defeated. The rule is therefore: **once a relay exists, it is the MX for
all of the deployment's hosted domains. The security level controls where mail
is *sealed*, never where it is *routed*.**

- **Fortress domains**: sealed at the relay to the **user's** public key — only
  a session can open it (the core of this spec).
- **Standard and Private domains**: pass through. The relay spools their mail
  sealed to a **transport keypair whose secret key Joinery holds ambiently** —
  same sealing machinery, different recipient. Joinery opens each item at pull
  time and runs today's ingest exactly as those levels expect (plaintext store
  for Standard; receive-time filters + field-level sealing for Private). The
  transport wrapping exists so the relay's disk never holds plaintext at rest
  for *any* level; it is transit protection, not a security-level guarantee.

Two side effects worth naming:

- **A relay-fronted Joinery box stops running an MTA.** With every domain
  fronted, Postfix/opendkim/opendmarc are decommissioned on that deployment's
  main box and port 25 is closed — the machine holding the data no longer
  exposes a mail listener to the internet. The relay doesn't just hide the
  box; it shrinks it. **rspamd stays**: deferred ingest still scores each
  message through rspamd's controller interface at parse time — the milter
  mode is simply unused. Decommissioning it with the MTA stack would silently
  leave Fortress mail unscored.
- **Relay compromise now also sees Standard/Private transit mail** — the same
  position any MTA hop occupies, and those levels never promised otherwise.
  The Fortress guarantee is unchanged.

### Colocated mode is permanent, not a transition state

The platform supports **two receive topologies forever**, chosen per
deployment:

- **Colocated (the default, and the cost floor):** the MTA stack runs on the
  Joinery box itself, exactly as `install_email.sh` builds it today. Zero
  extra infrastructure. Standard and Private are fully functional here —
  encryption at rest never needed a second box. The tradeoffs are simply the
  ones this spec exists to offer a way out of: the box's IP is in mail DNS,
  and the plaintext-arrival moment lives on the application server.
- **Relay-fronted (the opt-in upgrade):** one extra small VPS buys the hidden
  origin, the edge-sealed ingest, and the shrunken main box — and it fronts
  all domains per the rule above.

Fortress is the only thing that *requires* the relay — its guarantees are
unbuildable without it, so choosing Fortress for the first time leads into
relay provisioning as part of the guided setup (per the security-levels
spec). An operator may also run a relay with no Fortress domain purely
for origin-hiding. Both topologies are maintained code paths, not a migration:
`install_email.sh` (colocated) and `provision_relay.sh` (relay) are siblings
sharing the same provisioning lineage.

## Integration Points That Change

- **`provisioning/install_email.sh`** — unchanged in role: it remains the
  colocated-mode installer. `provision_relay.sh` is its sibling for the relay
  (adding WireGuard, the sealing program, the spool, and the alias-map sync
  endpoint). On relay-fronted deployments only, the Joinery box stops running
  Postfix/opendkim/opendmarc for MX mail.
- **`utils/inbound_email_handler.php` (pipe)** — replaced on the MX path by the
  relay's sealing program; on Joinery, a spool-pull consumer stores sealed
  items instead.
- **`InboundEmailRouter`** — receive-time routing logic (store vs forward)
  splits: forward execution moves relay-side; store-path ingest becomes the
  pull consumer plus the login-time deferred parse. `readAuthResults()` gains
  the relay as a trusted verdict source (it forwards the milter-stamped
  results as metadata).
- **`SRSRewriter`** — logic runs relay-side; the secret syncs to the relay.
- **Alias map sync** — a compact recipient/routing map (alias → store/forward +
  destinations) pushed to the relay over the tunnel whenever aliases change;
  the relay holds no database connection.
- **`InboundEmailSetupCheck` / `InboundEmailHealth`** — checks re-target the
  relay (MX resolves to relay, relay port 25, relay milters, tunnel up, spool
  draining, alias map fresh) and add "Joinery IP not present in mail DNS" —
  a deployment-wide check across **all** hosted domains once a relay exists,
  not a Fortress-only one.
- **`MailboxSender` / outbound transport** — smarthost setting points at the
  relay over the tunnel.
- **Encryption-at-rest spec pipeline** — its ingest ordering ("filters before
  sealing") holds *inside deferred ingest at login* rather than at receive
  time, for MX-path mail.

## Schema Changes (via data-class `$field_specifications`)

- Message row: pending-parse state flag; sealed raw blob reference (blob stored
  as a private `File` or bytea — decide by size during implementation); relay
  spool id (for ack/dedup on pull).
- Relay bookkeeping: last successful pull, alias-map sync version — settings or
  a small status table for the health checks.

## Deployment & Provisioning Automation

The relay must be trivial to create, because its disposability is the security
property: "rebuild it in minutes" is only true if rebuilding is a button, not
an afternoon. Two layers, both shipped with the plugin:

**1. `provisioning/provision_relay.sh` — the self-contained installer.**
A sibling of `install_email.sh`, shipped in `plugins/mailbox/provisioning/`,
runnable as root on a fresh minimal Debian VPS. Idempotent, one argument (the
mail hostname), zero interactive prompts. It installs and configures: Postfix +
verify milters + RBL restrictions, the sealing program, the spool, WireGuard
(generating the relay-side keypair and printing the public key), Let's Encrypt
for the mail hostname, unattended security upgrades, key-only SSH, and a
default-deny firewall (25/tcp, WireGuard UDP, SSH). It ends by printing the
three values Joinery needs (relay public IP, WireGuard public key, spool
endpoint) — usable standalone by an operator who never opens the admin UI.

**2. Admin-driven provisioning — the actual one-click.**
The platform already operates remote servers: the server_manager plugin's job
queue and Go agent SSH into managed nodes and execute multi-step jobs, with
health reporting on the dashboard. Relay provisioning reuses that machinery as
a job type: in the Fortress setup flow the operator pastes a fresh VPS's IP and
root SSH key, and a `provision_relay` job pushes `provision_relay.sh`, runs it,
exchanges WireGuard keys, pushes the user's sealing public key and the alias
map, registers the relay as a managed node (health dot on the dashboard), and
hands back to the Setup tab, whose checks (MX at relay, port 25, milters,
tunnel up, spool draining) verify the result. The same job type powers a
**Rebuild relay** button — point it at a fresh VPS and the incident response
is: click, wait, update DNS. Nothing is lost in a rebuild: unacked mail is
still queued at senders' MTAs (standard SMTP retry), and acked mail is already
on Joinery. **Rebuild is also routine, not just incident response**: the same
job is schedulable (e.g. monthly), rebuilding in place on the same VPS and IP
— no DNS change, senders retrying through the minutes of downtime as above.
Disposability only defends if it happens; a scheduled rebuild turns "we could
burn it down" into "persistence on this box has a shelf life."

**What stays manual** (the Setup tab's existing detect-instruct-verify
boundary): buying the VPS, the MX/A DNS records, and the PTR record at the VPS
provider. Copy-ready values for all three appear inline, as with every other
DNS step.

- Joinery side: pull consumer under the scheduled-task system; WireGuard peer
  config written by the provisioning job.
- Degradation: if the relay is down, senders' MTAs queue and retry for days
  (standard SMTP); if the tunnel is down, the relay spools sealed blobs until
  Joinery reconnects. Neither loses mail.

## Relationship to the Client-Side Crypto Alternative

The encryption-at-rest spec defers a browser-decryption model whose weakness
was that the server still saw plaintext at ingest. This relay is the missing
half: with sealing at the edge, if decryption later moves to the browser,
plaintext of stored mail never exists on Joinery at any point in its life —
the relay's acceptance moment becomes the only server-side plaintext in the
system. Deferred ingest would then need to move client-side too (the browser
would do the parse/re-seal), which is the same fork already recorded there.

## Documentation to Update

- `plugins/mailbox/docs/overview.md` — the self-hosted receiving
  architecture section describes the relay as where the MTA stack runs, the
  sealed spool + pull transport, deferred ingest, and relay-side forwarding
  (current-state only).
- `docs/mobile_apps.md` / anything documenting mail DNS — mail hostname records
  point at the relay.

## Open Items to Confirm During Implementation

- **Sealing program form — decided: a small Go binary.** The deciding factor is
  format compatibility: the system is committed to libsodium `crypto_box_seal`
  to the user's X25519 key, so `age` is out (incompatible format would fork the
  crypto into age-at-relay / libsodium-in-app). Go over a hand-written C
  program because it is memory-safe — this program parses untrusted SMTP input
  on the internet-facing box — ships as one static binary onto a minimal Debian
  VPS, and Go is already in the provisioning lineage (`server_manager`'s Go
  agent), not a new toolchain. `golang.org/x/crypto/nacl/box.SealAnonymous` is
  wire-compatible with libsodium's sealed box. The program does exactly one
  thing: stream stdin → `SealAnonymous` to the recipient public key (from the
  synced alias map) → atomic-rename the sealed entry into the spool → fsync →
  exit code to Postfix only after the fsync. Never buffers plaintext to disk.
  ⟨VERIFY at build⟩ the Go `SealAnonymous` output opens with PHP
  `sodium_crypto_box_seal_open` (round-trip test in CI).
- **Spool/pull protocol — decided: filesystem spool, pulled over SSH/rsync on
  the WireGuard interface.** The dumber option: no bespoke network daemon on the
  relay, so its network surface stays exactly Postfix + WireGuard + key-only
  SSH. The sealing program writes each item as `<spoolid>.seal` + `<spoolid>.meta`
  (cleartext metadata sidecar) via write-tempfile-then-atomic-rename, so a pull
  never sees a partial entry. Joinery, over the tunnel, `rsync`s new entries
  (copy only — **never** `--remove-source-files`, which would delete before
  durability), writes them durably with an idempotent store keyed on spool id
  (re-pulling an un-acked-but-stored item is a no-op = dedup), then deletes the
  remote entries it has durably stored — the delete-after-store **is** the ack.
  A short poll (~15–30s) under the scheduled-task system; email tolerates the
  interval, and no long-poll/push machinery is needed.
- Whether the relay needs its own rate limiting for forward-mode aliases (the
  per-alias/per-domain limits currently enforced in the app).
- Alias-map sync freshness vs. `reject_unmatched`: a newly created alias must
  not bounce mail during the sync gap — decide push-on-change plus a periodic
  reconcile.
- Confirm thread-key computation can run from relay-extracted headers alone
  (it groups on `iem_thread_key`, built from Message-ID/References — verify no
  body-derived input).
- Spam filtering beyond SMTP-time RBL: relay-side (more edge code) vs deferred
  to login (with the index fold). Default: deferred.
- Whether relay provisioning requires the server_manager plugin active (job/agent
  machinery) or `provision_relay.sh` standalone is the floor when it is not —
  and whether a stretch goal of creating the VPS itself via a provider API
  (Hetzner/DO token) is worth it, making even the "buy a VPS" step one-click.
