# Mailbox relay — live VPS end-to-end test runbook

Version: 1.0

Directions for an agent (Sonnet/Opus on the dev box, Claude Code) to run the
acceptance gate in `specs/inbound_email_hardened_ingest_relay_executor.md` § 11.2
against a real throwaway VPS, plus live re-proof of the regressions fixed in
`specs/mailbox_relay_fix_pack.md`. Everything here is a lookup or a verification —
no design decisions. When a gate fails, STOP on that gate, diagnose, and report;
do not improvise architecture changes.

## Ground rules for the executing agent

- NEVER commit to git. NEVER run DB WRITE statements without the user's explicit
  OK — all test data (domains, aliases, users) is created through the admin UI or
  existing API endpoints, not SQL.
- Secret handling: the VPS root key and any passwords never appear in the
  transcript. Reference key files by path; pass secrets via files/stdin.
- The dev box user (`user1`) has NO passwordless sudo. Any root step on the MAIN
  box is a command you print for the user to run (suggest the `! <command>`
  prefix). Root steps on the RELAY VPS you run yourself over SSH with the
  provided key.
- The live inbound domain `dev.getjoinery.com` stays untouched — its MX keeps
  pointing at the dev box. All receive tests use a dedicated test domain (below).
- Use the Playwright browser MCP for admin-UI steps; admin credentials are in
  Claude memory (`reference_credentials.md`).

## What the user provides (ask for all of it up front, once)

1. A fresh minimal **Debian 12** VPS: public IP, root SSH access. Get the key
   onto the dev box at a path the web user can read (e.g.
   `/var/www/html/joinerytest/config/relay_test_key`, mode 640, group www-data)
   — the pull consumer and map sync run as the web user and need it too.
2. A **mail hostname** for the relay (e.g. `mx-test.getjoinery.com`) and DNS
   control to create: `A mx-test → <VPS IP>`, and
   `MX relaytest.dev.getjoinery.com → mx-test.getjoinery.com`
   (`relaytest.dev.getjoinery.com` is the throwaway inbound test domain; any name
   works, keep it consistent). PTR on the VPS IP is nice-to-have, not gating.
3. Confirmation that port 25 inbound is open on the VPS (some providers block it
   by default — Hetzner/DO usually fine, new accounts sometimes not).

## Phase 0 — main-box pre-flight (all read-only checks; fix-by-asking)

Verify each; if one fails, ask the user to do the named action, then re-check.

- **Schema exists:** `mrl_mailbox_relays` table present, `mgn_managed_nodes` has
  `mgn_is_relay` (`psql` `\d` — read-only). Missing → user runs `update_database`
  from `/admin/admin_utilities`.
