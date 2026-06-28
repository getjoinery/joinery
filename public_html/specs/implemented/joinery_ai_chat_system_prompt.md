# Joinery AI — Editable chat system prompt

## Goal

The chat assistant's voice is currently hardcoded — "You are Joinery AI… **Use
Markdown. Be concise.**" plus admin-tool framing — which forces a terse,
bulleted, businesslike register on every reply, wanted or not. Make the **voice**
portion of the chat system prompt editable, while keeping the functional and
safety scaffolding (date/time, tool rules, the untrusted-input contract,
capability catalog) system-managed and always present.

This is chat-only. Recipes keep their own functional preamble ("produce a
report, don't chat") — a different job, not the same knob.

## What's editable vs fixed

**Editable** (one block, admin-controlled): the persona / role / tone / format
guidance. This is the only part that changes the assistant's character.

**Always system-injected, not editable:**
- **Current date/time** in the admin's timezone — always appended.
- **Tool rules** — only when tools are active: the "you can inspect/manage the
  site by calling tools," the acting user_id + write-owner instruction (data
  access on), and the confirmation-before-mutating note.
- **Model catalog** — when Data access is on (unchanged).
- **Untrusted-input contract** — when applicable, after the cache breakpoint
  (unchanged, security-critical).

The editable block cannot remove or weaken any of the fixed pieces — they are
appended by the builder regardless of what the admin types. That's the safety
guarantee: an admin can change the voice, not disable the untrusted-input
defense or the confirmation boundary.

## Storage

Declarative plugin setting (seeded from `plugin.json`, per the settings system):

- `joinery_ai_chat_system_prompt` (text) — the editable voice block. Factory
  default is a warm, tool-agnostic instruction (see below).

Empty value falls back to a shipped default **constant** in `ChatRunner` (so an
accidentally blank setting never produces an assistant with no instructions).

Factory default (note: no "Be concise", tool-agnostic so it reads right even
with no tools enabled):

```
You are Joinery AI, a helpful assistant for the administrator of this site.
Answer naturally and conversationally. Use Markdown when it helps.
```

## Prompt assembly (ChatRunner::buildSystemPrompt restructure)

Today the preamble welds voice + date + identity + tool/confirmation rules into
one string. Split it:

1. **Voice block** = the setting value (or the default constant if blank).
2. **+ Date/time line** — always.
3. **+ Tool rules** — only if the turn has any tools (`resolveAllowedTools()`
   non-empty): the "inspect/manage via tools" framing and the confirmation note.
   The acting user_id + write-owner line is added when Data access is on.
4. **+ Model catalog** — when Data access is on (unchanged
   `AiPromptBuilder::modelCatalogBlock`).
5. Assemble cached prefix via `AiPromptBuilder::systemBlocks($cachedText,
   $untrustedBlock)` exactly as now; the untrusted block still follows the cache
   breakpoint.

Result: a plain chat with no tools gets just **voice + date/time** — which is
the case the current prompt over-constrains. A tool-enabled chat gets the same
functional scaffolding it has today, with the voice swapped in at the top.

## Editing UI

A small admin page under Joinery AI (e.g. `/admin/joinery_ai/chat_settings`),
since a multi-line system prompt is a poor fit for the generic settings list:

- A FormWriter form (never hand-rolled) with one textarea bound to
  `joinery_ai_chat_system_prompt`, plus a **Reset to default** control that
  restores the shipped default constant.
- Per the self-documenting-pages rule: minimal helptext (one line: "Sets the
  assistant's voice. Date/time, tool rules, and safety instructions are always
  added automatically."), no explainer prose. Detailed docs live in
  `/docs`/overview.
- Add the page to the plugin's `adminMenu` under the Joinery AI group.
- Writing the setting goes through the platform's settings update path (see
  `/adm/admin_settings.php` for how settings are persisted) — no ad-hoc writes.

## Edge cases

- **Blank setting** → default constant (never an empty voice block).
- **Tools toggled mid-conversation** → the tool rules appear/disappear per turn
  as they already do; the voice block is constant.
- **Cache** — the voice block sits in the cached prefix with the catalog; editing
  the setting changes the cached text and simply starts a new cache lineage on
  the next turn. No special handling.
- **Prompt injection via the setting** — the editor is a permission-10 admin
  setting the site's own voice; it is trusted input, unlike tool-result content.
  The untrusted-input contract still wraps external data regardless.

## Test plan

- Default (blank) setting: a plain chat reply reads conversational, not forced
  into bullets; date/time still present in the prompt.
- Set a custom voice (e.g. "Reply in one short paragraph, no lists"): confirm
  replies follow it.
- Data access on: model catalog + user_id/write rules still present; voice block
  still applied.
- A mutating request still produces the confirmation card (tool rules intact).
- Reset to default restores the shipped constant.

## Docs (land at implementation, current-state voice)

`plugins/joinery_ai/docs/overview.md`, Chat section: document that the chat
system prompt is a fixed scaffold (date/time always; tool rules, model catalog,
and untrusted-input contract when applicable) plus an admin-editable voice block
stored in `joinery_ai_chat_system_prompt`, edited at
`/admin/joinery_ai/chat_settings`, with an empty value falling back to a built-in
default. Note recipes are unaffected.

## Not in scope

Per-conversation prompt overrides / saved personas ("My Copilots"-style). This
spec ships one global editable voice; per-conversation personas can build on this
setting later if wanted. Sampling parameters (temperature, top_p) are a separate
knob and not part of this spec.
