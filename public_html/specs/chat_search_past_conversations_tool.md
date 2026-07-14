# Spec: "Search past conversations" model tool (Joinery AI)

**Status:** Draft (awaiting implementation)
**Version:** 1.1
**Area:** `plugins/joinery_ai` — chat tool surface
**Depends on:** existing sealed-aware search helpers in `MultiAiConversation` (reused behind a new
`searchForTool()` seam, §4.4), Sealed Vault window
**Future reference:** `plugins/mailbox/includes/MailboxIndex.php` — the proven sealed-FTS pattern for §9

---

## 1. What this does, in plain terms

Right now the assistant answers only from the conversation in front of it. This adds a tool the
model can call to **search the user's own past conversations** — so when you ask "what did we
decide about the Mac Studio memory limits last week?", the assistant can find that earlier thread
and use it. It returns matching conversations with a short snippet, a title, a date, and an id.

Two hard rules shape the whole design, both about privacy:

- It only ever searches the **calling user's own** conversations.
- It must respect the **encryption boundary** — a user's protected (Private/Fortress)
  conversations must not have their decrypted content handed to a *remote* model through this
  tool (details in §3). This is the make-or-break constraint, so the spec leads with it.

---

## 2. Feasibility: what is searchable server-side

Conversations store content in one of two regimes, keyed on `aic_security_level`
(`data/ai_conversations_class.php:57`; `standard` / `private` / `fortress`):

- **Standard** — `aic_title`, `aic_instructions`, and message bodies `aim_content` are
  **plaintext at rest**. Fully searchable with plain SQL `ILIKE`, regardless of vault state.
- **Private / Fortress (protected)** — title *and* message bodies are AEAD ciphertext
  (`v1.aead.` blobs). There is **no server-side master key**. They can be read only by
  loading the row and calling `get()` **while the owner's vault window is open**
  (`includes/ChatSeal.php:226`, `data/ai_conversation_messages_class.php:103`); when the vault
  is locked they are cryptographically opaque even to the server.

**This split is already solved in the codebase.** `MultiAiConversation::getSearchResults()`
(`data/ai_conversations_class.php:235-316`) does exactly it: a bound SQL `ILIKE` over standard
chats (title + `EXISTS` over messages, pinned to `aic_security_level = 'standard'`), plus — only
when `ChatSeal::windowOpenFor($owner)` — an in-window decrypt-and-scan over protected chats
(`protectedConversationMatches()`, `:296`). It also exposes `ownerHasLockedProtected()` (`:320`)
to tell a caller that some protected threads couldn't be searched because the vault is locked.

**The tool builds on this layer rather than reinventing it.** It calls a small new sibling in the same
data class (`searchForTool()`, §4.4) that reuses these same helpers — the standard `ILIKE`, the in-window
decrypt-scan, and `ownerHasLockedProtected()` — and adds only what the tool needs (per-match snippets and
the surface-or-acknowledge gate). Reimplementing decrypt-and-scan inside the tool would duplicate the
crypto boundary in a second place — the wrong layer. All sealed/plaintext logic stays in the data class.

There is **no full-text or vector index** anywhere in the plugin (no `tsvector`/GIN, no
`pg_trgm`, no embeddings). All search is `ILIKE`/`strpos`. Chat volume is low enough that this is
acceptable; §9 notes when to revisit.

---

## 3. Security decision: protected content must not leak to a remote model (core requirement)

A conversation search runs *inside a live turn*, and that turn is being served by some model —
which may be **local** (on the Mac Studio / a Fortress-eligible local provider) or **remote**
(a hosted API). Protected conversations exist precisely so their content stays off remote
services. If the search tool decrypted a Fortress thread and returned its snippet as a
`tool_result`, that plaintext would be sent to whatever model is driving the current turn —
**exfiltrating Fortress content to a remote provider**. That must not happen.

**Rule:** the tool returns decrypted content from a protected conversation **only when the
current turn's model is at least as trusted as that conversation's security level requires**
(i.e. a local/Fortress-eligible provider). Concretely:

- **Standard** matches: returnable to any model (content is not secret at rest anyway).
- **Protected** matches, current turn on a **local** model **and** vault window open: return
  title + snippet, and a real match count is fine here (the caller is already trusted with the content).
