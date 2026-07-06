# Inbound Email — Security Levels — Executor Package

**Status:** Ready for implementation
**Version:** 1.0
**Design authority:** `specs/inbound_email_security_levels.md` (v1.1) — the *why*, the
three-level matrix, and the locked-state contract. This is the *how*.
**Orchestrates (build these first):** `passkeys_core_executor.md`,
`inbound_email_encryption_at_rest_executor.md`,
`inbound_email_outbound_send_protection_executor.md`,
`inbound_email_hardened_ingest_relay_executor.md`. This package builds **no new
mechanism** — it adds the per-domain level field, makes that field the single switch that
selects each mechanism's already-built branch, and defines the locked-state surface
contract once for every surface. Build it last.

### Naming baseline (rename interaction)

**Run the rename first.** Paths use today's `plugins/inbound_email/…`; after the rename
apply dir `plugins/inbound_email/`→`plugins/mailbox/` and setting-key
`inbound_email_`→`mailbox_`. Class names (`InboundEmailDomain`, `MailboxService`,
`RecipeDispatcher`, `ModelQueryExecutor`), table prefixes (`ied_`/`iem_`), and line numbers
are rename-invariant. `joinery_ai` is a separate plugin — not renamed.

## The one new fact: the level field, and how it reconciles with what's built

Add to `InboundEmailDomain::$field_specifications` (`ied`):
```php
'ied_security_level' => array('type'=>'varchar(10)','is_nullable'=>false,'default'=>'standard'), // 'standard' | 'private' | 'fortress'
```
This is the **single source of truth** for a domain's posture. To keep the mechanism
packages (which were written against their own flags) correct without editing them, derive
on save in `admin_inbound_email_domains_logic()`:
- `ied_is_protected_identity = (ied_security_level === 'fortress')` (the outbound package's
  DKIM/DNS-inversion flag).
