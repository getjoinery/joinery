# Fortress — live end-to-end verification runbook

Version: 1.0

Directions for an agent (Claude Code on the dev box) to prove the **Fortress**
security level end to end on a real second deployment fronted by a real off-box
relay shard — the topology dev cannot provide (dev is colocated, single tenant).
This is the functional acceptance layer; the adversarial layer is
`specs/mailbox_security_model_pentest_brief.md`, run after this is green. Parent
plan: `specs/new_site_deployment_fortress_verification.md` (this is its item 5).

Everything here is a lookup or a verification — no design decisions. When a gate
fails, STOP on that gate, diagnose, and report; do not improvise architecture
changes.

## What Fortress is (the claims under test)

Fortress = Private (content sealed at rest, decrypted only in bounded unlock
windows) **plus** two off-box guarantees:

1. **Edge-sealed ingest.** A separate relay VPS runs Postfix + verify milters +
   a Go sealer + WireGuard and no PHP/DB/web/accounts. It seals each inbound
   message to the recipient's key **before** it reaches the app box, so the app
   box never holds inbound plaintext at rest. Mail to a Fortress recipient lands
   **pending-parse** and only unseals+parses inside the owner's unlock window
   (deferred ingest).
2. **Session-gated sending identity.** The Fortress domain's DKIM private key is
   sealed to the owner's vault key and signed in-app at compose time. The domain
   publishes SPF that does **not** authorize the app box and `p=reject; aspf=s;
   adkim=s` DMARC, so **the only path to a DMARC-passing message from the domain
   is a signature only an open unlock window can produce.** Locked, the box holds
   nothing that can read the mail and nothing that can speak as the user.

The two-tenant topology additionally proves the **fleet isolation boundary**:
one shard fronts both dev (tenant A) and the new site (tenant B); B can never
list, read, or ack A's spool, and a map fragment naming another tenant's domain
is rejected at merge.

## Ground rules for the executing agent

- NEVER commit to git. NEVER run DB WRITE statements without the user's explicit
  OK — all test data (domains, aliases, users, vaults) is created through the
  admin UI or existing API endpoints, not SQL.
- Secret handling: VPS root keys, vault passphrases, recovery codes, and DKIM
  private material never appear in the transcript. Reference key files by path;
  pass secrets via files/stdin; describe a secret's source, never its value.
- Neither box's `user1` has passwordless sudo. Root steps on an APP box are
  commands you print for the user to run (suggest the `! <command>` prefix). Root
  steps on the RELAY shard you run yourself over SSH with the provided key.
- The live inbound domain `dev.getjoinery.com` stays untouched — its MX keeps
  pointing at the dev box. Fortress receive tests use a dedicated Fortress domain
  on the **new site** (below); the tenant-isolation gate additionally enrolls dev
  as tenant A on the same shard using a throwaway test domain.
- Use the Playwright browser MCP for admin/webmail UI steps; admin credentials
  are in Claude memory (`reference_credentials.md`). The new site has its own
  superadmin — get its credentials from the user up front.
- **Precondition:** items 1–4 of the parent plan are done — the new site is
  deployed and reachable, a real relay shard exists, and the new site is enrolled
  as a fleet tenant with its MX pointed at the shard. This runbook does NOT stand
  up infrastructure; it verifies Fortress behavior on top of it. Relay standup
  itself follows `specs/implemented/mailbox_relay_vps_test_runbook.md`.

## What the user provides (ask for all of it up front, once)

1. **New-site access:** the site URL and a superadmin login (permission 10).
2. **Shard access:** the relay shard's public IP and the root SSH key path
   (dedicated throwaway key, `user1`-readable, mode 600 — never the main
   all-access key). Confirm the shard already fronts the new site as a tenant
   (parent-plan item 4).
