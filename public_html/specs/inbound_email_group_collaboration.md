# Inbound Email — Group / Team Collaboration (Deferred)

## Status: deferred

This is the **team layer** for the inbound email plugin — turning a shared mailbox
(`legal@`, `info@`) into a real team queue. It is **deferred**. The near-term focus
is the *individual* self-hosted experience (a Proton-suite-style self-hosted webmail),
not multi-person shared inboxes. This spec records the group-collaboration work so it
is ready when the team use case becomes the priority; it is not scheduled now.

The individual-webmail maturity that used to live here (saved drafts, per-mailbox
signatures, rich-text compose, the member-context panel) has been **carved out** to the
individual track — those serve the single-user focus and are not gated on the team
work below. See "Carved out" at the end.

**Firm constraint — group mailboxes are Standard-level only.** The email
security levels (`inbound_email_security_levels.md`) above Standard rest on a
one-operator, one-key model; a shared mailbox would require multi-recipient
sealing, per-member key ceremonies, and member-revocation re-sealing. That is
a deliberate non-goal: a domain hosting a group mailbox stays at Standard, and
this spec's design assumes plaintext-at-rest semantics. If team + encryption
is ever wanted, it is a new spec, not an extension of this one.

## Context

The inbound email plugin already owns the hard infrastructure: provider-pluggable
ingest (Postfix *or* Mailgun behind one `InboundEmailRouter`), inbound SPF/DKIM/DMARC
verdicts, DKIM-signed + SRS forwarding with rate limits, tiered raw-message storage,
a Gmail-style reader (threading, read/star, full-text search, reply/reply-all/forward
as the mailbox), and **two-way IMAP sync** (CONDSTORE/QRESYNC, three-way flag merge,
label/folder membership reconciliation, sent + deletion sync). That receiving →
routing → sync layer is at or near parity with anything in the space.

What this spec adds sits in the layer *above* the reader: the workflow and
collaboration features that let **multiple people** share one mailbox without stepping
on each other. They are only worth building once the team/shared-inbox use case is the
target.

## Target when un-deferred: shared-inbox / team-helpdesk parity

There are two "self-hosted email" competitor sets, and they pull in opposite
directions. If/when this work is scheduled, the target is deliberately the
**shared-inbox / team-helpdesk** category (Front / Help Scout / Hiver), *not* the
self-hosted mail-*server* category (Mailcow / Mailu / Mail-in-a-Box).

**Not a target: self-hosted mail *server* parity.** Reaching it is a category change,
not a feature list — it requires *being* an IMAP+SMTP server with credentialed mailbox
accounts, quotas, native-client (phone / Thunderbird) access, and content spam
filtering. That is re-implementing Dovecot + rspamd + SOGo, it is where the "don't
self-host email" reputation bites hardest, and it abandons our only real edge (mail
tied to member records). We explicitly do **not** pursue it. See Non-Goals.

**The target: self-hosted shared inbox / team helpdesk parity.** We are roughly one
workflow layer away, and this framing matches the integration wedge. Everything below
builds toward it.

## Goals

- A shared mailbox (`legal@`, `info@`) becomes a real team queue: conversations can
  be owned, triaged, discussed internally, and closed — not just read.
- The inbox is trustworthy enough to *rely on* (content spam filtering in its own
  prerequisite spec), not just inspect.

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
`specs/implemented/inbound_email_content_spam_filtering.md`. An inbox that leaks obvious spam
loses trust faster than one missing assignment, so it lands before the workflow work
below. It is out of scope here beyond this pointer.

## Work

Ordered by priority. Phases 1–2 are the table-stakes shared-inbox layer; Phase 3 is
triage polish.

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

- **Tags** for triage. The local-tag substrate already exists: a custom label is a
  first-class `ilb_inbound_email_labels` row with genuine many-to-many membership
  (`ilm_`) in one global namespace, **decoupled from IMAP folders** (a folder may
  *bind* to a label to mirror it, but a label needs no folder and applies to
  store-only mailboxes). Filters apply labels as an action, including on import. So
  triage tags **are** labels — what remains for this phase is the triage-oriented
  surface (a queue/tag rail, bulk tag-from-the-list), not a new tag concept. See
  `specs/implemented/inbound_email_labels.md`.
- **Canned responses / saved replies** — a small library, inserted into the compose
  panel. Per-mailbox or shared; decide scope once.

### 4. Deferred within the team layer (not near-term even when this is scheduled)

Autoresponder / vacation; response-time & volume reporting. Real but none are
beachhead blockers.

Server-side filters/rules (a Sieve-equivalent for store/forward mailboxes) are
already split out and built: `specs/implemented/inbound_email_filters.md`
(Gmail-parity filters).

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

## Carved out → individual track

These were originally bundled here but are **individual webmail maturity**, not team
features. They serve the near-term individual / Proton-replacement focus and are not
gated on the team work above. They belong on the individual track:

- **Compose maturity** — saved drafts (persist the ephemeral compose panel),
  per-mailbox signatures (the alias editor; not DKIM signing), rich-text composer
  (minimal `contenteditable`, no heavy editor dependency, vanilla per theme rules).
- **Member-context panel** — beside an open conversation, show the linked member /
  registration / order resolved from the sender address. This is the integration
  differentiator and is valuable for a single user, so it moves to the individual
  track rather than waiting on the team queue.
- **Contacts / recipient autocomplete** — also individual-useful; moves with the above.

## Docs

No doc changes land with this spec — `docs/` describe current state only, and none of
this is built. When each phase ships, fold its description into
`plugins/inbound_email/docs/overview.md` (extend the **Mailbox Reader** section; do not
create a new doc file).

## Open decisions (resolve at implementation, not now)

- Conversation state keyed by `(thread_key, alias)` vs. a representative-message row —
  confirm against how `GROUP_KEY_SQL` defines a thread.
- Notes/@mentions on core messaging vs. plugin-local.
