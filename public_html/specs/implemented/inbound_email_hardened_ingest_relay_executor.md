# Inbound Email — Hardened Ingest Relay — Executor Package

**Status:** Ready for implementation
**Version:** 1.0
**Design authority:** `specs/inbound_email_hardened_ingest_relay.md` (v1.2) — the *why*,
the threat model, and the two resolved decisions (Go sealing binary via
`box.SealAnonymous`; filesystem spool + SSH/rsync pull, delete-after-durable = ack). This
is the *how*.
**Depends on (build first):** `specs/inbound_email_encryption_at_rest_executor.md` (the
relay seals to the same user public key with `crypto_box_seal`; the pull consumer re-injects
through the same store path) and `specs/inbound_email_outbound_send_protection_executor.md`
(the relay is the outbound smarthost). Reuses `server_manager`'s job/agent machinery.

### Naming baseline (rename interaction)

**Run the rename first.** Paths use today's `plugins/mailbox/…`; after the rename
apply dir `plugins/mailbox/`→`plugins/mailbox/` and setting-key
`inbound_email_`→`mailbox_`. Class names (`InboundEmailRouter`, `PostfixProvider`,
`ManagementJob`, `ManagedNode`), table prefixes (`iem_`/`iea_`/`ied_`/`mjb_`/`mgn_`), and
line numbers are rename-invariant. `server_manager` is a **separate plugin** — not renamed.

## Architecture in one line

