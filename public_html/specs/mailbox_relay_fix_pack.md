# Mailbox Relay Fix Pack

Fixes for the 10 confirmed findings from the code review of the hardened ingest
relay implementation (`specs/inbound_email_hardened_ingest_relay_executor.md`).
Every finding below was adversarially verified against the working tree — none
are speculative. Work through them in order: the first three destroy or falsify
data; the middle cluster silently regresses deliverability relative to a
colocated deployment; the last two make the feature unreachable.

None of this code is committed yet, and the schema (`mrl_` table, `iem_`/`mgn_`
columns) is not live, so fixes can change anything freely — there is no
deployed data to preserve.

---

## Fix 1 — Full-row `save()` destroys sealed content (CRITICAL, data loss)

**Where:** `plugins/mailbox/includes/InboundEmailRouter.php` `parsePendingMessage()`
(lines 502–506 and 537–541) and `plugins/mailbox/includes/RelaySpoolConsumer.php`
`ingestOne()` (lines 173–175).

**Problem.** `SystemBase::save()` writes every column in `$field_specifications`
from the in-memory object. Both paths update the row's content columns via
direct SQL (`sealMessageContent()` / `persistRawAndManifest()` /
`storeMessage()`'s sealing) keyed on the id — the in-memory object never sees
those values. The subsequent `$msg->set(...); $msg->save()` then overwrites the
sealed sender/subject/bodies/key/raw-storage descriptor with the stale empty
values, and in `parsePendingMessage()` simultaneously nulls
`iem_relay_sealed_raw` — the only remaining copy. Every Fortress message
delivered via the relay is permanently blanked at the owner's first unlock.

**Fix.** Never call full-row `save()` on a message object after its columns
have been written behind its back. Replace each `set()+save()` in these paths
with a targeted UPDATE of exactly the columns being changed:

- `parsePendingMessage()` success path: one prepared
  `UPDATE iem_inbound_email_messages SET iem_pending_parse = false,
  iem_relay_sealed_raw = NULL WHERE iem_inbound_email_message_id = ?`.
- `parsePendingMessage()` inconsistent-row branch (pending flag, no blob):
  targeted UPDATE of `iem_pending_parse` only.
- `RelaySpoolConsumer::ingestOne()` spool-id stamp: targeted UPDATE of
  `iem_relay_spool_id` only.

After the UPDATEs in `parsePendingMessage()`, reload the object
(`new InboundEmailMessage(intval($msg->key), TRUE)`) before handing it to
`InboundEmailFilter::runForMessage()` so the filter run never sees (or saves)
the stale pre-parse state.

**Acceptance.** A functional test that seeds a pending-parse row, runs
`parsePendingMessage()`, and asserts afterward: `iem_sender`/`iem_subject`/
`iem_body_plain`/`iem_body_html` non-empty (sealed), `iem_content_sealed`
true, `iem_sealed_key` present, raw-storage descriptor intact,
`iem_pending_parse` false, `iem_relay_sealed_raw` null. A matching test for
the transport pull path asserting the sealed columns survive the spool-id
stamp.

---

## Fix 2 — Sender-forged Authentication-Results wins over the milter verdict (CRITICAL, security)

**Where:** `plugins/mailbox/provisioning/relay-sealer/meta.go` `extractMeta()`
(line 49), `provision_relay.sh` (milter/Postfix config),
`InboundEmailRouter::authFromRelayMeta()`.

**Problem.** `extractMeta` keeps the LAST `Authentication-Results` header on
the premise that "milters append their own." Milters insert headers at the TOP
of the header block. A sender who embeds their own
`Authentication-Results: <mail_hostname>; spf=pass; dkim=pass; dmarc=pass`
gets that forged line selected (it sits below the milter-stamped one), and the
main box trusts it because the authserv-id matches. Spoofed mail is recorded
as fully authenticated.

**Fix.** Three layers, all required:

1. **Sealer:** collect ALL `Authentication-Results` headers in document order
   into a list in the `.meta` sidecar (replace the single string field).
   Header order in the raw block is preserved, so the milter-stamped verdicts
   are the earliest entries.
2. **Main box:** `authFromRelayMeta()` walks the list top-down and takes the
   first verdict per method (dkim/spf/dmarc) from headers whose authserv-id
   matches `mailbox_mail_hostname`, ignoring the rest. First-wins mirrors the
   prepend behavior of the milters.
3. **Relay ingress:** strip sender-supplied A-R headers bearing our
   authserv-id before the milters stamp, so forged lines never even reach the
   sealed raw. OpenDKIM's `RemoveARFrom <mail_hostname>` does this; add it in
   `provision_relay.sh`'s opendkim config.

**Acceptance.** A Go unit test feeding `extractMeta` a raw message with a
milter-style A-R at the top and a forged A-R (same authserv-id) lower in the
block, asserting the selected/first verdict is the milter one. A PHP test for
`authFromRelayMeta()` with the same shape. `bash -n` on the provisioning
script; the opendkim config block contains `RemoveARFrom`.

---

## Fix 3 — Map push records success when rsync failed (CRITICAL, silent stale routing)

**Where:** `plugins/mailbox/includes/RelayMapSync.php` `push()` (lines 80–87).

**Problem.** The four `RelaySsh::run(RelaySsh::rsyncCommand(...))` calls
discard their `[exit_code, output]` return. If an upload fails (tunnel blip,
remote permission error), the later postmap/reload runs on the OLD files, exits
0, and `push()` saves the NEW content hash. From then on the change-skip check
and `checkRelayMapFresh` both report the relay current while it runs a stale
map — a newly created alias bounces 554 forever with the health dashboard
green.

**Fix.** Check each rsync's exit code; on nonzero, return
`['status'=>'error', 'message'=>...]` immediately without touching
`mrl_map_content_hash`/`mrl_map_version`/`mrl_last_push_time`. The hash is
only recorded after every upload AND the postmap/reload round trip succeeded.

**Acceptance.** Unit-level test (or verified-by-inspection with the validator)
that a nonzero rsync code yields status `error` and leaves the relay row
untouched, so the next reconcile retries the push.

---

## Fix 4 — Relay smarthost strips DKIM from standard hosted-alias sends (deliverability)

**Where:** `includes/OutboundTransport.php` `forHostedAlias()` (lines 83–91),
`plugins/mailbox/includes/MailboxDkimSigner.php` (`resolveFor()`).

**Problem.** The relay early-return routes EVERY hosted-alias send through the
relay smarthost. The relay's opendkim is verify-only by design, and the in-app
signer (`MailboxDkimSigner::resolveFor()`) returns a signer only for protected
domains — so a standard hosted alias sends with no DKIM signature at all.
Gmail/Yahoo-class receivers reject or spam-folder it. Colocated, these sends
were signed (ambient provider, or main-box opendkim running sign+verify).

**Fix.** Keep the executor spec's goal — every hosted-domain send leaves
through the relay, origin hidden — and restore signing in the app: extend the
in-app DKIM signer so that when the domain is NOT protected it resolves the
domain's standard DKIM key (the same key main-box opendkim signs with on a
colocated deployment) instead of returning null. Protected domains keep the
sealed-key signer unchanged. The relay stays verify-only.