- **Protected** matches, current turn on a **remote** model (or vault locked): **do not return their
  content, titles, or a per-query count.** Return a **fixed, query-independent** note — the *same string
  regardless of the query or how many matched* — e.g. *"Your protected conversations aren't searched
  while this chat is on a remote model; switch to a local model to include them"* (or *"…while your vault
  is locked; unlock to include them"*). Never silently omit — a false "no results" is a correctness and
  trust failure — but never leak a count either (see the oracle note below).

**The count is itself a leak (metadata oracle).** A per-query match *count* on a remote turn turns this
tool into a keyword-presence oracle over sealed content: the model picks the query and the response
confirms how many protected conversations contain it (`"cancer"` → 2, `"lawsuit"` → 1, …). No plaintext
crosses the wire, but the information does, one keyword at a time — precisely the leak the feature exists
to prevent. So on a remote/locked turn the withheld-note must be **content-free *and* count-free and must
not vary with the query**; a real count appears only when content is already returnable (local + vault
open). This costs nothing in trust: the user still learns protected history was skipped and how to
include it (go local / unlock).

Determining "current turn's model is local" reuses the same signal `ChatLevel` uses for
`fortressAvailable()`: the turn's model is `aic_model` (it drives the turn — `ChatRunner.php:227,371`;
protected conversations are already coerced to a local model at `ChatSend.php:46-47`), and
`ChatLevel::isLocalModel()` (`includes/ChatLevel.php:22`) classifies it.

**Where the tool reads that model.** It is *not* on the `ToolContext` interface, and deliberately so:
the interface is the surface **both** run contexts share, and a recipe (`RecipeRunContext`) has no
conversation and no single turn-model to report. The chat context (`ChatTurnContext`) exposes it as a
concrete, chat-only accessor — `conversationModel()`, alongside `conversationId()` /
`conversationOwnerId()` — for exactly this use. So `search_conversations` is a **chat-only tool** that
reaches the model through the concrete `ChatTurnContext`, the same pattern `ViewAttachmentTool` already
uses to read `conversationId()`. It type-narrows `$ctx` to `ChatTurnContext`; run under a recipe context
it has no turn-model to check, which is consistent with it never being offered there (§5).

> This aligns the feature with the platform's sovereignty posture: protected material only ever
> reaches a model the user has chosen to trust with it.

---

## 4. Design

### 4.1 The tool class
Add `plugins/joinery_ai/recipe_tools/SearchConversationsTool.php` implementing
`RecipeToolInterface` (`includes/RecipeToolInterface.php:15`), auto-discovered by
`RecipeToolRegistry::scan()` (`includes/RecipeToolRegistry.php:73`). **Closest precedent to
mirror: `recipe_tools/GetMyNotesTool.php`** — an owner-scoped ILIKE search tool.

- `name(): 'search_conversations'`
- `description()`: LLM-facing — "Search the user's own past chat conversations by keyword.
  Returns matching conversations with a title, date, id, and a short snippet. Use when the user
  refers to something discussed earlier." Note the owner-scope and that protected chats may be
  withheld.
- `inputSchema()`: object with
  - `query` (string, required) — keywords.
  - `limit` (integer, optional, default 10, max 25).
- `execute(array $input, ToolContext $ctx)`:
  1. Narrow `$ctx` to `ChatTurnContext` (chat-only, §3); read `$uid = $ctx->actingUserId()` and the
     turn model via `$ctx->conversationModel()`.
  2. Call the data-class **search seam** (§4.4) scoped to the owner, passing whether protected content
     may be surfaced this turn (local model + vault open) or must only be acknowledged (§3). All
     decrypt-and-scan and snippet extraction stay behind that seam so the crypto boundary lives in one
     place (§2).
  3. Format a compact result (§4.2) and return it as the tool's string result
     (`RecipeToolInterface` contract, `includes/RecipeToolInterface.php:30-41`).

### 4.2 Result shape returned to the model
A short, token-bounded list of **surfaced** matches only, newest/most-relevant first (reuse the existing
ordering `aic_pinned DESC, aic_update_time DESC`). The list holds standard matches always, and protected
matches only on a surfaced (local + vault-open) turn — **withheld protected chats never appear as rows**
(one placeholder row per withheld chat would itself leak the count; §3). Per match:
- `id` (conversation id — lets a follow-up tool or the UI deep-link),
- `title`,
- `date` (from `aic_update_time`, formatted in `$ctx->ownerTimezone()`),
- `snippet` (≤ ~200 chars around the match).

