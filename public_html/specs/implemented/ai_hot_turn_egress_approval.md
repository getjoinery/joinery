# Hot-turn web egress approval

**Status:** Implemented 2026-08-09. Core gate + card + recipe refusal (0.19.0);
review hardening (whitespace-faithful cards, error-accounting fixes, durable
egress restriction); confinement of sealed reads to protected chats + result
framing/windowing. Dedicated suite green (39 checks); safe 97/97, joinery_ai db
519.
**Depends on:** `specs/implemented/ai_action_queue.md` (the queue and its
card/renderer machinery). The AI panel composer spec
(`specs/ai_panel_composer.md`) depends on THIS for its exemplar 3
(investigate an email by following its links); this spec stands alone and
should land first — it closes a gap that exists today.

## The gap

A chat turn that has read sealed content can still call the web tools
inline. That is an open exfiltration channel: the model's context at that
point contains both your sealed plaintext and attacker-controlled text
(the email being read, any page already fetched), and an injected
instruction can steer the model into sending sealed data out embedded in
a URL or a search query. The sealed-egress guard watches database writes;
nothing watches outbound URLs.

The channel is any tool whose *arguments* leave the box:

- `fetch_url` — the URL (path and query string) goes to an arbitrary host.
- `web_search` — the query text goes to the search provider.
- `get_stock_data` — the symbol string goes to the market-data provider.

## The rule

**Once a turn is hot, web-egress tool calls stop executing inline and
queue for approval, exactly as mutating calls do.** The user sees a card
with the literal outbound arguments — the exact URL, the exact query —
before any network is touched. A conversation that has never touched sealed
content is unchanged; its web lookups run inline.

One rule, two doors, same shape as writes: inline when harmless, a human
click when it matters.

- The egress predicate is `SealedEgressGuard::egressGated()` — true when the
  process is hot (`isHot()`, set when any sealed plaintext is opened, never
  clears within the run) OR the conversation is durably egress-restricted
  (see Cross-turn restriction below). Kept distinct from `isHot()` so arming
  it never arms the write-guard.
- The gate extends the existing dispatch test in `AgentLoop`: queue when
  the context queues writes AND (the call is mutating OR the call is
  web-egress under `egressGated()`). Web-egress tool names come from a small
  constant beside the mutating list in `RiskHeuristic`
  (`isHotEgress(array $tool_use): bool`).
- The three web tools implement `QueueableToolInterface`.
  `fetch_url` renders the full literal URL with the host led; `web_search`
  renders the literal query; `get_stock_data` the symbol. No summarizing,
  no model prose — same one-card doctrine as writes.
- The model's tool_result mirrors the write-queue wording: queued as
  pending action #N, has NOT run, do not retry, tell the user it awaits
  approval.

## Confinement: sealed content stays in protected chats

The gate above assumes a turn *can* be hot on any conversation. On a **protected**
chat that holds — the whole transcript seals, so hot turns persist and gate
cleanly. On a **standard** chat it does not, and the reason is structural:
protection is judged per *conversation* (locked-state, display, replies, all key
on `aic_security_level`). A standard conversation that opened sealed content
would be hot, but its transcript is plaintext — so it can neither persist its
next reply (the write-guard refuses long plaintext from a hot process) nor keep
what it read protected. "Standard" and "holds sealed content" are incompatible.

So the rule is not to patch the standard transcript but to keep sealed content
out of it: **the AI opens sealed content only in a protected chat.**
`ToolContext::sealedReadsAllowed()` answers true for a protected chat and for a
recipe (its whole run is the protected unit), false for a standard chat. The
read executor (`ModelQueryExecutor::decryptSealedFields`) excludes an
actually-sealed row when reads aren't allowed — the same exclusion a locked
vault already triggers, surfaced to the model as a partial result. A standard
turn therefore never decrypts sealed content, never goes hot, and both the
cold-start exfiltration gap and the swallowed-event bug (below) simply cannot
arise on it. A backstop in `ChatTurn` fails a standard turn cleanly, pointing to
a private chat, if a residual vector (an action that itself decrypts) ever turns
it hot.

Two defence-in-depth arms remain, both harmless once confinement holds:

- **Durable egress restriction.** The first turn to open sealed content marks
  `aic_egress_restricted`; every later turn arms
  `SealedEgressGuard::restrictEgress()` from it (via `egressGated()`), so egress
  gates even in a cold process reasoning over sealed-derived history. Under
  confinement this only ever fires on protected chats (which go hot from history
  decryption anyway), but it costs nothing and closes the seam if the boundary
  ever leaks. It arms the egress gate only, never the write-guard.
- **Fail-safe finalize.** A content write the guard refuses marks the turn failed
  rather than surfacing a 500.

## Result return

