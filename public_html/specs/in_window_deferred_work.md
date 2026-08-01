# Work that runs while you're signed in

**Status:** Built 2026-08-01, uncommitted. Everything in this spec is
implemented and the db tier is green (207 tests, 6,288 checks), including a
suite that exercises the whole path against a real Fortress domain with
genuinely sealed mail.

Still unverified: behaviour in a live browser, and anything on
jeremytunnell.com — no recipe has been created there and the domain has not
opted in.

## The problem in one paragraph

On a Fortress domain, your mail is encrypted with a key only you can supply.
You supply it by unlocking your vault, which happens when you sign in and tap
your passkey. The server holds that key in memory for a while afterward. That
period is called the unlock window. While the window is open, the server can
read your mail. While it's closed, the server cannot — not partially, not with
effort. The mail is just encrypted bytes.

That means anything the server wants to do with your mail has to happen while
you're there with the window open.

## What we want

Three AI features already exist and work: triage (file mail under your labels
and write a one-line summary), security scan (score mail for phishing), and
schedule (pull dated events onto your calendar). They run today on ordinary
domains. They cannot run on a Fortress domain, because they run from cron, and
cron can't read encrypted mail.

We want them working on Fortress domains.

## Why cron can never do it

The unlock key is stored in shared memory tied to your browser session. Cron
jobs run as separate command-line processes. Those processes get their own
separate shared memory, so they cannot see the key. This isn't a bug or an
oversight to route around. `VaultUnlock::secretKey()` returns nothing at all
when called from the command line, on purpose.

So the work has to move. It can't run on a timer. It has to run inside a web
request that happens while your window is open.

This applies to any future feature that encrypts content, not just mail and not
just AI. That's why this is a shared piece of the platform rather than a fix
inside the AI plugin.

## We already do this once, by hand

Mail parsing has the same problem and already solves it.

On a Fortress domain, mail arrives while you're logged out. The server can
store it, but it can't read it, so it can't pull out the subject, sender, or
body. It parks the message in an unparsed state. Later, when you open your
inbox with your vault unlocked, a helper called `DeferredIngest` grabs up to
200 of those parked messages and parses them.

That's the right idea. What it lacks:

- It only runs when you visit the mailbox. Nothing else wakes it.
- It has no time limit, so a big backlog can slow down a page load.
- Nothing stops two browser tabs from doing the same work twice.

This spec turns that idea into a shared service and moves mail parsing onto it.

## The design

A new core class, `includes/VaultDeferredWork.php`.

### Features sign up

Each feature that has work needing an unlock window registers two functions.
Registration happens in the plugin's existing `includes/bootstrap.php` file,
which the vault already loads.

```php
VaultDeferredWork::register(
    'mailbox_parse',                                  // a name, for logs
    fn(int $user_id) => bool,                         // is there work to do?
    fn(int $user_id, string $key, float $deadline) => int   // do some of it
);
```

The first function answers "is there anything to do for this user?" It runs
often, so it must be a fast database check. It must never decrypt anything or
call a model.

The second function does the work and stops by the deadline it's given.

### What starts the work

Every page you load while signed in with an open vault already sends a small
ping to the server every 25 seconds, saying "I'm still here, keep my window
open." That ping is core, not mailbox-specific, so it fires on every page.
That's the clock this hangs off — work gets done wherever you happen to be on
the site, and features don't need their own trigger.

**The work does not run inside the ping.** The ping's job is to be small and
reliable, and it must never be able to stall on a language model — the local
provider's timeout is 300 seconds, so one slow message would leave a keep-alive
request in flight for minutes while the browser's timer kept firing more behind
it. The thing that keeps your vault open cannot depend on an AI call.

So the ping answers one extra question — *is there work pending?* — and when
the answer is yes, the browser makes a **separate drain request**. The
keep-alive stays fast. The drain is bounded on its own terms.

Unlocking also triggers one drain immediately, so work starts on the tap rather
than at the next tick.

### Order matters

Features run in the order they registered, and that order is deliberate. Mail
parsing goes before AI processing, because the AI has nothing to read until the
message has been parsed. The order is set in one place,
`VaultUnlock::CONSUMER_PLUGINS`.

### Keeping it small