3. **Fortress DNS for the new site's test domain** (`fort.<newsite-zone>` — pick
   a subdomain of a zone the user controls, DNS-only / grey cloud):
   - `MX fort.<zone> → <shard mail hostname>` (prio 10) — inbound rides the shard.
   - `TXT fort.<zone> → v=spf1 -all` — SPF authorizes **no** sender for the bare
     identity domain (Fortress sends carry DKIM, not SPF; strict alignment).
   - `TXT _dmarc.fort.<zone> → v=DMARC1; p=reject; aspf=s; adkim=s` — strict
     alignment is load-bearing (relaxed would let the forwarding subdomain's SPF
     re-arm ambient capability).
   - The **DKIM selector TXT** record — value comes from the in-app setup flow
     (the sealed key's public half); publish it when the flow shows it.
   - Forwarding subdomain: `TXT fwd.fort.<zone> → v=spf1 ip4:<shard IP> -all`
     and PTR on the shard IP → shard mail hostname (SRS envelope + bounce legs
     leave the shard).
4. **A Gmail (or other DMARC-enforcing) mailbox** the user can read, as the
   external correspondent for send/receive/forward legs.
5. **For the isolation gate only:** confirmation that dev may be enrolled as a
   second tenant (tenant A) on the same shard using a throwaway domain
   `fortiso.dev.getjoinery.com` (MX → shard), to be torn down after.

Include a unique marker string in each test body, e.g. `FORTLIVE-<gate>-<random>`.

## Phase 0 — new-site pre-flight (read-only; fix-by-asking)

Verify each on the **new site**; if one fails, ask the user to do the named
action, then re-check.

- **Schema present:** the mailbox, sealed-vault, and relay tables exist
  (`iem_inbound_email_messages`, the vault key tables, `mrl_mailbox_relays`,
  `mgn_managed_nodes.mgn_is_relay`). Missing → user runs `update_database` from
  `/admin/admin_utilities`, which also syncs plugin tables.
- **Plugins active:** `mailbox`, `joinery_ai`, and (for the fleet path)
  `server_manager` are active on the new site.
- **Scheduled tasks ticking:** `PullRelaySpool` and `SyncRelayMap` are ACTIVATED
  on `/admin/admin_scheduled_tasks` and the cron runner's last-run times go fresh.
  Deferred ingest and index fold also ride cron — confirm the runner is live.
- **Tenant enrollment intact:** the new site's relay admin page
  (`/plugins/mailbox/admin/admin_mailbox_relay`) shows the shard enrolled, tunnel
  up (`ping -c2` the tunnel address from the app box), and the health battery
  green. If enrollment is incomplete this is a parent-plan item-4 gap, not a
  Fortress finding — STOP and report.
- **DNS live:** `dig +short MX fort.<zone>` returns the shard mail hostname;
  `dig +short TXT _dmarc.fort.<zone>` returns the strict-alignment policy.

## Phase 1 — Fortress setup flow (guided, no SQL)

Drive the guided setup exactly as a real operator would; the point is to prove
the flow, not to shortcut it.

1. **Create the Fortress domain.** In the new site's mailbox admin, create
   `fort.<zone>` and choose the **Fortress** level at the three-option card
   choice. Confirm the choice presents outcome language only (no mechanism names)
   and that Standard is the default the operator opted out of.
2. **2FA enrollment gate.** Fortress mandates a second factor independent of any
   single passkey. Confirm that adding the Fortress domain blocks at next action
   until TOTP or a second passkey is enrolled; enroll one. Confirm the 2FA cadence
   setting defaulted to `every_login` on the trigger.
3. **Vault ceremony.** If this owner has no sealed vault, run the enroll ceremony
   (passkey with `userVerification: required`), print recovery codes, and confirm
   the flow **requires explicit acknowledgment** of the *lose every device and
   these codes and the mail is gone forever* warning before it dismisses.
4. **Fortress DNS shape.** The setup tab must present, copy-ready: MX at the
   shard, SPF that does **not** authorize the app box, `p=reject; aspf=s; adkim=s`
   DMARC, the DKIM selector record (the sealed in-app key's public half), and the
   forwarding-subdomain records. Publish the DKIM selector TXT now.
5. **Automated-send fork.** Confirm the ceremony asks once whether the domain
   sends automated mail, and that answering "no" yields the all-or-nothing posture
   (nothing leaves the domain while locked). Answer no for this test.
6. **Confirm gate.** Confirm the one-line operational-consequence gate appears:
   *this domain cannot send mail unless you are logged in.*
7. **Setup-tab verify green.** Run the Setup-tab checks. For a Fortress domain the
   *correct* DNS shape inverts: SPF must NOT list the box, DMARC must be strict,
   the DKIM DNS record must match the sealed in-app key's public half, the
   forwarding subdomain's SPF must authorize the shard, and the domain must NOT be
   provider-verified. All green before proceeding.
8. **Aliases.** Create `me@fort.<zone>` (store mode, granted to exactly the
   vault-holding owner — user-sealed) and `fwd@fort.<zone>` (forward mode → the
   external Gmail). After one cron pass, on the shard confirm both appear in the
   tenant's recipients/routing map, with `me@`'s `key_kind = user` and its
   `public_key` differing from the tenant transport key.

## Phase 2 — the Fortress gates

Send real external mail (from the Gmail) unless a leg says otherwise.

**F1 — inbound edge-seals, no plaintext at rest.** Mail `me@fort.<zone>` while
the owner is **logged out**. On the shard, catch the tenant's spool entry: exactly
`<id>.seal` + `<id>.meta`; `grep -r '<marker>' /var/spool/joinery-relay` finds
NOTHING (ciphertext only). `.meta` cleartext JSON carries `recipient`, `key_kind`
`user`, `public_key` (the owner's, not transport), and the `authentication_results`
array. This proves the seal happens off-box, at the edge, before the app box.

**F2 — pull → pending-parse (deferred ingest armed).** Within a cron pass the
message lands in `iem_inbound_email_messages` on the new site as **pending-parse**:
body columns empty/absent, sealed blob retained, pending flag set. The thread list
renders the message with cleartext metadata (time, size, folder) but a neutral
sealed placeholder for sender/subject/preview — the *navigable but not readable*
locked-state contract. No plaintext body exists anywhere on the app box.

**F3 — deferred ingest at unlock.** Unlock the owner's vault in the browser (one
tap; passkey UV required). The login order runs: deferred parse → index fold →
recipe catch-up. Confirm the message becomes fully parsed (subject/body/attachments
sealed per the encryption rules, pending flag cleared) and now renders readable in
the same session. Search the mailbox for `<marker>` — it prompts unlock if the
window lapsed, then returns the message (FTS fold ran).

**F4 — locked send is refused.** Lock the vault (or start a fresh logged-out-then-
password-only session on `sensitive_only`… but this domain is `every_login`, so:
end the unlock window — sign out and back in without unlocking, or wait out the
TTL). Attempt to compose/send from `me@fort.<zone>`. The action must become a
one-tap unlock prompt, NOT a silent send: with no open window there is no
credential on the box that can sign for the domain. Confirm no message leaves
(check Sent and the Gmail — nothing arrives).

**F5 — in-window send, DKIM-signed, DMARC-passing.** Unlock, then compose a
message from `me@fort.<zone>` to the Gmail. It must arrive. In Gmail's
`Authentication-Results` (Show original) verify: `dkim=pass` aligned to
`fort.<zone>`, `dmarc=pass`, and `spf` is NOT the basis of the pass (SPF fails or
is neutral by design — DKIM alone carries DMARC). The signing selector matches the
published DKIM record; the private key was unwrapped in-window from the vault and
never touched disk.

**F6 — origin hidden.** Inspect the received message's `Received:` chain and every
mail-DNS record for `fort.<zone>`: the app box IP appears in NONE of them. Inbound
shows the shard; the compose send leaves via the tenant's outbound provider path
(inbound-only fleet — no shard smarthost), origin absent from the sent chain. Run
the new site's relay health battery: origin-hidden and map-freshness checks green.

**F7 — forward mode + SRS from the shard.** Mail `fwd@fort.<zone>` from an external
sender. The Gmail receives it; verify envelope/Return-Path is `SRS0=...@fwd.fort.
<zone>` (hash case intact), `From:` preserves the original sender's DKIM (survives
forwarding, carries DMARC at the destination), and the leg left the shard IP
(SPF/PTR for `fwd.fort.<zone>` name the shard). This is the one Fortress sending
surface that runs while the owner is logged out — and it never uses the owner's
identity.

## Phase 3 — fleet isolation (the N=2 gate)

This is the security property dev alone cannot prove: two tenants on one shard,
each blind to the other's mail.

**F8 — enroll dev as tenant A.** With the user's OK (Phase-0 provision item 5),
enroll dev on the same shard as a second tenant using `fortiso.dev.getjoinery.com`
(MX → shard, a single Fortress alias granted to a dev vault user). After a cron
pass both tenants appear on the shard with **separate spool directories, separate
chrooted pull accounts, and separate WireGuard peers/tunnel addresses**.

**F9 — spool isolation.** From tenant B's (new site's) pull account, attempt to
list/read/ack tenant A's spool directory. It must be denied by the chroot/account
scope — B cannot see A's `<id>.seal`/`.meta` at all. Send a marked message to each
tenant's Fortress alias; confirm each message appears ONLY in its owner's spool
namespace and each pull consumer ingests ONLY its own.