Alternative considered and rejected: sending standard aliases via the ambient
provider (transport=null) keeps provider DKIM but reintroduces a third-party
dependency on relay-fronted deployments and breaks entirely where the ambient
provider is the (decommissioned) local MTA.

**Acceptance.** With an active relay row, `forHostedAlias()` on a standard
hosted alias yields an SmtpProvider whose send path produces a DKIM-Signed
message (test at the signer level: `resolveFor()` returns a signer for a
standard hosted domain with a provisioned key, and the signature verifies
against the domain's public key). Protected-domain behavior unchanged.

---

## Fix 5 — Relay-side forwards skip the From-rewrite (deliverability)

**Where:** `plugins/mailbox/provisioning/relay-sealer/forward.go`
`forwardMessage()` (line 70), `routing.go` (`routingEntry`),
`plugins/mailbox/includes/RelayMapExporter.php`.

**Problem.** `forwardMessage` forwards `outgoing := raw` unmodified — the
From-rewrite/Reply-To preservation its own doc comment claims (mirroring
`InboundEmailRouter::buildForwardMessage()`) is not implemented, and
`routingEntry` carries no From identity to rewrite to. SRS aligns SPF to the
forwarding domain, not the From domain, so any sender domain whose DMARC
relies on SPF alone fails DMARC at the destination and the forwarded mail is
rejected or spam-foldered.

**Fix.**

1. `RelayMapExporter` exports the forward From identity per forward-mode
   recipient (the same identity `buildForwardMessage()` constructs), as a new
   `routingEntry` field.
2. `forwardMessage()` applies the identical header treatment
   `buildForwardMessage()` applies (read it at
   `InboundEmailRouter.php:1321–1361` and mirror it exactly): rewrite From to
   the forwarding identity, preserve the original sender as Reply-To when the
   message has none, and stamp the same X-* provenance headers.

**Acceptance.** A parity fixture test in the sealer's Go test suite, same
pattern as the existing PHP↔Go seal round-trip: PHP `buildForwardMessage()`
generates the expected output for fixture messages (with and without an
existing Reply-To, folded From headers, etc.), and the Go rewrite must produce
byte-identical header treatment.

---

## Fix 6 — SRS bounces are rejected or misfiled on relay-fronted deployments (mail loss)

**Where:** `plugins/mailbox/includes/RelayMapExporter.php` (recipient access
map, lines 108–131), the sealer's routing, and
`plugins/mailbox/includes/RelaySpoolConsumer.php` `ingestOne()` (line 171).

**Problem.** Nothing relay-side accepts or routes `SRS0=...@forwardingdomain`
addresses (the recipients map lists only alias addresses), so bounces to
forwarded mail are REJECTed at SMTP time. Even if one were spooled, the pull
consumer calls `storeMessage()` directly, bypassing `route()`'s
`isSRSAddress()/handleSRSBounce()` branch (`InboundEmailRouter.php:131`) —
the bounce would be stored as a normal message instead of decoded into a
delivery-failure notification. Senders never learn their forwarded mail
bounced.

**Fix.**

1. **Relay accepts SRS recipients:** the exporter emits accept entries for
   SRS local parts on each domain's forwarding domain (a
   `check_recipient_access` pattern entry — pcre map or equivalent — scoped
   to `SRS0=`/`SRS1=` at the forwarding domain), and the sealer's routing
   treats an SRS recipient at a known forwarding domain as store-mode,
   sealed to the TRANSPORT key (bounce processing needs no vault), with the
   recipient preserved in `.meta`.
