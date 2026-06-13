# Chat Plugin Spec

**Purpose:** A Discord-style realtime chat plugin for Joinery sites — text channels, threads, reactions, attachments, DMs, presence, and typing indicators. Each Joinery instance is its own "server" (no multi-org abstraction). Intended as a real alternative to Discord for communities that do not need voice/video.

**Last Updated:** 2026-06-10

**Status:** Active — not yet implemented.

**Plugin name:** `chat`

---

## Scope

### In scope (v1)

- Text channels (top-level, not nested under any "server" abstraction)
- 1:1 and group direct messages (delegated to existing messaging system)
- Realtime message delivery, typing indicators, online/offline presence
- Reactions and inline quoted replies on channel messages
- File and image attachments on channel messages
- Markdown rendering + fenced code blocks
- `@user` mentions and `@channel` mentions
- Browser push notifications, in-app unread indicators
- Channel visibility rules (public / role-gated / invite-only)
- Search within channels (Postgres full-text)
- Plugin admin UI for channel management

### Explicitly out of scope (v1)

- Voice and video channels, screen sharing
- Native mobile apps (responsive web + PWA install only)
- Bots / integrations marketplace
- Cross-site federation, server discovery
- Custom emoji uploads, stickers, GIF picker
- Fine-grained per-channel permission overrides (channel visibility is role-tier-based, not per-user ACL)
- Threads on channel messages (deferred to v1.1 — see [Future / Out of Scope](#future--out-of-scope))
- Reactions, threads, attachments on DMs (DMs use existing messaging, stay plain — see [DM handling](#dm-handling))

---

## Architecture Overview

The plugin has three components:

1. **PHP plugin** (`plugins/chat/`) — admin UI, channel CRUD, message persistence, mention parsing, attachment uploads, notification fan-out triggers, WS auth token minting.
2. **Go realtime service** — one binary per Joinery instance, runs on the same node as PHP under systemd, holds WebSocket connections, fans out messages to subscribers, tracks presence and typing.
3. **PostgreSQL** — single source of truth for messages, channels, membership, reactions. Also the signaling bus: PHP writes a message then issues `NOTIFY`, Go is `LISTEN`ing.

```
Browser ─── WSS ───▶ Go realtime ──┐
   │                                │ LISTEN
   │                                ▼
   └── HTTPS ──▶ PHP ──▶ Postgres ──┘
                  (writes msg + NOTIFY)
```

### Realtime: Postgres LISTEN/NOTIFY

PHP writes the message to the database, then issues a notification on a single fixed channel: `NOTIFY chat_events, '{"type":"channel","channel_id":<id>,"message_id":<id>}'`. (DM realtime rides core's generic `message_events` channel instead — see [DM handling](#dm-handling).) The Go service holds exactly one Postgres connection in LISTEN mode, established at startup, listening on both `chat_events` and `message_events`. On notification, Go loads the message row, looks up which connected WebSocket clients are subscribed to that channel in its in-memory subscriber map, and pushes the payload. A notification for a channel with no connected subscribers is one map lookup and a discard.

One fixed LISTEN channel means no LISTEN/UNLISTEN lifecycle to manage as chat channels become active and idle, and reconnect handling is a single re-`LISTEN` rather than a per-channel re-subscription pass. NOTIFY payloads carry only IDs (well under the ~8000-byte payload limit); message content is always loaded from the database.

Rationale: keeps the dependency footprint identical to today (just Postgres — no Redis), sufficient up to tens of thousands of concurrent connections per Postgres instance, removes one service to install/monitor/back up.

### Realtime: WebSocket auth

The WebSocket handshake uses a short-lived HMAC-signed token minted by PHP:

1. Browser calls `GET /api/chat/ws_token` using its existing Joinery session cookie.
2. PHP validates the session and mints a token: `base64url(user_id . "." . exp) . "." . hmac`, where `exp` is a unix timestamp 60 seconds out and `hmac` is HMAC-SHA256 of the payload under the chat signing key (see below).
3. Browser opens `wss://<site>/ws/?token=<token>` (proxied through Apache via `mod_proxy_wstunnel`).
4. Go recomputes the HMAC (constant-time compare), checks `exp`, extracts `user_id`, binds it to the connection. Connection is then trusted for its lifetime.
5. On WebSocket drop, the browser fetches a fresh token and redoes the handshake.

**Signing key:** derived from the existing `secret_box_key` in `Globalvars_site.php` — never used raw: `signing_key = hash_hmac('sha256', 'chat-ws-auth', base64_decode(secret_box_key))`. Domain separation means the chat verifier never holds a key valid for SecretBox's other uses. PHP signs with `hash_hmac`, Go verifies with `crypto/hmac` — standard library on both sides, no JWT dependency.

The token is a handshake credential only — not re-verified per message. No new secret is provisioned; rotating `secret_box_key` invalidates outstanding tokens (60s of disruption, acceptable).

### Realtime: Go service deployment

Packaged the same way as the `server_manager` Go agent:

- Single Go binary (`joinery-chat`)
- Installer script (`joinery-chat-installer.sh`) — self-extracting, handles fresh install and upgrade
- systemd unit at `/etc/systemd/system/joinery-chat.service`
- Env file at `/etc/joinery-chat/joinery-chat.env`
- Reads database credentials and `secret_box_key` from `Globalvars_site.php` (derives the chat signing key from the latter)
- Heartbeats to `ahb_agent_heartbeats` (or a chat-specific table) so the admin UI can show the service status
- Exposes a localhost-only HTTP status endpoint (`GET /status`: connected users with presence state, connection count, uptime) — the PHP admin UI calls it for live presence, and it doubles as a liveness check

Unlike `server_manager` (one agent per managed node, polling a central job queue), the chat service runs **on the same node as the Joinery instance it serves** and serves only that instance's users. One binary per site.

### Companion platform work: Web push notifications

Push notifications are platform infrastructure, not chat-specific. The chat plugin is the first consumer, but the implementation lives in core (`/includes/`, `/data/`) so other features (purchase confirmations, post replies, crush matches, anything that dispatches through the [signal bus](implemented/signal_bus.md)) can adopt it without re-implementing.

Bundled into this project:

- **`psh_push_subscriptions`** data class storing browser push subscriptions (endpoint, p256dh key, auth secret, `usr_user_id`, user_agent, last_seen_time). Lives in `/data/`.
- **VAPID keypair** generated on first deploy, stored in `Globalvars_site.php` as `vapid_public_key` and `vapid_private_key`. Public key exposed to clients via a small config endpoint.
- **Service worker** at `/service-worker.js` — registers for push events, displays notifications, handles click-to-open routing.
- **Push channel** added to Notify (the signal-bus subscriber), alongside the existing in-app and email channels. Same per-user opt-in model.
- **Composer dependency** `minishlink/web-push` for PHP-side push sending (handles VAPID signing and payload encryption).

When a second consumer adopts this push infrastructure, the section should be extracted into its own spec/doc — for now it lives here as a prerequisite to chat v1.

### Apache configuration

A new vhost fragment configures the WebSocket proxy:

```apache
ProxyPass        /ws/  ws://127.0.0.1:8765/
ProxyPassReverse /ws/  ws://127.0.0.1:8765/
```

Documented in `docs/deploy_and_upgrade.md` and bundled into the installer if feasible.

---

## Data Model

The plugin owns its own tables. Naming follows the standard `<prefix>_<table>` convention with table-prefixed columns.

**Channel administration model:** all channels are site-managed in v1. Creating, editing, archiving, and deleting channels requires `usr_permission >= 5` (admin). There is no per-channel owner or per-channel moderator role. Casual communities running this plugin typically have 1–2 admins managing every channel, matching the admin surface Joinery uses for the rest of the platform. Per-channel ownership and member-created channels are clean v1.1 additions if demand emerges.

### `chn_channels`

Top-level channels.

| Column | Type | Notes |
|---|---|---|
| `chn_channel_id` | `int8 serial` | PK |
| `chn_name` | `varchar(80)` | Display name (e.g. "general", "off-topic") |
| `chn_slug` | `varchar(80)` | URL slug, unique |
| `chn_description` | `text` | Optional channel topic |
| `chn_visibility` | `varchar(20)` | `public` / `role_gated` / `invite_only` |
| `chn_min_permission` | `int4` | For `role_gated` — minimum `usr_permission` to see channel |
| `chn_sort_order` | `int4` | For sidebar ordering |
| `chn_create_time` | `timestamp(6)` | |
| `chn_delete_time` | `timestamp(6)` | Soft delete |

### `chn_channel_members`

Dual-purpose table:

1. **Membership** for `invite_only` channels — presence of a row grants read access. `public` and `role_gated` channels do not require rows for access (visibility is computed from `chn_visibility` + `usr_permission`).
2. **Per-user state** for any channel — `last_read_message_id` and `is_muted` apply to every channel a user has interacted with, regardless of visibility tier. A row is created lazily the first time a user mutes or reads in a channel.

| Column | Type | Notes |
|---|---|---|
| `cmb_channel_member_id` | `int8 serial` | PK |
| `cmb_chn_channel_id` | `int8` | FK |
| `cmb_usr_user_id` | `int4` | FK |
| `cmb_last_read_message_id` | `int8` | For unread counts |
| `cmb_is_muted` | `bool` | Suppresses notifications for this channel |
| `cmb_create_time` | `timestamp(6)` | |
| `cmb_delete_time` | `timestamp(6)` | For invite_only — user left the channel |

### `chm_channel_messages`

Channel messages. Separate from core `msg_messages` because of different volume, indexing, and feature surface.

| Column | Type | Notes |
|---|---|---|
| `chm_channel_message_id` | `int8 serial` | PK |
| `chm_chn_channel_id` | `int8` | FK |
| `chm_usr_user_id_sender` | `int4` | FK |
| `chm_body` | `text` | Markdown source, rendered at display |
| `chm_reply_to_id` | `int8 NULL` | Inline quoted-reply pointer (tap-reply UX, shows parent above the new message) |
| `chm_sent_time` | `timestamp(6)` | |
| `chm_edited_time` | `timestamp(6)` | |
| `chm_delete_time` | `timestamp(6)` | Soft delete |

Indexes: `(chm_chn_channel_id, chm_sent_time DESC)` for channel scroll.

### `chr_channel_reactions`

| Column | Type | Notes |
|---|---|---|
| `chr_channel_reaction_id` | `int8 serial` | PK |
| `chr_chm_channel_message_id` | `int8` | FK |
| `chr_usr_user_id` | `int4` | FK |
| `chr_emoji` | `varchar(64)` | Unicode emoji codepoint or `:name:` shortcode |
| `chr_create_time` | `timestamp(6)` | |

Unique constraint: `(chr_chm_channel_message_id, chr_usr_user_id, chr_emoji)`.

### `cha_channel_attachments`

| Column | Type | Notes |
|---|---|---|
| `cha_channel_attachment_id` | `int8 serial` | PK |
| `cha_chm_channel_message_id` | `int8` | FK |
| `cha_filename` | `varchar(255)` | Original filename |
| `cha_mime_type` | `varchar(127)` | |
| `cha_byte_size` | `int8` | |
| `cha_storage_path` | `varchar(500)` | Resolves through cloud storage or local depending on setting |
| `cha_thumbnail_path` | `varchar(500)` | For images |
| `cha_create_time` | `timestamp(6)` | |

Attachments are uploaded via the existing `UploadHandler` and routed through the cloud storage subsystem (see `docs/cloud_storage.md`).

### Presence (no table)

Presence (`online` / `idle` / `dnd` / `offline`) and typing indicators are NOT persisted — both are derived entirely from live connections and are valueless when stale. Go holds them in its in-memory connection map and broadcasts changes to subscribed clients over WebSocket. The admin UI reads presence from the Go service's status endpoint (see [Go service deployment](#realtime-go-service-deployment)); when Go is down, the failed call correctly reads as "service down, nobody connected" rather than stale rows.

---

## DM Handling

Direct messages reuse the existing platform messaging system: `cnv_conversations`, `cnp_conversation_participants`, `msg_messages`. The plugin does **not** create parallel DM tables.

**Plugin responsibilities for DMs:**

- Render DMs in the unified chat sidebar alongside channels (using the existing inbox query pattern in `MultiConversation`).
- Call `Conversation::get_or_create_conversation()` and `Conversation::add_message()` to write DMs.

**Realtime DM delivery — small core change.** The realtime signal must fire at the write point, not in plugin code: DMs are also written from the existing messaging UI, and those must reach an open chat window too. `Conversation::add_message()` gains a generic emission at the end: `NOTIFY message_events, '{"conversation_id":<id>,"message_id":<id>}'`. Nothing chat-flavored lives in core — a NOTIFY with no listeners is essentially free, and the chat Go service is simply the first consumer LISTENing on `message_events`. (Routing realtime through the notification system instead would be wrong: muted participants skip notifications but should still see live delivery in an open window.)

**What this means for the v1 UX:**

DMs are plain text (existing 5000-char limit, `strip_tags`'d body). No reactions, threads, or attachments on DMs. Channel messages have the full Discord-style feature surface; DMs stay simple. Reactions-on-DMs can land in a follow-up by adding `msr_message_reactions` against `msg_message_id` (additive, doesn't break existing UI).

---

## Mentions and Notifications

### Mention parsing

PHP parses `@username` and `@channel` from message bodies at write time:

- `@username` resolves to a `usr_user_id`; recipient gets a notification regardless of mute state on the channel.
- `@channel` notifies users who have a `cmb_channel_members` row for the channel (i.e., have opened it at least once — rows are created lazily by `last_read` tracking), excluding muted and soft-deleted rows. It is not "everyone eligible to see the channel": for `public` and `role_gated` channels that set is unbounded (a public-channel `@channel` would ping the entire user base, including users who have never opened chat). For `invite_only` channels the two definitions coincide, since membership rows exist by definition. A true site-wide blast is an announcement feature, not a mention.

Parsed mentions are stored in a `chmn_channel_message_mentions` table so notification fan-out and badge counts are queryable without re-parsing bodies.

### Notification routing

Reuses the existing in-app notification system (`Notification::create_notification`) and the [signal bus](implemented/signal_bus.md). Signals dispatched by the chat plugin (declared with `notify` blocks so Notify alerts recipients):

- `chat.channel_message_posted` — targeted to channel members, topic for site-wide watchers
- `chat.channel_mention` — targeted to mentioned user
- `chat.dm_received` — targeted to recipient (mirrors existing message notification)

Browser push uses the existing PWA push infrastructure (TBD: confirm push is available platform-wide or build it into this plugin).

### Unread indicators

`cmb_last_read_message_id` advances when the browser reports the channel as viewed. Unread counts are computed as `COUNT(*) WHERE chm_channel_message_id > cmb_last_read_message_id AND chm_delete_time IS NULL`. Indexed for sub-millisecond response.

---

## Integration Points (Up-Front Inventory)

To avoid surprises mid-build, the systems this plugin touches:

| System | How chat plugin uses it | Modifications needed? |
|---|---|---|
| `Conversation` / `Message` (existing messaging) | DMs delegate here | Yes — `add_message()` gains a generic `NOTIFY message_events` emission at the write point (see [DM handling](#dm-handling)) |
| `Notification::create_notification` | Mentions, channel posts, DMs | No — existing API |
| Signal bus | Signal + subscriber declarations | Plugin declares signals (with `notify` blocks) via `plugin.json` |
| `User` / `usr_permission` | Role-gated channel visibility | No |
| `UserBlock` | DMs already integrate; channels do not filter by block (you see public messages even from blockers) | No |
| `UploadHandler` + cloud storage | Attachment uploads | No |
| Apache vhost config | WebSocket proxy `/ws/` | Add `mod_proxy_wstunnel` directives |
| `Globalvars_site.php` | Existing `secret_box_key` (chat signing key derived via HMAC, never used raw) | No — no new entry needed |
| `stg_settings` / `plugin.json` settings | WebSocket port, max attachment size, idle-presence threshold, etc. | Declared in `plugin.json` |
| Admin menu | Plugin admin UI entries | Declared in `plugin.json` (per platform convention) |
| Web push infrastructure (`psh_push_subscriptions`, VAPID, service worker, push channel) | Browser push delivery for mentions and DMs | Built as platform-level companion work — see [Companion platform work](#companion-platform-work-web-push-notifications) |
| `update_database` | Plugin tables created on plugin sync | No special handling needed |

---

## Build Phases

Suggested incremental build order, each phase deliverable on its own:

1. **Channels CRUD + persistence** — admin UI to create channels, plugin views to render channel list and message history (no realtime yet — page reloads to see new messages). Validates data model and visibility rules.
2. **Go service skeleton + WS handshake** — Go binary, systemd packaging, HMAC token validation, basic echo. No message fan-out yet. Validates the deployment + auth path.
3. **Realtime message delivery** — wire LISTEN/NOTIFY, browser receives live messages in the open channel. Validates the full realtime path end-to-end.
4. **Presence + typing** — Go in-memory state, broadcast on join/leave/typing, `/status` endpoint for the admin UI. Validates the ephemeral-state model.
5. **Mentions + notifications** — mention parsing, notification fan-out, unread badges.
6. **Replies + reactions** — inline quoted replies, emoji reactions on channel messages.
7. **Attachments** — file/image uploads, image embeds, thumbnails.
8. **DM integration** — sidebar rendering, generic `NOTIFY message_events` emission at the end of `Conversation::add_message` (small core change), realtime DM delivery.
9. **Search** — Postgres full-text index on `chm_body`, plugin search UI.
10. **Platform web push infrastructure + chat consumer** — see [Companion platform work](#companion-platform-work-web-push-notifications). Builds VAPID setup, service worker, subscription storage, push channel in the hooks system, and wires chat mentions and DMs to push as the first consumer.

Phases 1–3 are the load-bearing risk. After phase 3, the rest is mostly conventional feature work. Phase 10 is platform-level work bundled into the project; other features adopt the push channel after it ships.

---

## Complexity and Risk

Load-bearing risk concentrates in phases 1–3 (data model + Go service skeleton + realtime path end-to-end). Once realtime works for one channel, the rest of the feature surface is conventional.

Known complexity hotspots:

- **Go realtime hardening** (reconnect, backpressure, partial-delivery edge cases) is the kind of work that compounds — every "we'll handle that later" turns into a bug after launch. Treat phases 2–4 as a single hardening pass, not three separate features.
- **Attachments + cloud storage** — chat's upload frequency is higher than the cloud_storage plugin has been exercised at. Expect to surface and fix existing bugs there.
- **iOS Safari web push** has specific PWA-install requirements and limitations. Cross-browser push testing needs explicit coverage — push that works on Chrome desktop is not evidence that it works on iOS.

---

## Open Decisions

These are not yet decided and will be filled in as conversation continues:

- **Notification surface details** — browser push availability, in-app badge interaction patterns, mention ping semantics.
- **Search ranking** — recency-weighted vs pure relevance; per-channel vs site-wide search UI.
- **Markdown renderer** — server-side (PHP) vs client-side (JS); which markdown flavor (CommonMark + GFM extensions seems right).
- **Rate limiting** — per-user message rate caps to prevent spam; lives in PHP or Go?

---

## Future / Out of Scope

### Threads (deferred to v1.1)

Threads are a power-user feature; the v1 target audience (casual Discord users) primarily uses flat channel conversation with `@`-replies. Deferring threads keeps the v1 scope focused.

Both threading styles are fully additive — Joinery's `update_database` system handles schema changes automatically from data class `$field_specifications`, so no migration files are needed regardless of which style is chosen:

- **Slack-style** — add `chm_thread_root_id int8 NULL` to the `ChannelMessage` data class; thread view is a query plus side-panel UI. Low complexity.
- **Discord-style** — add a new `ChannelThread` data class (table `chnt_channel_threads`) plus a `chm_chnt_channel_thread_id` column on `chm_channel_messages`, plus thread management UI (rename, archive, browse). Medium complexity.

The v1 schema deliberately omits any thread-related columns so future maintainers do not see unused fields and wonder if they are live.

### Other future items

- Voice and video channels (multi-person-year project on its own — WebRTC SFU, TURN, mobile clients)
- Native mobile apps
- Bots and webhook integrations
- Custom emoji uploads, stickers, GIFs
- Cross-site federation
- Reactions/threads/attachments on DMs (additive follow-up)
- E2E encryption (architecturally incompatible with server-side search and notification fan-out — would require dedicated design)
