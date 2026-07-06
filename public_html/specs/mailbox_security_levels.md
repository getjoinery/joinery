# Mailbox — Security Levels (Per-Domain Protection Posture)

**Status:** Draft / awaiting implementation
**Version:** 1.1
**Unifies:** `specs/mailbox_encryption_at_rest.md`,
`specs/mailbox_outbound_send_protection.md`,
`specs/mailbox_hardened_ingest_relay.md`. Those specs define the
mechanisms; this spec defines how they are packaged, chosen, and presented.

## Goal

Not every address deserves the same protection, and the protections have real
costs. The address used to sign up for a local dance club's newsletter does not
need — and should not pay for — the guarantees the operator's primary personal
identity needs. Let the user choose a **security level per domain**, at setup,
with the tradeoffs stated in plain outcome language.

## Why Three Levels

The three underlying specs don't form an arbitrary feature list — they answer
three distinct questions, and the questions stack:

1. *Can a compromised server read my stored history?* → encryption at rest.
2. *Can a compromised server speak and listen **as me, live**?* → outbound send
   protection + edge-sealed ingest + hidden origin.
3. (The baseline: *do I care at all?*)

Question 2's answers only make sense together: protecting the sending identity
while fresh inbound mail is readable (or vice versa) leaves the user's live
identity half-exposed while paying full ambient-capability costs. Splitting
them into separate levels would create four-plus options differing in ways
only a security engineer could weigh. Merging questions 1 and 2 into a single
"secure" level would force the passphrase-only user to give up automated
sending and run a relay box. Three levels, one per question, is the natural
count:

| | **Standard** | **Private** | **Fortress** |
|---|---|---|---|
| One-line meaning | The server manages this mailbox for you | Only you can read stored mail | Even a fully hacked server can't read new mail or send as you |
| Stored bodies/subjects/attachments/search index encrypted | — | ✓ | ✓ |
| Unlock ceremony required (enroll a passkey + print recovery codes; passphrase optional) | — | ✓ | ✓ |
| Automated sends from this domain (confirmations, notifications, mailing lists) | ✓ | ✓ | ✗ — sending is session-gated |
| Sending identity survives server compromise (DMARC-enforced) | — | — | ✓ |
| Fresh inbound mail sealed before reaching Joinery (relay at edge) | — | — | ✓ |
| Joinery IP visible in mail DNS | hidden once any relay exists — the relay fronts *all* hosted domains (routing is deployment-wide; sealing is per-level) | same | never — Fortress requires the relay |
| Filters/rules act | at receive | at receive | at next login |
| Previews / search | anytime | in-session | in-session |
| Extra infrastructure | — | — | relay box (provisioned once, shared by all Fortress domains) |
| Key-loss risk | none | mail unrecoverable if every unlocker is lost (all passkeys + recovery codes) | same as Private |
| Best for | club signups, newsletters, low-stakes addresses | mail worth keeping private, where automation must keep working | the address that *is* you — banking, identity, primary correspondence |

**Standard** is today's behavior, unchanged. **Private** is the
encryption-at-rest spec alone. **Fortress** is all three specs together.

## The Unit of Choice Is the Domain

MX records, SPF, DMARC, and DKIM are all domain-level facts — a mailbox cannot
have a different MX than its domain. So the level attaches to the **domain
record**, and every mailbox/alias on it inherits. This also resolves the
automated-send tradeoff recorded in the outbound spec cleanly, with no extra
mechanism: a subdomain is simply another domain entry with its own level. The
operator who needs `user@example.com` at Fortress *and* around-the-clock
confirmation emails puts the automated senders on `mail.example.com` at
Standard. Under Fortress's strict DMARC alignment the Standard subdomain's
keys cannot sign as the bare domain, so the split is safe by construction.

**Group-collaboration mailboxes are Standard-only — a firm product decision,
not a deferral.** Every level above Standard rests on the one-operator,
one-key model (it is what makes the crypto affordable); shared mailboxes would
require multi-recipient sealing and member-revocation re-sealing, which is a
different product. The constraint is enforced structurally in both directions:
a domain hosting a group mailbox (`mailbox_group_collaboration.md`)
cannot be raised above Standard, and a group mailbox cannot be created on a
protected domain — the editors simply don't offer the invalid combination.

**IMAP-source domains** (mail pulled from a remote provider — e.g. a gmail.com
feed) offer **Standard and Private only**: Fortress's guarantees are
meaningless there — the remote provider holds the plaintext and the sending
identity, and there is no MX to move. The level picker hides Fortress for
IMAP-source domains rather than disabling it with an explanation (guided
controls, not explainer prose).

## Key Management Across Levels

One operator, one key hierarchy. The first time any domain is set above
Standard, the setup flow runs the unlock ceremony from the encryption-at-rest
spec, once: enroll a passkey (the everyday unlocker — fingerprint/face, nothing
to memorize; `specs/passkeys_core.md`), print the one-time recovery codes, and
optionally set a passphrase. Every Private and Fortress domain seals to the
same user keypair; raising a second domain's level never re-runs the ceremony.
Dropping the last protected domain back to Standard does not delete the key
material (re-raising should not re-run the ceremony either).

