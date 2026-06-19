# Inbound Email — Content Spam Filtering

## Summary

Add the **content scanner** layer that `specs/implemented/inbound_email_spam_filtering.md`
built the disposition pipeline for and deferred. That spec acts on **authentication**
(DMARC/SPF/DKIM) and named three protection layers — MTA RBLs (live), the DMARC
verdict (built), and a content scanner (rspamd/SpamAssassin) marked *deferred*. This
is layer 3.

It is the only layer that catches **authenticated bulk spam**: junk that passes its
own DMARC/SPF/DKIM — lookalike domains, bulk mail from real ESPs, a compromised but
properly-aligned account. The auth layer judges *who sent it*, not *what it is*, so it
waves all of that through. For a shared inbox people are meant to *rely on*, that is
the credibility floor, which is why this is built before the workflow features in
`specs/inbound_email_shared_inbox_parity.md`.

The scanner writes the **same** `iem_spam_verdict = 'spam'` field, with the same
disposition (held out of the inbox, shown in the Spam view, never forwarded). **No
rework** of the reader, the verdict model, forward-suppression, or IMAP junk mapping —
this spec only adds a new *source* of the spam verdict.

## Goals

- Catch authenticated bulk spam that the auth-only rule passes — the majority of
  real-world spam volume.
- Feed the existing `iem_spam_verdict` disposition; add a verdict source, change no
  downstream behavior.
- Make the content signal **uniform across every ingest path**: a Postfix milter for
  locally-received mail, and the spam signal webhook providers already hand us
  (Mailgun/SendGrid/SES) for those paths — one branch, decided up front.
- Keep the "no in-app scorer" decision intact: the scanner (or the provider) decides
  spam/ham against its own thresholds; the app records the result, it does not compute
  or weight a score.
- Close the loop: a manual spam/ham correction in the reader trains the classifier, so
  the filter improves from real corrections instead of staying static.

## Non-Goals

- **No in-app scoring/threshold engine.** Same stance as the auth spec. rspamd owns its
  score and action; the app reads a binary result. We may *record* the score for
  transparency (below) but never act on it in PHP.
- **No SMTP-time rejection.** Consistent with the platform's reviewable-verdict model
  (Non-Goal in the parity roadmap): content-judged spam is moved to the Spam view, not
  rejected at RCPT or bounced. rspamd's own `reject` action is left disabled; it runs in
  header-stamping (`add_header`) mode only.
- **No per-user or multi-tenant Bayes classifiers.** rspamd runs a single global
  classifier with its default autolearn/statistics. The spam/ham feedback loop (below)
  trains that one classifier; per-mailbox or per-user models are out of scope.
- **No replacement of the MTA RBLs or DMARC layer.** This sits behind both; all three
  layers stack.

## Design

### One new verdict source, OR'd into the existing rule

`InboundEmailRouter::classifySpam()` today takes the resolved auth verdicts and returns
`spam`/`ham`/`null`. Extend it to also consider a **content signal**, so the message is
spam if *either* the content scanner *or* the auth rule says so:

```
verdict = spam  if  content_signal == spam  OR  auth_rule == spam
        = ham   otherwise   (when filtering enabled)
        = null  when filtering disabled
```

The auth branch is unchanged. The content signal is resolved per ingest path:

- **Postfix path:** rspamd runs as a milter after the auth milters and stamps an
  `X-Spam` header (rspamd's `add_header` action; e.g. `X-Spam: Yes` / `X-Spam-Flag: YES`,
  plus `X-Spam-Status`/`X-Spam-Score` for the recorded score). A new
  `readSpamHeader($raw_email)` helper — sibling to the existing `readAuthResults()` —
  parses that header into a binary spam/ham/none, trusting it only because the milter is
  ours (same trust basis as the `Authentication-Results` line). Mirror `readAuthResults`:
  precedence, `none` when absent, never hand-rolled.
- **Webhook providers:** Mailgun, SendGrid, and SES already deliver a content/reputation
  spam signal in their authenticated payloads (Mailgun spam flag/score, SendGrid
  `spam_report`/`spam_score`, SES `spamVerdict`). Each provider's `handleInbound` already
  parses its payload for auth verdicts; have it also surface the provider's spam signal,
  carried alongside `$provider_auth` into the same content branch. These providers can't
  host our milter, so this is how they get content coverage — and it's exactly the
  signal they're built to provide.
