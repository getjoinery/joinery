# Mailbox reader — show the AI triage summary in the inbox

**Status:** Implemented.
**Built on:** `implemented/joinery_ai_email_triage.md` (which writes
`iem_ai_summary`) and the existing reader plumbing that already carries the
AI danger score end to end.
**Touches:** `plugins/mailbox/includes/MailboxService.php` (two methods),
`plugins/mailbox/assets/mailbox_reader.js` (one render spot),
`plugins/mailbox/assets/mailbox_reader.css` (one class). No schema, no new
endpoints, no job changes.

## The gap

The triage job writes a one-line summary of every message
(`iem_ai_summary`) — and no surface shows it. The danger score flows all
the way to the list badge; the summary reaches nothing. The whole point of
the field is scanning an inbox without opening messages, so the inbox list
is where it belongs.

## Where it goes (the existing path, one field wider)

The reader list is: `mailbox_reader.js` → `/ajax/mailbox_list` →
`thread_list_logic()` → `MailboxService::getThreads()`, whose thread rows
already carry `snippet` (built from the latest message's body) and
`danger_score`. The thread view is `thread_logic()` →
`MailboxService::getThread()`. Both logic files are `_logic_api()`-exposed,
so the native apps receive whatever these rows carry — adding the field
here is the whole server-side job.

### 1. `MailboxService::getThreads()` — list rows

- `fetchAndDecryptContent()`: add `iem_ai_summary` to its SELECT and to its
  `$fields` map (`'iem_ai_summary' => 'ai_summary'`). It is already a
  `$sealed_fields` member, so the existing per-column raw-row decrypt hook
  handles sealed mailboxes with **zero new crypto code** — decrypted
  in-window, the standard locked placeholder when locked, plain when never
  sealed: exactly the same states sender/subject/snippet already have.
- Thread row: add `'ai_summary' => trim((string)($latest['ai_summary'] ?? ''))`
  — the latest message's summary, matching how `subject`/`snippet` are
  taken from the latest message.

### 2. `MailboxService::getThread()` — per-message rows

Add `iem_ai_summary` to the SELECT, to `decryptThreadRow()`'s field list,
and `'ai_summary' => $decrypted['iem_ai_summary']` to the output row. This
is for the native apps' message payloads and future web use — the web
thread view does **not** render it (the full body is on screen; a summary
of what you're already reading is noise). Decision, not an omission.

### 3. Reader list rendering

In `mailbox_reader.js`'s thread-row builder (the `Subject — preview` line,
~line 320): when `t.ai_summary` is a non-empty string, render it as the
preview instead of the snippet; otherwise keep the snippet exactly as
today:

```js
var preview = t.ai_summary || t.snippet;
if (preview) {
    var cls = t.ai_summary ? 'mbx-thread-snippet mbx-thread-ai' : 'mbx-thread-snippet';
    var span = el('span', cls, ' — ' + preview);
    if (t.ai_summary) { span.title = 'AI summary'; }
    mid.appendChild(span);
}
```

One preview line, AI-written when available — the summary *is* the better
snippet, so they never stack. `mbx-thread-ai` gets one CSS rule
(`font-style: italic;` on the existing snippet styling) — a subtle cue that
the line is machine-written, plus the tooltip; no icon, no prefix, no
legend (self-documenting, minimal).

Untriaged messages (`ai_summary` null/empty) fall back to the snippet, so a
mailbox with no triage recipe looks exactly like today. A sealed-and-locked
mailbox shows the same locked placeholder in the preview it already shows
for sender/subject — no special case.

## What does NOT change

- The triage job, `iem_ai_summary` writes, sealing — untouched; this is
  read-side only.
- The danger badge, sections, pagination, search — untouched.
- No new endpoint, no `/ajax/` addition (the existing legacy shim already
  fronts `thread_list_logic`; nothing new lands there).
- The web thread view (§ 2 note).

## Security

Nothing new. The summary is model-authored text about attacker-controlled
mail; it renders through the same `el()` text-node path as the snippet
(no HTML interpretation), and its sealed/locked states are inherited from
the existing content-column handling.

## Implementation outline

1. `MailboxService`: § 1 + § 2 (bump its `@version`).
2. `mailbox_reader.js` + `mailbox_reader.css`: § 3.
3. Verify in the browser on dev: the triaged messages on
   `joineryemailtests@gmail.com` (summaries already written by recipe 126)
   show italic AI previews; an untriaged thread still shows its body
   snippet; the thread view is unchanged.
4. Verify the API face: `thread_list_logic` output now carries
   `ai_summary` per thread (the native apps' member-screens work picks it
   up on its own schedule — no mobile changes in this spec).
5. `php -l` + `validate_php_file.php` on `MailboxService.php`; bump
   `plugins/mailbox/plugin.json`.

## Docs

`plugins/mailbox/docs/overview.md`, the "Email triage" section: one
sentence — the inbox list shows `iem_ai_summary` as the thread's preview
line (italic, replacing the body snippet) when the message has been
triaged. Current-state voice.
