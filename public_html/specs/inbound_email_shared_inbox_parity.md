# Inbound Email — Shared-Inbox Parity Roadmap

## Context

The inbound email plugin already owns the hard infrastructure: provider-pluggable
ingest (Postfix *or* Mailgun behind one `InboundEmailRouter`), inbound SPF/DKIM/DMARC
verdicts, DKIM-signed + SRS forwarding with rate limits, tiered raw-message storage,
a Gmail-style reader (threading, read/star, full-text search, reply/reply-all/forward
as the mailbox), and **two-way IMAP sync** (CONDSTORE/QRESYNC, three-way flag merge,
label/folder membership reconciliation, sent + deletion sync). That receiving →
routing → sync layer is at or near parity with anything in the space.

The gaps are all in the two layers *above* it. This spec records what is missing to
make the reader a genuine quality-parity competitor, and — importantly — decides
**which competitor** once, so future work doesn't drift toward the wrong target.

## The decision: target the shared-inbox category, not the mail-server category

There are two "self-hosted email" competitor sets, and they pull in opposite
directions. We pick one deliberately.

**Not a target: self-hosted mail *server* parity (Mailcow / Mailu / Mail-in-a-Box).**
Reaching it is a category change, not a feature list — it requires *being* an
IMAP+SMTP server with credentialed mailbox accounts, quotas, native-client (phone /
Thunderbird) access, and content spam filtering. That is re-implementing Dovecot +
rspamd + SOGo, it is where the "don't self-host email" reputation bites hardest, and
it abandons our only real edge (mail tied to member records). We explicitly do **not**
pursue it. See Non-Goals.

**The target: self-hosted shared inbox / team helpdesk parity (Front / Help Scout /
Hiver).** We are roughly one workflow layer away, and this framing matches the
integration wedge. Everything below builds toward it.

## Goals

- A shared mailbox (`legal@`, `info@`) becomes a real team queue: conversations can
  be owned, triaged, discussed internally, and closed — not just read.
- The inbox is trustworthy enough to *rely on* (compose maturity here; content spam
  filtering in its own prerequisite spec), not just inspect.
- The reader does something Help Scout structurally cannot: show the linked
  member / registration / order record beside the conversation. This is the
  differentiator, not a parity item.

## Non-Goals

- **No IMAP/SMTP server of our own.** We poll external IMAP and read in the web UI;
  we do not expose a mailbox for native mail clients to connect to. Out of scope,
  permanently, under this roadmap.
- **No credentialed mail accounts / quotas / passwords.** A "mailbox" stays an alias
  + a grant to a platform user. We are not a mailbox provider.
- **No mail-server-grade SMTP-time rejection** (greylisting, RBL/DNSBL reject-at-RCPT).
  Disposition stays reviewable (verdict, not bounce), consistent with the current
  spam model.
- **No encryption-at-rest in this spec.** Tracked separately; if adopted it forces a
  blind-index rethink of full-text search (see
  `specs/implemented/inbound_email_fulltext_search.md`).

## Prerequisite (split out): content spam filtering

The credibility floor — content spam filtering that catches authenticated bulk spam
the current auth-only verdict passes — is **its own spec, built first**:
`specs/inbound_email_content_spam_filtering.md`. An inbox that leaks obvious spam
loses trust faster than one missing assignment, so it lands before the workflow work
below. It is out of scope here beyond this pointer.

## Work

Ordered by priority. Phases 1–2 are the table-stakes shared-inbox layer; Phases 3–5
are polish + the differentiator; Phase 6 is deferred.

### 1. Conversation workflow (the table-stakes blocker)

A shared mailbox has grants (who may see it) but no notion of who *owns* a thread or
whether it is handled — which is the entire reason teams buy these tools. Add
**conversation-level state**, keyed at the thread level (read/star are per-message and
aggregated; ownership/status are per-conversation and need their own home).

Proposed model — a new conversation-state row per `(thread_key, alias)`:

