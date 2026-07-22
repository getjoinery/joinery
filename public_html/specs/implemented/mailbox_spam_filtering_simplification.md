# Spam filtering: one switch, scanner in the stack, learning everywhere

## What this is

A site owner should be asked one question about spam: *do you want suspected
spam moved out of the inbox?* — plus one genuinely optional capability: *should
this deployment learn from what its users mark?* Everything else — the scanner,
where a verdict came from, which mail topology is in play — is the platform's
problem: the scanner ships with the mail stack, and how it is used is derived
from state that already exists. Neither question ever produces a command for
the owner to run.

Today they are asked two questions, one of which is really an infrastructure
decision ("run rspamd on this box"), both default to off, and the learning
capability only actually works in one of the four ingest paths. This spec
collapses the choice to one switch plus one sub-option, ships the scanner with
the stack, and makes learning real on every path that can carry it.

## Where this starts from

Two settings gate spam today, both defaulting to `0`:

- `mailbox_spam_filtering_enabled` — the master gate. Off ⇒ `classifySpam()`
  returns NULL and no verdict is stored, however the message scored.
- `mailbox_content_spam_filtering_enabled` — nominally "content filtering",
  actually two different things at once: install a local rspamd, *and* run the
  learning loop. It has no admin UI, so it can only be changed by a direct
  database write.

**Already fixed (`InboundEmailRouter` 1.22), not part of this build:** reading
a verdict that arrived with the message — the `X-Spam` header a relay or local
milter stamped, or a webhook provider's flag — no longer depends on the local
scanner setting.

## The four ingest paths (the complete map)

Every inbound message reaches the store through exactly one of these. Column
two is where its content-spam verdict comes from **today**; column three is
where it comes from **after this spec** when learning is on.