A minimal VPS is the public MX: Postfix + verify milters + a small **Go sealing binary** +
WireGuard, no PHP/DB/web. It seals each accepted message to the recipient's public key at
acceptance and spools ciphertext; the main Joinery box **dials out** over WireGuard and
pulls sealed blobs. Colocated mode (today's `install_email.sh` on the main box) and
relay-fronted mode are **both permanent**, chosen per deployment.

## What this changes / adds (map, verified)

| Area | File |
|---|---|
| The relay installer | new `plugins/mailbox/provisioning/provision_relay.sh` (sibling of `install_email.sh`, 659 lines, v2.8) |
| The Go sealing binary | new `plugins/mailbox/provisioning/relay-sealer/` (Go source + build) |
| Pipe interface it replaces | `utils/inbound_email_handler.php` (stdin raw, `$argv[1]` recipient) → `PostfixProvider::handleInbound()` (`includes/email_providers/PostfixProvider.php` 109) → `InboundEmailRouter::processEmail()` (125) |
| Store re-injection (pull consumer) | `InboundEmailRouter::processEmail()` (125) / `storeMessage()` (336) / `storeExtracted()` (682) |
| Alias/routing map source | `InboundEmailAlias` (`iea`, modes forward/store/forward_and_store) + `InboundEmailDomain` (`ied`: catch-all, `ied_reject_unmatched`); routing in `processEmail()` 134–192 |
| Pull consumer + map sync tasks | new tasks in `plugins/mailbox/tasks/` (mirror `PollImapAccounts.php` + `.json`, `every_run`) |
| Provisioning jobs | `server_manager`: `JobCommandBuilder::build_provision_relay()`, `JobResultProcessor::process_provision_relay()` (mirror `build_install_node` 1269 / `process_install_node` 269); `ManagementJob::createJob()` (48) |
| Node registration + WG | `ManagedNode` (`mgn`, `mgn_managed_nodes`) + new `mgn_wg_*` columns via `server_manager/migrations/migrations.php` |
| Smarthost | `SmtpProvider` smarthost config points at the relay over the tunnel |
| Setup/health retarget | `InboundEmailSetupCheck` / `InboundEmailHealth` |

## Phase 0 — Preflight

Branch `hardened-ingest-relay`. Confirm the encryption package's `SealedBox` exists (the Go
sealer's output must open with `SealedBox::openDek` = `sodium_crypto_box_seal_open`).
Confirm `server_manager` is active (the provisioning path reuses it; `provision_relay.sh`
standalone is the floor when it is not — see Phase 6). Go toolchain: the platform already
ships a Go agent (`joinery-agent`), so Go is in the lineage.

## Phase 1 — The Go sealing binary

New `plugins/mailbox/provisioning/relay-sealer/` (Go module, builds to a single
static binary shipped by `provision_relay.sh`). It replaces the `utils/inbound_email_handler.php`
pipe target on the relay. Behavior (design § 2 step 3, and the resolved decision):
- Invoked by Postfix as the pipe transport: raw RFC822 on **stdin**, envelope recipient +
  sender as **argv** (mirror the master.cf `flags=DRhu` pipe; pass `${recipient} ${sender}`).
- Look up the recipient's public key + routing in the **synced map** (Phase 3) — no DB.
- **Seal the entire raw message** with `crypto_box_seal` directly to the recipient's public
  key via `golang.org/x/crypto/nacl/box.SealAnonymous` (wire-compatible with libsodium; **no
  DEK, no AEAD at this layer** — the blob is opened once at deferred ingest and re-sealed
  with the real per-message DEK). Extract only header-level operational metadata (envelope
  recipient, `Message-ID`, `In-Reply-To`/`References`, `Date`, size, the milter-stamped
  `Authentication-Results` verdict) into a cleartext `.meta` sidecar.
- Write `<spoolid>.seal` + `<spoolid>.meta` via **write-tempfile → fsync → atomic rename**
  into the spool dir, then return the Postfix exit code **only after** the fsync. Never
  buffer plaintext to disk. Stream stdin.
- Standard/Private domains: seal to a **transport keypair whose secret Joinery holds
  ambiently** (same machinery, different recipient) so the relay disk never holds plaintext
  for any level; Joinery opens at pull and runs today's ingest. Fortress: seal to the
  **user's** public key.
- CI: a round-trip test that `SealAnonymous` output opens with PHP
  `sodium_crypto_box_seal_open` (pentest-brief verify).

## Phase 2 — provision_relay.sh

New `plugins/mailbox/provisioning/provision_relay.sh`, sibling of `install_email.sh`
(clone its structure). `chmod 666` the file; it runs as root on a fresh minimal Debian VPS,
idempotent, one arg (mail hostname), zero prompts. Sections, mapped to `install_email.sh`:
- **§1 packages** (install_email.sh 161): `postfix opendkim opendkim-tools opendmarc` —
  **drop `postfix-pgsql`** (no app DB on the relay). Optional rspamd per §5b.
- **§3 main.cf + RBL** (206–254): clone the `smtpd_recipient_restrictions` RBL block (226)
  verbatim. `virtual_transport` → the Go sealer pipe.
- **§2 pipe** (185–203): replace the PHP pipe
  (`argv=${PHP_BIN} utils/inbound_email_handler.php ${recipient}`) with the Go sealer
  (`argv=/opt/joinery-relay/relay-sealer ${recipient} ${sender}`, same `flags=DRhu`).
- **§4 domain map** (254–346): replace the live `pgsql:/etc/postfix/joinery-domains.cf`
  with a **synced static file** (`virtual_mailbox_maps` / `check_recipient_access`) pushed
  from the main server (Phase 3). The relay has no Postgres.
- **§5 milters** (348–492): clone opendkim (`localhost:8891`, verify) + opendmarc
  (`localhost:8893`, stamp `Authentication-Results`) verbatim. rspamd (§5b) optional via
  the controller interface (Phase 9).
- **New: WireGuard** — install `wireguard`, generate the relay keypair, write a `wg-quick`
  config with a `[Peer]` for the main server; the relay never dials in.
- Hardening: unattended-upgrades, key-only SSH, default-deny firewall (25/tcp, WG UDP, SSH).
- End by printing the three values the main server needs: relay public IP, WireGuard public
  key, spool endpoint — so an operator who never opens the admin UI can wire it by hand.
- No certbot (matches `install_email.sh`; inbound STARTTLS cert is out of scope here).

## Phase 3 — Alias-map export + sync

The relay needs a compact, DB-free routing map. Build it from enabled `InboundEmailDomain`
rows (`ied_catch_all_mode`/`ied_catch_all_address`/`ied_reject_unmatched`) + enabled
`InboundEmailAlias` rows (`iea_alias`, `iea_delivery_mode`, `iea_destinations`), plus each
recipient's **public key** (Fortress: the owner's `iek_public_key`; Standard/Private: the
ambient transport public key). Emit:
- A Postfix `check_recipient_access` / `virtual_mailbox_maps` file (valid local-parts per
  domain, `reject_unmatched` semantics preserved — a newly created alias must not bounce
  during the sync gap).
- A sealer-side routing table (recipient → store/forward + destinations + public key).

Push over the tunnel whenever aliases/domains change (**push-on-change**) plus a periodic
reconcile (a scheduled task, mirror `PollImapAccounts`), so freshness beats
`reject_unmatched`. `SRSRewriter` logic runs relay-side for forward-mode aliases (the secret
syncs to the relay); store-and-forward both seals and forwards.