- **Main WG bootstrap done:** setting `mailbox_relay_wg_public_key` is non-empty
  (read via a settings lookup, don't print the key) AND interface `jyrelay0`
  exists (`ip link show jyrelay0`) AND `/usr/local/sbin/joinery-relay-peer`
  exists. Missing → user runs
  `sudo bash plugins/mailbox/provisioning/provision_relay_main.sh`.
- **server_manager plugin active**, and the tasks **PullRelaySpool** /
  **SyncRelayMap** ACTIVATED on `/admin/admin_scheduled_tasks` — they ship as
  discovered "Available Tasks" and do nothing until the Activate button creates
  their row (both every_run; harmless no-ops while no relay is active). Confirm
  the cron runner is actually ticking (last-run times go fresh after activation).
- **Provisioning bundle intact:** `plugins/mailbox/provisioning/relay-sealer/`
  and `provision_relay.sh` exist; `cd` into relay-sealer is NOT needed — the
  provision job tarballs them itself.
- **DNS live:** `dig +short A <mail hostname>` returns the VPS IP;
  `dig +short MX relaytest.dev.getjoinery.com` returns the mail hostname.

## Phase 1 — provision

1. Add the VPS as a managed node at `/admin/server_manager` (Add Node): host =
   VPS IP, SSH user `root`, port 22, key path from Phase 0. Verify the node's
   connection check passes.
2. Open `/plugins/mailbox/admin/admin_mailbox_relay`. The provision box must NOT
   show the "run provision_relay_main.sh" instruction (that means Phase 0 is
   incomplete). Select the node, enter the mail hostname, submit **Provision**.
3. Watch the job on the server_manager job detail page until it completes. First
   run installs a Go toolchain and builds the sealer on the VPS — slow is normal;
   only a failed step is a finding.
4. On success verify, in order:
   - The relay row exists on the relay admin page (host, WG public key, endpoint
     populated); the node shows as a relay.
   - `sudo wg show jyrelay0` (user runs it) lists the relay peer with allowed IP
     `10.99.0.1/32`. If the peer is missing, the `joinery-relay-peer` exec failed
     — check the error log, have the user re-run `provision_relay_main.sh`.
   - Tunnel is up end-to-end: `ping -c2 10.99.0.1` from the main box (a
     handshake may need first traffic; ping IS that traffic).
   - Key-only SSH over the tunnel works as the web-user-readable key allows:
     `ssh -i <key> root@10.99.0.1 true` (the pull path uses the tunnel address —
     confirm `mrl_host` matches what RelaySsh will dial; read the row via the
     admin page).
5. **Enable** the relay on the relay admin page (explicit act — this makes it
   authoritative). Within one cron pass SyncRelayMap must push the map: on the
   VPS check `/etc/postfix/joinery-relay-domains`, `joinery-recipients`,
   `joinery-transport`, `joinery-srs-access`, and `/opt/joinery-relay/routing.json`
   are non-empty and contain the test domain once Phase 2 creates it.

## Phase 2 — test fixtures (admin UI, no SQL)

- Create inbound domain `relaytest.dev.getjoinery.com` in the mailbox admin,
  `reject_unmatched` ON, no catch-all.
- Aliases: `std@` (store mode, granted to a NON-vault user — transport-sealed),
  `fort@` (store mode, granted to exactly ONE user who HOLDS a sealed vault —
  user-sealed; create/enroll the vault on a fixture user first if none exists),
  `fwd@` (forward mode → an external mailbox you can read, e.g. a Gmail the user
  provides or `test@dev.getjoinery.com` — note: forwarding to the dev box's own
  live domain is the simplest loop).
- Confirm SRS is enabled (`mailbox_srs_enabled`) — forward-mode + bounce legs
  depend on it.
- After one cron pass, verify on the VPS that all three aliases appear in
  `joinery-recipients` and `routing.json` (with `key_kind` `transport` for std@,
  `user` for fort@ — and that fort@'s `public_key` differs from
  `transport_public_key`).

## Phase 3 — the gates (§ 11.2 + fix-pack regressions)

Send real external mail (e.g. from Gmail) unless a leg says otherwise. Include a
unique marker string in each test body, e.g. `RELAYTEST-<leg>-<random>`.

**G1 — receive → seal, no plaintext at rest.** Mail std@. On the VPS, catch the
spool entry (`/var/spool/joinery-relay/`): exactly `<id>.seal` + `<id>.meta`.
`grep -r '<marker>' /var/spool/joinery-relay /var/spool/postfix` must find
NOTHING (ciphertext only; postfix queue is transient — an empty queue also
passes). `.meta` is cleartext JSON: verify it carries `recipient`, `key_kind`,
`public_key`, and `authentication_results` as an ARRAY of every A-R header in
document order.

**G2 — pull → store → ack.** Within a cron pass the message lands in
`iem_inbound_email_messages` (body present — transport kind opens at pull) and
the spool entry on the VPS is GONE (delete-after-durable ack). Re-run tolerance:
the same spool id pulled twice must not duplicate the message.

**G3 — Fortress deferred ingest.** Mail fort@. The stored row is pending-parse:
body columns empty/absent, sealed blob retained. Then unlock the fixture user's
vault in the browser; the deferred ingest runs and the message becomes fully
parsed (subject/body/attachments sealed per the encryption rules, pending flag
cleared).

**G4 — A-R forgery stripped (fix pack § Fix 2).** Send to std@ a message that
already carries a forged `Authentication-Results: <authserv-id> ... dkim=pass`
header (swaks or a raw SMTP session from an external host works). Verify the
stored message's authentication verdict does NOT honor the forged header — the
meta's A-R list contains only relay-stamped results, forged one removed at
ingress.

**G5 — recipient case survives (fix pack R2-3/R2-9).** Mail
`STD@relaytest...` (uppercase local part) — must deliver (case-insensitive
match) — and run the forward leg G6 whose SRS address is mixed-case: both prove
`flags=DRh` (no lowercase-folding `u`) on the pipe. On the VPS check master.cf's
joinery pipe line actually says `flags=DRh `.

**G6 — forward mode + SRS.** Mail fwd@ from an external sender. The external
destination receives it; verify in its headers: envelope/Return-Path is
`SRS0=...@<forwarding domain>` with the hash case INTACT, `From:` rewritten to
the site identity (DMARC-safe), original sender preserved in the visible chain.

**G7 — SRS bounce → NDR.** Make the forward destination bounce (point fwd@ at a
nonexistent address at a real domain, send once) — the bounce comes back to the
SRS0 address, the relay must ACCEPT it (srs-access map), seal it to the
TRANSPORT key, and the pull consumer must decode it into a delivery-failure
notice for the original sender path. Also negative: an `SRS1=...` recipient is
REJECTED at SMTP time, not spooled.

**G8 — reject_unmatched at SMTP time.** Mail `nosuchalias@relaytest...`: the
sending MTA gets a 5xx during the SMTP session (Gmail shows the bounce
immediately). No spool entry, no backscatter from us.

**G9 — map freshness.** Create a brand-new alias `fresh@` via the admin UI, wait
ONE cron pass, mail it — accepted and delivered (no bounce window).

**G10 — smarthost / origin hidden.** Send a compose message FROM the webmail to
an external mailbox. Its `Received:` chain must show the relay, never the main
box IP. Then run the relay admin page's health battery: all checks green,
including origin-hidden (no main-box IP in any mail DNS for the test domain) and
map-freshness.

**G11 — rebuild loses no mail.** Fire **Rebuild** on the relay admin page (same
node). While the job runs, send mail to std@ from an external MTA. After the
rebuild completes (and one force-resync of the map if the health check asks for
it), the message arrives — senders retried through the gap. Verify the relay row
kept its identity and the tunnel re-established.

## Phase 4 — report + teardown

- Report a pass/fail table for G1–G11 with evidence one line each (message id,
  spool id, header snippet). Failures: include the exact command/output and the
  responsible file, no fixes applied without the user's OK.
- Teardown (only after the user confirms the report): disable + delete the relay
  row, remove the managed node, delete the test domain/aliases via the admin UI,
  tell the user which DNS records to remove and that the VPS can be destroyed.
- Do NOT move any spec to `specs/implemented/` — the main session/user does that.

## Known environment gotchas

- Cloudflare-proxied A records break SMTP — the mail hostname's A record must be
  DNS-only (grey cloud).
- Outbound port 25 from the MAIN box is irrelevant here (smarthost rides the
  tunnel), but outbound 25 from the RELAY is required for G6/G7/G10 — some
  providers require a support ticket to open it.
- `StrictHostKeyChecking=accept-new` means a REBUILT VPS at the same IP with a
  new host key makes SSH refuse: clear the old key from the web user's
  `known_hosts` (both the public IP and 10.99.0.1) after any rebuild.
- The dev error log is verbose — always grep (`PullRelaySpool`, `RelayMapSync`,
  `DeferredIngest`, `provision_relay`) rather than tailing.
