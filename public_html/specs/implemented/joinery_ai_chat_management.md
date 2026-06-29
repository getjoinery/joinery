# Joinery AI — Chat management & render polish

**Status:** Implemented (search + export; per-code-block copy and syntax highlighting deferred — see Out of scope)
**Plugin:** `joinery_ai`
**Touches:** `AiConversation` (one column) + `MultiAiConversation` (list query),
`ChatRender` (one data attribute), the chat left pane + transcript JS and CSS, a
small thread-mutation endpoint, a list endpoint, an export endpoint.

## Goal

The left pane only lists and selects threads, and an assistant reply is a wall of
text you can't easily lift out. This spec adds the expected affordances around the
conversation list and the rendered reply — the cheap, no-lifecycle half of
Chatbox parity.

In plain terms: rename / delete / pin / search your conversations, export a thread,
and copy a reply.

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

**`chat_export`** (GET `conversation_id`) assembles the thread in order from the
stored `aim_content`, in one of two formats the caller picks (GET `format`):

- **`markdown`** *(default)* — role-labeled turns (`**You:**` / `**Assistant:**`)
  with the stored source markdown intact. For pasting where Markdown renders
  (docs, issues, another chat).
- **`text`** — paste-ready **plain text** for social media, which doesn't render
  Markdown. Same role labels (`You:` / `Assistant:`), but the body is run through a
  markdown-to-plain-text pass: emphasis (`**`/`*`) stripped to the words, headings
  to plain lines, code fences/backticks removed (code kept as plain/indented
  lines), list markers kept as simple `• `/number bullets, and `[text](url)`
  flattened to `text (url)` so links survive as bare tappable URLs.

Owner-scoped. Returns the assembled string (JSON for the clipboard, or
`text/markdown` / `text/plain` for a direct download). The pane's Export action
offers the **format choice** (Markdown / Plain text) and, for each, **Copy** (to
clipboard) and **Download** (`.md` / `.txt`). Image/PDF export is out of scope.

## Render polish

### Copy buttons

- **Whole message:** a copy control on each assistant bubble that copies the
  **raw markdown** (so the paste is clean source, not HTML soup). `ChatRender`
  already has the source in hand and carries the HTML-attribute-escaped
  `aim_content` on the assistant bubble; the JS reads it to the clipboard.
  Event-delegated vanilla JS on the transcript, so it works on dynamically-appended
  bubbles too.

## Frontend (chat.php left pane + transcript)

- **List item affordances:** a compact per-item menu (Rename / Pin·Unpin /
  Delete / Export) — guided controls, no explainer prose. Rename swaps the label
  for an inline input; Delete confirms then removes the item; Pin re-sorts to the
  top with its glyph. Export opens the format choice (Markdown / Plain text), each
  with Copy and Download. Rename/Pin/Delete call `chat_thread_action`; Export
  calls `chat_export`.
- **Search box** atop the pane, debounced, re-querying `chat_list` and re-rendering
  the thread list (pinned-first ordering preserved).
- **Copy** applies to every assistant bubble, on initial load and on every
  appended turn, via the delegated handler above.

## What does NOT change

- The turn lifecycle, `AgentLoop`, the providers, `ChatRunner`, the confirmation
  boundary, token accounting, and the model-control knobs — untouched. This is
  list CRUD + render only.
- The assistant bubble structure — copy decorates it; it doesn't restyle the
  transcript or change `ChatRender`'s contract.

## Security & cost

- **Owner-scoped CRUD.** `chat_thread_action`, `chat_list`, and `chat_export` all
  resolve the conversation and enforce `aic_owner_user_id === uid` + permission ≥
  5 — a caller can only manage, search across, or export their own threads.
- **The copy attribute must be attribute-escaped.** The raw markdown goes into an
  HTML attribute for the copy button; escape it (`ENT_QUOTES`) so reply content
  can't break out of the attribute and inject markup. The visible body keeps its
  existing `MarkdownRenderer` escaping.
- **No new model spend** — nothing here calls a provider.

## Out of scope

- **Conversation branching / message-tree** — unrelated; not introduced here.
- **Image/PDF export** of a thread — text export only (Markdown / Plain text).
- **Cross-user / global search** — search is within the caller's own threads.
- **Saved searches, folders, tags, bulk actions** — single search box + per-thread
  actions for v1.
- **Syntax highlighting** of fenced code blocks, and **per-code-block copy**
  buttons — deferred; this spec covers whole-message copy only.
- **Rich rendering** (LaTeX, Mermaid, HTML artifacts) — a separate, deferred
  cluster.

## Implementation outline

Already shipped: `aic_pinned` column, pinned-first ordering
(`aic_pinned DESC, aic_update_time DESC`), the `chat_thread_action` endpoint
(rename/pin/delete) with its per-item kebab menu, and whole-message copy. Remaining:

1. `MultiAiConversation`: add a `search` option (title ILIKE OR message-content
   EXISTS); the shared chat logic passes it through.
2. Endpoint `chat_list` (filtered, pinned-first JSON list) — owner-scoped.
3. Endpoint `chat_export` (GET `conversation_id`, `format`): Markdown assembly, plus
   a `text` format that runs each turn's `aim_content` through a markdown-to-plain-text
   pass — owner-scoped.
4. Frontend: debounced search box atop the pane (re-queries `chat_list`); add an
   Export item to the kebab menu offering the format choice (Markdown / Plain text),
   each with Copy and Download (`.md` / `.txt`); CSS as needed.
5. `php -l` + `validate_php_file.php` on every modified PHP file; bump the plugin
   version in `plugin.json`.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` (current-state
voice): a "Managing conversations" section covering rename/pin/delete, search
(title + content), and export (Markdown or plain text); and a note in the
Chat-rendering description that assistant replies offer whole-message copy.
