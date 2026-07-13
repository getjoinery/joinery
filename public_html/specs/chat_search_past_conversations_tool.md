# Spec: "Search past conversations" model tool (Joinery AI)

**Status:** Draft (awaiting implementation)
**Version:** 1.0
**Area:** `plugins/joinery_ai` — chat tool surface
**Depends on:** existing sealed-aware search (`MultiAiConversation::getSearchResults`), Sealed Vault window

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

**The tool reuses this layer rather than reinventing it.** Reimplementing decrypt-and-scan
inside a tool would duplicate the crypto boundary in a second place — the wrong layer. All
sealed/plaintext logic stays in the data class.

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
  title + snippet.
- **Protected** matches, current turn on a **remote** model (or vault locked): **do not return
  their content or titles.** Instead return a count-only note, e.g. *"3 protected conversations
  also matched but are not shown here because this chat is using a remote model"* (or
  *"…because your vault is locked"*). Never silently omit — a false "no results" is a
  correctness and trust failure.

Determining "current turn's model is local" reuses the same signal `ChatLevel` uses for
`fortressAvailable()` (a configured local model; `includes/ChatLevel.php:46-53`). The tool reads
the current conversation + active provider from its `ToolContext`.

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
  1. Read `$uid = $ctx->actingUserId()`.
  2. Call the search layer scoped to the owner:
     `new MultiAiConversation(['owner_user_id'=>$uid, 'search'=>$query, 'deleted'=>false], ...)`
     (or a thin new sibling of `getSearchResults()` that also returns a per-match snippet +
     `aic_conversation_id` + `aic_update_time`; put any snippet extraction in the data class so
     the crypto boundary stays centralized — §2).
  3. Apply the §3 remote-model guard to protected matches.
  4. Format a compact result (§4.2) and return it as the tool's string result
     (`RecipeToolInterface` contract, `includes/RecipeToolInterface.php:30-41`).

### 4.2 Result shape returned to the model
A short, token-bounded list, newest/most-relevant first (reuse the existing ordering
`aic_pinned DESC, aic_update_time DESC`). Per match:
- `id` (conversation id — lets a follow-up tool or the UI deep-link),
- `title` (or `Protected chat (locked)` placeholder for withheld ones),
- `date` (from `aic_update_time`, formatted in `$ctx->ownerTimezone()`),
- `snippet` (≤ ~200 chars around the match; omitted for withheld protected chats).

Cap total results at `limit`; append the §3 count-note for any withheld protected matches, and a
"vault locked" note when `ownerHasLockedProtected()` is true. If nothing matches, return a plain
"No matching conversations found."

### 4.3 Optional companion (noted, not required now)
Search returns snippets, not whole threads. If the model needs the full text of a found
conversation, that is a separate `get_conversation` (read-by-id) tool with the identical
owner-scope + §3 remote-model guard. Out of scope here; flagged so the boundary is designed once.

---

## 5. Capability gating

Tool exposure is per-conversation. Register the tool by adding `'search_conversations'` to
`ChatRunner::DATA_TOOLS` (`includes/ChatRunner.php:106-107`), so it appears only when the chat
has the **data-access** capability (`aic_data_access`) turned on — the same toggle that gates the
other data-reading tools, wired through `resolveAllowedTools()` (`:345-378`) and
`ChatControls` (`includes/ChatControls.php:13-23`). No new capability flag or UI is needed;
searching one's own history is a data read and belongs in that group.

Member vs admin: the tool reads owner identity from `$ctx->actingUserId()` and scopes every
query to it, so it is safe for both (members are owner-scoped by `ChatTurnContext`,
`includes/ChatTurnContext.php:136`).

---

## 6. Integration points (implementation checklist)

| # | File | Change |
|---|------|--------|
| 1 | `plugins/joinery_ai/recipe_tools/SearchConversationsTool.php` | New tool class (§4.1) — mirror `GetMyNotesTool.php` |
| 2 | `data/ai_conversations_class.php` | Optionally extend `getSearchResults()` (or add a sibling) to return per-match snippet + id + date, keeping decrypt-and-scan in this one place (§2, §4.1) |
| 3 | `includes/ChatRunner.php` | Add `'search_conversations'` to `DATA_TOOLS` (`:106-107`) |
| 4 | (tool body) | Apply the §3 remote-model / vault-locked guard using the `ChatLevel` local-model signal (`ChatLevel.php:46-53`) and `ownerHasLockedProtected()` (`ai_conversations_class.php:320`) |

No `serve.php` route, no AgentLoop change (dispatch is generic via `RecipeToolRegistry`), no new
DB column, no migration.

---

## 7. Edge cases & decisions

- **Vault locked** — protected chats are unsearchable; return the count-note, never a false
  empty. Standard chats still search normally.
- **Remote model current turn** — protected matches withheld with a count-note (§3); standard
  matches returned.
- **Searching the current conversation** — harmless; optionally exclude the active
  `conversation_id` so the model doesn't "find" the chat it's already in.
- **Injection via found content** — snippets are returned inside a `tool_result`, i.e. untrusted
  data; the chat system prompt already frames tool output as untrusted. Keep snippets plain text,
  no markup interpretation.
- **Cost/latency** — bounded by `limit` and snippet length; protected decrypt-scan is O(candidate
  rows) but only over the owner's own protected chats, low volume.
- **No results** — explicit "no matches" string, not an empty result.

---

## 8. Documentation

Update the joinery_ai chat/tools documentation to list `search_conversations` among the
data-access tools, describing (as the current end state): owner-scoping, the standard-vs-protected
searchability split, and the rule that protected content is only surfaced to a local model with an
open vault. Cross-reference the Sealed Vault doc for the encryption model.

---

## 9. When to revisit the search mechanism

`ILIKE`/`strpos` is fine at current volume. Move to a real index (PostgreSQL FTS with a GIN
`tsvector`, or embeddings for semantic recall) when a user's conversation count makes linear scan
slow — but note that **protected content can never be indexed server-side** (no plaintext at
rest), so any future index covers standard chats only; protected search stays
decrypt-and-scan-in-window by construction.

---

## 10. Acceptance criteria

1. When data-access is on, the model can call `search_conversations` and receive matches scoped
   to the calling user only; user A never sees user B's conversations.
2. Standard-chat matches return title, date, id, and a snippet.
3. Protected-chat matches are returned **only** when the current turn runs on a local model with
   the vault open; otherwise they appear as a count-only note (never silently dropped, never
   leaked to a remote model).
4. With the vault locked, protected chats are reported as unsearchable and standard search still
   works.
5. The tool is absent from the model's toolset when data-access is off.
6. No matches yields an explicit "no matches" result.

### Test plan
- Harness test (`plugins/joinery_ai/tests/`, `@joinery-test` header): seed standard + protected
  conversations for user A; assert search returns standard matches with snippets; assert protected
  matches are withheld (count-note) when the turn model is remote, and returned when local + vault
  open.
- Owner-scope test: user A's search never returns user B's threads.
- Vault-locked test: protected chats reported unsearchable, standard unaffected.
- Gating test: tool not offered when `aic_data_access` is off.