- **IMAP-polled mail:** unchanged. The remote already classified it; junk-folder
  ingest still sets the verdict in `ImapIngestor` (existing behavior, outside
  `classifySpam`).

Resolving the signal per-path and OR-ing it in one place keeps a single disposition
and one place to reason about it.

### Recorded score (transparency, not disposition)

The auth spec left a clean slot for a score column. Add it now, **record-only**:

| Field | Type | Notes |
|---|---|---|
| `iem_spam_score` | `numeric`, nullable | The scanner's/provider's numeric score as reported. NULL = none reported. **Never** read for disposition — display/debugging/tuning only. |

This is added via `$field_specifications` (auto-synced; no migration). It does not
reintroduce an in-app scorer: nothing in PHP branches on it. It exists so an admin can
see *why* something was judged and so real thresholds can be tuned in rspamd against
real data once mail flows.

## Integration Point Inventory

Every path where a content spam signal can enter, decided up front (per the
build-extensible-systems-once principle):

| Path | Content signal source | Verdict behavior |
|---|---|---|
| Postfix handler (`utils/inbound_email_handler.php` → router) | rspamd milter `X-Spam` header, read by `readSpamHeader()` | OR'd with the DMARC auth rule. |
| Mailgun webhook | Provider spam flag/score in payload | OR'd with the SPF/DKIM fallback. |
| SendGrid webhook | `spam_report` / `spam_score` | OR'd with the SPF/DKIM fallback. |
| SES webhook | SNS `spamVerdict` | OR'd with the DMARC auth rule. |
| IMAP ingest | Remote `junk`-role folder (existing) | Unchanged; set in `ImapIngestor`. |
| Reader / forward / IMAP junk | — | Unchanged. Same `iem_spam_verdict`, same Spam view, same `spam_held` forward suppression. |

## Settings (`plugin.json`)

```json
{ "name": "inbound_email_content_spam_filtering_enabled", "default": "0" },
{ "name": "inbound_email_rspamd_controller_url", "default": "http://127.0.0.1:11334" }
```

- `inbound_email_content_spam_filtering_enabled` gates whether the router **reads** the
  content signal (`X-Spam` header / provider flag). Requires the master
  `inbound_email_spam_filtering_enabled` to be on — content filtering is a source
  feeding the same disposition, so the master gate still governs the whole feature.
- `inbound_email_rspamd_controller_url` is the controller endpoint the feedback loop
  POSTs learn requests to. Default loopback; it's the seam a future dedicated-mail-node
  split would change. **No password setting** — the controller is loopback-trusted (see
  Feedback / Provisioning), so there is no secret to store.
- Default off. The milter may stamp headers regardless (harmless); the app ignores them
  until this is enabled. Ships off; existing behavior byte-for-byte unchanged until
  toggled.

## Provisioning

rspamd is the content scanner (resolves the parity roadmap's open "rspamd vs
SpamAssassin" decision — rspamd: faster, modern, built-in RBL/greylist/Bayes,
single-process milter). Extend `provisioning/install_email.sh`:

- Install `rspamd` and its `redis-server` dependency. redis is **not optional** —
  rspamd's Bayes classifier persists its token corpus there; no redis means no Bayes,
  which makes the feedback loop inert.
- Configure rspamd as a Postfix milter wired **after** opendkim and opendmarc in
  `smtpd_milters`, so it can use the auth results in its scoring. Run in
  header-stamping mode (`add_header` action), **rejection disabled**, consistent with
  the reviewable-verdict Non-Goal. (Runtime note: rspamd queries DNS RBLs while scanning,
  so the host needs outbound DNS egress / a working resolver, or that scoring silently
  degrades.)
- **`X-Spam` header contract.** Configure rspamd's `milter_headers` module to stamp the
  spam header under the exact name `readSpamHeader()` reads. This name is a shared
  contract between the rspamd config and the PHP — pin it in one place both reference.