2. **Consumer routes, not stores:** for transport-kind entries the consumer
   must run the same routing branch colocated ingest runs — detect
   `SRSRewriter::isSRSAddress($recipient)` before the store and dispatch to
   the SRS bounce handling (`handleSRSBounce()` path), falling through to
   `storeMessage()` only for normal recipients. Factor the shared branch out
   of `route()` rather than duplicating it.

**Acceptance.** Functional test: seal a bounce addressed to a valid
SRS-rewritten address of a forward-mode alias, run the consumer's ingest on
it, assert an NDR/delivery-failure result identical to the colocated
`handleSRSBounce()` outcome (and no normal message row). Exporter test:
recipient access artifact contains the forwarding-domain SRS accept entry.

---

## Fix 7 — Push-on-change hook has zero call sites (mail loss window)

**Where:** `plugins/mailbox/includes/RelayMapSync.php` `onChange()` (line 34)
and the data classes whose state feeds `RelayMapExporter::build()`.

**Problem.** `onChange()` — documented as closing the "new alias must not
bounce during the reconcile gap" window — is never called. The only trigger is
the periodic `SyncRelayMap` task, so a new alias on a `reject_unmatched`
domain bounces 554 (permanent, no retry) until the next cron pass.

**Fix.** Hook at the data layer, not in individual admin logic files, so every
write path (admin UI, API, AI surface) triggers it. Complete inventory of
routing-map inputs and their owning classes:

| Map input | Owning class / event |
|---|---|
| alias local part, enabled, deleted, delivery mode, destinations, security level (seal target) | `InboundEmailAlias` save/delete |
| domain name, enabled, deleted, catch-all mode/address, reject_unmatched, forwarding subdomain | `InboundEmailDomain` save/delete |
| alias grants → single-owner user (Fortress seal target) | the grant write path (wherever `singleOwnerUserId()`'s source rows are written) |
| owner vault public key (enrollment/rotation) | covered by the periodic reconcile — acceptable: before reconcile the map still seals to the transport key, a safe degradation |
| relay transport key rotation | the rotation path itself must call `push($relay, force: true)` |

Override `save()`/the delete path in `InboundEmailAlias` and
`InboundEmailDomain` (call parent, then `RelayMapSync::onChange()`), and hook
the grant write path. `onChange()` already swallows failures and `push()`
hash-skips unchanged maps, so the hook is cheap on saves that don't affect
routing and best-effort on network failure, with the reconcile as backstop —
that design stands.

**Acceptance.** Creating an alias through the admin flow (or its logic entry
point) with an active relay row invokes one `push()` (assert via a test seam
or log capture); saves that don't change the map result in status `skipped`.

---

## Fix 8 — No content spam scanning on relay ingest (spam floods the inbox)

**Where:** `provision_relay.sh` (package set + milter chain, line 91) and
`InboundEmailRouter::parsePendingMessage()`.

**Problem.** The relay stamps no `X-Spam` header (no scanner installed), so
`resolveContentSpam()`/`readSpamHeader()` find nothing for relay-ingested
mail. Additionally `parsePendingMessage()` never calls
`resolveContentSpam()`/`classifySpam()` at all, and `storeRelayPending()`
stores no spam verdict — so Fortress relay mail gets NO spam classification
even after parse. Auth-passing spam lands unflagged.

**Fix.**

1. **Relay:** install rspamd in `provision_relay.sh` and wire it into the
   milter chain after opendkim(verify) and opendmarc, so the `X-Spam` header
   is inside the sealed raw — identical to what the colocated main-box MTA
   stamps. Match the colocated rspamd config (add-header-only; the relay
   never rejects on content).
2. **Deferred parse:** `parsePendingMessage()` runs the same
   `resolveContentSpam()` + `classifySpam()` sequence `storeMessage()` runs,
   using the auth verdicts already stored on the row at pull time, and
   persists `iem_spam_score`/verdict via the same targeted-UPDATE rule as
   Fix 1. (The transport pull path already classifies inside
   `storeMessage()`; once the relay stamps `X-Spam`, that path is whole.)

**Acceptance.** Provisioning script installs and enables rspamd with the
milter registered (bash -n plus config inspection). A pending-parse functional
test where the sealed raw carries a spam-positive `X-Spam` header asserts the
parsed row carries the spam score/verdict.

---

## Fix 9 — Deferred-ingest drain never fires on the combined inbox (stuck blank messages)

**Where:** `plugins/mailbox/includes/MailboxService.php` `drainRelayBacklog()`
(lines 731–735).

**Problem.** The drain returns immediately for a null alias scope, but the
primary reader view (thread_list_logic, and the native apps) calls
`listThreads` with null = "all accessible mailboxes." A Fortress owner looking
at their default combined inbox sees relay-pulled messages with empty
sender/subject/body forever; content only appears if they happen to open a
single-alias scope.

**Fix.** Pending-parse rows are single-owner scoped, and in the combined view
that owner is the session user. When `$aliasId` is null, drain for the current
session user id (same vault-unlocked gate, same once-per-request memo) instead
of returning. Keep the per-alias branch for explicit scopes whose single owner
may differ from the viewer.

**Acceptance.** Functional test: seeded pending row for user U, unlocked
vault, `listThreads(null)` as U triggers the drain and the thread list renders
parsed content on the same request.

---

## Fix 10 — Relay provisioning has no admin surface; jobs and rows are unreachable (feature unreachable)

**Where:** `plugins/server_manager/includes/JobCommandBuilder.php`
(`build_provision_relay`/`build_rebuild_relay`, line 1700+),
`plugins/mailbox/data/mailbox_relay_class.php`, mailbox admin.

**Problem.** Nothing creates a `provision_relay`/`rebuild_relay` job and no
admin surface creates or enables a `MailboxRelay` row — the docs and the
provisioning script's printed instructions describe UI that does not exist.
The feature cannot be reached end-to-end without hand-written SQL.

**Fix.** One admin page owned by the mailbox plugin (it owns the model), with
provisioning delegated to server_manager jobs:

1. **Mailbox admin relay page** (plugin admin view, following
   `docs/admin_pages.md` patterns): lists relay rows with status, tunnel
   host, WG public key, map version, last push/pull times, and the four relay
   health check results; enable/disable action; delete.
2. **Provision flow:** "Add relay" selects a managed node (from
   server_manager's node list) and submits — creating a `provision_relay`
   job via the existing convention dispatch. `process_provision_relay`
   (JobResultProcessor) already handles the result; make it also create/update
   the `MailboxRelay` row and the `mgn_is_relay`/`mgn_wg_*` fields from the
   job output, so a successful job leaves a registered, disabled relay row the
   admin then enables. A "Rebuild" action on an existing relay creates the
   `rebuild_relay` job the same way.
3. **Docs claim cleanup:** drop the "rebuild_relay is also schedulable" claim
   (no scheduling path exists and none is needed); the docs describe exactly
   the page and flow built here.

Per the self-documenting-pages rule: no explainer prose on the page — guided
controls only (node picker, status badges, action buttons); details live in
the plugin docs.

**Acceptance.** From a clean admin session: add → provision (job visible in
server_manager) → job success registers the relay row → enable → health
checks and map sync run against it. FormWriter used for all forms; API-first
logic (`_logic_api()`) per the endpoint rules.

---

## Additional confirmed gap (from the review's verified-but-capped set)

`InboundEmailHealth::checkOriginHidden()` resolves only MX targets and their
A records; it never checks the domain's SPF TXT records for the origin IP
(`DnsResolver::getTxt()` exists and is unused here). A deployment whose SPF
still lists the main box IP leaks the origin while the check reports hidden.
While touching the health checks (Fixes 3/10), extend `checkOriginHidden()`
to also flag the origin IP appearing in any SPF TXT record of a relay-fronted
domain.

---

# Round 2 — findings from the verification pass on the Round 1 implementation

The Round 1 fixes were implemented and re-reviewed. Ten new confirmed findings
(0 refuted), four of which break the fixes they were meant to land. All fixed
in the same working tree before commit.

## R2-1 — updateColumns() boolean binding breaks deferred parse (CRITICAL)

**Where:** `plugins/mailbox/data/inbound_email_message_class.php`
`updateColumns()`.

**Problem.** Values are bound via plain `execute([$values])`, which sends PHP
`false` as `''`; PostgreSQL rejects it (`SQLSTATE 22P02, invalid input syntax
for type boolean`). Both boolean call sites throw:
`parsePendingMessage()`'s success path and its inconsistent-row branch. The
pending flag never clears, messages render blank forever, filters never run,
and every retried unlock re-runs the parse and duplicates attachment rows.

**Fix.** Bind each value with an explicit type: `PDO::PARAM_BOOL` for
booleans, `PDO::PARAM_NULL` for null, `PDO::PARAM_INT` for int,
`PDO::PARAM_STR` otherwise (the same rule SystemBase::save() applies).

## R2-2 — Relay admin job dispatch calls undefined methods (CRITICAL)

**Where:** `plugins/mailbox/logic/admin_mailbox_relay_logic.php` (job
dispatch).

**Problem.** `JobCommandBuilder::$job_type($node, $params)` resolves to
`provision_relay()`/`rebuild_relay()`, which do not exist — the builders are
`build_provision_relay()`/`build_rebuild_relay()`. Every provision/rebuild
submit is a fatal 500; the Fix 10 flow is still unreachable from the UI.

**Fix.** Call `JobCommandBuilder::{'build_' . $job_type}($node, $params)`.

## R2-3 — SRS bounce detection is dead code: case destroyed end-to-end (CRITICAL)

**Where:** `plugins/mailbox/includes/RelaySpoolConsumer.php` (recipient
lowercasing), `relay-sealer/main.go` (recipient ToLower before `.meta`).

**Problem.** SRS local parts are case-significant twice over: detection
matches the uppercase `SRS0=` prefix, and `SRSRewriter::validate()`'s
`hash_equals` on the base64 hash is case-sensitive. The sealer lowercases the
envelope recipient before writing `.meta`, and the consumer lowercases it
again before the SRS check — so the branch never fires, the bounce falls
through to normal routing, resolves as `orphan`, and is ACKED — silently
destroyed.

**Fix.** Preserve local-part case end-to-end: the sealer writes the original
recipient into `.meta` and lowercases only for its own routing-map lookups
(domain matching stays case-insensitive); the consumer keeps the original
local part for SRS detection/validation and lowercases only the domain (and
the local part only where it feeds alias lookup, which is case-insensitive by
design). Go tests cover a mixed-case SRS recipient surviving to `.meta`; a
PHP test covers detection + hash validation on a consumer-pulled bounce.

## R2-4 — SRS-off deployments silently destroy spooled bounces

**Where:** `plugins/mailbox/includes/RelaySpoolConsumer.php` (SRS branch ack)
and `plugins/mailbox/includes/RelayMapExporter.php` (unconditional SRS accept
entries).

**Problem.** The exporter emits SRS accept entries regardless of
`mailbox_srs_enabled`, so the relay accepts bounces the consumer's handler
then declines (returns null when the setting is off) — and the branch returns
'bounce' anyway, which acks and deletes the entry with no NDR, no stored
message, and no log.

**Fix.** Gate the exporter's SRS accept entries on `mailbox_srs_enabled` (a
new bounce is then rejected at SMTP time with a proper 5xx, matching a
colocated deployment with SRS off, where no SRS addresses exist to accept).
In the consumer, when SRS detection fires but the handler declines, log the
discard explicitly (`error_log` with spool id + recipient) before acking —
in-flight bounces from before the setting flip are the only mail that can hit
this path, and the discard must be observable.

## R2-5 — Nothing writes the main box's WireGuard identity; provisioned tunnels can't come up (CRITICAL)

**Where:** `plugins/mailbox/logic/admin_mailbox_relay_logic.php` (reads
`mailbox_relay_wg_public_key`), plugin settings, provisioning scripts.

**Problem.** The setting has no writer and no declaration; the provision job
therefore always skips the "add main-box WireGuard peer" step, and a
dashboard-provisioned relay is unreachable (no wg interface, no peer) until
an operator hand-configures both ends.

**Fix.** Add the main-box side as a provisioning script (root, one-time):
`plugins/mailbox/provisioning/provision_relay_main.sh` generates the WG
keypair, writes `/etc/wireguard/` config for the relay tunnel, and stores the
public key + tunnel address into settings via a CLI PHP entry point (private
key stays on disk root-only, never in the DB). Declare
`mailbox_relay_wg_public_key` (and the tunnel address setting the script
writes) in `plugin.json` with empty factory defaults. The relay admin page
gates the provision form on the setting being present: when empty it shows
the not-configured state with the exact command to run instead of a
submittable form.

## R2-6 — Standard-domain DKIM key is unreadable by the PHP process; sends still leave unsigned

**Where:** `plugins/mailbox/includes/MailboxDkimSigner.php`
(`standardFilesystemSigner()`), `plugins/mailbox/provisioning/provision_dkim.sh`.

**Problem.** The signer reads `/etc/opendkim/keys/<domain>/<selector>.private`
but provisioning sets it `opendkim:opendkim` mode 600; `is_readable()` is
false for the web/cron user, the signer silently returns null, and
relay-fronted standard-alias mail still leaves unsigned — with no log.

**Fix.** `provision_dkim.sh` sets the key group to the web server group with
mode 640 (pre-launch, so re-provisioning existing keys is acceptable). The
signer logs an explicit `error_log` when a relay-fronted standard-domain send
finds the key missing or unreadable — an unsigned send must never be silent.

## R2-7 — Ownerless Fortress blobs become permanently undrainable rows

**Where:** `plugins/mailbox/includes/RelaySpoolConsumer.php` /
`storeRelayPending()` owner resolution, `relay-sealer` `.meta`.

**Problem.** A `key_kind=user` blob whose alias no longer resolves to a
single owner (deleted alias, grants became shared) stores a pending row with
`iem_sealed_owner_user_id` NULL — `DeferredIngest::pendingIds()` filters on
owner, so no drain can ever select it: permanently blank mail.

**Fix.** The blob is sealed to exactly one user public key, and the sealer
already holds it — write `public_key` into `.meta`. The consumer resolves the
owner primarily via `singleOwnerUserId()` and falls back to looking up the
vault user by that public key. Only if both fail (no matching vault — key
rotated away, nothing can ever open the blob) does it store ownerless, now
with an `error_log`. Rationale: the key in `.meta` is public material already
present in the relay's routing map.

## R2-8 — Map-freshness health check uses a different hash formula than push (permanent false alarm)

**Where:** `plugins/mailbox/includes/InboundEmailHealth.php`
`checkRelayMapFresh()`, `plugins/mailbox/includes/RelayMapSync.php` `push()`.

**Problem.** `push()` hashes five artifacts (including the Round 1
`srs_access`); the health check rebuilds and hashes four. The hashes can
never match, so the check is red on every relay-fronted deployment
immediately after a successful push.

**Fix.** One hash function, one place: add a static
`RelayMapSync::contentHash(array $artifacts): string` and use it from both
`push()` and `checkRelayMapFresh()`. A test asserts the two sides agree on
identical artifacts.

## R2-9 — Local rspamd health check false-alarms on relay-fronted deployments

**Where:** `plugins/mailbox/includes/InboundEmailHealth.php`
`checkContentSpamScanner()`.

**Problem.** Only `checkInboundMailServer()` got the relay-fronted no-op
guard; the spam-scanner check still probes rspamd on 127.0.0.1, which is
decommissioned along with the rest of the local mail stack — a red check the
admin cannot fix.

**Fix.** Make the check relay-aware, not a blanket no-op: relay-fronted
deployments still spam-score at parse time through the local rspamd
CONTROLLER (`mailbox_rspamd_controller_url`) — only the milter mode is
unused. When a relay is active, probe the controller host:port instead of the
milter port; colocated behavior is unchanged.

## R2-10 — Relay list shows every row the active relay's health, N× the network battery

**Where:** `plugins/mailbox/logic/admin_mailbox_relay_logic.php` (list loop).

**Problem.** The per-row health call takes no relay argument — every check
resolves `MailboxRelay::active()` internally, so a disabled row displays the
active relay's results, and each page view repeats the full battery (5s TCP
timeout + map build + DNS per hosted domain) once per row.

