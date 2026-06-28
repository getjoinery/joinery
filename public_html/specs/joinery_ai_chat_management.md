# Joinery AI — Chat management & render polish

**Status:** Active — awaiting implementation
**Plugin:** `joinery_ai`
**Touches:** `AiConversation` (one column) + `MultiAiConversation` (list query),
`ChatRender` (one data attribute + a markdown highlight hook), `MarkdownRenderer`
(core, optional highlight pass), the chat left pane + transcript JS and CSS, a
small thread-mutation endpoint, a list endpoint, an export endpoint.

## Goal

The left pane only lists and selects threads, and an assistant reply is a wall of
text you can't easily lift out. This spec adds the expected affordances around the
conversation list and the rendered reply — the cheap, no-lifecycle half of
Chatbox parity.

In plain terms: rename / delete / pin / search your conversations, export a thread,
and copy a reply (whole message or a single code block) with code shown
syntax-highlighted.

## Why these are one spec

None of them touch the turn lifecycle, the agent loop, the providers, or the
model-control knobs. They are **CRUD on the conversation row**, a **list query**,
and **frontend render** over already-escaped HTML. That shared "cheap, no
async" nature is the seam — the heavier turn actions (stop / regenerate / edit)
live in their own spec precisely because they *do* touch the lifecycle.

## Conversation management

### Schema

One column on `AiConversation` (`aic_conversations`):

```php
'aic_pinned' => array('type'=>'bool', 'default'=>false),
```

Rename reuses `aic_title`; delete reuses the existing `aic_delete_time`
soft-delete; search needs no column. So pin is the only new field.

### Thread-mutation endpoint

A single owner-scoped endpoint **`chat_conversation`** (POST `conversation_id`,
`action`) covering the thread-level mutations — distinct from the per-chat
*settings* endpoint in the model-control spec (that one steers the model; this one
manages the thread):

- **`rename`** (`title`) — set `aic_title` (trim, length-cap; empty → "Untitled").
- **`pin`** / **`unpin`** — set `aic_pinned`.
- **`delete`** — soft-delete: set `aic_delete_time = now()`. The list already
  filters `deleted = false`, so it drops out immediately. Permanent removal (and
  message cascade) stays with the platform deletion system — this is the
  hide-from-list gesture.

Every action checks `aic_owner_user_id === uid` and permission ≥ 5, exactly like
`chat_send`/`chat_poll`.

### Pinned ordering

The list resolves **pinned first, then most-recent**. `MultiAiConversation`
already orders by `aic_update_time DESC`; change the order to
`aic_pinned DESC, aic_update_time DESC` (and `admin_chat_logic` passes the same).
A pin glyph marks pinned items in the pane.

### Search

`MultiAiConversation::getMultiResults()` gains a `search` option that matches the
term against the **title** (`aic_title ILIKE %term%`) **or** any message body in
the thread (an `EXISTS` subquery on `aim_conversation_messages.aim_content ILIKE`,
non-deleted rows). Title-and-content in one box is what users expect; the subquery
keeps it to one query with no denormalization.

A small JSON endpoint **`chat_list`** (GET `search`) returns the filtered,
ordered thread list (id + title + pinned) so the pane can filter live as the user
types (debounced) without a full page reload. With no `search`, it returns the
same list the page renders on load.

### Export

**`chat_export`** (GET `conversation_id`) assembles the thread as **Markdown** —
role-labeled turns (`**You:**` / `**Assistant:**`) joined in order, from the
stored `aim_content` (the source markdown, not rendered HTML). Owner-scoped.
Returns the markdown string (JSON, or `text/markdown` for a direct download). The
pane offers **Copy** (to clipboard) and **Download .md** from one export action.
Image export is out of scope (markdown only).

## Render polish

### Copy buttons

- **Whole message:** a copy control on each assistant bubble that copies the
  **raw markdown** (so the paste is clean source, not HTML soup). `ChatRender`
  already has the source in hand — add a `data-md` attribute on the assistant
  bubble carrying the HTML-attribute-escaped `aim_content`; the JS reads it to the
  clipboard. (Escaping matters — see Security.)
- **Per code block:** a copy control injected by JS onto each `<pre>` in a
  rendered bubble, copying the contained `<code>` text. `MarkdownRenderer` already
  emits `<pre><code class="language-x">…</code></pre>` for fenced blocks
  (`MarkdownRenderer.php`), so the hook is already there.

Both are event-delegated vanilla JS on the transcript and CSS in
`plugins/joinery_ai/assets/css/joinery_ai.css` — no library, no per-bubble
wiring, and they work on dynamically-appended bubbles too.