- **Bayes classifier on redis.** A `local.d/classifier-bayes.conf` enabling the Bayes
  classifier with `backend = redis; servers = 127.0.0.1`, plus autolearn defaults.
  rspamd ships most of this; the override just pins the backend.
- Enable the rspamd **controller worker**, bound to `127.0.0.1:11334` with `secure_ip`
  set to loopback (**no password** — loopback-trusted; see Feedback). The controller is
  required for the spam/ham learning path; binding it to loopback only means nothing
  outside the container can reach it.
- **Start rspamd + redis on every container start.** Containers have no systemd, so the
  container `CMD` must start both daemons alongside the existing mail stack, and the
  script must (re-)assert their config idempotently — the same
  `mail_stack_container_persistence` pattern that already covers Postfix/opendkim/
  opendmarc. On a systemd host, `systemctl enable rspamd redis-server` instead.
- Keep it idempotent / self-repairing on re-run, matching the script's existing
  milter-wiring approach (the script runs on every container start — see its 2.x
  version notes).
- Bump the script's header version and note the rspamd milter addition.

### Bayes corpus durability — redis is disposable, Postgres is the source of truth

rspamd's learned corpus lives in redis (`/var/lib/redis`), in the container's writable
layer: it survives `stop`/`start` but a **recreate/rebuild wipes it** (the gap
`mail_stack_container_persistence` documents for the rest of the stack). True durability
would need a redis volume mount, which is set at container-create time — above the plugin
(neither the plugin nor `install_email.sh`, which runs *inside* the container, can create
it), and not something to bake into the base image for a feature most deployments won't
run.

So redis is treated as **disposable plugin-local state, not a system of record.** The
durable training signal (which messages are spam/ham) lives in Postgres
(`iem_spam_verdict`); redis only holds the *derived* token model. After a wipe the corpus
rebuilds from ongoing corrections, so the failure degrades to "the filter is temporarily
less sharp," never "training data lost." rspamd and redis install via the plugin's own
`install_email.sh` into the writable layer, only on deployments that enable this — core
is untouched. Persisting redis on a volume is an optional deploy-layer optimization, never
a correctness requirement; there is **no re-seed action** (it could only reach as far back
as raw retention anyway, so it can't restore a mature corpus).

### Webhook-provider deployments install none of the above

Everything in this section is the **Postfix-path** requirement. A deployment whose
inbound is a webhook provider (Mailgun/SendGrid/SES) gets its content spam signal from
the provider's own upstream scanning — no rspamd, no redis, no milter. The external
provisioning surface there is zero; the app just consumes the provider's spam flag.

New provisioner entry in `plugin.json` (mirrors the existing `inbound_mail_server` /
`outbound_forwarding_relay` entries) so the dashboard surfaces its health:

```json
{
    "key": "content_spam_scanner",
    "label": "Content spam scanner (rspamd)",
    "details": "Optional. rspamd milter scores inbound mail for content spam and stamps X-Spam; the router reads it into the spam verdict. Webhook providers supply their own spam signal instead.",
    "check": { "type": "code", "call": "InboundEmailHealth::checkContentSpamScanner" },
    "script": "provisioning/install_email.sh"
}
```

Add `InboundEmailHealth::checkContentSpamScanner()` — verify the rspamd milter
socket/port is listening locally (same shape as `checkInboundMailServer`).

## Data Model Changes

`InboundEmailMessage` (`data/inbound_email_message_class.php`), all via
`$field_specifications` (auto-synced; **no migration**):

| Field | Type | Notes |
|---|---|---|
| `iem_spam_score` | `numeric`, nullable | Recorded scanner/provider score (above). Display only, never disposition. |
| `iem_learned_verdict` | `varchar(10)`, nullable | The last verdict actually taught to rspamd's classifier. Drives the feedback reconcile (below); NULL = never taught. |

No verdict-model change — `iem_spam_verdict` and its `SPAM_VERDICT_*` constants are
reused exactly. No new non-unique index is needed: the reconcile query and the Spam
view both filter on `iem_spam_verdict`, already indexed by the existing thread/state
access; the divergence scan is bounded by the content-filtering being enabled and the
small, human-paced set of unreconciled corrections.

## Reader / UI Changes

Minimal — the disposition surfaces already exist (Spam view, inbox exclusion, manual
"Mark as spam" / "Not spam"). Optional, low-priority: show the recorded `iem_spam_score`
on the message detail when present, so an admin can see how strongly it scored. Any
control uses FormWriter and the reader's vanilla-HTML5 patterns. No new view plumbing
is required for the verdict itself.

## Spam/ham feedback (Bayes training)

The reader's existing manual correction (`MailboxService::setSpamVerdict()`, surfaced
as the "Mark as spam" / "Not spam" button) already flips `iem_spam_verdict` and moves
the message between the inbox and Spam view. Wire that same action into rspamd's
Bayesian classifier so a correction also *teaches the filter*, and future similar mail
is auto-caught. This closes the loop: the milter judges, the user corrects, the
classifier improves.