Cap total results at `limit`. When `protected_withheld` is set, append the **fixed, query-independent**
withheld-note (§3) — content-free and count-free; when `locked` is set, append the "unlock to include
them" variant; on a surfaced (local) turn where `protected_capped` is set, append "some older protected
chats weren't searched". If nothing matches, return a plain "No matching conversations found" (still
appending the withheld/locked note if applicable, so an empty standard result on a remote turn is never
mistaken for "you have no matching history").

### 4.3 Optional companion (noted, not required now)
Search returns snippets, not whole threads. If the model needs the full text of a found
conversation, that is a separate `get_conversation` (read-by-id) tool with the identical
owner-scope + §3 remote-model guard. Out of scope here; flagged so the boundary is designed once.

### 4.4 The search seam (one method, swappable engine)
The existing `getSearchResults()` returns whole rows, has no snippet, and couples "vault open →
decrypt-and-include protected content" — none of which fits the tool's needs (per-match snippets; and
§3's remote/locked case, which must acknowledge protected chats *without decrypting them or revealing a
query-dependent count*).

So add **one** new method to `MultiAiConversation` — the seam the tool calls:

```php
// Returns ['matches' => [ {id, level, title, snippet, date} ... ],   // standard, + protected only when surfaced
//          'protected_withheld' => bool,   // owner HAS protected chats not searched this turn (query-independent)
//          'protected_capped'   => bool,   // (surfaced path only) more protected chats than the scan cap
//          'locked'             => bool ]  // owner has protected chats but the vault is locked
searchForTool(int $owner_id, string $query, int $limit, bool $surface_protected): array
```

- **Standard** matches: always full (title + snippet), plain SQL `ILIKE` as today.
- **Protected**, `$surface_protected` **true** (local model **and** vault open): decrypt-scan in-window;
  return matches with title + snippet, and set `protected_capped` if the cap was hit (below).
- **Protected**, `$surface_protected` **false** (remote model, or vault locked): **do not decrypt or scan
  at all.** Run only a cheap, query-independent existence check — does the owner have *any* non-deleted
  protected conversation? — and set `protected_withheld` / `locked` from it. Because nothing here reads
  content or the query, there is no count to leak and no oracle to probe (§3). The tool renders these two
  booleans as the *fixed* withheld-note.
- `locked` reuses `ownerHasLockedProtected()`.

Everything crypto lives behind this one method. The tool never touches ciphertext, a vault window, or a
provider check beyond the single boolean it passes in. **This is also the swap point for a future index
(§9):** replacing the protected path's linear decrypt-scan with a sealed FTS lookup is an edit *inside*
`searchForTool()` that the tool never sees.

**Candidate cap (perf, §7).** The decrypt-scan runs *only* on the surfaced path (`$surface_protected` =
true, i.e. an already-trusted local turn), and there it is bounded by neither `limit` nor result count —
it decrypts protected messages until it finds matches. `searchForTool()` caps that scan at **N most-recent
protected conversations** (config, default e.g. 50) and sets `protected_capped` when the owner has more,
so the model can say "some older protected chats weren't searched" rather than imply a complete result.
The cap is a local-turn concern only — the remote/locked path never scans, so it neither hits the cap nor
leaks a count. This is the interim guard until the §9 index removes the scan entirely.

---

## 5. Capability gating

Tool exposure is per-conversation, gated by a capability flag the same way the other data-reading tools
are — wired through `resolveAllowedTools()` (`includes/ChatRunner.php:345-378`) and `ChatControls`
(`includes/ChatControls.php:13-23`).

**Decision — its own gate (`aic_history_access`), not `aic_data_access`.** `aic_data_access` means "the
AI may read my *business models*" (events, products, …). Searching chat history is a different, broader,
more sensitive capability: it lets the assistant range across the user's own past *conversations* — the
ambient record of everything they've discussed — not a business table. Give it a **distinct
`aic_history_access` capability + control**, opted into separately.