**F10 — cross-claim rejected at merge.** Attempt to have tenant B push a map
fragment naming tenant A's domain (`fortiso.dev.getjoinery.com`). The shard-side
merge unit must reject the whole fragment against B's domain allowlist and report
the rejection in-band (the `joinery-merge` verb's verdict), installing nothing
from it. This proves the domain-claim boundary is enforced continuously at every
sync, not only at enrollment — the property that stops B from stealing A's mail.

## Phase 4 — rebuild drill

**F11 — rebuild loses no accepted mail.** Fire the shard **Rebuild** (fleet policy
is weekly; this drills it on demand). While the job runs, send mail to
`me@fort.<zone>` from an external MTA. Also arrange a still-deferred forward (point
a second forward at a greylisting/slow destination just before rebuild) so a queue
file is in flight. After rebuild completes (and one map force-resync if health
asks): the inbound message arrives (spool carried across the wipe, or sender
retried through the gap), the deferred forward eventually delivers (queue carried
across), both tenants' relay identities and tunnels re-established, and the
validating restore installed only `<id>.seal`/`<id>.meta` files with correct
per-tenant ownership and no exec bits. No accepted message is lost.

## Phase 5 — exit ramp (trust-is-cheap proof)

**F12 — repoint to self-hosted, nothing else changes.** On the new site, follow
the documented exit ramp: point `fort.<zone>`'s MX at a self-hosted relay (or the
colocated stack) instead of the fleet hostname. Confirm that after DNS propagation
inbound Fortress mail still edge-seals and delivers with **no app-side config
change** beyond the relay target — same stack, same sealing, same guarantees. Mail
queues at senders during the DNS change; nothing is lost. This proves the fleet is
a convenience, not a lock-in.

