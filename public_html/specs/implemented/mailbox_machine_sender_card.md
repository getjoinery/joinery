# Machine Sender Card: Guided Setup for a Separate Sending Identity

**Status:** Built 2026-08-08 — test-db suite 30/30, safe 94/94, db tier
242/242, ceremony browser-verified on dev. The live acceptance gate (the
jeremytunnell identity flip run through the card) is queued in the live
verification list and needs a deploy to that node first.
**Depends on:** `specs/mailbox_strict_sending_identities.md` (doctrine), DNS record
management (`includes/dns/`, built), `defaultreplyto` (built).

## Problem

The platform's doctrine is settled: humans send as the bare domain, automated
mail sends from a separate subdomain identity (`mail.<domain>`). The Setup tab
*enforces the prohibition half* — the Fortress checks fail a bare domain whose
SPF authorizes a provider, and their fix text says "automated mail belongs on a
separate sending subdomain" — but nothing walks the operator through *creating*
that subdomain identity. Both times it has been done (ScrollDaddy, and now
jeremytunnell) it was a hand ceremony: register the subdomain at Mailgun in
their dashboard, force DKIM authority, hand-publish SPF and DKIM TXT records,
flip `defaultemail`.

The cost of the gap is not hypothetical. On jeremytunnell (2026-08-08), a
Fortress domain with `defaultemail` still on the bare domain silently dropped
every ambient send — calendar reminder and summary emails were claimed in
their dedup ledger, then refused by the protected-identity guard
(`EmailSender.php`: "Refusing to send from a protected identity domain outside
the session-gated mailbox compose path"), and the only trace was one line in
the cron log. No card anywhere said "your system mail cannot send."

## What gets built

Four pieces, one incident behind all of them:

1. A new Setup tab card — **Automated mail identity** — plus the planner that
   feeds its records into the existing DNS publish box, plus one small
   provider capability interface so the registration step can be a button
   instead of a dashboard errand.
2. A single core eligibility check — *can this address carry transactional
   mail?* — called by every UI that turns transactional email on.
3. Refused sends written to the main event log at the `EmailSender`
   chokepoint, so a dropped scheduled email is visible in admin, not only in
   cron stdout.
4. Honest task results: a run with delivery failures reports them instead of
   "success — Sent 0".

### The card

Row id `domain.machine_sender`, layer `domain`, produced by
`InboundEmailSetupCheck`. Placement:

- **Domain-focused view**: rendered with the domain checks.
- **Mailbox view**: joins the **Sending** group (`_setup_is_sending_row()`),
  alongside `plugin.relay` / `domain.dkim` / `host.opendkim`. It must be
  **excluded from `_setup_is_receiving_row()`** — that filter admits every
  `domain`-layer row by default, and this row is outbound (same carve-out
  `domain.dkim` already has).

### On/off is derived, never a toggle

There is no new setting. The machine sender is **on** when `defaultemail`'s
domain is a proper subdomain of the focused registered domain (`mail.X` under
`X`, `mg.X` under `X`). It is **off** otherwise. One source of truth — the
setting that actually controls where system mail sends from — so the card can
never disagree with reality, and zero-config installs are unaffected.

Off-state rendering is scoped so nine hosted domains don't get nine nag cards:
the off-state card appears only on a focused domain that **is** `defaultemail`'s
domain (system mail currently sends as this bare domain, so "move it to a
subdomain" is this domain's business). On any other domain the row is simply
absent.

### Severity model

| State | Severity | Status | Reads as |
|---|---|---|---|
| Off | `OPTIONAL` | neutral | Doctrine explained, "Set up" offered. Never amber, never red. |
| On, all sub-checks pass | `REQUIRED` | `PASS` | "System mail sends as notifications@mail.X — registered, signed, aligned." |
| On, any sub-check fails | `REQUIRED` | `FAIL` | Red, naming the broken piece. |
| On, provider API unreachable | `REQUIRED` | `UNKNOWN` | Never a fabricated verdict. |

Severity escalates from `OPTIONAL` to `REQUIRED` the moment the operator turns
the feature on — an optional feature, but not an optional *half-configured*
feature. This is also what lets the `CheckDomainSetup` verdict roll-up count it
only when it matters.

### Sub-checks while on (machine domain M, parent P = focused domain)

1. **Registered and usable at the provider** — not mere existence: the
   provider's reported domain *state* (Mailgun already tracks it per sending
   domain for aligned submission). A registered-but-unverified or disabled
   domain fails with provider-specific guidance; `not_registered` likewise.
2. **DKIM live** — the provider-reported records for M exist in DNS (same
   comparison the existing DKIM row does, scoped to M).
3. **SPF on M** — TXT with the provider's `getSpfMechanism()` and a strict
   `-all` terminal (never the box or relay IP; provider-fronted shape only).
   Extra mechanisms the owner added alongside pass; a missing provider
   mechanism or a softer terminal fails.
4. **M itself is not protected** — `MailIdentityGuard::isProtectedDomain(M)`
   must be false, or ambient sends are refused exactly as on the bare domain.
   The failure text quotes the runtime refusal so the operator sees what their
   cron jobs see.
5. **Reply-To** — informational only: `defaultreplyto` set means replies land
   in a human mailbox; unset gets a hint, never red.

Alignment needs no check of its own: a message From M signed `d=M` is aligned
under any DMARC policy P publishes; sub-checks 1–3 are precisely what makes
that true, and the card's detail text says so. (Protection is exact-match on a
registered domain — `MailboxDkimSigner::isProtected()` loads the domain row —
so M under a protected P is sendable by construction; sub-check 4 guards only
the case where M was *itself* registered and protected.)