Why this and not the simpler reuse:
- **Cost asymmetry.** If a separate gate is overkill, the cost is one extra toggle — trivial and
  reversible (merge it later). If reusing `aic_data_access` is wrong, a user who enabled data-access to
  look up an event has *silently* also granted the AI a read across their whole chat history — a trust
  surprise, hard to walk back, and exactly the failure mode a sovereignty/privacy platform exists to
  prevent. Toggle vs. trust → pick the toggle.
- **North star.** "The AI may read all my past conversations" deserves a deliberate, standalone opt-in,
  not a capability that rides in on a switch flipped for business data.
- **Scope, vs. the `get_my_notes` precedent.** Notes are already in the data-access group and are personal
  too — but notes are content the user *deliberately authored for the AI*; conversation history is the
  entire ambient record. The far broader scope is what justifies the separate gate.

Rejected alternative — reuse `aic_data_access`: simplest, no new UI, and defensible on "both are
owner-scoped reads," but it silently widens an existing toggle's meaning into much more sensitive
territory. Rejected on the asymmetry above.

Implementation: a new `aic_history_access` boolean on the conversation (a `ChatControls` flag + a
create/edit UI control), and `resolveAllowedTools()` offers `'search_conversations'` only when it is on.
This is a gating change only — no change to the tool body or the §4.4 seam.

Member vs admin: the tool reads owner identity from `$ctx->actingUserId()` and scopes every
query to it, so it is safe for both (members are owner-scoped by `ChatTurnContext`,
`includes/ChatTurnContext.php:136`).

---

## 6. Integration points (implementation checklist)

| # | File | Change |
|---|------|--------|
| 1 | `plugins/joinery_ai/recipe_tools/SearchConversationsTool.php` | New chat-only tool class (§4.1) — mirror `GetMyNotesTool.php`; reach the turn model via `ChatTurnContext::conversationModel()` like `ViewAttachmentTool` reads `conversationId()` (§3) |
| 2 | `data/ai_conversations_class.php` | Add the `searchForTool()` seam (§4.4): per-match snippet + id + date + level; the `surface_protected` gate (scan only when true); query-independent `protected_withheld` / `locked` booleans when false; the candidate cap. All decrypt-and-scan stays here |
| 3 | `data/ai_conversations_class.php` (field spec) + `includes/ChatControls.php` + create/edit UI | New `aic_history_access` capability (§5): a boolean field, a `ChatControls` flag, and a control in the conversation create/edit form |
| 4 | `includes/ChatRunner.php` | In `resolveAllowedTools()` (`:345-378`), offer `'search_conversations'` only when `aic_history_access` is on (its own gate, §5) |
| 5 | (tool body) | Compute `surface_protected` = `ChatLevel::isLocalModel($ctx->conversationModel())` **and** vault open; pass it to `searchForTool()`; render `protected_withheld` / `locked` as the **fixed, query-independent** withheld-note, never a count (§3) |

No `serve.php` route, no AgentLoop change (dispatch is generic via `RecipeToolRegistry`), no migration —
the one new column (`aic_history_access`) is created automatically from the field spec by
`update_database`.

---

## 7. Edge cases & decisions

- **Vault locked** — protected chats are unsearchable; return the fixed "unlock to include them" note
  (query-independent, no count), never a false empty. Standard chats still search normally.
- **Remote model current turn** — protected chats are not scanned at all; return the fixed
  "switch to a local model" note (query-independent, no count, §3); standard matches returned.
- **Count as an oracle** — never return a per-query protected match count on a remote/locked turn; a
  varying count lets the model probe sealed content one keyword at a time (§3). Real counts only when
  content is already surfaced (local + vault open).
- **Searching the current conversation** — harmless; optionally exclude the active
  `conversation_id` so the model doesn't "find" the chat it's already in.
- **Injection via found content** — snippets are returned inside a `tool_result`, i.e. untrusted
  data; the chat system prompt already frames tool output as untrusted. Keep snippets plain text,
  no markup interpretation.
- **Cost/latency** — the standard path is a bounded SQL `ILIKE`. The **protected path is not bounded
  by `limit`**: it decrypts protected messages until it finds matches, now inside a live turn, and an
  agent may call the tool several times per turn. The §4.4 candidate cap (N most-recent protected chats)
  is the interim guard; §9 is the durable fix. Do not describe protected cost as "bounded by `limit`".
