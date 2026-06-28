# Joinery AI — asynchronous chat turns

**Status:** Active — awaiting implementation
**Plugin:** `joinery_ai`
**Touches:** the chat send/confirm endpoints, the chat transcript model (two
lifecycle columns), and the chat front end. No engine, provider, or `AgentLoop`
changes.

## Goal

Eliminate the chat timeout. Today `chat_send` runs the whole turn inside one HTTP
request and responds only when the model is completely done
(`views/admin/chat_send.php` calls `ChatRunner::runTurn()` then echoes JSON). On
the local model a tool-using turn — fetch a page, then reason over it — runs for
minutes while the request sits **silent**, so the proxy in front (Cloudflare)
cuts it at its ~100s idle ceiling, the browser's `fetch` gets a dead response,
and the user sees "Send failed" even though nothing is broken.

The fix: stop holding the browser on the line. `chat_send` queues the work and
returns in well under a second; the turn finishes **in the same fpm process**
after the connection is released; the page polls a lightweight endpoint until the
reply is ready. The "Thinking…" indicator (already in the UI) finally earns its
keep.

In plain terms: instead of making the browser wait two minutes for one big
answer and hoping nothing in the middle hangs up the call, we take the message,
say "working on it," and let the page check back until the reply is ready.

## Why this approach (and what we ruled out)

This is a **single-admin, one-turn-at-a-time** chat. That fact drives the design.
Three alternatives were investigated and set aside — the reasoning is worth
keeping, because it's not obvious:

### Live token streaming (SSE) — ruled out by the environment, not the idea

The appealing version is to hold the request open and stream the model's tokens
as they generate, so the slow model becomes "watch it type" instead of a frozen
spinner. We verified empirically that this **cannot be done cleanly on this
stack** without a site-wide config change:

- Cloudflare does **not** buffer `text/event-stream` (it passes straight
  through), and Apache `mod_proxy_fcgi` forwards chunks as they arrive — neither
  is the obstacle.
- The obstacle is php-fpm's stock `output_buffering = 4096`. Sub-4096 writes sit
  in that buffer until it fills or the script ends, so incremental flushes never
  reach the browser. `flush()` / `ob_flush()` can't defeat it (`output_buffering`
  is `PHP_INI_PERDIR`, not settable at runtime), and a per-endpoint `.user.ini`
  doesn't apply under the front controller (php-fpm reads `.user.ini` from
  `serve.php`'s directory, not the view's). Confirmed: a streaming endpoint still
  sees `output_buffering=4096`.
- Defeating it would require `output_buffering=0` **site-wide** (fpm pool), a
  deploy-level prerequisite. Padding every event past 4096 bytes works but is a
  bandwidth-absurd band-aid for token granularity.

Streaming is therefore a **possible later upgrade** gated on making
`output_buffering=0` a standard deploy setting — not part of this work. Crucially,
streaming was only ever needed for the *typewriter UX*; it is **not** needed to
fix the timeout, which is this spec's only goal.

### A realtime service (the Discord-plugin Go daemon) — wrong scale

The chat plugin spec ([`chat_plugin.md`](chat_plugin.md)) stands up a Go
WebSocket service to push to **thousands of idle connections**. That solves
connection *density* and fan-out. Single-admin assistant chat has neither — one
user, one in-flight turn. A separate binary + systemd unit to notify one browser
is wildly overbuilt. Out of scope.

### Webhooks — wrong direction