Each drain request gets a time limit, set by
`vault_deferred_work_slice_seconds` (default 10 seconds). Features with work to
do take turns inside that limit, so a slow one can't hog it. A feature that
crashes is logged, skipped for the rest of that drain, and tried again on the
next one. One broken feature never stops the others.

Ten seconds inside a 25-second cycle gives roughly a 40% duty cycle, which
clears a 79-message backlog in about twenty minutes of ordinary browsing.

**The limit is checked between items, not inside them.** An in-flight call to a
model can't be cut off cleanly, so the deadline governs how many items a drain
*starts*. A drain can overrun by one slow item. That's acceptable for a
background request, and it's the second reason this doesn't ride on the ping.

### Two tabs open

If you have two tabs open, both ping, and both would fire drain requests for
the same work. Each drain takes a database lock on the pair (user, feature). If
the lock is already held, that drain skips the feature and moves on. No
duplicate work, no waiting.

### No new queue table

Both features already know what work is outstanding. Mail has its unparsed
flag. The AI pipeline has its log of already-processed items. A new shared
queue table would just be a second copy of that, which could drift out of sync
with the first. So this spec adds no storage. It's a scheduler, not a queue.

## One thing that must not be got wrong

Fortress windows close automatically after two hours of inactivity. "Activity"
is measured by when the key was last used to decrypt something.

Background work uses the key to decrypt things. So if we're not careful,
background work counts as activity, and a browser tab you left open at your
desk keeps your window alive forever. The two-hour limit quietly stops
existing.

So background work must not count as activity.

A separate "background" version of the key-fetching function isn't enough,
because the code underneath the drain fetches the key on its own. Instead we
set a flag for the duration of the batch:

```php
VaultDeferredWork::withBackgroundWork(function () { ... });
```

While that flag is set, fetching the key still works and still refuses when the
vault is locked, but it doesn't record activity and doesn't extend the window.

There's a test for this specifically: a window where the only activity is
background work must still close after two hours.

## Feature 1: mail parsing

`DeferredIngest` keeps its logic and gains a deadline. Mailbox registers it as
`mailbox_parse`. Its "is there work?" check is "any unparsed messages for this
user."

The two places that already call it directly stay as they are, because parsing
when you open your inbox is faster than waiting for a ping. They just route
through the new service so they share the lock and the time limit.

## Feature 2: the AI email jobs

### Jobs say whether they need the vault

A new method on the job interface:

```php
public function requiresVaultScope(array $config): ?string;   // null = doesn't need it
```

Each of the three email jobs looks at the domain of the mailbox it's pointed
at. On an ordinary domain it returns nothing, and the job keeps running on cron
exactly as it does today, overnight, unattended. On a Private or Fortress
domain it returns `'user'`, and the job moves to the in-window path.

Nothing changes for anyone not using a sealed domain.

### How they run

A recipe whose job needs the vault is never launched as a background
command-line process. The dispatcher skips it. The spawner refuses it. Instead,
`joinery_ai` registers itself with the new service, and its work function runs
the existing pipeline for as many items as fit in the time limit.

The pipeline already handles one message at a time and records each one as it
finishes, so stopping partway through loses nothing. It picks up where it left
off on the next ping.

**One rule to enforce:** the recipe runner normally creates a fake session for
the recipe's owner. Running inside your live browser request, that would
overwrite your real session. So the in-window path skips that step and instead
requires that the person browsing *is* the recipe's owner. A recipe drained
this way only ever runs as its own owner, in that owner's session.

### Which messages they pick up

Three changes to the selection each job makes.

**Encrypted messages stop being skipped.** That filter comes out, since the
whole point is that they can now be read.

**Unparsed messages start being skipped.** This fixes a live bug. Right now
they aren't skipped, so on a Fortress domain the jobs would pick them up, judge
them on empty content, and permanently mark them as processed — never looked at
again once they were parsed.

**Only unread mail is processed.** This is the scope of the whole feature, not
a performance tweak. A summary exists to help you decide whether to open
something, and a danger score is no use after you've read it. Mail you've
already read doesn't need either.

The practical effect is large. On jeremytunnell.com, 79 of the 1,966 stored
messages are unread. That's the backlog — not 1,958.