- `assignee` (FK to user, nullable) — who owns it.
- `status` — `open` / `pending` / `closed` / `snoozed` (+ `snooze_until`).
- timestamps for opened/closed for later reporting.

New data class (e.g. `InboundEmailConversation`, table `iec_…`) following the
active-record `$field_specifications` convention; thread-key index via a migration
(the auto-updater does not create non-unique indexes — same pattern as
`iem_fulltext_idx`). Reader surface: an **Assignee** control and a **Status** control
on the open-thread toolbar, and queue filters in the left rail (Unassigned / Mine /
Open / Snoozed / Closed) alongside the existing All / Unread / Starred.

### 2. Collaboration (internal notes, mentions, collision)

- **Internal notes / @mentions** — discuss a thread without emailing the customer.
  *Build-generally check:* the platform already has core messaging/conversations and
  a `ntf_notifications` system — reuse them for the note thread + @mention
  notification rather than reinventing. A note is conversation-scoped (keyed by
  `thread_key`), never relayed, never visible on the wire.
- **Collision detection** — surface "X is viewing / replying" so two staff don't
  double-answer. A lightweight presence ping on the open-thread endpoint; no
  hard locking.

### 3. Triage (tags, canned responses)

- **Tags** for triage. The reader already has folder/label membership for IMAP feeds;
  a lightweight local tag (not tied to an IMAP folder) covers store-only mailboxes.
  Decide whether tags are a distinct concept or a presentation over local-only
  folders — pick one (up-front, not incrementally).
- **Canned responses / saved replies** — a small library, inserted into the compose
  panel. Per-mailbox or shared; decide scope once.

### 4. Compose maturity (noticed on day one)

Confirmed absent today, all noticed immediately by anyone used to webmail:

- **Saved drafts** — the current compose panel is ephemeral (a hidden FormWriter form
  populated in-memory). Persist drafts so a half-written reply survives navigation.
- **Per-mailbox signatures** — none today (existing "signature" code is DKIM signing).
  A signature appended on compose, editable on the alias editor.
- **Rich-text composer** — compose is plain today. Add an HTML composer. Respect the
  theme framework rules (vanilla by default; no framework pulled in just for this) —
  prefer a minimal `contenteditable` over a heavy editor dependency.

### 5. The differentiator: member-context panel

Not a parity item — the thing that makes the reader *better* than Help Scout for a
membership org. Beside an open conversation, show the **linked member / registration /
order** record resolved from the sender address: who they are, their tier, recent
events/orders, a link into their admin record. This is where the integration wedge
is realized and where effort is best spent once Phases 1–2 land.

### 6. Deferred (not near-term)

Autoresponder / vacation; server-side filters/rules (a Sieve-equivalent for
store/forward mailboxes); contacts / recipient autocomplete; response-time & volume
reporting. Real but none are beachhead blockers.

## Build-generally notes

Per the platform's "build abstractions, not product-specific code" principle, flag —
before building — which pieces are candidate **core** capabilities rather than
inbound-email-only:

- **Conversation workflow** (assignee/status/queue) and **internal notes/@mentions**
  overlap conceptually with the core messaging/conversations + notifications systems.
  Decide whether to build them in the plugin or extend core so a future shared-queue
  consumer (support tickets, etc.) reuses them. Decide once, at Phase 1 start.
- **Canned responses** may overlap with the email-template system; check before
  adding a parallel store.

## Docs

No doc changes land with this spec — `docs/` describe current state only, and none of
this is built. When each phase ships, fold its description into
`plugins/inbound_email/docs/overview.md` (extend the **Mailbox Reader** /
**Spam filtering** sections; do not create a new doc file).

## Open decisions (resolve at implementation, not now)

- Conversation state keyed by `(thread_key, alias)` vs. a representative-message row —
  confirm against how `GROUP_KEY_SQL` defines a thread.
- Tags as a first-class concept vs. local-only folders.
- Notes/@mentions on core messaging vs. plugin-local.