### Provider scope

v1 verifies against providers that implement `DkimRecordSource` (Mailgun,
SES). On any other transport the card still derives on/off, but the
registration and DKIM sub-checks render as guidance ("this provider cannot be
verified from here") rather than fabricated verdicts, and severity does not
escalate past those rows alone. Connected-account sending
(`ConnectedMailboxProvider`) is out of scope entirely — its From must be the
connected account, so a machine subdomain has no meaning there.

### Blockers with no domain to appear on

The off-state and incident cards are keyed to a focused domain. Two blocker
cases match no domain view: `defaultemail` empty, and `defaultemail`
syntactically invalid. Those must not be homeless — they surface on the admin
settings page (below) and as a row in the Setup tab's Advanced server-wide
diagnostics, so a blocked site always has at least one red surface.

A related suppression: when the focused domain is itself a subdomain of
another registered domain (someone registered `mail.X` as an inbound domain),
the off-state card does not render there — the parent's card owns the machine
sender question, and "set up `mail.mail.X`" is nonsense.

### Test send

A **Send a test email** action on the on-state card: one ambient
`EmailSender` send (no injected transport) to the logged-in admin's address.
This exercises the whole runtime path — protected-identity guard, provider
submission, alignment — and is the only sub-check that proves the guard rather
than inferring it. Uses the standard recheck/action plumbing of the page.
When `email_dry_run` is on, the action says so instead of reporting a
suppressed send as proof.

## DNS publish-box integration

`dnsPlan(P)` gains a `machineSenderPlan()` contribution: when the machine
sender is on (or the enable ceremony is mid-flight and the provider has issued
records), the plan for P's zone additionally desires:

- `TXT M` — SPF: `v=spf1 <provider mechanism> -all`
- The provider's DKIM records for M (TXT or CNAME, from `getDkimStatus(M)`)

Subdomain records living in the parent zone is established practice — the
forwarding subdomain's SPF/MX and the in-zone mailhost A record already plan
this way. The publish box then offers them in the same diff → authorize →
write flow, with ownership receipts in `dnr_dns_records`.

Planner rule (learned the hard way in the original DNS build): the plan is
computed from the desired state, **never harvested from failing check rows** —
an already-correct setup must still produce a full plan so it can be adopted.

## Enable ceremony (the off-state card's guided steps)

Ceremony style follows the send-protection ceremony: a settled step is stated,
not offered; the publish box renders only while something is unmet.

1. **Register `mail.P` at the provider.** The subdomain is fixed to `mail.P`
   — the doctrine's conventional choice (`news.P` stays reserved for bulk; a
   custom subdomain takes the manual path). One button when the configured
   provider implements the new capability interface (below); on success the
   provider returns the records to publish. A provider without the capability
   gets manual instructions naming its dashboard, and the ceremony continues
   from the records it reports via `getDkimStatus()` once registered.