The filter is `iem_is_read = false`. A message you read before the AI reaches
it simply never gets processed, which is the intended behaviour rather than a
gap. One consequence worth knowing: on a Fortress domain, processing can only
happen while you're signed in, which is also when you're reading. So you will
sometimes open a message before its summary exists. Summaries are best-effort
for mail you haven't got to yet.

### New mail goes first

All three jobs currently take the oldest unprocessed message. That's backwards
even for a small backlog: mail from three weeks ago gets a summary before mail
from this morning.

So the ordering flips. Every job takes the **newest** unread, unprocessed
message (`ORDER BY iem_received_time DESC`). Today's mail is always handled
first, and anything older drains underneath it.

**Mail parsing flips too.** `DeferredIngest` parses oldest-first today. It
becomes newest-first, for two reasons.

The obvious one: on first unlock with a backlog, your most recent mail should
be the first to become readable, not the last.

The one that actually forces it: the AI skips unparsed messages. If parsing ran
oldest-first while the AI ran newest-first, the two would fight. Come back from
two weeks away with hundreds of messages waiting, and the parser would work
forward from the oldest while the AI waited on the newest — stalling the AI on
exactly the mail you most want summarized until the parser had ground through
everything else. The two orders have to agree.

This is safe to change. Parsing is self-contained per message: decrypt, parse,
seal the fields, classify spam, run that message's filters. Thread keys are
assigned when a message is stored, not when it's parsed, which is why threading
and unread counts already work on unparsed mail. No filter reads a neighbouring
message.

One small edge case: a filter that forwards is subject to a per-alias rate
limit, so the order decides which messages get forwarded when a large batch
hits the limit. Recent mail is the better use of a limited allowance.

### Catching up

79 unread messages across three jobs is roughly 240 model calls — a few minutes
of ordinary background batches, spread over a session or two. Normal use needs
no tool at all.

Catch up is insurance for the case where you've been away and come back to
several hundred unread messages and no summaries. It lives **in the mailbox**,
not on the recipe page, because the mailbox is where you feel the problem. It
also covers all three jobs at once, since they're all just work registered
against your open window.

It isn't a permanent button. When more than roughly twenty unread messages are
waiting to be processed, a quiet line appears at the top of the inbox saying how
many are outstanding, with a control to work through them and a progress
display. Below that threshold there's nothing to see, because background
batches will have it done shortly anyway.

Nothing about it is special: it's the same work function under the same lock,
without the per-batch time limit.

The recipe page gets no equivalent. Its existing Run Now already covers testing
a recipe without opening a mailbox, and it has to keep working for in-window
recipes regardless.

## Token caps have to stop counting free work

This is independent of the vault work and would be worth fixing on its own.

Both monthly caps count tokens rather than money, and they count them whichever
model ran — so a model running on your own hardware, costing nothing, still
burns through them.

The numbers on this box: the plugin-wide cap is 25,000,000 tokens a month,
which is comfortable. The **per-recipe** cap defaults to 200,000. The initial
unread backlog alone is roughly 380,000 tokens across the three jobs, so the
recipes would stop about a third of the way in, on their first day, having
spent nothing.

Raising the number by hand on each recipe is a workaround. The caps exist to
stop runaway spending, and there's no spending to stop.

So the caps become provider-aware: **usage on a local provider doesn't count
toward either monthly cap.** Paid providers are unchanged, including the 80%
warning email. Runaway protection for local runs comes from the limits that
already bound every run — the per-run item count, the output-token budget, and
the batch deadline. None of those change.

`CostGuard` needs to know which provider a run used. `LlmProviderInterface`
already exposes `isPrivate()`, which draws exactly this line, so the check hangs
off that rather than a new flag. Both `check()` and `enforceGlobalCap()` need
it, since the chat surface shares the plugin-wide cap.

## Turning it on has to be a deliberate choice

Right now, Fortress means the server can't read your mail unless you're there.
After this, it means the server reads your mail while you're there, and sends
it to your model host.

That's a reasonable trade, since the model runs on your own Mac over your own
private network. But it's a real change in what the setting buys you, and it
must never switch on quietly.

So: a new checkbox on the domain, `ied_ai_processing_enabled`, off by default.