**Fix.** Run the battery once, above the loop, for the active relay only;
non-active rows show their own row-level facts (status, host, map version,
push/pull times) without health dots.

## Round 2 verification gates

Same gates as Round 1 (php -l, validator, bash -n, go vet + go test with the
new case-preservation tests, seal round-trip green), plus: a live-driver test
of `updateColumns()` with a boolean false, and the hash-agreement test
(R2-8). Bump the plugin to 1.36.1.

## Round 2 implementation notes (verification-pass catches)

An adversarial pass over the Round 2 fixes themselves caught and fixed three
more defects before commit:

- `relay_wg_register.php` fataled ("Class DbConnector not found") — Globalvars
  only loads DbConnector lazily inside `get_setting()`, which the CLI never
  calls. Explicit `require_once` added; without it R2-5's provision form
  stayed locked forever.
- The COLOCATED SRS path had the same case destruction as the relay path:
  `processEmail()` lowercased the recipient before the SRS check. The SRS
  check runs on the raw recipient; lowercasing happens after.
- The spec's PHP test gates had not been written. Added
  `plugins/mailbox/tests/relay_fix_pack_test.php` (15 assertions): contentHash
  determinism/coverage, SRS case round-trip (validates raw, rejects
  lowercased), and the live-driver `updateColumns()` boolean/null/int/string
  mix on a self-cleaning scratch row.