A webhook is a server-to-server callback; there is no external party to receive
one *from* (Anthropic's Messages API is synchronous; Ollama has no callback), and
a callback would land on the **server**, not the **browser**, so the page would
still need polling on top of it. Solves nothing here.

## Design

### The turn runs in the same process, after the response is sent

`fastcgi_finish_request()` (an fpm SAPI function — confirmed available, not in
`disable_functions`) lets a request **send its response and release the browser**,
then keep executing. `chat_send` uses it to queue-then-work in one process:

1. Auth, validate, enforce the global cap — exactly as today.
2. Insert the user message (`complete`) and an assistant placeholder (`running`).
3. Echo the JSON the front end needs to start polling
   (`{conversation_id, message_id, status: "running", is_new, title}`), then call
   `fastcgi_finish_request()`. The browser's `fetch` resolves immediately.
4. `ignore_user_abort(true)`, `set_time_limit(0)`, then run
   `ChatRunner::runTurn($conversation, $uid)` — unchanged.
5. Write the result onto the placeholder row (content, tool calls, pending
   action, token totals), roll up the conversation token counters, and set
   `aim_status = complete` (or `failed` + `aim_error`).

No detached CLI worker, no spawner, no daemon. Because the turn runs in the
**web** process, the established session is still loaded — so, unlike a CLI
worker, there is **no identity re-setup** (`runTurn` already takes the acting
`$uid`). This is the simplest design that removes the request from the critical
path.

### Why this eliminates the timeout

After `fastcgi_finish_request()` the client connection is closed, so **no
web-request or proxy timeout applies to the turn** — it isn't on a connection
anymore. The dev stack confirms the rest:

- php-fpm `request_terminate_timeout = 0` (disabled) — fpm won't wall-clock-kill
  the post-response work.
- `set_time_limit(0)` covers PHP's own limit (and on Linux fpm,
  `max_execution_time` counts CPU time, not the model's I/O wait, anyway).
- The browser only ever makes the initial sub-second `chat_send` call and short
  poll calls — none come near any timeout.

**Ops cost:** each in-flight turn occupies **one fpm child** for its full
duration (`fastcgi_finish_request` releases the client connection but not the
worker). Single-admin chat against the dev pool (`pm.max_children = 5`) is
comfortable — one pinned child, short polls served by the rest. A multi-admin or
production deployment should keep `pm.max_children` comfortably above expected
concurrent turns plus normal page traffic.

### Message lifecycle

A reply row is created **before** it is filled, so the page can poll it:

- `aim_status` (`varchar(20)`) on `AiConversationMessage`: `running` →
  `complete` | `failed`. User rows are `complete` on insert; the assistant
  placeholder is `running` until the turn finishes it.
- `aim_error` (`text`) for a failed turn's message.

No other transcript schema change — content, tool calls, pending action, and
token columns are written exactly as the synchronous endpoint writes them today,
just onto the pre-created placeholder instead of a fresh row.

### Endpoints

- **`chat_send.php`** — insert user message + `running` placeholder, enforce the
  cap, return the poll handle, `fastcgi_finish_request()`, run the turn, finalize
  the row. New conversations still derive title + capability flags here (returned
  in the immediate response).
- **`chat_confirm.php`** — same shape for a confirmation: flip the pending row to
  `running`, return immediately, `fastcgi_finish_request()`, run
  `ChatRunner::resumeTurn()`, finalize. The confirm/cancel logic and the
  transcript's strict alternation are unchanged.
- **`chat_poll.php?message_id=N`** *(new)* — owner-scoped; returns `{status}` and,
  when `complete`, the rendered bubble (via `ChatRender::assistantBubble`); when
  `failed`, the error message. This is the happy-path delivery channel.

### Front end

After send: show the user bubble + the existing "Thinking…" placeholder, then
poll `chat_poll` every ~2s until the assistant row is `complete` (render the
bubble) or `failed` (show the error). Same loop after a confirm/cancel. This
replaces the current "await the reply in the send response" logic; the send
response now only carries the poll handle.

### Failure & staleness

- **Provider / turn error:** caught in the post-response phase; the row is set
  `failed` with `aim_error`, and the poller surfaces it.
- **Process died mid-turn** (fpm restart, OOM): the placeholder is left `running`.
  A lightweight sweep marks any row `running` past a derived ceiling as `failed`,
  run on poll or as a small scheduled task — so the page shows an error instead of
  spinning forever. The ceiling must exceed a turn's real worst case:
  `AgentLoop` bounds a turn by `max_iterations` and the token budget, **not** by
  elapsed time, so the longest legitimate turn is roughly
  `chat max_iterations × the provider HTTP timeout` (with chat defaults, 8 × 300s
  ≈ 40 min) plus bounded tool time. Set the sweep ceiling to that worst case plus
  a margin so it never reaps a turn that is still working.
- **Client give-up:** after a generous ceiling the poller stops and shows a "took
  too long" note with a retry; the row's own sweep is the server-side backstop.

### Non-fpm fallback

`fastcgi_finish_request` exists only under php-fpm (the platform's stack). Gate on
`function_exists()`: if absent, run the turn synchronously before responding (the
current behavior) so the feature degrades to "works, but can time out on a slow
model" rather than breaking. A detached CLI worker (the recipe pattern) is the
heavier fallback if a non-fpm deployment ever needs true async, but it is not
built here.

### What's reused vs new

- **Reused, unchanged:** `ChatRunner` (`runTurn` / `resumeTurn`), `ChatRender`,
  the confirmation logic, `ChatTurnContext`, `CostGuard`, and the entire
  `AgentLoop` + provider layer. Recipes are untouched.
- **New:** `aim_status` / `aim_error`; `chat_poll.php`; the JS polling loop; the
  staleness sweep; and the `chat_send` / `chat_confirm` change from
  run-and-return to queue-finish-run-finalize.

## What does NOT change

- `ChatRunner` turn logic, the risk heuristic / confirmation boundary, the
  capability toggles, lazy model discovery, `CostGuard`, and token accounting.
- The canonical LLM request/response contract and both providers.
- The transcript data model except the two lifecycle columns.

## Security & cost

- **No new trust surface, no new service, no new secret.** The turn runs in the
  same authenticated web process it does today; nothing is exposed that the
  synchronous endpoint didn't expose. Endpoints stay owner-scoped and
  permission-gated.
- **Cost is unchanged.** Same tokens, same `CostGuard` per-run and monthly
  metering. The global cap is still enforced up front in `chat_send`, before any
  spend.

## Testing

- A tool-using turn on the local model (fetch a page, then reason) completes via
  poll without a request/proxy timeout — the case that fails today.
- `chat_send` returns in well under a second with the placeholder id; the reply
  appears when the in-process turn finishes.
- A confirm/cancel resume runs the same async way.
- A turn that errors leaves the row `failed` with `aim_error`; the page shows it.
- A killed fpm process leaves the row `running`; the sweep marks it `failed` and
  the page shows the error rather than spinning.
- Token totals, trace, and pending-action handling match the synchronous path
  (the same `ChatRunner` methods run, just after the response is sent).
- The `function_exists('fastcgi_finish_request')` fallback runs the turn
  synchronously and still produces a correct transcript.

## Out of scope

- **Live token streaming (SSE)** — a later upgrade gated on making
  `output_buffering=0` a standard deploy setting (see [Why this approach](#why-this-approach-and-what-we-ruled-out));
  not needed to fix the timeout.
- **The Go realtime service / WebSockets** — a connection-density tool for the
  chat plugin's many-user fan-out, not warranted for single-admin chat.
- **Multi-user / member-facing chat** — gated separately
  ([`joinery_ai_chat_member_access.md`](joinery_ai_chat_member_access.md)).
- **Cancelling an in-flight turn from the UI** — easy to add later via a kill
  flag the running process checks between iterations; not needed for v1.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md`: document the
asynchronous turn lifecycle (queue → `fastcgi_finish_request` → in-process run →
poll), the two lifecycle columns, the staleness sweep, and the synchronous
fallback. Note that streaming is intentionally not used and why (the
`output_buffering` finding). Describe only the end state, per the docs rule.