## Phase 4 — Spool/pull protocol (filesystem + SSH/rsync)

The resolved decision: the dumber option, no bespoke relay daemon.
- Relay network surface stays exactly **Postfix + WireGuard + key-only SSH**.
- Main server, over the tunnel, on a short poll (~15–30s) via a new scheduled task
  (`plugins/mailbox/tasks/PullRelaySpool.php` + `.json`, `every_run`, mirror
  `PollImapAccounts`): `rsync` new `<spoolid>.seal`+`.meta` entries **copy-only** (never
  `--remove-source-files`), store each durably with an **idempotent store keyed on spool
  id** (re-pull of an un-acked-but-stored item = no-op = dedup), then **delete the remote
  entries it has durably stored** — the delete-after-store **is** the ack.
- Store re-injection: for a Fortress blob, insert a **pending-parse** message row (Phase 5);
  for Standard/Private, open the transport-sealed blob and run today's ingest via
  `InboundEmailRouter::processEmail($raw_mime, $recipient)` (reuses all routing) or
  `storeExtracted()` (682) if bypassing forward. The `.meta` sidecar populates the cleartext
  operational columns without opening the blob.
- Degradation: relay down → senders' MTAs retry for days; tunnel down → relay spools sealed
  blobs until the main box reconnects. Neither loses mail.

## Phase 5 — Deferred ingest (Fortress)

For MX-path Fortress mail, full parse/filter/attachment-split moves from receive to the
next unlock (design § Deferred Ingest; the ordering rule from the encryption package holds
*inside deferred ingest at unlock*, not at receive):
1. While logged out, the pull consumer stores each item as **pending-parse**: cleartext
   operational metadata (recipient, message-id, thread key from the relayed header inputs,
   received time, size, auth verdicts) + the sealed raw blob. Add an `iem_pending_parse`
   bool column to `InboundEmailMessage::$field_specifications`. Threading + unread counts
   work; subject/sender/body/attachments don't exist yet as fields.
2. At the next unlock (encryption package's index fold): unseal the raw blob (needs the
   in-window secret key), run the full existing pipeline — parse MIME, run filters/rules on
   plaintext, split attachments to sealed `File`s, seal fields under a fresh per-message DEK
   with AD row-binding — then discard the raw blob and clear `iem_pending_parse`.
3. Store-mode filter actions thus apply at unlock, invisibly for a single reader.
`InboundEmailRouter::readAuthResults()` gains the relay as a trusted verdict source (it
forwards the milter-stamped `Authentication-Results` as metadata).

## Phase 6 — Provisioning jobs (server_manager)

Reuse the queue + Go-agent machinery. PHP builds step arrays, inserts a `ManagementJob`,
post-processes the agent's result — it never SSHes directly.
- **`JobCommandBuilder::build_provision_relay($node, $params)`** (mirror `build_install_node`
  1269): a `local` preflight, `scp`/`ssh` to deliver `provision_relay.sh` + the sealer
  binary (fetch a tarball over HTTPS from the control plane as `install_node` does, or
  `scp` the shipped `provisioning/` files), `ssh` to run it, then steps to exchange
  WireGuard keys and push the initial synced map + the user's sealing public key. Step-dict
  shapes: `{type:'ssh',label,cmd,node_id,timeout}`, `{type:'scp',direction:'upload',
  local_path,remote_path}`, `{type:'local',...}`.
- **`JobResultProcessor::process_provision_relay($job)`** (mirror `process_install_node`
  269): on success + a `RELAY_READY` marker in `mjb_output`, store the relay's returned
  WireGuard public key + endpoint + IP on the `ManagedNode` row and flip a relay/install
  state; on failure set the failed state.
- **`ManagedNode` schema**: add `mgn_wg_public_key`, `mgn_wg_endpoint`, `mgn_wg_ip`,
  `mgn_is_relay` (bool) via `server_manager/migrations/migrations.php`. A relay is a
  `ManagedNode` row; health uses the existing `AgentHeartbeat` / status-chip machinery.
- **`rebuild_relay`** job type: same builder pointed at a fresh (or the same) VPS —
  incident response is click → wait → update DNS; **also schedulable** (e.g. monthly,
  in-place same IP) so persistence on the relay has a shelf life. Nothing is lost: unacked
  mail is queued at senders' MTAs, acked mail is on Joinery.