- **No results** — explicit "no matches" string, not an empty result.

---

## 8. Documentation

Update the joinery_ai chat/tools documentation to list `search_conversations`, describing (as the current
end state): its own `aic_history_access` capability gate (§5), owner-scoping, the standard-vs-protected
searchability split, and the rule that protected content is only surfaced to a local model with an
open vault. Cross-reference the Sealed Vault doc for the encryption model.

---

## 9. When to revisit the search mechanism

`ILIKE`/`strpos` is fine at current volume, and chat volume per user is low (dozens–hundreds of
conversations), so the §4.4 seam plus candidate cap is enough for the first build. The durable fix, when
protected-chat volume actually makes the in-window scan hurt, is a **running per-user index** — and the
protected half of that is a solved problem in this codebase, not a new frontier.

**Protected content *can* be indexed — the mailbox already does it.** Correcting a common assumption:
sealed content can't be indexed *in Postgres in the clear* (the columns are ciphertext), but it can be
indexed exactly the way `plugins/mailbox/includes/MailboxIndex.php` indexes sealed email: a **per-user
SQLite FTS5 index built from decrypted content, held only in `/dev/shm`** (RAM, never on disk in the
clear) for the unlock-window lifetime, **persisted between windows as a sealed blob** under the owner's
vault key, folded forward incrementally from a high-water mark, wiped on window-close, purged/rebuilt on
key rotation, and treated as a **disposable cache** (missing/stale/corrupt → rebuild, never an error).
That design dissolves the §4.4/§7 cost concern entirely: a search becomes an FTS lookup, not a full
decrypt-scan.

**When to build it, and how it lands.** Trigger on real pain (protected search latency at a real user's
volume), not speculatively. When triggered, it plugs in **behind `searchForTool()` (§4.4)** — the
protected path swaps decrypt-scan for the FTS lookup with no change to the tool. The open design question
at that point is whether chat gets its own `ChatIndex` or `MailboxIndex` is generalized into one
sealed-FTS engine both mail and chat share; decide it then, with both call sites in hand. The standard
path can move to Postgres FTS (GIN `tsvector`) independently and at any time, since standard content is
plaintext at rest.

---

## 10. Acceptance criteria

1. When `aic_history_access` is on, the model can call `search_conversations` and receive matches scoped
   to the calling user only; user A never sees user B's conversations.
2. Standard-chat matches return title, date, id, and a snippet.
3. Protected-chat matches are returned **only** when the current turn runs on a local model with
   the vault open; otherwise the response carries a **fixed, query-independent** withheld-note — no
   content, no title, no count (never silently dropped, never leaked to a remote model).
4. With the vault locked, protected chats are reported as unsearchable (same fixed, count-free note) and
   standard search still works.
5. The tool is absent from the model's toolset when its `aic_history_access` gate is off (§5).
6. No matches yields an explicit "no matches" result.
7. When the owner has more protected conversations than the §4.4 candidate cap on a surfaced (local)
   turn, the unscanned ones are reported, never silently dropped as a false empty.
8. **No metadata oracle:** on a remote/locked turn, two different queries (one that would match protected
   content, one that would not) produce the **identical** withheld-note. The response never varies with
   the query or the protected match count.

### Test plan
- Harness test (`plugins/joinery_ai/tests/`, `@joinery-test` header): seed standard + protected
  conversations for user A; assert search returns standard matches with snippets; assert protected
  matches are withheld (fixed note) when the turn model is remote, and returned when local + vault open.
- Owner-scope test: user A's search never returns user B's threads.
- Vault-locked test: protected chats reported unsearchable, standard unaffected.
- **Oracle test:** on a remote turn, run a query that matches seeded protected content and one that does
  not; assert byte-identical withheld-notes and that neither reveals a count (guards acceptance #8).
- Candidate-cap test: on a local + unlocked turn, seed more than the cap of protected chats; assert the
  overflow is reported as unscanned rather than yielding a false "no matches".
- Gating test: tool not offered when `aic_history_access` is off; offered when on (and unaffected by
  `aic_data_access`, confirming the two gates are independent).
