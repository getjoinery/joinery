# Inbound Email Spam Filtering

## Summary

Add spam filtering to the inbound email plugin. The platform already *captures* SPF/DKIM/DMARC verdicts on every stored message but never acts on them. This spec acts on the one signal that matters — **DMARC** — to assign a first-class **spam verdict** to each message, and surfaces judged-spam mail in a **Spam view** instead of the inbox, without rejecting or silently dropping anything.

Spam is modeled as a **message attribute** (`iem_spam_verdict`), not as a folder membership. That single field is what lets one Spam view work identically for locally-received mail (Postfix/Mailgun/SES) and IMAP-polled mailboxes, and it leaves a clean slot for a content scanner (rspamd / SpamAssassin) to be added later behind the same disposition.

There is deliberately **no in-app scoring engine**. See "Why no scorer" below.

## Goals

- Act on the DMARC verdict we already store, instead of logging and ignoring it.
- Produce a single spam verdict per message that the reader can filter on, uniformly across every ingest path.
- Keep mail forgiving: judged-spam is moved to a Spam view, never hard-rejected or silently discarded.
- Stop the platform from relaying spam: judged-spam is not forwarded to external destinations.
- Leave a clean, no-rework path to add a content scanner (rspamd / SpamAssassin) later if authenticated bulk spam becomes a problem.

## Non-Goals

- Computing SPF/DKIM/DMARC ourselves. We continue to consume verdicts from trusted sources only (provider webhooks, Postfix milters). Unchanged.
- Replacing the MTA-edge defenses (Spamhaus/SpamCop/Barracuda RBLs in Postfix, webhook provider pre-filtering). Those stay; this layer sits behind them.
- A weighted multi-signal scoring engine with tunable weights/thresholds. (Considered and dropped — see below.)
- Content classification in-app (Bayesian/ML). Delegated to an optional external scanner, deferred.
- Manual per-sender / per-domain block lists. (Proactive manual blocking sees little real-world use; value is in automatic disposition, which the verdict provides.)

## Why no scorer

The first draft of this spec proposed a `SpamScorer` that aggregated weighted signals (DMARC/SPF/DKIM fails, unverified-auth, sender reputation, external scanner) into a score and two thresholds. That was dropped on closer analysis:

- **The auth contributors were redundant with DMARC.** DMARC is alignment-based and already incorporates SPF and DKIM. Weighting SPF/DKIM separately catches essentially nothing a DMARC verdict alone doesn't.
- **Tuning had no data.** The platform is pre-launch with no production inbound mail, so the weights and thresholds would be guesses tuned against nothing.
- **The real spam-catching power was never in the weighted math.** The only contributor that catches *authenticated bulk spam* (junk from real domains that pass their own DMARC — the majority of real-world spam volume) was the external scanner. That is an optional, ops-provisioned component, independent of the aggregation machinery.

So the engine was a redundant middle layer. We act on DMARC directly now, and when authenticated junk shows up in volume, add the external scanner directly — its verdict writes the same `iem_spam_verdict` field, with no aggregation layer in between.

Protection layers, for context:
1. **MTA RBLs** (Spamhaus/SpamCop/Barracuda) — already live; reject known-bad senders at the edge.
2. **DMARC** — this spec; catches spoofing, forged-sender phishing, and unauthenticated junk.
3. **Content scanner** (rspamd/SpamAssassin) — deferred; the only layer that catches authenticated bulk spam.

## Design Decision: verdict-on-message, not folder membership

The plugin has a folder model (`iif_inbound_imap_folders` + the `imf_inbound_message_folders` join table) with a `ROLE_JUNK` ('junk') role. The obvious-looking move is to drop spam into a Junk folder. We are **not** doing that:

- `InboundImapFolder` is hard-owned by an IMAP account — `iif_iia_inbound_imap_account_id` is `NOT NULL`, and folder rows are created *only* inside IMAP-sync code. Locally-received aliases (Postfix/Mailgun/SES store mode) have **no folders at all** and rely on the null-folder "All Mail" view.
- Landing local spam in a Junk folder would require generalizing folder ownership to aliases — a real refactor of the IMAP-centric folder model — just to express one boolean.