2. **Publish DNS.** The publish box (step records now in the plan) or the
   manual record list for operators whose DNS host has no driver.
3. **Switch system mail.** Offered only once 1–2 verify. A local-part field
   (prefill `notifications` — owner decision 2026-08-08) plus `defaultreplyto`
   pre-filled with the focused domain's primary mailbox address and **saved
   unless cleared** (owner decision 2026-08-08: replies must work by default;
   an empty Reply-To is a deliberate act, not an oversight); sets
   `defaultemail` to `<local>@mail.P`. Written through the settings model the
   way `admin_settings` writes.

**The ceremony holds no state of its own.** Fixing the subdomain is what
makes that possible: after any reload, mid-flight progress is re-derived by
probing the provider for `mail.P` and DNS for its records — registered but
unflipped renders as "steps 1–2 settled, step 3 offered". The local part is
not needed until step 3, so nothing the operator typed can be lost.

Disabling is just pointing `defaultemail` elsewhere — the card returns to off.
Provider registration and DNS records may remain; they are inert capability
for a subdomain nobody sends as, and the off-state card says so rather than
nagging for cleanup.

## Prevention: the protect ceremony learns to look first

The incident's root cause was ordering: `defaultemail` lived on the domain,
*then* the domain was protected, and nothing said a word until cron mail
started dropping. The card detects that state; the send-protection ceremony
must prevent it. It gains a readiness row — "System mail still sends as this
domain" — that is unmet while `defaultemail`'s domain is the domain being
protected, with the machine sender ceremony offered as the fix. It warns
rather than hard-blocks (an owner heading for a deferred posture, or accepting
the gap knowingly, may proceed), but proceeding past it is an explicit act,
and the red card is waiting on the other side.

## Provider capability: SendingDomainRegistrar

New optional interface in `includes/EmailServiceProvider.php`, sibling of
`DkimRecordSource`:

```php
interface SendingDomainRegistrar {
    /**
     * Create $domain as a sending domain at the provider. Idempotent: an
     * already-registered domain returns 'ok'. Never throws.
     *
     * @return array{status:'ok'|'error'|'unreachable', error?:string}
     */
    public static function createSendingDomain(string $domain): array;
}
```

Mailgun implements it: `domains()->create()` with **DKIM authority forced to
the subdomain itself**, so keys are issued for M rather than inherited from P
— inherited authority is the known trap that breaks strict alignment
(`force_dkim_authority`, per the email-consolidation findings). Providers that
opt out lose only the one-button step, not the card.

## Fortress interplay — the incident state

On a Fortress domain P whose `defaultemail` is still `user@P`, every ambient
send throws at runtime. Under the severity table above, the machine sender is
"off" and the card sits gray — which is exactly the silence that ate the
calendar emails.

**DECIDED (owner, 2026-08-08):** this state renders as `FAIL` even though the
feature is off. A `defaultemail` that is by definition incapable of sending at
any time is an error, not an unstarted option. The card copy leads with the
consequence ("Automated mail from this site cannot send") and offers the
ceremony as the fix.

Precisely: red = the eligibility check below returns a blocker for
`defaultemail` **and no declared posture accounts for it**. Today no such
posture exists, so red is unconditional on the blocker. If the deferred
in-window posture (below) is ever built, it is a declared, step-up-gated
choice, and declaring it converts this card from a fault into a statement of
the posture — red stays reserved for the accidental state, where mail is
dropped and nobody chose that.

## The eligibility check — one function, called everywhere

Whether an address can carry transactional mail is currently knowable only by
sending one and watching it throw. The answer becomes a single core function:

```php
// includes/EmailSender.php
/**
 * Why $from cannot carry ambient/transactional mail right now, or null when
 * it can. $from defaults to the defaultemail setting. The predicates are THE
 * SAME calls the runtime guard in send() makes — a config-time verdict that
 * could drift from send-time behavior would be worse than none.
 *
 * @return string|null  Human-readable blocker, e.g. 'No system sender address
 *   is configured.' or the protected-identity refusal, or null = eligible.
 */
public static function transactionalSendBlocker(?string $from = null): ?string;
```