### Syntax highlighting

`MarkdownRenderer` already tags fenced blocks with `language-x`. There are two
honest ways to color them, and it's a real fork — plain stakes first:

- **Server-side, in `MarkdownRenderer`** *(recommended)* — a highlight pass wraps
  tokens in classed `<span>`s, themed by CSS. Catch: writing/own a tokenizer means
  modest language coverage, unknown languages fall back to plain code. Upside:
  stays fully vanilla (the platform's CSS/JS-framework rule), needs no client
  dependency, benefits **every** `MarkdownRenderer` consumer (the spec viewer
  too), and the colors survive in exported/printed output.
- **Client-side highlighter library** — richer language coverage out of the box.
  Catch: adds a third-party JS dependency, which the theme-framework rule
  discourages unless explicitly adopted, and does nothing for export.

Recommendation: **server-side in `MarkdownRenderer`**, keyed off the existing
`language-x` class, degrading to plain `<code>` for unknown languages — it keeps
the platform vanilla and is reused platform-wide. Flagging it because it's a
dependency/scope call worth your explicit yes before implementation.

## Frontend (chat.php left pane + transcript)

- **List item affordances:** a compact per-item menu (Rename / Pin·Unpin /
  Delete / Export) — guided controls, no explainer prose. Rename swaps the label
  for an inline input; Delete confirms then removes the item; Pin re-sorts to the
  top with its glyph. Each calls `chat_conversation`; Export calls `chat_export`.
- **Search box** atop the pane, debounced, re-querying `chat_list` and re-rendering
  the thread list (pinned-first ordering preserved).
- **Copy / highlight** apply to every assistant bubble, on initial load and on
  every appended turn, via the delegated handlers above.

## What does NOT change

- The turn lifecycle, `AgentLoop`, the providers, `ChatRunner`, the confirmation
  boundary, token accounting, and the model-control knobs — untouched. This is
  list CRUD + render only.
- The assistant bubble structure — copy/highlight decorate it; they don't restyle
  the transcript or change `ChatRender`'s contract beyond the `data-md` attribute
  and the (optional) highlighted code spans.

## Security & cost

- **Owner-scoped CRUD.** `chat_conversation`, `chat_list`, and `chat_export` all
  resolve the conversation and enforce `aic_owner_user_id === uid` + permission ≥
  5 — a caller can only manage, search across, or export their own threads.
- **`data-md` must be attribute-escaped.** The raw markdown goes into an HTML
  attribute for the copy button; escape it (`ENT_QUOTES`) so reply content can't
  break out of the attribute and inject markup. The visible body keeps its
  existing `MarkdownRenderer` escaping.
- **Highlighting adds no trust surface** — it re-classes already-escaped code
  text; it never executes or re-parses it as HTML.
- **No new model spend** — nothing here calls a provider.

## Out of scope

- **Conversation branching / message-tree** — unrelated; not introduced here.
- **Image/PDF export** of a thread — markdown export only.
- **Cross-user / global search** — search is within the caller's own threads.
- **Saved searches, folders, tags, bulk actions** — single search box + per-thread
  actions for v1.
- **Rich rendering** (LaTeX, Mermaid, HTML artifacts) — a separate, deferred
  cluster; this spec covers code highlighting and copy only.

## Implementation outline

1. Add `aic_pinned` (bool, default false) to `AiConversation`; run plugin sync.
2. `MultiAiConversation`: `search` option (title ILIKE OR message-content EXISTS);
   order `aic_pinned DESC, aic_update_time DESC`; `admin_chat_logic` matches.
3. Endpoints: `chat_conversation` (rename/pin/unpin/delete), `chat_list` (filtered
   JSON list), `chat_export` (markdown assembly) — all owner-scoped.
4. `ChatRender::assistantBubble()`: add the escaped `data-md` attribute.
5. `MarkdownRenderer`: optional server-side highlight pass over `language-x`
   fenced blocks (pending the fork decision); theme in `joinery_ai.css`.
6. `chat.php`: per-item menu (rename/pin/delete/export), debounced search box,
   delegated copy-message + copy-code handlers; CSS for buttons, pin glyph, and
   code theme.
7. `php -l` + `validate_php_file.php` on every modified PHP file; bump the plugin
   version in `plugin.json`.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` (current-state
voice): a "Managing conversations" section covering rename/pin/delete, search
(title + content), and markdown export; and a note in the Chat-rendering
description that assistant replies offer whole-message and per-code-block copy and
that fenced code is syntax-highlighted. If the highlight pass lands in
`MarkdownRenderer`, note the capability in `docs/` where MarkdownRenderer is
described so other consumers know it's available.