- Ingest seals (encryption package) iff `ied_security_level` is `'private'` or `'fortress'`
  (this refines the encryption package's "seal when key material exists" gate to "level ≥
  private **and** key material exists").
- Relay pull/deferred-ingest (relay package) treats a domain as Fortress iff
  `ied_security_level === 'fortress'`; the relay still *routes* all domains once it exists.

No per-mailbox override column — mailboxes/aliases inherit their domain's level by design.

## Level → mechanism-branch switch (the whole job, in one table)

| Concern | Standard | Private | Fortress | Mechanism package + switch point |
|---|---|---|---|---|
| Ingest store | plaintext columns | seal columns + attachments | seal (deferred at unlock) | encryption Phase 4 / relay Phase 5 — branch on level in `InboundEmailRouter::storeMessage()` |
| Search | SQL (today) | FTS5 in-window | FTS5 in-window | encryption Phase 6 — `MailboxService::listThreads()` q-path |
| Outbound signing | ambient (opendkim) | ambient | in-app, session-gated | outbound Phase 2/3 — `SmtpProvider` DKIM hook + `EmailSender` guard |
| DNS shape check | today | today | inverted (SPF/DMARC strict) | outbound Phase 6 — `InboundEmailSetupCheck::checkDomain()` branches on level |
| Receive path | colocated or relay | colocated or relay | relay required | relay Phase 4/8 |
| Filters act | at receive | at receive | at next unlock | encryption / relay |

Every fork above is already built by the mechanism packages; this package makes
`ied_security_level` the branching key each one reads. The mechanism specs' per-check
changes are subsumed here: `InboundEmailSetupCheck` / `InboundEmailHealth` branch expected
DNS/infra shape on the domain's level.

## Phase 0 — Preflight

Branch `security-levels`. Confirm the four mechanism packages are in place (their branches
are what this switches between). No new dependencies.

## Phase 1 — The level picker (domain editor)

- **Form** (`plugins/inbound_email/admin/admin_inbound_email_domains.php`, `domain_form` at
  line 62): add a **required three-option level picker** right after `ied_is_enabled`
  (line 100). Use FormWriter radio options styled as cards (confirm the radio/segmented
  helper; else `dropinput`), each carrying **outcome language only** — name, one-line
  meaning, "best for", tradeoff lines from the matrix. **No mechanism names** (no "DKIM",
  "DEK", "FTS5") at the point of choice. Default selection **Standard**.
- **IMAP-source hides Fortress:** add a `visibility_rule` on the picker keyed to the
  existing `domain_type` field (mirror `$type_visibility`, lines 72–80) so the Fortress
  card is hidden when `domain_type !== 'custom'` (IMAP source — Fortress is meaningless
  there; the remote provider holds plaintext and there's no MX to move).
- **Save** (`logic/admin_inbound_email_domains_logic.php`, set() block lines 40–52): add
  `$domain->set('ied_security_level', ...)` and the two derived sets above. On **raising**
  (Standard→Private/Fortress) the save is gated on an open unlock window (backfill needs the
  key); on **lowering** it's gated too (Private→Standard decrypts). The gate is structural.
- **Group-collaboration constraint (firm):** a domain hosting a group mailbox cannot be
  raised above Standard, and a group mailbox cannot be created on a protected domain — the
  editor simply doesn't offer the invalid combination (hide/disable the picker options,
  guided-controls style, no explainer prose).

## Phase 2 — The guided setup ceremony (level-driven)

Choosing a level drives the existing Setup-tab detect-instruct-verify flow, branched:
- **Standard** → today's checklist unchanged.
- **Private** → Standard's checklist plus, **if this is the first protected domain**, the
  unlock ceremony from the encryption package (enroll a passkey, print recovery codes,
  optional passphrase) — run **once** across all domains; raising a second domain never
  re-runs it, and dropping the last protected domain to Standard does not delete key
  material. The recovery-codes step requires explicit acknowledgment that losing every
  unlocker = permanent loss.
- **Fortress** → Private's steps plus the level-specific DNS shape (MX at relay, SPF without
  the box, `p=reject; aspf=s; adkim=s`, forwarding-subdomain records), relay provisioning
  **if this is the first Fortress domain** (relay Phase 6 `provision_relay` job), and one
  confirm-gate: *this domain cannot send mail unless you are logged in.* If the operator
  needs automated sends, the gate offers a one-click **"add a Standard subdomain for
  automated mail"** action that pre-fills a `mail.<domain>` domain entry + DKIM provisioning
  + DNS from the parent's setup state (outbound package's optional path).

## Phase 3 — The Locked-State Surface Contract (defined once, here)

The rule: **every surface shows cleartext metadata; every content action becomes a one-tap
unlock prompt, and the original action resumes after unlock without re-navigation.** "Locked
but logged in" is a Private/Fortress user's most common state.

### 3.1 Web reader

- **Thread list.** `MailboxService::listThreads()` (line 434) currently returns `subject`
  (547), `senders`/`sender` (548–549), `snippet` (550). The **server payload** is the
  withhold point: when the viewer's mailbox is protected and no unlock window is open,
  return threading/unread/labels/folders/times/sizes normally but replace
  sender/subject/snippet with a neutral sealed placeholder and add `locked: true`. The JS
  row builder `threadRow()` (`assets/mailbox_reader.js` 288; From 303, subject 309, snippet
  314–315) renders the placeholder as-is — the mailbox is *navigable but not readable*.
- **Search.** Folded into the thread list (the `q` filter). On a protected+locked mailbox
  the box renders; submitting a query prompts unlock (client sees `locked`), then re-runs.
- **Open thread / download attachment / compose on Fortress.** `getThread()` (line 596) /
  the attachment `File` stream / `send` all require an open window (encryption + outbound
  packages already enforce this). The reader (`renderThread()` JS 363) shows the one-tap
  unlock prompt on the `locked` response, then proceeds.
- **Pending-parse (Fortress) messages** show the **same** placeholder as sealed ones — never
  a visible third state.

### 3.2 Native `/api/v1` (the five mail actions — add the `locked` flag)