Instead, spam is a verdict on the message:

- The DMARC rule writes `iem_spam_verdict` for locally-received mail.
- For IMAP-polled mail, the remote server already decided — so when a message is ingested into a folder whose `iif_role = 'junk'`, we set `iem_spam_verdict = 'spam'`. (`iif_role` is currently dead metadata — nothing reads it — so this gives the role its first real meaning and unifies both paths into one notion of "spam.")
- The reader's Spam view filters on the verdict, so it works for every mailbox type with no folder-ownership changes.

## Data Model Changes

### `InboundEmailMessage` (`data/inbound_email_message_class.php`)

Add one field to `$field_specifications`:

| Field | Type | Notes |
|---|---|---|
| `iem_spam_verdict` | `varchar(10)`, nullable | `ham` or `spam`. NULL = not evaluated (e.g. filtering disabled). |

Verdict constants on the class: `SPAM_VERDICT_HAM`, `SPAM_VERDICT_SPAM`. (A `spam_score` column can be added later, alongside the deferred scanner, without touching disposition or reader plumbing.)

### `MultiInboundEmailMessage` (`getMultiResults()`)

Add filter keys:

- `spam_verdict` — exact match (e.g. `'spam'`).
- `not_spam` — boolean convenience; when true, excludes rows where `iem_spam_verdict = 'spam'` (NULLs and `ham` pass). Used by the default inbox view.

No raw column names through the filter interface — these are option keys per the Multi-class convention.

## Classification Rule

No new class. A small private helper in the router classifies from the auth verdicts already read at store time:

- `iem_dmarc_result = 'fail'` → `spam`
- DMARC absent (no verdict — i.e. `none`/`unverified`) **and** both `iem_spf_result` and `iem_dkim_result` are `fail` → `spam`
- otherwise → `ham`

The first clause is the primary rule and applies wherever a DMARC verdict exists (Postfix milters, SES). The second is a fallback for providers that supply SPF/DKIM but no DMARC (Mailgun, SendGrid), so this layer gives them *some* coverage instead of none. It requires **both** SPF and DKIM to fail, because raw SPF/DKIM lack the alignment check that makes DMARC trustworthy — a single failure has too many legitimate causes (forwarding breaks SPF; some legit mail breaks DKIM), whereas both failing is a clean "even basic auth broke" signal.

Governed by the master gate `inbound_email_spam_filtering_enabled` (default off; when off, verdict stays NULL and behavior is exactly as today). The strict rule is safe precisely because the disposition is a reviewable Spam view, never rejection — a false positive costs a click, not a lost message.

## Router Integration (`includes/InboundEmailRouter.php`)

**Store path (`storeMessage()`, ~lines 260–328):**
- After building `$auth`, set `iem_spam_verdict` on the row passed to `InboundEmailMessage::CreateEntry()`.
- No folder membership is created (consistent with current local-store behavior). The verdict alone drives the reader.

**Forward path:**
- If the verdict is `spam`, **do not forward** to external destinations — forwarding spam burns our sending reputation and can relay abuse. Log the transaction with a new log status `spam_held`.
- If the alias is forward-*and*-store, the message is still stored (with its spam verdict) so it stays reviewable in the Spam view; only the outbound forward is suppressed.

**IMAP ingest path (`ImapIngestor::ingestOne`):**
- When a message is recorded into a folder with `iif_role = 'junk'`, set `iem_spam_verdict = 'spam'`. No DMARC rule runs for IMAP mail — the remote already classified it.

### Log status

Add `spam_held` to `inbound_email_log_class.php` status values (joining `forwarded`, `stored`, `rejected`, `discarded`, `rate_limited`, `store_capped`, `bounce_forwarded`, `error`). Used when a forward is suppressed due to a spam verdict.

## Reader / UI Changes

**Mailbox reader (`MailboxService::listThreads`, `ajax/mailbox_list.php`):**
- Default inbox view gains `not_spam => true` — `spam`-verdict messages drop out of the inbox.
- A new **Spam** view (a pseudo-folder selectable in the reader, alongside the existing folder list) filters `spam_verdict => 'spam'`. Works for local and IMAP mailboxes alike because it reads the verdict, not folder membership.