- It sits on the same admin screen as the security level, and reads:
  *"Let Joinery AI read this domain's mail while your vault is unlocked."*
- It only appears for Private and Fortress domains. On an ordinary domain the
  server already reads the mail, so there's nothing to consent to.
- Changing it requires a fresh identity check, like other vault-related
  changes.
- Saving a recipe pointed at a sealed domain with the box unticked fails, and
  says which domain and which setting. You find out when you save, not
  silently at 3am.

## Things we considered and rejected

**Give the AI its own key that the server keeps.** This would allow overnight
processing, because the server could decrypt without you. It also means the
server can read your mail whenever it likes, which is the exact thing Fortress
exists to prevent. Rejected.

**Pass the key to the cron job.** Any way of doing this puts your unwrapped key
into a command line, an environment variable, or a file. Rejected.

**A shared queue table.** Duplicates information both features already have.
Rejected.

## What this does not give you

A triaged inbox waiting for you in the morning.

On a Fortress domain, summaries appear a few seconds after you open your mail,
not before you arrive. That's what encrypting your mail to yourself means. No
design gets around it. If you want overnight triage on a domain, that domain
has to run at the ordinary security level.

## Database changes

| Table | Change |
|---|---|
| `ied_inbound_email_domains` | add `ied_ai_processing_enabled`, boolean, default false |

Nothing else. The new service stores nothing of its own, and the token-cap
change is logic only — the existing usage rows already record which run spent
what.

One new setting in `settings.json`: `vault_deferred_work_slice_seconds`,
default 10.

One new endpoint: the drain request the browser fires when the keep-alive ping
reports work pending. It declares `requires_browser_session`, like every other
vault endpoint — the unlock window is keyed to the browser session, so an API
key could never carry one.

## Docs to update

Written as current state, with no mention of how things used to work:

- **`docs/sealed_vault.md`** — a new section on deferred work: how features
  sign up, what starts a batch, the time limit, and the rule that background
  work doesn't count as activity, with the reason.
- **`docs/scheduled_tasks.md`** — say plainly that work needing the vault never
  runs from cron, and why.
- **`plugins/mailbox/docs/overview.md`** — mail parsing as a registered
  feature; the AI checkbox on sealed domains.
- **`plugins/joinery_ai/docs/overview.md`** — jobs can require the vault; those
  recipes run while the owner is signed in rather than on a schedule, and what
  that means in practice. Also: monthly token caps apply to paid providers
  only, and pipeline jobs take the newest candidate item first.
- **`plugins/mailbox/docs/overview.md`** — the email jobs process unread mail
  only.

## Tests

In `tests/vault/`:

- features run in registration order;
- the time limit stops new items starting, and one long item may overrun it;
- a second simultaneous drain is skipped by the lock, not blocked;
- a feature that throws is skipped, and the rest of the drain still runs;
- the keep-alive ping returns without doing any work of its own, however much
  work is pending;
- **the important one:** a window whose only activity is background work still
  closes after two hours;
- a locked user gets no work done.

In `plugins/mailbox/tests/` — a backlog of unparsed mail drains through the new
service, newest message first.

In `plugins/joinery_ai/tests/` — an encrypted message is available to the job
while unlocked and not while locked; unparsed messages never get logged as
processed; read messages are never selected; a recipe on a sealed domain is
refused without the checkbox; jobs take the newest candidate, so a fresh
arrival is picked up ahead of a backlog; local-provider usage doesn't count
toward either monthly cap, and paid usage still does, including the 80% alert.

## Decisions

All settled. Nothing is waiting on an answer.

- Only unread mail is processed. Read mail is never picked up, however old.
- Newest first everywhere — both AI selection and mail parsing — so today's
  mail is always handled before a backlog, and the two never fight.
- Monthly token caps ignore local-provider usage.
- Work runs in its own request, not inside the keep-alive ping, with a
  10-second limit checked between items.
- Catch up lives in the mailbox, appearing only when the backlog is worth
  mentioning. The recipe page keeps Run Now and gains nothing.
- Per-domain opt-in checkbox, off by default, identity check to change.
- No server-held AI key. Overnight processing on a sealed domain is off the
  table, permanently.

The one number worth revisiting after real use is the 10-second limit, once we
know how long the 35B model actually takes per message.