All are `requires_session=true`, gate on `MailboxViewer::fromSession()`. Insert the `locked`
flag so clients render placeholders and trigger the native unlock ceremony instead of
erroring:

| Action | Logic file | Insertion point |
|---|---|---|
| `inbound_email/thread_list` | `logic/thread_list_logic.php` | returns `listThreads()` verbatim (line 42) — add `locked` + placeholder swap here (or in `listThreads`) |
| `inbound_email/thread` | `logic/thread_logic.php` | returns `getThread()` (line 37) — withhold body + set `locked` |
| `inbound_email/send` | `logic/send_logic.php` | Fortress compose while locked → return `locked` instead of sending (`MailboxSender::send()`, line 56) |
| `inbound_email/mailboxes` | `logic/mailboxes_logic.php` | expose each mailbox's level + a `locked` state for the switcher |
| `inbound_email/thread_action` | `logic/thread_action_logic.php` | state mutations (mark/star/delete) are cleartext-metadata — **unaffected**, keep working while locked |

The native unlock ceremony is the passkey `vault-kek` derivation over `/api/v1` (passkeys +
encryption packages), opening the same server-side window.

## Phase 4 — AI processing & spam learning (key-gate, not re-plumb)

The AI email path rides the **generic recipe engine** — there is no bespoke email poll. The
single choke point where any recipe reads an `InboundEmailMessage` body/subject/sender is
`ModelQueryExecutor::query()` (`plugins/joinery_ai/includes/ModelQueryExecutor.php`, line 54;
PDO SELECT 155, `execute` 163, `fetchAll` 164, `wrapUntrustedFields` 166). Insert the gate
around 163–166:
- When the queried model is `InboundEmailMessage` and a row's domain is Private/Fortress:
  - **No unlock window open** → exclude the row from results; it stays **pending** in the
    recipe processing log (durable in PostgreSQL, ciphertext at rest). No plaintext
    side-queue at any level.
  - **Window open** → the sealed fields must be **decrypted in-window** before returning to
    the model. Route through the message model's in-window decrypt accessor (encryption
    package's `VaultCrypto`), not raw ciphertext columns — this is the one genuinely
    cross-plugin seam; keep the decrypt in the mail plugin and have `ModelQueryExecutor`
    consult a model-declared "sealed fields" hook (same discipline as the File decrypt hook
    and the DKIM signer hook). Standard rows: unchanged (gate always open).
- On Fortress the message also waits for deferred ingest, so the login order is: deferred
  parse → index fold → recipe catch-up. Nothing is lost — triage results are only ever
  *seen* in-session.
- **AI exposure opt-in lands in the same class edit** the triage spec already requires: add
  `$ai_readable = true` and `$ai_untrusted_fields = ['iem_sender','iem_subject','iem_body_plain']`
  to `InboundEmailMessage` (not declared today). The `iem_ai_summary` output is
  content-in-miniature — sealed under the message DEK on protected domains, decrypted
  in-session with the previews it renders alongside; a label is operational metadata —
  cleartext.
- **LLM provider is a disclosure, not a level gate.** Sending message text to a configured
  cloud provider is an operator choice (like forwarding to Gmail). The AI settings for a
  protected domain carry one disclosure line — *recipes send message text to your configured
  provider; choose a local model if it must never leave the box* — and nothing more.
- **Spam learning** (`LearnSpamFeedback`, `plugins/inbound_email/tasks/LearnSpamFeedback.php`,
  `run()` 41): it ships the **raw** message via `getRawMessage()` (line 79) to rspamd's
  learn endpoint. Gate at line 79 — skip a Private/Fortress row's raw unless a window is
  open (it already skips absent raw at 80–82); learning happens in-window like AI. Ingest
  *scoring* is pre-seal and unchanged.

## Phase 5 — Changing levels later

- **Raising** (in-window): Standard→Private runs the ceremony if needed, then the one-time
  idempotent backfill (encryption Phase 9 — converge each message to lean sealed form
  *including destroying its plaintext raw*). Private→Fortress = DNS cutover + relay
  enrollment; existing sealed mail is already correct.
