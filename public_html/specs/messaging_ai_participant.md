# Spec: AI participant in messaging (an assistant that lives in a human conversation)

**Status:** Draft (awaiting implementation)
**Version:** 1.2
**Area:** core messaging (`data/conversations_class.php`, `logic/conversation_send_logic.php`) + `plugins/joinery_ai` engine (`AgentLoop`, `AiModelResolver`, `AiPromptBuilder`)
**Depends on:** the shipped human messaging system (`cnv`/`cnp`/`msg`); the shipped joinery_ai chat engine; **group-conversation enablement** (§3.2 — a real prerequisite, not assumed)
**Related:** `joinery_ai_chat_member_access.md` (owner-scoped reads — the opposite direction; here the AI reads *nothing* private), `docs/social_features.md`

---

## 1. What this does, in plain terms

Today a person's AI assistant lives in its own private chat (the `joinery_ai` chat, single-owner). And two people who both use Joinery can message each other (the human messaging system). This joins the two: **a normal conversation between people can have the AI sitting in it as a participant**, and anyone in the room can pull it into the discussion by **@-mentioning it**. It answers once, in the room, where everyone can see — then goes quiet until it's addressed again.

The important reframe, and the reason this is small: **this is not "sharing an AI chat."** It is a human conversation that happens to have an AI member. So it is built on the messaging system you already have — which is already multi-participant, already has per-person read state, already runs block-checks — and the AI is *just another participant*. The joinery_ai engine stops owning the conversation; it becomes the thing you call to produce that participant's next message.

One rule shapes the whole design, and it's about privacy, so the spec leads with it (§2): **the in-room AI reaches no participant's private data.** It can see and reply to the conversation it's in and look things up on the public web, but it cannot read any participant's private data, notes, files, or other conversations, and it does not act as any one person's agent. It belongs to the *room*.

---

## 2. The security spine: the AI reaches no participant's private data (core requirement)

The joinery_ai engine is powerful precisely because it can be given capabilities that reach into one person's private world: read the user's business data, search their past conversations, read their notes, reach into their files, decrypt their vault. **None of that may cross into a shared room.** The line is drawn by *whose data a tool reads* — not by whether tools are used at all.

The failure mode this prevents: Alice @-mentions the AI in a room she shares with Bob. If the AI answered *as Alice's agent* — with Alice's data-access, Alice's history search, Alice's vault — then Bob is now watching an assistant that can pull Alice's private records into a space Bob shares, and a well-phrased question from Bob could turn the AI into a read-oracle over Alice's data. That must not happen.

**Rule:** the in-room AI may use only tools that read the **room itself or neutral, public resources**; it may **not** use any tool that reads a **participant's private data**. Concretely, when a turn runs for a messaging conversation:

- **No private-data tools.** The `allowed_tools` passed to `AgentLoop::run()` **excludes** every tool that reaches into a participant's private world — `query_model` (business data), `get_my_notes`, `search_conversations`, the durable-memory tools `recall`/`remember`/`forget` (which read/write a user's private `mem_memories` — added to the platform after this spec's first draft and equally on the wrong side of the private-data line), and any file/attachment/vault reach. The engine dispatches tools generically from this list (`AgentLoop.php`), so leaving them off the list is a hard, structural boundary, not a prompt request. Any tool added to the platform later defaults to *off* here; inclusion requires re-applying this rule.
- **Room-safe tools are allowed — web search foremost.** Tools that touch only the public web or the room itself *are* offered, because they read nobody's private data. Web search sends a query out to a public search provider and pulls public results back — the same class of outbound exposure the room already accepted by having an AI at all (its content already goes to the model provider), not a new reach into anyone's private store. **The test for any future tool is exactly this: does it read a participant's private data? If yes, it's off; if it only reads the room or the public web, it's on.** Deciding the rule once, not tool-by-tool, is deliberate (up-front inventory).
- **No personal identity.** The turn does **not** run under any participant's vault, provider config, data-access, or model preferences. It reads no `aic_*` capability columns because there is no `aic` conversation — this room lives entirely in `msg_messages`.
- **In-room content plus public lookups only.** The model receives the conversation's own message history (§3.5), a fixed group-chat system prompt (§3.6), and any public web results it fetches — **never** another participant's private records. The history is plaintext-at-rest human messaging with no sealed content to leak, which is why a remote default model is acceptable (§3.4).

