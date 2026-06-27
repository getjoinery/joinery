# Joinery AI — asynchronous chat turns

## Goal

Make a chat turn survive a slow model. Today `chat_send` runs the whole turn
inside the HTTP request and only responds when the model is done. On the local
model a tool-using turn (e.g. fetch a page, then reason over it) runs well past
the web server's request timeout, so Apache kills the request, the browser's
`fetch` gets a dead response, and the user sees a "Send failed" popup even though
nothing is actually broken.

Decouple the turn from the request: `chat_send` returns immediately after
queuing the work, a background worker runs the turn, and the page polls for the
result. The "Thinking…" indicator (already in the UI) finally earns its keep.

In plain terms: instead of making the browser hold the line for two minutes while
the model thinks, we take the message, say "working on it," and let the page
check back until the reply is ready.

## Why this is the right fix

- **Local-first stays viable.** The local model is slow by nature; no request
  timeout can be tuned high enough to make a multi-minute turn feel right
  synchronously. Async is the only approach that makes a slow, tool-using turn
  reliable.
- **It already exists here.** Recipes run exactly this way — a status-bearing row,
  a detached CLI worker, a spawner, and a page that refreshes until the row hits
  a terminal state. Chat reuses that proven pattern rather than inventing one.
- **No engine changes.** `ChatRunner::runTurn()` / `resumeTurn()` already return a
  result; only *who calls them and when* changes.

## Design

### Message lifecycle

A reply row is created **before** it is filled, with a status the page can poll:

- Add `aim_status` (`varchar(20)`) to `AiConversationMessage`: `running` →
  `complete` | `failed`. User rows are `complete` on insert; an assistant
  placeholder is `running` until the worker finishes it.
- Add `aim_error` (`text`) for a failed turn's message.

A turn is then: insert the user message (`complete`), insert a placeholder
assistant message (`running`), launch a worker, return the placeholder's id. The
worker fills `aim_content` / `aim_tool_calls` / `aim_pending_action` / token
counts and sets `aim_status = complete` (or `failed` + `aim_error`).

### Background worker

`cli/run_chat_turn.php <message_id>` — the chat counterpart to
`cli/run_recipe.php`. CLI-only; loads the placeholder assistant row + its
conversation, re-establishes the acting admin's identity
(`SessionControl::set_api_user($owner)`, the same step the recipe runner does so
logic-file actions see the right user), runs `ChatRunner::runTurn()` (or
`resumeTurn()` for a confirmation), writes the result onto the row, and flips the
status. A turn argument distinguishes a fresh send from a confirm/cancel resume
(carry the decision + pending action — e.g. on the row, or as worker args).

### Spawner

Reuse the recipe spawn primitive (`RecipeWorkerSpawner`-style detached
`proc_open`) to launch the worker without blocking the request. Chat is
interactive and one-worker-per-turn, so no scheduled dispatcher or queue is
needed — spawn on send/confirm. A concurrency guard (cap simultaneous chat
workers) is optional; the global token cap already bounds spend.

### Endpoints

- `chat_send.php` — insert the user message + a `running` placeholder assistant
  message, enforce the global cap, spawn the worker, return
  `{conversation_id, message_id, status: "running"}` immediately. (New
  conversations still derive title + capability flags here, as today.)
- `chat_confirm.php` — same shape: flip the pending row back to `running`, spawn a
  resume worker, return immediately.
- `chat_poll.php?message_id=N` — return `{status}` and, when `complete`, the
  rendered bubble HTML (via `ChatRender`); when `failed`, the error. Owner-scoped
  like the other endpoints.

### Front end

After send: show the user bubble + a live "Thinking…" placeholder, then poll
`chat_poll` every ~2s until the assistant row is `complete` (render the bubble) or
`failed` (show the error). Same loop after a confirm/cancel. Polling replaces the
current "await the reply in the send response" logic.

### Failure & staleness

- **Client give-up:** after a generous ceiling (e.g. the per-turn wall clock +
  margin) the poller stops and shows a "took too long" note with a retry.
- **Server sweep:** a row left `running` past a threshold (worker died) is marked
  `failed` — a lightweight version of the recipe dispatcher's reaper, run on poll
  or on a small scheduled task.

### What's reused vs new

- **Reused:** `ChatRunner` (`runTurn`/`resumeTurn` unchanged), `ChatRender`, the
  confirmation logic, `ChatTurnContext`, and the recipe spawn/worker pattern as a
  template.
- **New:** `aim_status` / `aim_error`; `cli/run_chat_turn.php`; `chat_poll.php`;
  the JS polling loop; `chat_send` / `chat_confirm` change from run-and-return to
  queue-and-return.

## Lighter alternative: `fastcgi_finish_request()`

Instead of a detached CLI worker, `chat_send` can insert the rows, call
`fastcgi_finish_request()` to release the browser, then run the turn inline in the
same process; the page polls as above. No spawner, no CLI entry, no identity
re-setup (the web session is already established).

Trade-off: it holds an FPM pool slot for the turn's duration and dies if FPM
recycles the worker mid-turn; it also depends on FPM (not portable to CLI/cron).
Acceptable at low concurrency. Recommended only as a faster first cut — the
CLI-worker design is the robust end state and matches recipes.

(Note: on Linux, PHP `max_execution_time` counts CPU time, not time blocked on the
model's HTTP call, so a mostly-I/O turn won't trip the 30s limit in either
flavor — the killer today is the *web request* timeout, which both flavors remove
from the critical path.)

## What does NOT change

- `ChatRunner` turn logic, the risk heuristic / confirmation boundary, the
  capability toggles, lazy model discovery, and `CostGuard` — all unchanged.
- The transcript data model except the two new lifecycle columns.

## Testing

- A tool-using turn on the local model (fetch a page, then reason) completes via
  poll without a request timeout — the case that fails today.
- `chat_send` returns in well under a second with the placeholder id; the reply
  appears when the worker finishes.
- A confirm/cancel resume runs async the same way.
- A killed worker leaves the row `failed` (sweep), and the page shows the error
  rather than spinning forever.
- Token totals, trace, and pending-action handling match the synchronous path
  (the worker calls the same `ChatRunner` methods).

## Out of scope

- Token-by-token streaming (SSE) — a later enhancement on top of this; polling is
  the v1 transport.
- Cancelling an in-flight chat turn from the UI (a chat analog of the recipe Stop
  button) — easy to add later via the same kill-flag pattern, not needed for v1.