**Auto-train on correction — no new UI.** The existing button is the whole trigger:
flipping the verdict is what later drives the learn (`learn_spam` for a `spam` verdict,
`learn_ham` for `ham` — see the reconcile below). A flip-back drives the opposite learn
so rspamd unlearns the prior training; a misclick self-heals. There is no separate
"report to filter" control, and the correction path itself gains no new step.

**Async, via a reconcile not a queue.** There is no separate job table. The verdict is
the source of truth, and a per-message marker tracks what's been taught: a correction
just sets `iem_spam_verdict` (the existing `setSpamVerdict()` behavior, unchanged), which
leaves it *diverged* from `iem_learned_verdict`. A dedicated scheduled task,
**`LearnSpamFeedback`**, reconciles the divergence out-of-band on the platform's
15-minute cron runner (`utils/process_scheduled_tasks.php`):

```
SELECT … WHERE iem_spam_verdict IS DISTINCT FROM iem_learned_verdict
             AND iem_spam_verdict IS NOT NULL
```

For each row it fetches the raw message, calls `learn_spam`/`learn_ham` per the current
verdict, and on success sets `iem_learned_verdict = iem_spam_verdict`. This makes
flip-backs and idempotency fall out for free: change the verdict and the row re-selects
and relearns the new direction; once equal, it stops selecting. The correction click
never blocks on rspamd, and an unreachable controller never breaks the user's action —
the row simply stays diverged and retries on the next pass, which is what makes the
reconcile self-heal through a controller outage rather than stranding corrections.

**Why a dedicated task, not folded into an existing one.** `LearnSpamFeedback` runs
`every_run`, gated only on `inbound_email_content_spam_filtering_enabled`. Every existing
inbound-email task is gated on a *different* feature (cloud offload, IMAP polling, a
retention setting) or is a destructive purge — folding learning into one would couple it
to that feature's on/off state (disable IMAP polling, silently lose training) or violate
that task's single responsibility. The task is a thin shim in the
`OffloadInboundRawToCloud` mold (a docblock + a `run()` that calls one reconcile method).

**Transport: loopback HTTP, no password.** The job fetches the message's raw RFC822
from the plugin's tiered raw-message store and POSTs it to the rspamd controller's
`/learnspam` | `/learnham` at `inbound_email_rspamd_controller_url`
(default `http://127.0.0.1:11334`). The controller binds to loopback only and trusts
loopback via `secure_ip`, so the privileged learn command is authorized by originating
inside the container — **no controller password to store, rotate, or leak**. This holds
because the task runner is co-located with rspamd: the mail stack and the cron runner
both run in the site container (the container `CMD` asserts the mail stack on every
start; the cron runs there too). A future dedicated-mail-node split is the only case
that would need a password or network ACL, and the URL setting is the seam where that
change lands.

**Edge cases, decided up front** — the reconcile must distinguish *permanent* no-ops
(mark handled so the row stops re-selecting) from *transient* failures (leave diverged,
retry next pass):
- **Webhook inbound (Mailgun/SendGrid) has no local rspamd to teach.** The correction
  flips the local verdict only; the reconcile treats it as a **permanent no-op** —
  set `iem_learned_verdict = iem_spam_verdict` so it's marked handled, never retried.
- **Raw message already pruned** (`inbound_email_mailbox_retention_days`, default 14):
  nothing to learn from → **permanent no-op**, mark handled, never error.