Blockers, in order: no address configured; not a syntactically valid address;
`MailIdentityGuard::isProtectedDomain(domainOf($from))`. The function is pure
read — no DNS, no provider API — so it is cheap enough for every settings page
render. (Whether the mail *authenticates* once sent is the machine sender
card's job; this function answers only "will `EmailSender` refuse it".)

**Callers at build time:**

- The machine sender card — sub-check 4 and the incident state are this
  function applied to M and to `defaultemail` respectively.
- The admin settings page — saving or rendering `defaultemail` shows the
  blocker inline when one exists.
- Every UI that turns on a transactional email feature surfaces the verdict
  beside the switch: the calendar settings page (summary/reminder dropdowns),
  and the bookings reminder configuration. A member enabling reminders on a
  site whose system mail cannot send is told so at that moment, not never.

**Standing rule (goes in the email-system doc):** any future feature that
enables transactional mail calls `transactionalSendBlocker()` during its
configuration flow and renders the result. One check, one wording, no
per-feature reimplementation.

## Refused sends land in the main log

The calendar incident's only trace was one `error_log()` line in cron stdout,
while the task reported a clean "success — Sent 0". Two fixes, both general:

1. **`EmailSender::send()` logs the refusal centrally before throwing.** At
   the protected-identity refusal (and any future eligibility refusal), write
   an `EventLog` row — `evl_event = 'email_send_refused'`,
   `evl_was_success = false`, `evl_note` naming the From address, recipient,
   subject, and reason — then throw as today. One chokepoint covers every
   caller: scheduled tasks, hooks, future features. The write dedupes to
   **once per From-address per day** — `evl_event_logs` carries no retention
   policy, and a caller without a dedup ledger of its own could otherwise
   refuse once per cron minute forever. The day's first row is the record;
   repeat refusals bump nothing (the red card, not the log, is the live
   signal).
2. **Task results tell the truth.** `CalendarEmailEngine::run()` gains a
   `failed` count (deliver() already knows); the `CalendarEmails` task reports
   it in its run message and returns error status when `failed > 0`, and
   writes its run record with `evl_was_success = false`. "Sent 0" with silent
   failures — the exact lie the incident produced — becomes impossible. Any
   engine-style sender built later follows the same contract.

## Interplay: deferred in-window sending (allowed for, not built here)

There is a third possible posture besides "refuse" and "machine sender":
queue the *intent* of a transactional send and execute it during the owner's
next vault unlock window, where the sealed DKIM key is available — the mail
then signs `d=<bare domain>` and passes strict DMARC legitimately, and nothing
ever sends unless the human is present. The platform for this already exists
(`VaultDeferredWork`, the in-window deferred work build); an owner choosing it
is choosing identity purity and presence-gated outbound on purpose.

It is deliberately out of scope here, with three rules recorded so this spec
does not foreclose it:

1. **Per-category suitability is declared, never assumed.** Digest-type mail
   (summaries, reports) defers well; timed reminders never do (late = dead by
   the engine's own missed-window rule); access-recovery mail never defers
   under any design — a reset email that waits for an unlock waits for the
   thing it exists to restore. On a deferred-posture site, an unsuitable
   category says "requires a machine sender", it does not silently queue.
2. **Intent is queued, not rendered messages** — re-render at send time
   in-window. No stale "today" content, no protected plaintext at rest in
   `equ_queued_emails`.
3. **The guard and `transactionalSendBlocker()` stay binary** ("can this send
   ambiently now"). Deferral is a consumer's choice about what to do with a
   blocked verdict, not a third guard state.
4. **The posture is declared, never inferred** — an explicit setting, step-up
   to change (the `ied_ai_processing_enabled` pattern), so the site's outbound
   posture is never changed silently. The declaration is what the card reads:
   blocker + no declaration = red (the incident state); blocker + declared
   deferred posture = the card states the posture and what it excludes
   ("timed reminders and recovery mail require a machine sender"), which is a
   description of a choice, not a fault.

If built, it is its own spec consuming `VaultDeferredWork`, and this card
grows a posture line rather than changing shape.

## Out of scope

- Marketing/bulk subdomain (`news.<domain>`) — doctrine reserves it; nothing
  here builds it.
- Per-domain machine senders on multi-domain deployments. `defaultemail` is
  site-wide, so there is one machine identity per deployment. If a hosted
  domain ever needs its own automated sender, that is a different feature.
- Moving the strict-sending-identities spec forward otherwise (bare-domain
  registration for compose, DMARC prescription changes) — this card
  operationalizes its machine-sender half only.

## Touch points

- `plugins/mailbox/includes/InboundEmailSetupCheck.php` — new row family
  `domain.machine_sender`, `machineSenderPlan()` into `dnsPlan()`, test-send
  action. Version bump.
- `plugins/mailbox/includes/mailbox_setup_scope.php` — sending-row include,
  receiving-row exclude. Version bump.
- `plugins/mailbox/logic/admin_mailbox_setup_logic.php` + setup view —
  ceremony POST actions (register, switch system mail), test-send action, the
  protect ceremony's system-mail readiness row. Version bumps.
- `includes/EmailServiceProvider.php` — `SendingDomainRegistrar` interface.
  Version bump.
- `includes/email_providers/MailgunProvider.php` — implement it. Version bump.
- `includes/EmailSender.php` — `transactionalSendBlocker()`, EventLog write at
  the refusal. Version bump.
- `includes/calendar/CalendarEmailEngine.php` + `tasks/CalendarEmails.php` —
  `failed` count, honest run status/record. Version bumps.
- `adm/admin_settings.php` (or its logic) — blocker rendered beside
  `defaultemail`. Version bump.
- `views/profile/calendar_settings.php` / `logic/calendar_settings_logic.php`
  — blocker verdict beside the enable controls. Version bumps.
- Bookings reminder configuration — same verdict. Version bump.
- `plugins/mailbox/plugin.json` — plugin version bump.
- No schema changes, no new settings, no migrations.

## Testing

- **safe:** on/off derivation from `defaultemail` vs focused domain (subdomain,
  equal, unrelated, multi-label); row lands in Sending and not Receiving;
  severity escalation off→on; `transactionalSendBlocker()` verdicts (empty,
  invalid, protected, eligible) and that its predicates match the send() guard.
- **db:** sub-check verdicts with a stubbed provider (`not_registered`,
  unverified/disabled state, records-mismatch, unreachable→UNKNOWN);
  `machineSenderPlan()` contents for on/off/mid-ceremony; ceremony step gating
  (step 3 absent until 1–2 verify) and stateless resume after reload;
  Fortress incident-state rendering red while off; homeless-blocker surfacing
  (empty/invalid `defaultemail` → Advanced row); registering M at the provider
  does **not** trip P's protected checks (`domain.provider`, foreign-DKIM
  probe) — the names cannot collide, which is exactly the kind of claim that
  gets an assertion rather than trust; a refused send writes the
  `email_send_refused` EventLog row (once per From per day) and still throws;
  the calendar engine reports `failed` and the task run record goes
  `evl_was_success = false`; the protect ceremony's system-mail readiness row
  unmet/met on either side of a flip.
- **live gate:** the jeremytunnell flip itself — already queued in the live
  verification list; running it *through this card* is the card's acceptance
  test (SPF/DKIM `d=mail.jeremytunnell.com`/DMARC pass at an external mailbox,
  no protected-identity refusal in the cron log, calendar summary delivered).

## Documentation (at build time)

- `plugins/mailbox/docs/overview.md` — Setup tab section gains the Automated
  mail identity card: states, sub-checks, ceremony, test send.
- `docs/email_system.md` — machine-sender doctrine note beside
  `defaultemail`/`defaultreplyto`; `transactionalSendBlocker()` and the
  standing rule that every transactional-enable UI calls it; the
  `email_send_refused` event.

## Decisions (owner, 2026-08-08)

1. **Default local part: `notifications`** — friendly and accurate for
   reminder/summary mail; with Reply-To routing replies to a human mailbox,
   the hostile `noreply` signal would be both unnecessary and untrue.
2. **`defaultreplyto` at step 3: prefilled with the domain's primary mailbox
   and saved unless cleared** — replies work by default; an empty Reply-To is
   a deliberate act rather than an oversight.

No open questions remain; the spec is decision-complete.