## Phase 6 — report + teardown

- Report a pass/fail table for F1–F12, one evidence line each (message id, spool
  id, header snippet, `Authentication-Results` line). Failures: include the exact
  command/output and the responsible file/endpoint; apply no fixes without the
  user's OK.
- Teardown (only after the user confirms the report): remove the tenant-A
  isolation fixtures from dev and the shard (`fortiso.dev.getjoinery.com` domain,
  alias, tenant enrollment); on the new site leave the Fortress domain in place if
  the user wants it as an ongoing fixture, else remove it; tell the user which DNS
  records to remove.
- Do NOT move any spec to `specs/implemented/` — the main session/user does that.
- If new live-tier tests were written during the run (relay/Fortress suites), note
  where they landed (`plugins/mailbox/tests/`, `tier: live`) so they enter the
  test estate rather than being one-off.

## Known environment gotchas

- **Cloudflare-proxied A records break SMTP** — the shard mail hostname's A record
  must be DNS-only (grey cloud).
- **Strict alignment is load-bearing** — if DMARC is `aspf=r`/`adkim=r` the
  forwarding subdomain's SPF re-arms ambient capability against the bare identity;
  F5's "SPF is not the basis of the pass" check catches a mis-published policy.
- **WebAuthn virtual authenticator has no PRF** — a headless/virtual authenticator
  cannot derive the vault secret, so the unlock ceremonies (F3/F5) need a real
  platform/hardware authenticator or the project's established WebAuthn test
  harness. Do not conclude "unlock is broken" from a virtual-authenticator failure.
- **`StrictHostKeyChecking=accept-new`** — a rebuilt shard at the same IP with a
  new host key makes SSH refuse; clear the old key from the app user's
  `known_hosts` (public IP and tunnel address) after any rebuild (F11).
- **Deferred-ingest timing** — deferred parse runs at unlock, then index fold,
  then recipe catch-up; give the cron runner a pass before asserting F3, and grep
  the log (`DeferredIngest`, `RelayMapSync`, `PullRelaySpool`) rather than tailing
  the verbose dev log.
- **Provider outbound for compose (F5)** — the fleet is inbound-only, so compose
  leaves via the tenant's own configured outbound provider; if that provider isn't
  configured on the new site, F5 fails for a provider reason, not a Fortress one —
  check the site's mail provider settings first.