Also narrowed relay-side SRS acceptance to `SRS0=` only (exporter regexp +
sealer `isSRSLocalPart`): `SRSRewriter` generates and decodes nothing else, so
accepting `SRS1=` only spooled blobs the consumer could never decode —
another unobservable discard.

---

## Cross-cutting verification gates

Run after each fix and before declaring the pack done:

- `php -l` + `validate_php_file.php` on every touched PHP file.
- `bash -n` on `provision_relay.sh`.
- `go vet` + `go test` in `relay-sealer/`, including the new forged-A-R test
  (Fix 2) and forward-parity fixtures (Fix 5); the existing PHP↔Go seal
  round-trip must stay green.
- The Fix 1 sealed-content-survival functional tests are the regression gate
  for the whole deferred-ingest path — they must exist before any other fix
  touches these files.
- Bump `plugins/mailbox/plugin.json` version once for the pack.

## Documentation

Update `plugins/mailbox/docs/overview.md` (current-state only, no history):
the relay admin page and provisioning flow (Fix 10), relay-side spam
scanning (Fix 8), forward From-rewrite parity (Fix 5), SRS bounce handling on
relay deployments (Fix 6), the outbound DKIM rule for standard vs protected
hosted domains (Fix 4), and push-on-change map sync (Fix 7). Server manager
overview gains the provision/rebuild job types if job types are enumerated
there.
