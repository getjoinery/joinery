# In-window deferred work

**Status:** Active spec, unbuilt.

## What this gives the user

Some work can only happen while you are standing there with your vault open —
because the content it works on is encrypted to you and the server genuinely
cannot read it otherwise. Today that means: mail on a sealed domain arrives and
sits unparsed until you next open your inbox, and the AI email features
(triage, security scan, calendar extraction) cannot run on sealed mail at all.

This spec makes "work that needs the user present" a first-class platform
capability: any feature can register work that runs automatically, in bounded
slices, whenever the owner has an unlock window open — on any page, not just
the feature's own screen.

The first two consumers are mail parsing (which already does this by hand) and
the AI email pipeline jobs (which currently cannot).

## The structural constraint this is built around

The vault secret key lives in APCu keyed to the **browser session**
(`vault:{session_id}:{user_id}:{scope}`), unwrapped only in web-worker RAM.
A CLI process has its own APCu segment and can never see it —
`VaultUnlock::secretKey()` returns null under the CLI SAPI by construction.

Therefore: **no cron task can ever process sealed content.** Not with more
effort, not with a better job design. Work over sealed content must execute
inside a web request carrying a live window for that user, or not at all.

This is not a mailbox quirk or an AI-pipeline quirk. It is a property of the
vault, and every future consumer that seals content will hit it.

## What already exists (and what this generalizes)

`plugins/mailbox/includes/DeferredIngest.php` solves exactly this problem for
one consumer: Fortress mail arrives sealed, parks in `iem_pending_parse`, and
drains when `MailboxService` happens to run with an open window, capped at 200
per pass so it never blocks a render.