| path | content verdict today | with learning on |
|---|---|---|
| **P1 — Colocated Postfix** (SMTP → opendkim/opendmarc → rspamd milter → pipe handler → `storeMessage`) | local milter's `X-Spam` header (includes Bayes when installed) | unchanged — the milter already scores with the tenant corpus |
| **P2 — Relay spool** (relay's stateless rspamd stamps `X-Spam` inside the sealed raw → `PullRelaySpool` → `RelaySpoolConsumer` → store ingest; plus the Fortress deferred-parse variant `parsePendingMessage`) | relay's header (static rules only, Bayes off by design) | local HTTP scan at ingest **replaces** the relay's header |
| **P3 — Webhook provider** (Mailgun/SendGrid/SES HTTPS POST → dispatcher passes `$provider_spam`) | provider's spam flag | local HTTP scan at ingest **replaces** the provider flag |
| **P4 — IMAP poll** (`ImapIngestor`; junk-role folder ⇒ spam verdict) | the remote mailbox's own filing | unchanged — the remote already curated it (see Out of scope) |

Two hard facts drive the P2/P3 rows:

1. **Spooled and webhook mail never transits the local milter.** The relay
   seals raw files into a spool the main box pulls; webhook mail arrives over
   HTTPS. A milter-only scanner can never score either. The scan must happen
   at ingest, over HTTP.
2. **A verdict from a Bayes-less scanner cannot be rescued.** If the relay's
   static rules flag a newsletter the user keeps marking "not spam", OR-ing
   the local verdict in can only ever add spam, never subtract it — the user's
   corrections would never change a disposition. That is why the local verdict
   **replaces** the upstream content signal rather than OR-ing with it: the
   local scanner runs the same static ruleset *plus* the tenant corpus, so it
   is strictly better informed. (Auth-rule classification — DMARC-fail,
   SPF+DKIM both-fail — is unchanged and still OR'd in by `classifySpam()`.)

## Decisions

### D1 — One switch, on by default

`mailbox_spam_filtering_enabled` becomes the only spam question most owners
ever see, and its factory default flips to `1`.

On means: use every verdict available, wherever it came from. Suspected spam
lands in the reviewable Spam view; it is never rejected, bounced, or deleted,
and it is not forwarded. A wrong guess costs a click.

Default-on is safe precisely because of that disposition, and because the auth
verdicts it acts on are already computed and recorded for every message
regardless of this setting. Default-off buys nothing and means a new
deployment silently files spam into the inbox.

### D2 — The scanner ships with the mail stack; only its *use* is derived

The scanner is never a user setting, and it is not a derived install either:
`install_email.sh` installs rspamd + redis **unconditionally**, and the
platform never removes them. Every box that hosts its own mail has a scanner
from birth, so any later spam choice is a pure settings toggle — asking the
owner to paste an install command on day 2 is a UX failure this decision
exists to prevent. Idle, the scanner costs a dormant service (~100–150MB).

What IS derived is how the scanner is used:

```
scanAtIngest() =
       learningEnabled()                      // a tenant corpus exists that upstream lacks
   AND somethingUpstreamScanned()             // colocated mail was already scored by this
                                              // box's own milter — never re-scan it

somethingUpstreamScanned() =
       resolved inbound provider is a webhook // InboundProviderRegistry::active(), NOT the raw
                                              // setting — empty/unknown falls back to postfix
    OR topology mode is not 'colocated'       // InboundEmailSetupCheck::topology()
```

A box with **no** local mail stack — webhook-only, or relay-fronted from birth
— never ran a root script of ours, so it has no scanner and cannot be given
one automatically (plugin activation is a web action without root; a shared
host may have no root at all). Learning is simply unavailable there: the
Settings page disables the checkbox with the reason, and an operator who wants
it runs `provision_spam_scanner.sh install` by hand. Scanner presence is
always **observed** (the controller answers on its port), never stored.

The predicates read the **resolved** provider, not the raw `mailbox_provider`
row, because the registry falls back to postfix when the setting is empty or
names an unknown provider — the policy must agree with what actually ingests
mail.

### D3 — Learning is the one advanced option, and it works everywhere

The one thing a local scanner offers that no relay or provider can is a spam
corpus of *this deployment's own mail*, taught by users marking messages spam
and not spam. A shared relay is deliberately stateless — one model trained
across every tenant's mail is a privacy leak in token form and a poisoning
vector — so this capability genuinely cannot be delegated upstream. Relay
users are exactly the privacy-forward audience this is for; "learning only
works if you expose your box as the MX" is not an acceptable answer.

Setting `mailbox_content_spam_filtering_enabled` is renamed
`mailbox_spam_learning_enabled` (default `0`) and reframed from infrastructure
to outcome: **"Learn from what users mark as spam."** It is a sub-option of D1
— meaningless with nothing filing spam — and it runs on the scanner that
ships with the mail stack (D2), whatever the topology.

Learning on means, concretely:

- P2/P3 messages are scored locally at ingest and the local verdict replaces
  the upstream content signal (the map above).
- `LearnSpamFeedback` teaches the corpus from every correction that still has
  a raw message — including webhook-sourced and IMAP-sourced rows. A
  "webhook provider ⇒ nothing to teach" rule would only be true for a
  milter-only scanner, so no such rule exists.
- Nothing is installed: the scanner is already there (D2). Where it is not
  (no local mail stack), the checkbox is disabled with the reason.

### D4 — One policy object, so the derivation exists once

New `plugins/mailbox/includes/MailboxSpamPolicy.php`:

```
MailboxSpamPolicy::filingEnabled(): bool        // the D1 switch
MailboxSpamPolicy::learningEnabled(): bool      // the D3 switch AND filingEnabled() — clamped, never raw
MailboxSpamPolicy::scanAtIngest(): bool         // the D2 derivation — whether P2/P3/deferred-parse rescore locally
MailboxSpamPolicy::upstreamScanner(): string    // 'relay' | 'provider' | 'none' (display + the P1 distinction)
MailboxSpamPolicy::mailStackPresent(): bool     // this box hosts its own Postfix stack (⇒ a scanner is required)
MailboxSpamPolicy::controllerReachable(): bool  // observed scanner presence — probed, never stored
MailboxSpamPolicy::controllerUrl(): string      // mailbox_rspamd_controller_url, defaulted to loopback :11334
MailboxSpamPolicy::milterWired(): bool          // rspamd present in Postfix's smtpd_milters (drift check)
```

Every consumer — health probe, learning task, ingest scan, admin page — reads
these. No caller re-derives topology or re-reads the raw settings. Topology
comes from `InboundEmailSetupCheck::topology()`, which already owns that
question; the policy never re-implements it. `upstreamScanner()` reports
'provider' when the resolved provider is a webhook, else 'relay' when the
topology is fronted, else 'none'.

Provisioning consults nothing: the scanner installs unconditionally (D2), so
there is no rule for a shell script to ask about. `utils/spam_policy.php show`
prints the resolved posture for a human in a shell session — ops
introspection, not a provisioning dependency.

### D5 — Existing deployments move with the defaults

A `plugin.json` default only seeds rows that do not exist yet, so a migration
carries the change to deployments that already stored a value:

- Rename: copy `mailbox_content_spam_filtering_enabled`'s stored value to
  `mailbox_spam_learning_enabled`, then delete the old row.
- Default flip: set every stored `mailbox_spam_filtering_enabled = 0` to `1`.
  A stored `0` cannot be distinguished from a deliberate one and does not need
  to be: the platform is pre-launch and flipping wholesale is the intent.

**Rollout note:** boxes provisioned before the scanner shipped with the mail
stack don't have one, so their "Spam scanner on this server" row on the admin
Plugins page reads unmet until `provision_spam_scanner.sh install` runs there
— once, pushed fleet-wide via Server Manager node exec rather than per-owner
copy-paste. Every box provisioned after this change has the scanner from
birth and never sees that state.

### D6 — One scanner installer, standalone, with verbs

New `provisioning/provision_spam_scanner.sh install|remove|status`. The rspamd
section previously inlined in `install_email.sh` (§ 5b) moves here verbatim in
spirit; `install_email.sh` § 5b becomes an **unconditional** call-through —
no setting, SQL read, or policy gate stands between the mail installer and the
scanner (D2), and the gate test pins that.

**install** (idempotent, safe to re-run any time):
- Packages: rspamd + redis-server.
- All `local.d` configs exactly as § 5b writes them today: the
  `X-Spam`/`X-Spam-Status` header contract (`InboundEmailRouter`'s
  `SPAM_*_HEADER` constants), add_header-only actions (never reject), Bayes on
  redis with autolearn, loopback controller on 11334 trusted without password,
  milter worker on 11332.
- Milter wiring into Postfix (`smtpd_milters` append) **only when Postfix is
  installed** (`postconf` present and `main.cf` exists). On a box with no
  Postfix — a relay-fronted deployment whose listener is decommissioned or was
  never installed, or a webhook deployment — the scanner is HTTP-only and the
  milter worker simply idles.
- Same systemd/service/container start fallbacks as § 5b (spec
  mail_stack_container_persistence): config is re-asserted idempotently; in a
  container the CMD restarts the services.

**remove** (operator escape hatch only — the platform never runs or surfaces
it; the scanner is a permanent part of the mail stack):
- Stop and purge rspamd and redis-server, delete the joinery-managed
  `local.d` files, and strip `inet:localhost:11332` from `smtpd_milters` when
  Postfix is present. Unwire before purge, so Postfix never points at a milter
  that is gone.
- The Bayes corpus dies with redis. That is deliberate: the corpus is the
  tenant's private model and must not linger on a reclaimed box. It is also
  disposable by design — Postgres (`iem_spam_verdict`) is the durable truth
  and the corpus self-heals from stored corrections if the scanner is ever
  reinstalled (the learn task re-teaches unreconciled rows).
- Assumption, stated in the script header: on joinery-provisioned boxes redis
  exists solely for the scanner. The platform installs it nowhere else.

**status**: machine-readable markers (packages, services, milter wiring,
controller reachable) for tests and the health probe's detail line.

`provision_relay.sh` is untouched — the relay's stateless rspamd is a
different animal and stays exactly as it is.

### D7 — Day-2 activation is a pure settings toggle

There is no "installed" setting — installed-ness is observed, not declared
(zero-config: derive from existing state). Because the scanner ships with the
mail stack, there is normally nothing to do on day 2 at all:

- **Turn learning on** (settings page) → save → done. The scanner is already
  running; ingest re-scoring and teaching begin with the next message and the
  next cron pass. No command, no red row, no waiting.
- **Turn learning off** → nothing changes on the box: the scanner stays (it is
  part of the mail stack) and, on a colocated box, keeps scoring with whatever
  the corpus already knows; it just stops being taught and ingest re-scoring
  stops.
- **Turn filing off** → verdict writing stops for new mail (`classifySpam`
  returns NULL); learning is clamped off by the policy regardless of its
  stored row; already-filed spam stays where it is, reviewable as ever. The
  stored learning preference survives so re-enabling filing restores the
  previous behavior — the clamp, not the row, is what guarantees sanity.
- **Topology moves** carry the corpus, per D9.

The Settings page offers learning only where a scanner is observed running
(`controllerReachable()`): on a box with no local mail stack the checkbox is
**disabled** with the state line saying why, instead of a red row and a
command the owner may not even be able to run (shared hosting has no root).
Because a disabled checkbox never posts, the save path writes the learning
setting only while the scanner is present — saving the form can never stomp
the stored preference.

The `content_spam_scanner` health row (admin Plugins page — plugin health rows
render there, with states `verified`/`unmet`/`error` only) enforces the D2
guarantee rather than driving a workflow, via
`InboundEmailHealth::checkContentSpamScanner`:

- **No local mail stack** (`mailStackPresent()` false): passes silently —
  nothing of ours ever ran as root there, so nothing can be required, and a
  hand-installed scanner is equally fine.
- **Mail stack present, controller not answering**: unmet, with the install
  command — the box predates the scanner shipping with the stack, or the
  service is down.
- **Direct-receiving (`upstreamScanner() === 'none'`) with the scanner running
  but missing from `smtpd_milters`**: unmet — mail would flow unscored.
  Re-running the idempotent installer repairs the wiring. This is the drift a
  box hits after restoring a decommissioned listener whose scanner was
  installed while Postfix was absent.

The `plugin.json` health entry's `script` pointer targets
`provision_spam_scanner.sh`.

### D8 — Degraded states never block mail

The scanner being missing (a box provisioned before it shipped with the
stack) or down mid-run is a health-row problem, never a delivery problem:

- Ingest scan (P2/P3): attempt only when `scanAtIngest()`; on connection
  failure or timeout, fall back to the upstream signal that arrived with the
  message and store normally. Never hold, bounce, or retry-loop a message on
  the scanner's account. `error_log` once per failure; the red health row is
  the durable signal.
- `LearnSpamFeedback`: controller unreachable ⇒ `skipped` with a message
  naming the endpoint; rows stay unreconciled and are re-taught on a later
  run (this self-healing already exists — a corpus wipe has always been
  recoverable from stored corrections).
- The scan endpoint is the controller's `/checkv2` (rspamd's controller serves
  both scan and learn — same endpoint `LearnSpamFeedback` already POSTs to),
  read from `controllerUrl()`. Scoring a spooled message over HTTP lacks the
  live SMTP client context, but the relay's `Received` headers ride in the
  raw, so header-based network rules still fire; Bayes and content rules are
  unaffected.

### D9 — Topology and provider transitions (audited against the scripts)

- **Colocated → relay-fronted (listener decommission).** The
  `joinery-mail-listener off` helper stops postfix/opendkim/opendmarc and
  closes 25, and deliberately leaves rspamd+redis alone — the scanner is part
  of the mail stack and stays. The corpus survives the move, and with
  learning on the scanner switches roles from milter to HTTP-at-ingest with
  no reinstall. **Audit result: no change to the helper.** Its comment
  ("deferred ingest still scores pulled mail") describes behavior that only
  becomes true with this spec; the comment stands.
- **Relay-fronted → colocated (listener restore).** `joinery-mail-listener
  on` restarts Postfix. If the scanner was hand-installed while Postfix was
  absent, `smtpd_milters` was never wired — the health check's milter-wiring
  probe (D7) catches this and the fix is re-running the idempotent installer.
  **Audit result: no change to the helper; the drift check covers it.**
- **Provider postfix → webhook.** The scanner stays; with learning on the
  ingest scan takes over scoring (P3), and the idle milter wiring is
  harmless.
- **Fleet slot.** Identical to relay-fronted in every respect here
  (`topology() mode 'fleet'` is non-colocated; the fleet relay runs the same
  stateless scanner config).
- **Fortress deferred parse.** `parsePendingMessage` re-resolves content spam
  at parse time from the sealed raw; it follows the same P2 rule — local scan
  replaces the relay header when `scanAtIngest()`.

## Settings model (no nonsensical state reachable)

Three rows, unchanged in number:

| setting | default | meaning |
|---|---|---|
| `mailbox_spam_filtering_enabled` | `1` | file suspected spam into the Spam view |
| `mailbox_spam_learning_enabled` | `0` | learn from user corrections; re-scores relay/webhook mail at ingest |
| `mailbox_rspamd_controller_url` | `http://127.0.0.1:11334` | endpoint, not a choice |

Nonsense is prevented structurally, not by validation messages:

- *Learning without filing* — impossible to act on: `learningEnabled()` is
  clamped false when filing is off, and the UI hides the checkbox
  (`visibility_rules`). The stored row is a remembered preference, inert until
  filing returns.
- *Learning without a scanner* — the checkbox is disabled wherever the
  controller is not answering, and the save path writes the learning setting
  only while it is (a disabled checkbox never posts, so saving cannot stomp
  the stored value). A stored "on" with the scanner later gone degrades
  gracefully (D8) rather than erroring.
- *Scanner state as a setting* — doesn't exist. The scanner ships with the
  mail stack (D2); presence is probed (D7). There is nothing to contradict.
- *Raw provider string vs reality* — the policy reads the resolved provider,
  so an empty or misspelled `mailbox_provider` cannot flip a derivation
  (D2).

## Integration points (complete list — decide once)

| touchpoint | change |
|---|---|
| `plugin.json` settings | `mailbox_spam_filtering_enabled` default `0` → `1`; rename `mailbox_content_spam_filtering_enabled` → `mailbox_spam_learning_enabled` |
| `plugin.json` health entry `content_spam_scanner` | `script` → `provision_spam_scanner.sh`; `details` rewritten for the ships-with-the-stack model |
| new `migrations/` entry | rename carry-over + default flip (D5) |
| new `includes/MailboxSpamPolicy.php` | the predicates (D4) |
| new `utils/spam_policy.php` | `show` — spam posture readout for shell sessions (no provisioning consumer) |
| new `provisioning/provision_spam_scanner.sh` | install / remove / status (D6) |
| `provisioning/install_email.sh` § 5b | replaced by an unconditional call-through to the scanner installer (D2) |
| `includes/InboundEmailHealth::checkContentSpamScanner` | mail-stack-implies-scanner rule + direct-receiving milter-wiring drift check (D7) |
| `includes/InboundEmailRouter` | local HTTP scan on P2/P3/deferred-parse when `scanAtIngest()`; local verdict replaces upstream content signal; version bump |
| `tasks/LearnSpamFeedback.php` | gate on `learningEnabled()`; no webhook mark-all-handled branch; unreachable controller ⇒ skipped, rows deferred (D8) |
| `admin/admin_mailbox_settings.php` | master checkbox restated in outcome terms; learning checkbox beneath it, shown only when filing is on (`visibility_rules`, not hand-rolled JS) and disabled when no scanner answers; state line from `upstreamScanner()` |
| `logic/admin_mailbox_settings_logic.php` | write `mailbox_spam_learning_enabled` only while the scanner is present (disabled checkboxes never post); surface `scanner_state` / `scanner_present` |
| `provision_relay_main.sh` / `joinery-mail-listener` | **audited, no change** (D9) |
| `provision_relay.sh` | **audited, no change** — relay rspamd stays stateless |
| `includes/InboundEmailRouter::resolveContentSpam` | **done** in 1.22 — reads an arriving verdict unconditionally |

Explicitly unchanged: the auth classification rules (DMARC-fail primary,
SPF+DKIM both-fail fallback), the reviewable-verdict model (nothing is ever
rejected or deleted), `iem_spam_score` remaining display-only, the relay's
stateless rspamd configuration, RBL checks at RCPT time, and P4 ingest
classification.

## Admin surface

The mailbox Settings page's "Spam filtering" box carries both controls and no
explainer prose:

- **Move suspected spam to the Spam view** — on by default. Help text names
  the consequence and the escape hatch: spam is moved, never rejected or
  deleted, and never forwarded.
- **Learn from what users mark as spam** — revealed only when the first is
  on, disabled when no scanner is running here (D7). Help text says what the
  corpus is: this deployment's alone; a shared relay is deliberately
  stateless and cannot learn for you.

Where the scanning happens is shown, not asked — a state line reading from
`upstreamScanner()` (scored by the relay / scored by the provider / scored on
this server), extended with why learning is unavailable when it is.

## Tests

- `plugins/mailbox/tests/spam_policy_test.php` (tier `db`) — the D2
  derivation across the full matrix: topology `colocated` / `self_hosted` /
  `fleet` × provider postfix / webhook / **empty-string (resolves to
  postfix)** × learning on / off × filing on / off, asserting
  `scanAtIngest()`, `upstreamScanner()`, and the learning clamp for each
  cell. Real relay rows, not hand-built facts (the unloaded-`count()` class
  of bug). The cell that matters most: relay/webhook + learning on re-scores
  here; colocated never does.
- `plugins/mailbox/tests/spam_filtering_test.php` — extend: ingest-time scan
  on the spool path against a stub controller, both directions — local *spam*
  overrides an upstream none/ham, and local *ham* rescues an upstream spam
  flag (the false-positive-rescue rule is the one worth pinning). Scanner
  unreachable ⇒ message stores with the upstream verdict (D8).
- `LearnSpamFeedback` — webhook-sourced correction with raw present is taught
  (not marked handled); controller down ⇒ `skipped`, rows untouched.
- Settings-declaration coverage: `mailbox_spam_filtering_enabled` declares
  default `1`; `mailbox_content_spam_filtering_enabled` absent from
  `plugin.json`.
- The migration is exercised by the standard migration run: a stored learning
  value survives the rename; a stored `0` master becomes `1`.
- `spam_scanner_gate.sh` (tier `safe`) — the scanner script parses, verbs
  dispatch, the `X-Spam` header contract matches the router's constants,
  rejection stays disabled, the controller stays loopback/password-free,
  remove unwires before purging, and `install_email.sh` calls the installer
  **unconditionally** (no setting, SQL read, or policy gate in front of it).

## Documentation

Update `plugins/mailbox/docs/overview.md`: the settings table entries, the
spam-filtering and content-scanner sections rewritten around the one-switch
model, the derived scanner, the ingest-path verdict map, and the
`provision_spam_scanner.sh` verbs. Current state only — the reasoning lives
here, not there.

## Out of scope

- Changing the auth classification rules or the reviewable-verdict model.
- Rejecting or bouncing mail at any layer.
- Per-user or per-alias spam preferences (the corpus is deployment-wide).
- Making the relay's rspamd stateful.
- Scoring P4 (IMAP-polled) mail at ingest. The remote mailbox already
  curated those messages with its own filter; re-scoring risks double-filing
  and adds nothing. IMAP corrections still *teach* the corpus (D3) — the mail
  informs the model even though the model doesn't file it.
- A remote/shared rspamd endpoint. `mailbox_rspamd_controller_url` remains
  loopback-defaulted; pointing it elsewhere is unsupported operator territory.