**Transparency is the consent.** Room content is sent to an LLM provider (and, for web search, a derived query to a search provider), so every person in the room must be able to *see* that the room has an AI in it. That is automatic: the AI is a visible participant row (§3.1) in the participant list, and adding it is a deliberate act. A person joining a room that already has the AI sees it listed like any member. No hidden listener.

> This is the mirror image of `joinery_ai_chat_member_access.md`. That spec carefully lets a member's *own* AI read the member's *own* rows. This spec says a *shared-room* AI reads **no one's** private rows — only the room and the public web. Same sovereignty posture, opposite scope: an AI's reach is bounded by the trust context it sits in.

Everything below is plumbing around this constraint.

---

## 3. Design

### 3.1 The AI is a real, reserved participant identity

The AI is represented as a **real `usr_users` row** — a reserved system identity — and it sits in `cnp_conversation_participants` exactly like a human member.

Why a real user row and not a new "non-user participant" type: the entire messaging UI (participant lists, avatars, message authorship, notifications) resolves people through `usr_users`. A real row means the AI renders and behaves everywhere with **zero special-casing** — `User::display_name()` (`data/users_class.php:986`) and `User::get_picture_link()` (`data/users_class.php:1285`) already return a name and avatar for any row, so populating `usr_first_name` / `usr_organization_name` and `usr_pic_picture_id` makes the AI appear in the participant list with no rendering changes. A parallel "participant that isn't a user" would fork every one of those surfaces.

**Precedent already exists.** The codebase reserves fixed system user ids: `User::USER_SYSTEM = 2` and `User::USER_DELETED = 3` (`data/users_class.php:70-71`), protects those rows from normal edit/delete (`:1128`), and excludes them from user listings via the `MultiUser` `not_system_users` filter (`:1365-1367`). Add the AI the same way:

- A new reserved constant `User::USER_AI` (next reserved id) and a seeded row for it (name e.g. "Joinery Assistant", a default avatar), created at install alongside the other reserved users, protected from edit/delete like `USER_SYSTEM`.
- Extend `not_system_users` to also exclude `USER_AI` so the AI never shows up in "start a conversation with…" people-pickers as if it were a person to befriend — it is added to a room by a deliberate affordance (§3.3 note), not by browsing users.

**One global identity.** There is a single AI participant identity shared across all rooms; its per-room state is nothing more than that room's message thread. No per-room bot rows.

### 3.2 Group conversations are a real prerequisite (call it out honestly)

"A conversation between people, with an AI in it" is, at minimum, **three participants** (two humans + the AI). The messaging *data layer* already supports N participants — `Conversation::create_conversation($participant_user_ids, $subject)` (`data/conversations_class.php:91`) accepts an array and only requires `count >= 2`, and `cnv_conversations` has a `cnv_subject` column for group naming (`:35`). But the *product* only ever creates 1:1 conversations: `get_or_create_conversation($u1, $u2)` (`:45`) is strictly two-user, it is the only creation path any send flow calls (`logic/conversation_send_logic.php:48`), and `get_other_participant()` (`:240`) returns null for any conversation whose participant count isn't exactly 2 — i.e. the UI helper *recognizes* a group conversation but doesn't render one.

So group conversations are a **latent capability with no creation path and no multi-party rendering**, and this feature cannot ship without lighting that up. This is the largest piece of net-new work here, and the spec owns it rather than hiding it. The **minimum** group surface this feature needs:

- **Create with the AI included.** A path that creates a conversation whose participants are the human(s) plus `USER_AI`. This is the "add the assistant to this chat" affordance — practically, a create/convert action that calls `create_conversation([...humans, User::USER_AI], $subject)`.
- **Render >2 participants.** The conversation view and list must handle a participant count ≠ 2: show the participant set (including the AI) instead of assuming a single "other person" via `get_other_participant()`. A group title falls back to `cnv_subject` or a names-joined label.
- **Add-a-participant action** (at least enough to add the AI to an existing 1:1, turning it into a small group). No such action exists today (grep-confirmed: only `create_conversation` ever inserts `cnp` rows).