- **Controller unreachable / learn errors:** **transient** — leave the row diverged and
  retry on the next pass. No per-row attempt counter: the dominant transient failure
  (controller down) is *global*, so a per-row cap would burn every pending correction's
  budget during one outage and permanently strand them after recovery. The unreconciled
  set is small and human-paced, so retrying indefinitely is cheap; the permanent-no-op
  marking above already keeps genuinely unlearnable rows out of the query.
- **Bayes corpus minimum:** rspamd's classifier does not contribute until it has built
  up roughly 200 messages of each class, so early corrections have little visible
  effect. Expected behavior, not a fault — worth stating so it isn't read as "the
  button does nothing."

## Testing

- Unit: `readSpamHeader()` parses `X-Spam: Yes` / flag / score variants to spam,
  absence to none, and never trusts a forged header on a path where no milter ran
  (honored only on the Postfix/milter path, same basis as `readAuthResults`).
- Unit: `classifySpam()` OR semantics — content=spam + auth=ham → spam; content=ham +
  auth=spam → spam (auth rule still fires); content=ham + auth=ham → ham; filtering
  disabled → null; content gate off → auth-only behavior unchanged.
- Integration per ingest path: a DMARC-passing message that rspamd flags is stored
  spam, held out of the inbox, shown in Spam, and **not forwarded** (`spam_held`
  logged) — proving the content layer catches authenticated junk the auth rule passes.
  A Mailgun/SendGrid payload carrying a provider spam flag classifies spam via the
  webhook branch. `iem_spam_score` round-trips and is never used for disposition.
- Regression: with `inbound_email_content_spam_filtering_enabled = 0`, behavior is
  identical to the auth-only spec.
- Feedback reconcile: a "Mark as spam" leaves `iem_spam_verdict` diverged from
  `iem_learned_verdict`; `LearnSpamFeedback` selects it, POSTs the raw to the loopback
  controller without a password, succeeds, and sets `iem_learned_verdict` equal — the
  row stops re-selecting. A flip-back to "Not spam" re-diverges and relearns `ham`. A
  webhook-inbound message and a pruned-raw message are marked handled as permanent
  no-ops (no re-selection, no error). A down controller leaves the row diverged and
  retries on the next pass once it recovers — never failing the user's correction,
  never stranding it.

## Rollout

- Ships **off**. Enabling requires (1) the master spam gate on, (2) the content gate
  on, and (3) the rspamd milter provisioned (or a webhook provider that supplies the
  signal). Until then, auth-only behavior is unchanged.
- Pre-launch, no inbound volume: rspamd runs at its shipped defaults; thresholds get
  tuned against real mail later, aided by the recorded score. No data migration (no
  production mail to reclassify) — consistent with the platform's pre-launch posture.

## Docs

On implementation, extend the existing **Spam filtering** section of
`plugins/inbound_email/docs/overview.md` (current-state voice): add the content layer
to the protection-layers description, document `readSpamHeader()` / the provider spam
signal as additional verdict sources OR'd into `classifySpam()`, the
`inbound_email_content_spam_filtering_enabled` gate and its dependence on the master
gate, the recorded `iem_spam_score`, the rspamd milter + controller provisioner, and the
spam/ham feedback loop (the `LearnSpamFeedback` task reconciling `iem_learned_verdict`,
loopback controller, no password). Do not create a new
doc file; do not narrate the migration from auth-only — describe the end state as
though it always existed.

## Decided defaults (overridable at implementation)

These are local build-time conventions with no architectural fork; defaults are chosen
so nothing dangles. Override only if the code makes a different choice obviously cleaner.

- **Header convention:** rspamd stamps `X-Spam: Yes` (the `add_header` action) plus
  `X-Spam-Status` / `X-Spam-Score`; `readSpamHeader()` parses the same name, and
  `iem_spam_score` records the numeric value from `X-Spam-Score`. Pin the header name as
  a single shared constant the rspamd config and the PHP parser both reference.
- **Provider spam signal placement:** carried as a **sibling argument** into
  `classifySpam()`, not inside `$provider_auth`. That array carries authentication
  verdicts; a content-spam signal is not auth, so keeping it separate stops the
  auth-verdict contract from blurring.
