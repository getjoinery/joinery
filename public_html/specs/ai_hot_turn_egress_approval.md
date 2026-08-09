# Hot-turn web egress approval

**Status:** Built 2026-08-09 — dedicated test suite green (26 checks);
awaiting commit.
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

## Cross-turn restriction (cold-start)

`isHot()` is per-process, but a chat conversation carries sealed-derived
context across turns in its transcript, and each turn is a fresh process. A
standard conversation stores that transcript in plaintext, so a short secret
(at or under the write-guard's 64-char threshold) that a hot turn quoted into
a reply survives as cleartext; the next turn rebuilds history cold and could
fetch it out inline. Process hotness alone cannot see that.

So egress restriction is also **durable per conversation**. The first turn to
open sealed content marks `aic_egress_restricted` on the conversation; every
later turn arms `SealedEgressGuard::restrictEgress()` from that mark before
dispatch, so `egressGated()` holds even in a cold process. The mark never
clears — the transcript's contamination is permanent — and a protected
conversation acquires it on turn one (its history decrypts every turn), while
a standard conversation acquires it only after it actually touches sealed
content. Restriction arms the egress gate only, never the write-guard, so an
ordinary standard conversation keeps writing its plaintext transcript.

The set/read points are in `ChatRunner::drive`: read the mark and
`restrictEgress()` before `AgentLoop::run`, and persist the mark (a boolean, so
the write passes the hot guard) after a turn that ended hot.

## Result return

Writes are fire-and-forget side effects; a fetch's value is its content.
On approve, the action executes through the existing
`AgentLoop::executeApproved()` path and the fetched text (already capped
at 50k chars by the tool) is carried back into the conversation in the
resolution event row, so the next turn can reason over it. Sealing is the
existing event-row/queue behaviour: the aqa result column seals per row,
the event row seals per the conversation's level. Decline and expiry
return nothing, as with writes.

The fetched content re-enters the context as untrusted text — the same
standing it has when fetched inline. The rule does not make fetched pages
trustworthy; it makes the *decision to reach out* the user's.

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