This feature's first real-world group conversation *is* "humans + the assistant," so building it doubles as switching on group messaging generally. Full-featured group chat (arbitrary membership management, admin/leave semantics, group avatars) is **out of scope** (§8) — build only the minimum above.

### 3.3 Invocation: @-mention (one turn, on demand)

The AI speaks **only when addressed**, which keeps it from interrupting two humans talking. There is no existing mention feature anywhere in the platform (grep-confirmed — no mention parsing in messaging, posts, or comments), so this is defined fresh and kept deliberately small:

- The AI participant has a stable **handle** (its `display_name()`, and/or a fixed token like `@assistant`). A message whose body addresses that handle is a summons.
- **Detection happens at the one send funnel.** Every message — from the API action and any other caller — passes through `Conversation::add_message($sender_user_id, $body)` (`data/conversations_class.php:121`), which persists the row (`:163`) and already fans out participant notifications (`:174-204`). Immediately after the row is saved is the single correct hook: if the conversation includes `USER_AI` **and** the new message mentions the AI **and** the sender is not the AI itself (§3.7), enqueue one AI turn.
- **The solo-human shortcut.** Requiring an @-mention matters only when more than one human is present. In a room whose only participants are one human and the AI (count 2, the AI being the "other"), every message is obviously directed at the AI, so **no mention is required** — it behaves like the familiar 1:1 assistant chat. The rule: *mention required when the room has ≥2 human participants; every human message triggers a turn when the room has exactly one human + the AI.*

Each summons is **self-contained**: one turn in, one reply out. No always-on listener, no "is it my turn" state machine.

### 3.4 The turn: a messaging-side runner reusing the engine

The turn is produced by a **new runner** (`ConversationAiRunner`, in `plugins/joinery_ai/includes/`) that reuses the engine but adapts input assembly and persistence to messaging. It does four things:

1. **Assemble speaker-attributed history** from `msg_messages` (§3.5 — the one genuinely new mechanism).
2. **Build a group-chat system prompt** via the reusable `AiPromptBuilder` seam (§3.6) — *not* `ChatRunner::buildSystemPrompt()`, which is `private static` and coupled to an `AiConversation` row (`ChatRunner.php:396`) this room doesn't have.
3. **Resolve a model with no `aic` row.** `AiModelResolver::resolve(AiModelRequirementBuilder::forPurpose('the messaging room'))` returns an `AiModelResolution` requiring no conversation: the requirement states floors and the site's `joinery_ai_selection_policy` picks among the catalog models that clear them. Because the room is non-sealed and sandboxed (§2), no trust floor beyond the site posture is needed — there is no fortress content to keep local. If group replies prove to need more than the basic tier, state a fallback capable floor on the requirement rather than naming a model.
4. **Run and persist.** Call `AgentLoop::run($resolution, $system, $messages, $room_safe_tools, $context, ...)` — signature at `AgentLoop.php:83`, returns an assoc array whose `assistant_text` is the reply. `$room_safe_tools` is the room-safe whitelist (§2 — web search and any other tool that reads no participant's private data), **never** the private-data tools. Write that reply back with `Conversation::add_message(User::USER_AI, $assistant_text)` — the *same* funnel human messages use, so the AI's reply reuses the existing notification fan-out for free (participants are told a new message arrived) and appears as an ordinary message authored by the AI.

**`ToolContext`.** `AgentLoop::run()` requires a `ToolContext`. Room-safe tools like web search *are* dispatched, but they read no participant data, so the context carries **no participant identity and no private-data capability** — a minimal sandbox context that can service the room-safe tools while exposing no owner whose private data could be read. It does not impersonate a participant. (Reuse an existing lightweight context if one fits, or add a small `SandboxToolContext`.)

**Async, reusing the worker pattern.** Turns take seconds; the send request must not block. Enqueue at the §3.3 hook and run the turn on the existing async worker pattern (`ChatWorkerSpawner` / `cli/run_chat_turn.php` analog). The reply simply arrives as a new message + notification — no streaming socket needed for v1 (the message list already updates on new messages). Streaming the AI's reply token-by-token into the room is a **future** refinement (§8).

### 3.5 Speaker-attributed history (the one new mechanism worth care)

This is the only genuinely new piece of engine logic. The existing chat builds a strict `user`/`assistant` alternation from a *single* owner's messages (`ChatRunner::buildHistoryMessages()`, `normalizeAlternating()`). A group room has **many** human speakers, and the model must know **who said what** — otherwise "tell Bob I agree" is unattributable.

Assembly rule, from the room's `msg_messages` (ordered `msg_message_id ASC`):

- A message whose sender is `USER_AI` → role **`assistant`**, content as-is (it's the AI's own prior turn).
- A message from **any human** → role **`user`**, content **prefixed with that speaker's `display_name()`**, e.g. `Alice: I think we should push the date.`
- Consecutive human messages collapse into one `user` turn as **multiple attributed lines** (reuse the same same-role-run collapsing the engine already does), so a run of human chatter becomes a single well-formed user turn the model can read as a transcript with names.

The speaker labels live **inside the message content**, which is what lets a single `user` turn carry several distinct humans while still satisfying the provider's strict role-alternation. The system prompt (§3.6) tells the model that user turns are a multi-person transcript and names are speakers, not part of the message text.

### 3.6 System prompt / persona

Build the system blocks directly from the **public** `AiPromptBuilder` helpers — `AiPromptBuilder::systemBlocks($text, $untrusted)` (`AiPromptBuilder.php:124`) plus the reusable `untrustedInputBlock()` — rather than the `aic`-coupled `ChatRunner::buildSystemPrompt()`. The voice text is a fixed group-chat persona, roughly:

> You are the Joinery Assistant, a participant in a group conversation between people. Messages from people are shown as a transcript prefixed with the speaker's name; those names identify who is talking and are not part of what they wrote. Reply to the room. You can see this conversation and you can search the public web, but you have no access to anyone's private data, files, notes, or other conversations. Address people by name when useful.

No model-catalog block; a tool-rules block only for the room-safe tools that are offered (web search); and the untrusted-input contract still applies — message content is untrusted user input, so the existing framing carries over unchanged.

Persona is **fixed** in v1 (one name, one avatar, one voice). Per-room custom personas/instructions and multiple assistant personalities are **future** (§8); the voice string is the obvious seam for it.

### 3.7 Loop & abuse guards

- **Never trigger on the AI's own message.** The AI's reply is written through `add_message()` — the very funnel the §3.3 detector hooks. Without a guard the AI could mention itself or echo a handle and re-summon itself forever. **Hard rule:** the detector ignores any message whose sender is `USER_AI`. This is load-bearing, not optional.
- **One in-flight turn per conversation.** If the AI is @-mentioned again while a turn is already running for that room, coalesce (ignore or queue-once) rather than spawning parallel turns.
- **Rate limit per conversation** to bound cost and prevent a human from spamming mentions into a runaway bill.
- **Provider failure is graceful.** Reuse the shipped chat error-resilience posture (`joinery_ai_chat_error_resilience`): on provider error the turn posts a brief "the assistant couldn't respond just now" message (or stays silent with a system note), never a stack trace, never a half-written row.

---

## 4. Integration points (implementation checklist)

| # | File | Change |
|---|------|--------|
| 1 | `data/users_class.php` | Add reserved `User::USER_AI` constant; protect its row from edit/delete like `USER_SYSTEM` (`:1128`); extend `not_system_users` (`:1365-1367`) to exclude it. Seed the AI user row (name + avatar) at install. |
| 2 | `data/conversations_class.php` | In `add_message()` after the row saves (`:163`): if the conversation includes `USER_AI`, the message mentions the AI (or the room is 1-human-+-AI, §3.3), and the sender ≠ `USER_AI` (§3.7), enqueue an AI turn. Add the mention-detection helper. |
| 3 | `data/conversations_class.php` + messaging views/logic | Group-conversation minimum (§3.2): a create/convert-with-AI path calling `create_conversation([...humans, USER_AI], $subject)`; render participant count ≠ 2 (don't assume `get_other_participant()`); an add-participant action. |
| 4 | `plugins/joinery_ai/includes/ConversationAiRunner.php` | **New.** Assemble speaker-attributed history from `msg_messages` (§3.5); build the group persona via `AiPromptBuilder::systemBlocks()` (§3.6); resolve a model via `AiModelResolver::resolve(AiModelRequirementBuilder::forPurpose(...))` (§3.4); `AgentLoop::run(..., allowed_tools: <room-safe whitelist — web search, never private-data tools>, ...)`; write reply via `add_message(USER_AI, $text)`. |
| 5 | `plugins/joinery_ai/includes/` (context) | A minimal sandbox `ToolContext` (§3.4) that services room-safe tools but carries no participant identity or private-data capability, or reuse an existing lightweight context. Never impersonates a participant. |
| 6 | async worker (`cli/` + spawner) | A messaging-turn worker mirroring `cli/run_chat_turn.php` / `ChatWorkerSpawner`, invoking `ConversationAiRunner` off the §3.3 enqueue. |
| 7 | notification fan-out (`add_message`, `:174-204`) | Exclude `USER_AI` from the human-notification recipients (the AI needs no email/unread), and don't accrue unread state for it. |

No `serve.php` route, no schema migration (the reserved user is seeded data, not a column; the AI turn writes ordinary `msg_messages` rows). No change to `AgentLoop` dispatch — a room-safe subset of `allowed_tools` is already a valid input.

---

## 5. Edge cases & decisions

- **Self-summon loop** — the §3.7 sender-≠-`USER_AI` guard is the single most important correctness rule; without it the feature is an infinite-message generator.
- **@-mention when the AI isn't a participant** — no-op; only rooms containing `USER_AI` are considered.
- **Two humans, no mention** — the AI stays silent; it is not an always-on listener (§3.3). This is intended, not a gap.
- **Attachments/images shared in the room** — v1 the AI reads **text only**; it does not open in-room files or images even though they are in-room content. Reading in-room attachments is a natural but separate step (§8) — kept out to keep the first build tight.
- **Remote provider + private human conversation** — acceptable and disclosed: messaging content is plaintext-at-rest (not sealed), the AI reaches no participant's private data (§2), and the AI is a *visible* participant, so sending room content to the default provider is a consequence of a deliberate act, not a hidden exfiltration. There is no fortress/sealed content in this surface to protect.
- **Web search is outbound** — when the AI searches the web, a query derived from room chatter goes to a public search provider (a second outbound recipient beyond the model). It reads nobody's private data and is disclosed by the same visible-participant fact that discloses the model, so it is not a new class of leak (§2) — it sits firmly on the room-safe side of the line. Personal-data tools stay off that line and off.
- **Blocking** — the existing block-checks in messaging are unchanged; the AI is neutral and does not alter block semantics between humans.
- **Token accounting** — attribute the turn's tokens to the conversation (and/or the triggering user) for any quota/limit, since no single "owner" exists. Minor; wire to whatever quota mechanism messaging uses.
- **AI participant in people-pickers** — excluded from "start a conversation" user lists via `not_system_users` (§3.1); it is added only through the explicit affordance (§3.2), never stumbled into.
- **Persona is fixed** — one name/voice/avatar in v1 (§3.6); no per-room customization yet.

---

## 6. What does NOT change

- **The private `joinery_ai` chat** (`aic`/`aim`, single-owner, capability-bearing) is untouched. This feature adds a *second, sandboxed* surface for the engine; it does not alter the owner's private assistant, its capabilities, or its sealed-vault behavior.
- **Human 1:1 messaging** works exactly as before when no AI participant is present.
- **`AgentLoop`** — reused as-is; a room-safe subset of tools is already valid, so there is no engine change, only a new caller.

---

## 7. Documentation

Fold into existing docs as current-state (no migration narration):

- `docs/social_features.md` (messaging section) — a conversation may include the Joinery Assistant as a participant; it is invoked by @-mention (or directly in a 1-human room); it is sandboxed to the conversation with no access to any participant's private data, files, or other chats; it runs on the platform-default provider. Document that group (N-participant) conversations are supported.
- `plugins/joinery_ai/docs/overview.md` — add the in-messaging assistant as a third engine surface alongside the private chat and recipes, noting it runs with **no private-data tools** (room-safe tools like web search are allowed) and no `aic` row (model via `AiModelResolver` with a `forPurpose()` requirement, persona via `AiPromptBuilder`).

---

## 8. Future / out of scope

- **Custom personas** — per-room name/instructions and multiple assistant personalities (the §3.6 voice string is the seam).
- **In-room attachment/image reading** — let the AI open files/images shared *in the conversation* (still sandboxed to the room).
- **Streaming** — token-by-token streaming of the AI's reply into the room, rather than a message appearing when the turn completes (§3.4).
- **Full group-chat management** — arbitrary membership add/remove, leave, admin roles, group avatars/rename UI (this spec builds only the minimum group surface a room-with-AI needs, §3.2).
- **Consented personal-agent mode** — a deliberate, all-participants-consent path where the AI *may* use a specific participant's capability in a shared room. Explicitly not v1; the sandbox (§2) is the default and the safe baseline.

---

## 9. Acceptance criteria

1. A conversation can be created/converted to include the AI participant; the AI appears in the participant list with a name and avatar, and the room renders correctly with >2 participants.
2. In a room with ≥2 humans, @-mentioning the AI produces exactly one AI reply, authored by `USER_AI`, visible to all participants and delivered via the normal new-message notification.
3. In a room with one human + the AI, every human message produces one AI reply with no @-mention required.
4. The AI's reply is attributed correctly and the model demonstrably knows who said what: in a 3-party room, a message like "@assistant, summarize what Alice and Bob each want" yields a reply that distinguishes the two speakers (validates §3.5 attribution).
5. **Sandbox:** the AI turn is offered only room-safe tools (web search) and **none** of the private-data tools — it cannot read any participant's business data, notes, files, or other conversations. A prompt asking it to read a participant's private data is unable/refused and dispatches no private-data tool; a prompt that needs a public lookup *can* use web search.
6. **No self-summon loop:** a message authored by `USER_AI` never triggers another turn, even if its text contains the AI's handle.
7. Provider failure yields a graceful in-room message (or silent system note), never a stack trace or a half-written message row.
8. Removing/absent AI participant: a normal human 1:1 conversation with no AI participant behaves exactly as before, and @-text in such a room does nothing.

### Test plan

- Harness test (`plugins/joinery_ai/tests/` or `tests/messaging/`, `@joinery-test` header): seed a 3-participant room (2 humans + `USER_AI`); post a human message mentioning the AI; assert exactly one `msg_messages` row authored by `USER_AI` is created, and that the assembled history passed to the engine carries speaker-attributed user turns (§3.5).
- **Sandbox test:** assert `AgentLoop::run` was called with an `allowed_tools` list that contains web search and **none** of the private-data tools (`query_model`, `get_my_notes`, `search_conversations`, `recall`/`remember`/`forget`, file/vault reach); assert a private-data tool is never dispatched even when the prompt asks for one.
- **Loop-guard test:** insert a message authored by `USER_AI` whose body contains the AI handle; assert **no** turn is enqueued (§3.7).
- **Solo-human test:** in a 1-human-+-AI room, post a message with no mention; assert a turn fires. In a 2-human-+-AI room, post a message with no mention; assert **no** turn fires.
- **Attribution test:** seed interleaved messages from two named humans; assert each human turn in the engine input is prefixed with the correct `display_name()` and that consecutive human messages collapse into one attributed `user` turn.
- **Group-render test:** a conversation with 3 participants renders its participant set (does not rely on `get_other_participant()` returning non-null).
- **No-regression test:** a 1:1 human conversation with no AI participant sends and renders unchanged.