That is the right shape with a consumer-specific trigger ("someone looked at
their inbox") and no shared budget, ordering, or concurrency guard. This spec
lifts the shape into core and re-points mailbox at it.

## The interface

New core class `includes/VaultDeferredWork.php`. `VaultUnlock` stays focused on
the window itself (it already declares that policy belongs to consumers).

### Registration

Consumers register from their existing `plugins/{name}/includes/bootstrap.php`
— already loaded by `VaultUnlock::loadConsumerBootstraps()`, and both `mailbox`
and `joinery_ai` are already in `CONSUMER_PLUGINS`.

```php
VaultDeferredWork::register(
    'mailbox_parse',                       // consumer id, stable, appears in logs
    fn(int $user_id) => bool,              // hasWork(): cheap, indexed, no plaintext
    fn(int $user_id, string $secret_key, float $deadline) => int   // drain(): returns items done
);
```

`hasWork()` runs on every heartbeat for every registered consumer, so it must be
a cheap indexed query — never a decrypt, never an LLM call.

**No queue table.** Both real consumers already have a durable record of
outstanding work (`iem_pending_parse`; the AI item log's not-exists clause). A
shared queue table would duplicate that state and introduce a sync failure mode
with no benefit. The abstraction is the trigger, the budget, and the safe key
access — not storage.

### Trigger

Two entry points, both core:

- **`vault_heartbeat_logic`** — after a successful heartbeat, run one slice.
  `assets/js/vault-presence.js` already beats from **every signed-in page** with
  an open vault (emitted by `PublicPageBase`, not by mailbox), so this gives
  platform-wide background processing with no per-consumer trigger.
- **`VaultUnlock::open()`** — run one slice immediately on unlock, so work
  starts on the tap rather than at the next beat.

Drain order is registration order, and it is meaningful: mailbox parsing must
precede AI judging, because an unparsed message has no fields for the AI to
read. Registration order is declared in `VaultUnlock::CONSUMER_PLUGINS`.

### Budget

- A wall-clock slice deadline, setting `vault_deferred_work_slice_seconds`
  (default 3), passed to each `drain()` as an absolute deadline it must respect.
- Consumers with work are visited round-robin within the slice so a slow
  consumer cannot starve a fast one.
- A consumer that throws is logged, skipped for the rest of the slice, and
  retried next beat. One broken consumer never stalls the others.

### Concurrency

Two open tabs beat independently. Each slice takes
`pg_try_advisory_lock` on `(user_id, consumer_id)` — non-blocking; a slice that
cannot take the lock skips that consumer and moves on. No double-processing, no
waiting.

## The idle-cap trap (must not be got wrong)

`VaultUnlock::secretKey()` stamps `meta['content']` on every fetch, and the
**Fortress idle cap measures from that stamp** (`FORTRESS_IDLE_CAP_SECONDS`,
2 hours). A drain that calls `secretKey()` every heartbeat would keep a Fortress
window alive indefinitely for a user who walked away with a tab open — silently
converting the strictest posture into an unbounded one.

Background work must therefore **not** count as user activity. A dedicated
accessor is not enough, because consumer code below the drain (the sealed-field
model hook, `EmailSecurityDigest`) calls `secretKey()` itself.

The mechanism is a request-scoped suppression wrapper:

```php
VaultDeferredWork::withBackgroundWork(function () { ... });
```

While the flag is set, `VaultUnlock::secretKey()` returns the key but does
**not** stamp `content`, does **not** re-store the APCu TTL, and does **not**
touch the `/dev/shm` window marker. It still fails closed on every existing
policy check. The absolute cap (measured from `armed`) is unaffected either way.

A test asserts this directly: a window whose only reads come from background
work still ends at the Fortress idle cap.

## Consumer 1: mailbox parse

`DeferredIngest::drainForUser()` keeps its logic and gains a deadline
parameter. Mailbox's bootstrap registers it as `mailbox_parse` with
`hasWork()` = "any `iem_pending_parse` rows for this owner".

The two existing ad-hoc call sites (`MailboxService`, `protection_ceremony`)
stay — draining on an inbox view is still correct and lower-latency than
waiting for a beat — but they route through the registry so they share the lock
and the budget.

## Consumer 2: the AI email pipeline

### Jobs declare their vault requirement

New method on `PipelineJobInterface`:

```php
public function requiresVaultScope(array $config): ?string;   // null = runs anywhere
```

The three email jobs resolve the target alias's domain and return `'user'` when
its `ied_security_level` is `private` or `fortress`, `null` when `standard`.
A standard-domain recipe therefore keeps running on cron exactly as it does
now — overnight, unattended. Only sealed domains move to the in-window path.

### Execution

A recipe whose job requires a scope is never spawned as a CLI worker.
`RecipeDispatcher` skips it; `RecipeWorkerSpawner` refuses it. Instead
`joinery_ai`'s bootstrap registers `ai_pipeline`, whose `drain()` calls the
existing `RecipeRunner`/`PipelineRunner` with `max_iterations` bounded by the
slice deadline.

The pipeline is already one-item-at-a-time with a durable item log, so it is
naturally resumable across slices — a slice that ends mid-batch loses nothing.

**Session invariant:** `RecipeRunner::setupActorSession()` installs a synthetic
actor session, which would clobber the live browser session it is running
inside. An in-window run instead asserts that the acting session user **is** the
recipe owner and skips actor setup entirely. A drained recipe only ever runs as
its own owner, in that owner's own session. Anything else is a bug.

### Item selection changes

All three jobs' `nextItem()` queries:

- drop `AND iem_content_sealed IS NOT TRUE` — sealed content is now readable
  through the open window;
- add `AND iem_pending_parse IS NOT TRUE` — an unparsed row has empty content
  columns. This also fixes a live defect: today those rows are selected,
  judged on empty digests, and permanently written to the item log, so they
  are never re-judged once parsed.

### Draining a backlog

One slice per heartbeat processes very few items when each item is an LLM call
(seconds each on a local model). That is fine for steady state and useless for
a backlog — jeremytunnell.com currently holds 1,958 sealed messages.

So: a **Catch up** control on the recipe page runs a longer bounded pass with
visible progress while the page stays open, using the same drain under the same
lock. Background drain keeps up with new mail; catch-up burns down history when
the owner chooses to sit and watch it.

Whether to process the full history at all is an owner decision (see Open
decisions).

## The opt-in flag

Turning this on changes what Fortress means in practice: from "the server
cannot read my mail unless I am present" to "the server reads my mail while I
am present, and sends it to my model host." That is a defensible trade, and it
must never become true silently.

New field on `ied_inbound_email_domains`:

```php
'ied_ai_processing_enabled' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
```

- Surfaced on the same admin screen as `ied_security_level`, in plain language:
  *"Let Joinery AI read this domain's mail while your vault is unlocked."*
- Only consequential when the level is `private` or `fortress`; on `standard`
  the mail is already server-readable and the flag is not shown.
- Changing it requires a recent step-up, like other vault-consequential
  actions.
- A pipeline job's `validateConfig()` refuses a sealed-domain alias while the
  flag is off, naming the domain and the setting. The refusal happens at recipe
  save, not silently at run time.

## Explicitly rejected

- **A second vault scope with server-held custody for AI.** Would allow true
  overnight processing by sealing the digest to a key the server holds. It is
  Fortress in name only for everything the AI can see. Rejected.
- **Handing the secret key to the CLI worker** (argv, env, temp file). Puts an
  unwrapped secret key on disk or in a process listing and defeats the vault's
  central property. Rejected.
- **A shared queue table.** Duplicates state both consumers already keep.
  Rejected.

## What this deliberately does not deliver

Overnight AI triage on a sealed domain. Summaries appear seconds after the
owner opens their mail, not before they arrive. This is inherent to Fortress,
not a limitation of the design, and no amount of interface work changes it.
Anyone who wants a triaged inbox waiting for them must run that domain at
`standard`.

## Data model changes

| Table | Change |
|---|---|
| `ied_inbound_email_domains` | `ied_ai_processing_enabled` bool, default false |

No other schema changes — the interface stores nothing of its own.

New setting (declared in `settings.json`, per the declared-settings rule):
`vault_deferred_work_slice_seconds`, default 3.

## Docs to update

Per the docs rule, all written as current state with no migration narration:

- **`docs/sealed_vault.md`** — new section *Deferred work in the window*: the
  registry, the trigger, the budget, and the background-work suppression rule
  with its reason.
- **`docs/scheduled_tasks.md`** — state plainly that vault-scoped work never
  runs from cron, and why.
- **`plugins/mailbox/docs/overview.md`** — `DeferredIngest` as a registered
  consumer; the AI-processing flag on sealed domains.
- **`plugins/joinery_ai/docs/overview.md`** — pipeline jobs may declare a vault
  scope; such recipes run in the owner's unlock window rather than on cron, and
  what that means for scheduling.

## Tests

`tests/vault/` (safe tier where possible):

- registration order is respected across consumers;
- the slice deadline is honoured and a long consumer cannot overrun it;
- a second concurrent slice is skipped by the advisory lock, not blocked;
- a consumer that throws is skipped and the rest of the slice still runs;
- **regression:** a window whose only reads come from background work still
  ends at the Fortress idle cap;
- a locked user drains nothing.

`plugins/mailbox/tests/` — a pending-parse backlog drains through the registry.

`plugins/joinery_ai/tests/` — a sealed message is a candidate in-window and not
while locked; `pending_parse` rows never enter the item log; a sealed-domain
recipe is refused by the dispatcher and by `validateConfig()` without the flag.

## Open decisions

1. **Backlog cutoff.** Should triage process all 1,958 historical messages on
   jeremytunnell.com, or start from a cutoff date? Judging years of old mail
   costs a lot of local inference for little value; a `since` config field on
   the email jobs would make it a per-recipe choice.
2. **Where Catch up lives** — the recipe page, the mailbox reader, or both.
3. **Slice budget default** — 3s is a guess; worth tuning once real drain
   timings on the 35B local model are known.