## Setup Presentation

The level is chosen at **domain creation** (and changeable from the domain
editor), as a required three-option choice — FormWriter radio options styled as
cards, each carrying only: the name, the one-line meaning, a "best for" line,
and its tradeoff lines from the matrix above. Outcome language only — no
mechanism names (no "DKIM", "sealed DEK", "FTS5") at the point of choice.
Default selection: **Standard** (the choice with no obligations attached; the
user opts *into* responsibility, never out of it).

Choosing a level drives the guided setup that follows — the existing
Setup-tab pattern of copy-ready records and verify checks, branched by level:

- **Standard** → today's checklist, unchanged.
- **Private** → Standard's checklist, plus the unlock ceremony if this is the
  first protected domain (enroll a passkey, print recovery codes, optional
  passphrase). The recovery-codes step must state plainly: *lose every passkey
  device and these codes and the mail is gone forever* — and require explicit
  acknowledgment before dismissal.
- **Fortress** → Private's steps, plus the level-specific DNS shape (MX at the
  relay, SPF without the Joinery box, `p=reject` strict-alignment DMARC, the
  forwarding-subdomain records), relay provisioning if this is the first
  Fortress domain, and one confirm-gate stating the operational consequence in
  one line: *this domain cannot send mail unless you are logged in.* If the
  user needs automated sends, the gate offers the subdomain pattern as a
  one-click "add a Standard subdomain for automated mail" action rather than
  prose advice.