A fetch's value is its content, so an approved egress result rides back into the
conversation — but only ever on a protected chat (confinement), where the event
row seals. On approve, the action runs through `AgentLoop::executeApproved()`;
the fetched text (tool-capped at 50k) is stored on the resolution EVENT row after
`AiConversationMessage::EVENT_RESULT_SEP`, separating the trusted platform
narration from the untrusted fetched bytes. The queued-action `aqa_result` seals
per row; the event row seals per the conversation's level.

At history-build (`ChatRunner::buildHistoryMessages`) each such event is split on
that separator and the result is:

- **framed untrusted** — wrapped in the turn's `<<UNTRUSTED_nonce>>` markers with
  a source line added to the system prompt, the same standing fetched content has
  inline; and
- **windowed** — only the most recent carried result is sent in full; older ones
  collapse to a short prefix, so a fetch reasoned over once stops re-bloating
  every later turn.

Decline and expiry return nothing. Write outcomes keep their short summary inline
in the narration (no separator), unchanged.

## Recipe runs

Recipes are non-interactive — there is no one to approve. On a hot recipe
run, a web-egress call is refused with an `is_error` tool_result naming
this rule, and the run continues. `RecipeRunContext` keeps
`queuesWrites() = false`; the refusal happens at the same dispatch gate.
A recipe that legitimately needs both sealed reads and the web does not
currently exist; if one ever does, the answer is a design pass there, not
a bypass here.

## What the user sees

- A pending card per outbound call: tool, the literal argument line(s),
  approve / decline. On the chat page and the panel's Waiting section
  like any other pending action; batch approve applies.
- An asks-for-many pattern (an investigation queuing five link fetches in
  one turn) is five cards — deliberate. The batch buttons make the
  approve cheap while every URL stays individually visible.

## Alternatives considered (for the standard-conversation case)

Three event-level fixes were designed and rejected before landing on
confinement; each foundered on the conversation-level protection model.

- **Seal the resolution event row on a standard conversation.** Makes the hot
  write legal, but puts a sealed row inside a standard conversation — and
  locked-state is judged per conversation, so every read path (`ChatSerializer`,
  the web transcript, poll, export) would throw on that row when the window is
  shut. It would spread per-row sealed-handling across the whole display surface
  with ongoing fragility. A band-aid, not a layer fix.
- **Carry the result by reference from `aqa_result`, auto-injected each turn.**
  Keeps the transcript plaintext, but reading the sealed `aqa_result` re-heats the
  process on *every* later turn — so the next reply write (plaintext on a standard
  conversation) is then refused. It would break every subsequent turn of the
  conversation, worse than the original swallowed-event bug.
- **Auto-escalate a standard conversation to protected when it touches sealed
  content** (optionally behind a consent prompt with discard-on-deny). Coherent —
  the conversation-level model then handles everything — but a silent escalation
  is a surprising state change (the chat thereafter needs the vault to read), and
  the consent variant is a "seal it or lose the answer you just got" flow that is
  user-hostile and effectively its own spec. Confinement reaches the same
  protection without converting anyone's conversation.

The through-line: a standard conversation cannot safely *hold* sealed-derived
content, so the fix is to keep sealed content from entering it — not to make the
plaintext transcript pretend to protect what it can't.

## Out of scope

- Per-domain or per-session standing grants for fetching ("always allow
  example.com") — a later ergonomics pass once real usage shows the
  friction; the first cut keeps every hot-turn fetch behind a click.
- Any change to cold-turn behaviour, the SSRF validator, or the web
  tools' own caps.
- Model-provider egress (what the LLM API itself sees) — governed by the
  conversation security level (fortress pins local), not this rule.

## Documentation (at build time)

- `plugins/joinery_ai/docs/overview.md` — the hot-turn egress rule in the
  proposed-actions section; the web tools' queueable status.
- `docs/sealed_vault.md` — one line in the consumer-hooks/egress passage
  pointing at the rule, if the guard is described there.

## Testing (at build time)

- Queue test additions: hot turn + `fetch_url` queues (card shows the
  literal URL), cold turn executes inline; `web_search` same; approve
  executes and the event row carries the fetched text; decline runs
  nothing.
- Injection-shaped test: a turn that opens a sealed fixture then calls
  `fetch_url` with a query-string payload — asserts no network attempt
  before resolution (validator/HTTP layer never invoked).
- Recipe-run test: hot recipe run + web tool call → `is_error` refusal,
  run completes.
- Confinement: `sealedReadsAllowed()` is false for a standard chat, true for a
  protected chat and a recipe; the read executor excludes an actually-sealed row
  when reads aren't allowed while still returning plaintext fields.
- Result carry: an approved egress event stores the result after
  `EVENT_RESULT_SEP`; history-build frames it untrusted and windows older ones.