- **Lowering** (in-window, warned): Fortress→Private reverts the identity posture (SPF/DMARC/
  DKIM shape, where mail is sealed) and re-enables ambient capability; Private→Standard
  decrypts the archive back to plaintext columns. The confirm gate states, in outcome
  language, what protection is given up. **A level change never moves the MX** — the relay
  fronts every domain, so a downgraded domain keeps receiving through the relay
  (pass-through-sealed to the transport key). Removing the relay is a separate deployment
  decommission with its own checklist (repoint every MX, re-provision colocated, reopen port
  25) — never a side effect of one domain's level change.

## Phase 6 — Notifications & native apps

- **Push content is set by when plaintext legally exists, not policy.** Standard: full
  (sender/subject/snippet). Private: generated at the ingest moment (pre-seal, plaintext
  legitimately in hand) → sender + subject available; a per-mailbox "generic notifications"
  toggle (title only) for operators who don't want content on the lock screen — a disclosure
  + switch, not a level gate. Fortress: generic by construction — the message arrives sealed,
  so the server *cannot* put content in the push; ceiling is "New mail to `user@domain`"
  (recipient + count are cleartext).
- **Native offline cache** is a device decision, not a server residual (plaintext on the
  user's own device, governed by OS sandbox/device encryption/screen lock). Per-level
  default: caching on for Standard/Private, **off for Fortress** (turn-on-able with the same
  one-line disclosure).

## Phase 7 — Setup/health branching (subsumes the per-spec check changes)

`InboundEmailSetupCheck` / `InboundEmailHealth` branch expected DNS/infra shape on the
domain's `ied_security_level` — this is the single branching key the outbound and relay
packages' check changes key off (SPF inversion, strict DMARC, MX-at-relay, tunnel/spool
health). No new check logic here beyond making level the switch.

## Phase 8 — Docs

`plugins/inbound_email/docs/overview.md` — a "Security levels" section: the three postures,
the per-domain unit, the matrix, and the subdomain pattern for automated mail
(current-state voice). `docs/settings.md` cross-reference if any level default lands in
settings.

## Phase 9 — Verification (acceptance gate)

9.1 `php -l` + `validate_php_file.php` on every edited PHP file.

9.2 On `dev.getjoinery.com`:
- **Picker:** domain editor shows three outcome-language cards, default Standard; Fortress
  hidden for an IMAP-source domain; picker refuses a group-mailbox domain above Standard.
- **Raise Standard→Private:** ceremony runs once; backfill seals + destroys raw; a second
  domain raised to Private does not re-run the ceremony.
- **Locked-state web:** a Private mailbox while locked shows threads with placeholders +
  metadata; search/open/attachment prompt one-tap unlock, then resume.
- **Locked-state native:** `thread_list`/`thread` return `locked:true` + metadata (no
  content); `thread_action` (mark read) still works locked.
- **AI gate:** a triage recipe over a Private domain skips messages while locked (they stay
  pending), digests them after unlock; `iem_ai_summary` is sealed on protected domains.
- **Spam gate:** `LearnSpamFeedback` skips a locked Private message's raw.
- **Fortress send:** locked compose returns `locked`; the automated-subdomain one-click adds
  `mail.<domain>` at Standard.
- **Lowering:** Private→Standard decrypts to plaintext with the give-up-protection gate; MX
  does not move.

9.3 `batcat` commands for each edited file (do not run them).

## Open items the executor confirms against the running system (not decisions)

- Final level names (product decision; "Standard/Private/Fortress" are working names —
  outcome-evocative, one word, no jargon).
- The exact FormWriter radio/segmented-card helper for the picker (else `dropinput`).
- Whether Private→Standard bulk-decrypt is worth building pre-launch or is
  delete-and-recreate until needed (design open item).
- The `ModelQueryExecutor` sealed-fields hook shape (Phase 4) — the one cross-plugin seam;
  keep the decrypt in the mail plugin.
- Where the picker sits relative to the `domain_type` (MX vs IMAP) choice, since IMAP hides
  Fortress.