`InboundEmailSetupCheck` / `InboundEmailHealth` branch per level: each check
verifies the DNS and infrastructure shape *correct for that domain's level*
(the outbound spec already notes some checks invert for protected domains —
this spec makes the domain's level the branching key).

## Changing Levels Later

- **Raising** is a guided, in-session act:
  - Standard → Private: run the ceremony if needed, then the one-time
    in-window backfill from the encryption-at-rest spec (idempotent,
    `looksEncrypted()`-marked) — which converges each message to the lean
    sealed form *including destroying its plaintext raw* (inline column and
    store file), not merely sealing the columns.
  - Private → Fortress: DNS cutover checklist + relay enrollment; existing
    sealed mail is already in the right form.
- **Lowering** is allowed but warned: Fortress → Private reverts the
  *identity* posture — the SPF/DMARC/DKIM shape and where mail is sealed —
  and re-enables ambient capability; Private → Standard decrypts the archive
  in-session back to plaintext columns. The confirm gate states what protection
  is being given up in outcome language. **A level change never moves the
  MX**: routing is deployment-wide by the relay spec's fronts-every-domain
  rule, so a downgraded domain keeps receiving through the relay (its mail
  simply pass-through-seals to the transport key like any Standard/Private
  domain). Removing the relay itself is a separate deployment-level
  decommission with its own checklist — repoint every domain's MX, re-provision
  the colocated stack, reopen port 25 — never a side effect of one domain's
  level change.
- A level change is a domain-editor action gated on an active session (raising
  needs the key for backfill; lowering needs it for decryption — the gate is
  structural, not policy).

## The Locked-State Surface Contract

Logged in but locked is the state a Private/Fortress user sees most often, so
its behavior is defined once, here, for every surface — not invented per
screen. The rule: **every surface shows cleartext metadata; every action that
needs content becomes a one-tap unlock prompt, and the original action
continues after unlock without re-navigation.**

- **Thread list**: threading, unread counts, labels, folders, times, and sizes
  render normally; sender, subject, and preview show a neutral sealed
  placeholder. The mailbox is *navigable but not readable* — the at-rest
  guarantee made visible.
- **Search** on a protected mailbox: the box renders; submitting prompts
  unlock, then runs the query.
- **Opening a thread, downloading an attachment, composing/replying on a
  Fortress domain**: the same prompt, then the action proceeds.
- **Pending-parse (Fortress) messages** show the same placeholder as sealed
  ones — the user never sees a third state.
- **Native apps inherit the contract over `/api/v1`**: endpoints return
  metadata plus a `locked` flag instead of erroring, so clients render the
  same placeholders rather than failure states, and trigger the native unlock
  ceremony on content actions.

## AI Processing of Protected Mail

Recipes that read message content (`joinery_ai_email_triage.md`,
`joinery_ai_email_security_scan.md`, and any future pipeline job over mail)
interact with the levels through three rules. The same key-gated pattern also
covers the non-AI content reader: spam-feedback learning (`LearnSpamFeedback`
trains rspamd on body tokens) queues cleartext references and learns in-window
on protected domains, while ingest-time spam *scoring* is pre-seal and
unchanged — see the encryption spec § No Sideways Copies.

- **Processing is key-gated, not re-plumbed.** The recipe's scheduled poll and
  per-recipe processing log stay exactly as the triage spec resolved them; the
  only change is a gate: a message on a Private or Fortress domain can be
  digested only while an unlocked session's key is available. Until then it
  simply remains pending in the log — durable, in PostgreSQL, ciphertext at
  rest; **no plaintext side-queue exists at any level.** On Standard the gate
  is always open (today's behavior, unchanged). On Fortress the message also
  waits for deferred ingest, so the login order is: deferred parse → index
  fold → recipe catch-up. Nothing is lost by waiting, by construction: triage
  results are only ever *seen* in-session, so processing that only *runs*
  in-session costs the user nothing.
- **Derived outputs split along the same sealed/cleartext line as everything
  else.** A label is operational metadata — cleartext, so the sorted inbox
  works like folders do. Content-derived text (the one-line `iem_ai_summary`
  gist) is content in miniature — sealed under the message's DEK on protected
  domains and decrypted in-session alongside the previews it renders with.
- **The LLM provider is a disclosure, not a restriction.** The levels promise
  concerns what a *compromised* box can do; sending message text to a
  configured cloud provider is a deliberate operator choice — the same class
  of choice as forwarding mail to Gmail — and is not gated by level (many
  operators have no local model, and pretending otherwise would make Fortress
  unusable). The AI settings for a protected domain carry one line of
  disclosure — *recipes send message text to your configured provider; choose
  a local model if it must never leave the box* — and nothing more.

## Notifications & Native Apps

**What a push notification can say is set by when content legally exists,
not by policy:**

- **Standard**: full notification content (sender, subject, snippet), as the
  mobile spec defines it.
- **Private**: the notification is generated *at the ingest moment*, while the
  plaintext is legitimately in hand pre-seal — so sender and subject are
  available. No server-side plaintext survives the moment; what leaves is a
  one-way copy into the push channel. State the honest limit once: push
  content transits Google/Apple push services and rests on the lock screen —
  operators who don't want that flip a per-mailbox "generic notifications"
  toggle (title only, no content). A disclosure and a switch, not a level gate
  — same doctrine as the LLM-provider rule above.
- **Fortress**: generic by construction, not by choice — the message arrives
  already sealed, so the server *cannot* put content in the notification.
  "New mail to `user@domain`" (recipient and count are cleartext metadata) is
  the ceiling.

**The native apps follow the web's unlock model exactly:**

- Unlock in-app is the passkey ceremony via the platform credential managers
  (`specs/passkeys_core.md`, native open item), opening the same server-side
  unlock window; reading and search are server-decrypted in-window over
  `/api/v1`, and sealed attachments serve through the gated `File` stream via
  signed URLs as today. No mail-key material is ever stored in the app.
- **Offline cache is a device decision, not a server residual.** A mail app
  that caches messages for offline reading holds plaintext on the user's own
  device, like every mail client ever — governed by the OS sandbox, device
  encryption, and screen lock, and stated as such. Per-level default: caching
  on for Standard/Private, off for Fortress (turn-on-able with the same
  one-line disclosure), so the strictest posture's data lives only where its
  guarantees hold.

## Integration Points That Change

- **Domain data class** — new level field (see Schema).
- **Domain create/edit editor** (Accounts tree `+ Domain` / Edit) — the level
  picker cards and the level-driven guided steps.
- **`InboundEmailSetupCheck` / `InboundEmailHealth`** — branch expected-state
  per domain level (subsumes the per-spec check changes already listed in the
  outbound and relay specs).
- **Ingest, sending, and search paths** — each consults the domain level to
  pick its path (plaintext vs sealed store; ambient vs session-gated signing;
  direct MX vs relay pull; SQL vs FTS5 search). The three mechanism specs
  define both branches of each fork; this spec makes the domain level the
  single switch that selects between them.

## Schema Changes (via data-class `$field_specifications`)

- Domain record: `security_level` (smallint or enum-style varchar: standard /
  private / fortress).
- No per-mailbox override column — mailboxes inherit by design.

## Documentation to Update

- `plugins/mailbox/docs/overview.md` — a "Security levels" section: the
  three postures, the per-domain unit, the matrix, and the subdomain pattern
  for automated mail (current-state only).
- `docs/settings.md` cross-reference if any level defaults land in settings.

## Open Items to Confirm During Implementation

- Final level names are a product decision; "Standard / Private / Fortress" are
  working names. Criteria: outcome-evocative, one word, no security jargon.
- Whether Private→Standard downgrade (bulk decrypt to plaintext) is worth
  building at all pre-launch, or whether lowering below Private is
  delete-and-recreate until someone needs it.
- Where the level picker sits in the domain-create flow relative to the
  existing provider/hosting-mode choices (MX-hosted vs IMAP-source), since
  IMAP-source hides Fortress.
- Confirm the one-click "add a Standard subdomain for automated mail" action
  can pre-fill everything (domain entry, DKIM provisioning, DNS records) from
  the parent domain's setup state.