**Manual correction:**
- In the reader, "Not spam" on a spam message and "Mark as spam" on an inbox message set `iem_spam_verdict` directly.

All forms use FormWriter; no hand-rolled HTML. The Spam view and per-message controls follow the existing reader's vanilla-HTML5 patterns (no Bootstrap/jQuery).

## Settings (`plugin.json`)

Add, with factory defaults:

```json
{ "name": "inbound_email_spam_filtering_enabled", "default": "0" }
```

That is the only required setting. Forward suppression applies whenever filtering is enabled (spam is never relayed); no separate toggle.

## Integration Point Inventory

Every place a message enters or is read, decided up front:

| Path | Verdict behavior |
|---|---|
| Postfix handler (`utils/inbound_email_handler.php` → router) | Primary DMARC rule (milter supplies the verdict). |
| Mailgun webhook | SPF/DKIM both-fail fallback (no DMARC field from Mailgun). |
| SES webhook | Primary DMARC rule; SES supplies a real DMARC verdict. |
| SendGrid webhook | SPF/DKIM both-fail fallback (no DMARC field from SendGrid). |
| IMAP ingest | Verdict derived from remote `junk`-role folder membership; no auth rule. |
| Reader display | Inbox excludes `spam`; Spam view shows `spam`; manual corrections write the verdict. |

Note: Postfix and SES yield a real DMARC verdict and get the full primary rule. Mailgun/SendGrid have no DMARC field, so they rely on the SPF/DKIM both-fail fallback — weaker (no alignment) and narrower than DMARC, but better than nothing, and backed by those providers' own upstream pre-filtering before delivery.

## Documentation

On implementation, add a **"Spam filtering"** section to `plugins/inbound_email/docs/overview.md` describing (in current-state voice): the verdict model (`iem_spam_verdict`), the classification rule (primary DMARC-fail plus the SPF/DKIM both-fail fallback for no-DMARC providers), the `inbound_email_spam_filtering_enabled` gate, the forward-suppression behavior, the IMAP junk-folder mapping, and how the Spam view and manual corrections work. Per the docs rule, that section describes the end state only — the rationale, the rejected scorer, and the rejected folder-membership approach stay in this spec and git history.

## Testing

- `tests/models/` — verdict field persists and round-trips; `MultiInboundEmailMessage` `spam_verdict` / `not_spam` filters return correct sets.
- Classification rule unit coverage: DMARC-fail → spam; DMARC-pass → ham; no-DMARC + both SPF/DKIM fail → spam; no-DMARC + only one of SPF/DKIM fail → ham; no-DMARC + both pass → ham.
- `tests/integration/` — feed messages through each ingest path and assert disposition: DMARC-fail message stored-not-forwarded with `spam_held` logged; DMARC-pass message forwarded/stored normally; a no-DMARC message with both SPF and DKIM failing (Mailgun/SendGrid shape) classified spam; IMAP junk-folder ingest yields a spam verdict.
- Verify default inbox view excludes spam and the Spam view shows it, for both a local alias and an IMAP-backed mailbox.

## Rollout

- Ships **off** (`inbound_email_spam_filtering_enabled = 0`); existing behavior is byte-for-byte unchanged until enabled.
- Schema change lands via the data-class `$field_specifications` + plugin "Sync with Filesystem" (no migrations).
- No data migration needed — the platform is pre-launch with no production inbound mail to reclassify.

## Deferred: content scanner (rspamd / SpamAssassin)

Out of scope for this spec, documented so the path is clear. When authenticated bulk spam (mail that passes DMARC but is junk) becomes a problem, add an rspamd or SpamAssassin milter to the Postfix chain. It stamps an `X-Spam` header; the router reads it and, on a spam result, sets `iem_spam_verdict = 'spam'` — the same field, the same disposition, the same Spam view. No rework of anything in this spec. Webhook providers can't host a milter, so this remains a Postfix-path enhancement; webhook inbound continues to rely on the provider's own filtering.