- The Fortress setup flow (levels spec) pastes a fresh VPS IP + root key and fires
  `provision_relay`; the Setup tab then verifies (MX at relay, port 25, milters, tunnel up,
  spool draining). What stays manual (Setup tab's detect-instruct-verify boundary): buying
  the VPS, the MX/A DNS, the PTR at the provider — copy-ready values shown inline.
- Floor: `provision_relay.sh` runs standalone when `server_manager` is inactive.

## Phase 7 — Outbound smarthost

Compose sends route out through the relay as smarthost over the tunnel (otherwise every
sent message's `Received:` leaks the main box IP). Point `SmtpProvider`'s smarthost config
at the relay; DKIM signing stays in-app (outbound package) — the relay only transports. The
relay's PTR/HELO present the mail hostname.

## Phase 8 — Setup/health retarget + "the relay fronts every domain"

- Once a relay exists it is the **MX for all** of the deployment's hosted domains (a
  mixed MX would leak the origin). Level controls where mail is *sealed*, never where it is
  *routed*.
- `InboundEmailSetupCheck` / `InboundEmailHealth`: retarget to the relay (MX resolves to
  relay, relay port 25, relay milters, tunnel up, spool draining, alias map fresh) and add a
  **deployment-wide** "Joinery IP not present in mail DNS" check across all hosted domains
  once a relay exists (not Fortress-only).

## Phase 9 — Decommission the main box's MTA (relay-fronted only)

With every domain fronted, Postfix/opendkim/opendmarc are decommissioned on the main box
and port 25 closed — the box holding the data stops exposing a mail listener. **rspamd
stays:** deferred ingest still scores each message through rspamd's **controller interface**
at parse time; only the milter mode is unused. Do not decommission rspamd with the MTA
stack — that would silently leave Fortress mail unscored. Relay compromise now also sees
Standard/Private transit mail (same as any MTA hop; those levels never promised otherwise);
the Fortress guarantee is unchanged.

## Phase 10 — Settings, deployment, docs

- Settings: relay bookkeeping (last successful pull, alias-map sync version) as settings or
  a small status table for the health checks.
- Deployment: `provision_relay.sh` + the sealer binary ship in the plugin's `provisioning/`;
  the pull consumer + map-sync run under the scheduled-task system.
- Docs (current-state voice): `plugins/mailbox/docs/overview.md` — the relay as where
  the MTA stack runs, the sealed spool + pull transport, deferred ingest, relay-side
  forwarding; `docs/mobile_apps.md` / mail-DNS docs — mail hostname records point at the
  relay.

## Phase 11 — Verification (acceptance gate)

11.1 `php -l` + `validate_php_file.php` on PHP; `bash -n` on `provision_relay.sh`;
`go vet` + the round-trip CI test on the sealer.

11.2 End-to-end on a throwaway VPS:
- **Provision:** `provision_relay` job brings up the relay; WG key stored on the node;
  Setup tab verifies MX/milters/tunnel.
- **Receive → seal:** send mail to a Fortress alias; confirm the relay spool holds only
  `<spoolid>.seal` (ciphertext) + `.meta` (cleartext metadata), **no plaintext on the relay
  disk**; the sealer exited 0 only after fsync.
- **Pull → pending-parse:** the main box pulls, acks (remote entry deleted after durable
  store), and stores a pending-parse row (threading/unread work; body absent).
- **Unlock → deferred ingest:** at unlock the blob unseals, parses, seals fields + splits
  attachments, clears pending-parse.
- **Map freshness:** a newly created alias receives mail without bouncing during the sync
  gap.
- **Smarthost:** a compose send leaves via the relay; the sent message's `Received:` chain
  shows the relay, not the main box IP.
- **Rebuild:** `rebuild_relay` on the same IP loses no mail (senders retry through the gap).
- **Origin hidden:** no main-box IP appears in any mail DNS (the deployment-wide check).

11.3 `batcat` for each created/edited file (do not run them).

## Open items the executor confirms against the running system (not decisions)

- Whether the pull consumer re-injects via `processEmail()` or `storeExtracted()` per level
  (Phase 4) — read both and pick per whether forwarding must re-run.
- The exact `ManagementJob` step-dict fields the Go agent consumes for `scp` uploads of the
  sealer binary (mirror `build_install_node`'s tarball-fetch, or scp the shipped files).
- Relay-side rate limiting for forward-mode aliases (design open item — mirror the app's
  per-alias/per-domain limits if needed).
- rspamd controller-interface invocation from deferred ingest (Phase 9) — the API shape.
- Whether a stretch goal of creating the VPS via a provider API (Hetzner/DO token) is worth
  making even "buy a VPS" one-click (design open item).
