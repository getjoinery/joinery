# AI Chat Turn Activity — Live "What's Happening" Line While a Turn Runs

**Status:** Draft

## The problem

While an AI chat turn runs, every surface shows the same anonymous waiting
state (a typing indicator) until the first answer token streams back. The
quiet stretches are exactly the interesting ones — connecting to the
provider, the model reading the prompt, a tool call executing — and today
they are indistinguishable from a hung turn. A starved local model that
produced zero tokens for 25 minutes looked identical to a healthy 3-second
turn's first moments. The turn runner passes through each of these stages;
it just never records them anywhere a client can see.

## What the user gets

Under the typing indicator, a live one-line status with an elapsed clock:

```
◦ ◦ ◦
Waiting for qwen3.5:9b… · 2m 40s
```

progressing through, e.g.:

- `Starting…`
- `Waiting for {model}…` (repeats per agent-loop step: `… (step 2)`)
- `Running tool: web_search…`
- `Writing…` (first answer text has started streaming)
- `Resuming…` (after a confirm/cancel decision)

A stalled provider now reads as `Waiting for qwen3.5:9b… · 20m` — the user
(and anyone debugging) can see *where* the turn is stuck and for how long,
instead of inferring it from a spinner.

## Design

### Single writer: the turn runner

One new nullable column on the message row the poller already reads:

```php
// plugins/joinery_ai/data/ai_conversation_messages_class.php
'aim_activity' => array('type'=>'varchar(160)'),
```

The running assistant row's `aim_activity` is overwritten at each stage
transition and set to NULL when the turn finalizes (complete or failed rows
never carry a stale activity). Stage transitions are a handful of tiny
UPDATEs per turn — no throttling needed beyond what exists; the `Writing…`
transition rides the first flush of the existing throttled stream sink
(`ChatAsync::streamSink`), costing no extra write.

### Stamp points (all existing seams — no new control flow)

`ToolContext` gains one method, `noteActivity(string $label): void`, so the
shared loop serves both AI surfaces without forking them
(`AgentLoop` is shared by chat and recipes by design):

| Stamp | Where it fires |
| --- | --- |
| `Starting…` | ChatRunner, when it creates the running assistant row |
| `Waiting for {model}… (step N)` | AgentLoop, immediately before each `createMessageStreamed()` (step suffix only when N > 1) |
| `Writing…` | ChatAsync stream sink, on the first content flush of the turn |
| `Running tool: {name}…` | `ChatTurnContext::beginToolCall()` (already exists) |
| `Resuming…` | the confirm path, when a held turn re-enters the loop |

`{model}` is the short display label — the trailing segment of the model id
(`accounts/fireworks/models/glm-5p2` → `glm-5p2`), matching how much space a
status line has.

- **ChatTurnContext** implements `noteActivity()` by writing the row via a
  stamper callable installed alongside the stream sink
  (`ChatAsync::activityStamper($msg)`); until installed it is a no-op, the
  same pattern `setStreamSink()` uses.
- **RecipeTurnContext** implements it as a no-op. Recipes have no live
  polling UI; when one grows, the stamps are already flowing through the
  shared loop. (Consistent with the ongoing recipe/chat unification — the
  mechanism lives in the shared layer, only the sink differs.)

### Wire surface

`chat_poll` (and `ChatSerializer` for a thread loaded mid-turn, so a client
that opens a running conversation shows the line immediately) add two fields
to running rows:

```json
{
  "status": "running",
  "partial_text": "…",
  "activity": "Waiting for qwen3.5:9b…",
  "running_seconds": 160
}
```

- `activity` — the current `aim_activity`, omitted/empty when not running.
- `running_seconds` — server-computed `now − aim_create_time`, so every
  client shows the same truthful elapsed time without clock math against
  DB timestamp strings (and a thread opened mid-turn starts at the real
  elapsed value, not zero).

Both fields are additive. Old app builds ignore them; new builds treat a
missing `activity` as today's behavior. No version gate.

## Client inventory (all of them, decided up front)

1. **Web reader** — `plugins/joinery_ai/includes/chat_view_body.php`
   (shared by the profile and admin chat views, so one change covers both):
   render `activity · elapsed` in muted small text under the typing
   indicator; update it on every poll tick; tick the elapsed label locally
   between polls seeded from `running_seconds`.
2. **iOS** — `JoineryAIChatKit`: `ChatPollResult`/`ChatMessage` parse the
   two fields; `ChatThreadStore` folds them into the running row; the
   assistant bubble shows the line under `TypingIndicator` (and next to the
   small progress spinner once text is streaming).
3. **Android** — `joinery-android-ai-chat`: same three touches
   (`ChatModels`, `ChatThreadStore`, `ChatThreadView`), mirroring iOS.
4. **Recipes** — explicitly none (no live UI; no-op context, see above).

No other surface polls a running turn.

## Tests

- **Runner lifecycle (functional, PHP):** drive a turn through `AgentLoop`
  with a scripted fake provider (one tool-use iteration, then a streamed
  answer) and a real `ChatTurnContext` + stamper; assert the row's
  `aim_activity` sequence — waiting → running tool → waiting (step 2) →
  writing — and that finalize nulls it.
- **API layer (functional, PHP):** while a row is RUNNING, `chat_poll`
  returns `activity` and a sane `running_seconds`; after completion it
  returns neither.
- **Client parsing (both platforms):** extend the chat parsing suites with
  a running-row fixture carrying `activity`/`running_seconds`; missing
  fields parse as absent (old-server tolerance).

## Acceptance checklist

1. Sending a chat message on web, iOS, and Android shows a live activity
   line with elapsed time before the first token, updating through model
   wait → tool run → writing.
2. Opening a conversation whose turn is already running (started elsewhere,
   e.g. on the web) shows the current activity and the true elapsed time on
   all three surfaces.
3. A completed or failed turn shows no activity remnant anywhere (row's
   `aim_activity` is NULL after finalize).
4. Recipes still run unchanged (no-op context method; no behavior change).
5. Old app builds against the new server, and new app builds against an
   old server, both degrade to exactly today's behavior.

## Out of scope

- Push notifications or any transport change — the existing poll cadence
  carries the field.
- Recipe-side activity UI (mechanism is ready; UI is a future spec).
- Provider-timeout tuning on the Ollama proxy path (the client-side
  timeout-vs-slow-generation mismatch seen on the Mac mini) — an
  infrastructure concern, not a chat-surface one.
- Persisting an activity history per turn (only the current stage is kept;
  the tool trace already records completed tool calls).

## Versioning

Bump `@version` on every modified PHP file. Native modules follow their
platforms' conventions (no per-file versions).

## Documentation deliverables (on implementation)

- `plugins/joinery_ai/docs/overview.md` — § Chat API surface: document
  `activity` and `running_seconds` on `chat_poll` and the running-row
  serializer shape.
- `docs/mobile_apps.md` — the JoineryAIChatKit and joinery-android-ai-chat
  sections: one line each describing the activity line under the typing
  indicator.
