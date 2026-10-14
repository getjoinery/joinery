# Chat Plugin Spec

**Purpose:** A Discord-style realtime chat plugin for Joinery sites — text channels, reactions, attachments, presence, and typing indicators. Direct and group messages are the [Messenger](../plugins/messenger/docs/overview.md) plugin's job; chat links to it. Each Joinery instance is its own "server" (no multi-org abstraction). Intended as a real alternative to Discord for communities that do not need voice/video.

**Last Updated:** 2026-10-11

**Status:** Active — not yet implemented.

**Plugin name:** `chat`

**Prerequisite:** `community_shared_components.md` — member-post markdown and mentions (C1), space access (C2), content reports (C3), full-text search (C4), push (C5), attachments (C6). C5 is built (2026-10-14, the forum was its first consumer); chat sends its mentions through it. The Forum plugin (`forum_plugin.md`) shares the same components.

---

## Scope

### In scope (v1)

- Text channels (top-level, not nested under any "server" abstraction)
- Realtime message delivery, typing indicators, online/offline presence
- Reactions and inline quoted replies on channel messages
- File and image attachments on channel messages
- Markdown rendering + fenced code blocks
- `@user` mentions (picked from a list) and `@channel` mentions
- Browser push notifications, in-app unread indicators
- Channel access rules for reading and posting (members / tier / group / invite-only / staff)
- Report a message; admin moderation queue
- Search within channels (Postgres full-text)
- Plugin admin UI for channel management

### Explicitly out of scope (v1)

- Voice and video channels, screen sharing
- Native mobile apps (responsive web only; the native apps do not get a chat screen)
- Bots / integrations marketplace
- Cross-site federation, server discovery
- Custom emoji uploads, stickers, GIF picker
- Fine-grained per-channel permission overrides (a channel's access is one read rule and one post rule, not a per-user ACL)
- Reading chat signed out (chat needs a session for its WebSocket and is not indexed; public, searchable discussion is the Forum's job)
- Threads on channel messages (deferred to v1.1 — see [Future / Out of Scope](#future--out-of-scope))
- Protected (Private) channels — every channel is Standard protection; sealed conversations are Messenger's

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

PHP writes the message to the database, then issues a notification on a single fixed channel: `NOTIFY chat_events, '{"type":"channel","channel_id":<id>,"message_id":<id>}'`. The Go service holds exactly one Postgres connection in LISTEN mode, established at startup, listening on `chat_events` and on core's `message_events` (which `Conversation::add_message()` already emits for every Messenger message, carrying `{conversation_id, message_id, sent_time}`). On notification, Go loads the message row, looks up which connected WebSocket clients are subscribed to that channel in its in-memory subscriber map, and pushes the payload. A notification for a channel with no connected subscribers is one map lookup and a discard.

One fixed LISTEN channel means no LISTEN/UNLISTEN lifecycle to manage as chat channels become active and idle, and reconnect handling is a single re-`LISTEN` rather than a per-channel re-subscription pass. NOTIFY payloads carry only IDs (well under the ~8000-byte payload limit); message content is always loaded from the database.

Rationale: keeps the dependency footprint identical to today (just Postgres — no Redis), sufficient up to tens of thousands of concurrent connections per Postgres instance, removes one service to install/monitor/back up.

### Realtime: WebSocket auth

The WebSocket handshake uses a short-lived HMAC-signed token minted by PHP:

1. Browser calls the `chat/ws_token` action on `/api/v1` (a `_logic_descriptor()` action) with its session cookie and `X-Joinery-Csrf` header.
2. PHP validates the session and mints a token: `base64url(user_id . "." . exp) . "." . hmac`, where `exp` is a unix timestamp 60 seconds out and `hmac` is HMAC-SHA256 of the payload under the chat signing key (see below).
3. Browser opens `wss://<site>/ws/?token=<token>` (proxied through Apache via `mod_proxy_wstunnel`).
4. Go recomputes the HMAC (constant-time compare), checks `exp`, extracts `user_id`, binds it to the connection. Connection is then trusted for its lifetime.
5. On WebSocket drop, the browser fetches a fresh token and redoes the handshake.

**Signing key:** derived from the existing `secret_box_key` in `Globalvars_site.php` — never used raw: `signing_key = hash_hmac('sha256', 'chat-ws-auth', base64_decode(secret_box_key))`. Domain separation means the chat verifier never holds a key valid for SecretBox's other uses. PHP signs with `hash_hmac`, Go verifies with `crypto/hmac` — standard library on both sides, no JWT dependency.

The token is a handshake credential only — not re-verified per message. No new secret is provisioned; rotating `secret_box_key` invalidates outstanding tokens (60s of disruption, acceptable).

### Realtime: Go service deployment

Shipped as a plugin host service, the way the Mailbox plugin ships Postfix:

- Single Go binary (`joinery-chat`), built and signed with the plugin
- A `host_installer` (`provisioning/install_chat.sh`) installs and upgrades it. The host runner executes it at the root moments (container start, site install, upgrade, the converger within a minute of the plugin being switched on). The installer picks systemd (`/etc/systemd/system/joinery-chat.service`) or, on hosts without systemd such as Docker containers, a supervise script plus cron keepalive, as `install_agent.sh` does
- A `provisioners` entry with a `probe` check on the WebSocket port, so the plugin health panel shows a missing or stopped service and points at the fix
- Env file at `/etc/joinery-chat/joinery-chat.env`
- Reads database credentials and `secret_box_key` from `Globalvars_site.php` (derives the chat signing key from the latter)
- Exposes a localhost-only HTTP status endpoint (`GET /status`: connected users with presence state, connection count, uptime) — the PHP admin UI calls it for live presence, and it doubles as a liveness check

The chat service runs **on the same node as the Joinery instance it serves** and serves only that instance's users. One binary per site.

### Push notifications

Browser push is the shared push layer in `community_shared_components.md` (C5): the destinations table, the driver interface, the web driver, VAPID keys, the service worker and the Notify push channel. It is built (docs/push_notifications.md); chat's mention alerts go through Notify like the forum's, so they reach the push channel with no chat-side push code (phase 10). A message from a Private conversation set to Nothing leaves unsealed is dispatched with `push_protected`.

### Apache configuration

The WebSocket proxy lines belong in the vhost templates the converger renders (`default_virtualhost.conf` for bare metal, `default_proxy_vhost.conf` for a container behind a host proxy), versioned through `vhost_history/` and written by `render_vhost.sh`, with `mod_proxy_wstunnel` enabled by the host installer. Chat does not edit Apache config by hand:

```apache
ProxyPass        /ws/  ws://127.0.0.1:8765/
ProxyPassReverse /ws/  ws://127.0.0.1:8765/
```

On a container the proxy host must forward the upgrade to the container's port as well. Documented in `docs/deploy_and_upgrade.md`.

---

## Data Model

**Channels are their own tables, not Messenger conversations (decided 2026-10-11).** Access to a channel comes from a rule (the shared space-access rules, C2), not a membership row, and a channel may have thousands of readers; Messenger's model assumes a member row, a 32-person cap and a sealed key per participant. Chat shares platform code, not tables: the `.jy-chat-*` kit in `joinery-styles.css`, `assets/js/joinery-poll.js`, and the components in `community_shared_components.md` (member-post markdown, space access, reports, search, push, attachments). Its message renderer is its own (it renders `chm_body_html`). Messenger is not modified.

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
| `chn_read_rule` | `varchar(20)` | `members` / `tier` / `group` / `invite` / `staff` (C2; `public` is not offered — chat is not readable signed out) |
| `chn_post_rule` | `varchar(20)` | `members` / `tier` / `group` / `invite` / `staff`; default `members` |
| `chn_tier_min_level` | `int4 NULL` | Used by whichever rule is `tier`; registered with `TierGatedContentRegistry` |
| `chn_grp_group_id` | `int4 NULL` | Used by whichever rule is `group` |
| `chn_sort_order` | `int4` | For sidebar ordering |
| `chn_create_time` | `timestamp(6)` | |
| `chn_delete_time` | `timestamp(6)` | Soft delete |

### `chn_channel_members`

Dual-purpose table:

1. **Membership** for `invite` channels — presence of a row grants access (chat implements C2's `SpaceMembers` with it). Other rules need no rows; access is computed from the channel's rules.
2. **Per-user state** for any channel — `last_read_message_id` and `is_muted` apply to every channel a user has interacted with, regardless of its rules. A row is created lazily the first time a user mutes or reads in a channel.

| Column | Type | Notes |
|---|---|---|
| `cmb_channel_member_id` | `int8 serial` | PK |
| `cmb_chn_channel_id` | `int8` | FK |
| `cmb_usr_user_id` | `int4` | FK |
| `cmb_last_read_message_id` | `int8` | For unread counts |
| `cmb_is_muted` | `bool` | Suppresses notifications for this channel |
| `cmb_create_time` | `timestamp(6)` | |
| `cmb_delete_time` | `timestamp(6)` | For invite channels — user left the channel |

### `chm_channel_messages`

Channel messages. Separate from core `msg_messages` because of different volume, indexing, and feature surface.

| Column | Type | Notes |
|---|---|---|
| `chm_channel_message_id` | `int8 serial` | PK |
| `chm_chn_channel_id` | `int8` | FK |
| `chm_usr_user_id_sender` | `int4` | FK |
| `chm_body` | `text` | Markdown source, kept as the truth |
| `chm_body_html` | `text` | Safe HTML rendered at write time (see [Message rendering](#message-rendering)); re-rendered on edit |
| `chm_reply_to_id` | `int8 NULL` | Inline quoted-reply pointer (tap-reply UX, shows parent above the new message) |
| `chm_sent_time` | `timestamp(6)` | |
| `chm_edited_time` | `timestamp(6)` | The old body is recorded in `ChangeTracking` for admins; readers see only "edited" |
| `chm_delete_time` | `timestamp(6)` | Soft delete |

Indexes: `(chm_chn_channel_id, chm_sent_time DESC)` for channel scroll.

### `chr_channel_reactions`

Chat keeps its own reaction table: core `Reaction` allows one reaction per user per entity and has an `int4` entity id, so it cannot hold several emoji per user on one message.

| Column | Type | Notes |
|---|---|---|
| `chr_channel_reaction_id` | `int8 serial` | PK |
| `chr_chm_channel_message_id` | `int8` | FK |
| `chr_usr_user_id` | `int4` | FK |
| `chr_emoji` | `varchar(64)` | Unicode emoji codepoint or `:name:` shortcode |
| `chr_create_time` | `timestamp(6)` | |

Unique constraint: `(chr_chm_channel_message_id, chr_usr_user_id, chr_emoji)`.

### Attachments

Message attachments use the shared `eat_entity_attachments` table (C6), entity type `channel_message`. Chat registers an `AccessGateProvider` so a file is exactly as visible as its channel, and uploads go through `UploadHandler` and cloud storage. Chat has no attachment table of its own.

### Presence (no table)

Presence (`online` / `idle` / `dnd` / `offline`) and typing indicators are NOT persisted — both are derived entirely from live connections and are valueless when stale. Go holds them in its in-memory connection map and broadcasts changes to subscribed clients over WebSocket. The admin UI reads presence from the Go service's status endpoint (see [Go service deployment](#realtime-go-service-deployment)); when Go is down, the failed call correctly reads as "service down, nobody connected" rather than stale rows.

---

## Direct messages

Direct and group messages are the Messenger plugin's (`/profile/messenger`): replies, reactions, files, read receipts, typing, and Private (sealed) conversations. Chat creates no DM tables and writes no DMs. The chat sidebar lists channels, with a link to Messenger and its unread count.

Realtime: Messenger polls today. The Go service LISTENs on `message_events` and can push the same messages to an open chat page, with Messenger's poll endpoint as the fallback. Whether the Messenger page itself switches to that push is a Messenger decision, outside this spec.

---

## Mentions and Notifications

### Mention parsing

`@user` mentions are inserted by the shared picker (C1): choosing a person writes `@[Name](user:ID)` into the source, and `MentionParser::userIds()` reads the ids at write time. A mention of someone who cannot read the channel is dropped. `@channel` is typed text, recognised by PHP at write time.

- `@user` — the recipient gets a notification regardless of mute state on the channel.
- `@channel` notifies users who have a `cmb_channel_members` row for the channel (i.e., have opened it at least once — rows are created lazily by `last_read` tracking), excluding muted and soft-deleted rows. It is not "everyone eligible to see the channel": for `members`, `tier` and `group` channels that set is unbounded (an `@channel` would ping the entire eligible user base, including users who have never opened chat). For `invite` channels the two definitions coincide, since membership rows exist by definition. A true site-wide blast is an announcement feature, not a mention.

Parsed mentions are stored in a `chmn_channel_message_mentions` table so notification fan-out and badge counts are queryable without re-parsing bodies.

### Notification routing

Reuses the in-app notification system (`Notification::create_notification`) and the [signal bus](../docs/signals.md). The plugin declares its signals under the `signals` key of `plugin.json`, with `notify` blocks so Notify alerts recipients (see `docs/plugin_developer_guide.md`, Plugin Signals & Subscribers):

- `chat.channel_message_posted` — targeted to channel members, topic for site-wide watchers
- `chat.channel_mention` — targeted to mentioned user

Browser push is the shared push layer (C5).

### Unread indicators

`cmb_last_read_message_id` advances when the browser reports the channel as viewed. Unread counts are computed as `COUNT(*) WHERE chm_channel_message_id > cmb_last_read_message_id AND chm_delete_time IS NULL`. Indexed for sub-millisecond response.

---

## Message rendering

Messages are rendered **once, in PHP, at write time**, with the shared member-post markdown profile (C1), and stored in `chm_body_html` beside the markdown source in `chm_body`. The platform deliberately has no JS markdown renderer (a second grammar would drift from the PHP one), so none is added.

- **Live and historical messages are the same bytes.** The Go service already loads the message row on each notification and forwards `chm_body_html` as-is; it knows nothing about markdown.
- **Editing** re-renders. A later change to the renderer leaves old messages as stored until a backfill task re-renders from `chm_body`, which is always kept.
- C1's link allow-list and member-post profile are built and tested before phase 1 ships.

---

## Search

Uses the shared full-text helper (C4): a `gin` index declared with `FullTextSearch::indexSpec('chm_body')` and queries built with `FullTextSearch::match()`. Results are ordered **newest first** — chat messages are short, so relevance scores add little, and "what did we say about X" almost always means recently. Search runs in the current channel by default, with a toggle to search every channel the person may see. Optional author and date filters narrow it.

Every query is combined with `SpaceAccess::readableSql()` (C2), so a person only finds messages in channels they may read. Soft-deleted messages and channels never appear. The ordering can change later without touching stored data, since the index is the same. (The Forum orders by best match, because its posts are long and durable.)

---

## Moderation

Any message can be reported (C3); chat registers a report handler that previews the message, links to it in context, and lets an admin delete it. Admins can also delete any message directly from the channel. Deleted messages stay as soft-deleted rows with the original body in `chm_body` for the audit trail. Moderators are admins in v1.

---

## Rate limiting

Messages are rate-limited in PHP, at the one place they are written (the `/api/v1` send action), using the platform limiter `RequestLogger::rate_limit_state()` with its own bucket (`chat_post`), keyed to the user and counted across all channels. The general API limits are per hour and too coarse for flooding.

- **Messages:** 5 per 10 seconds per user. Over the limit, the send is refused with a `RateLimitError` (429) carrying `retry_after`; the composer shows "Slow down, try again in Ns" and keeps the typed text.
- **Typing frames:** the only client traffic that reaches Go besides subscribe and presence. Go throttles to 1 per 3 seconds per connection in memory and drops the excess. Nothing is stored.
- **Reactions:** covered by the existing per-user API session limit; no separate bucket.
- **No admin exemption, no per-channel slow mode in v1.**
- Message count and window are declared as settings in `plugin.json` so an owner can tune them. The defaults above are starting values, not measured.

---

## Integration Points (Up-Front Inventory)

To avoid surprises mid-build, the systems this plugin touches:

| System | How chat plugin uses it | Modifications needed? |
|---|---|---|
| Messenger plugin | Sidebar link and unread count; `message_events` realtime | No — the NOTIFY already exists |
| `Notification::create_notification` | Mentions, channel posts | No — existing API |
| Signal bus | Signal declarations | Plugin declares signals (with `notify` blocks) via `plugin.json` |
| `User` / `usr_permission` | Admin-only channel management and moderation (`>= 5`) | No |
| `UserBlock` | Channels do not filter by block (you see public messages even from blockers) | No |
| `UploadHandler` + cloud storage, C6 | Attachment uploads, `eat_` rows, an `AccessGateProvider` | No |
| C1 / C2 / C3 / C4 | Member-post markdown and mentions; read and post rules; reports; full-text search | Built in core per `community_shared_components.md` |
| `ChangeTracking` | Old body of an edited message | No |
| Vhost templates + `render_vhost.sh` | WebSocket proxy `/ws/` | Yes — add the proxy lines to both templates; host installer enables `mod_proxy_wstunnel` |
| `Globalvars_site.php` | Existing `secret_box_key` (chat signing key derived via HMAC, never used raw) | No — no new entry needed. Push's key pair is the sealed setting `push_vapid_keys`, not a config entry |
| `stg_settings` / `plugin.json` settings | WebSocket port, max attachment size, idle-presence threshold, message rate limit, etc. | Declared in `plugin.json` |
| Admin menu | Plugin admin UI entries | Declared in `plugin.json` (per platform convention) |
| Push layer (C5) | Browser push delivery for mentions | Built (docs/push_notifications.md) — see [Push notifications](#push-notifications) |
| `host_installer` + `provisioners` | Installs and health-checks the Go service | Plugin ships both |
| `update_database` | Plugin tables created on plugin sync | No special handling needed |

---

## Build Phases

Suggested incremental build order, each phase deliverable on its own:

1. **Channels CRUD + persistence** — first the shared C1 (member-post markdown, link allow-list) and C2 (space access); then admin UI to create channels, plugin views to render channel list and message history (no realtime yet — page reloads to see new messages). Validates data model and visibility rules.
2. **Go service skeleton + WS handshake** — Go binary, systemd packaging, HMAC token validation, basic echo. No message fan-out yet. Validates the deployment + auth path.
3. **Realtime message delivery** — wire LISTEN/NOTIFY, browser receives live messages in the open channel. Validates the full realtime path end-to-end.
4. **Presence + typing** — Go in-memory state, broadcast on join/leave/typing, `/status` endpoint for the admin UI. Validates the ephemeral-state model.
5. **Mentions + notifications** — the mention picker (C1), notification fan-out, unread badges.
6. **Replies + reactions** — inline quoted replies, emoji reactions on channel messages.
7. **Attachments** — C6 uploads, image embeds, thumbnails.
8. **Messenger link** — sidebar entry and unread count; Go pushes `message_events` to an open chat page.
9. **Search and reports** — C4 search on `chm_body` and the plugin search UI; C3 report handler.
10. **Push for chat** — chat mentions reach the existing push channel through Notify; protected conversations dispatch with `push_protected`.

Phases 1–3 are the load-bearing risk. After phase 3, the rest is mostly conventional feature work. Phase 10 is small: the platform push layer already exists.

---

## Complexity and Risk

Load-bearing risk concentrates in phases 1–3 (data model + Go service skeleton + realtime path end-to-end). Once realtime works for one channel, the rest of the feature surface is conventional.

Known complexity hotspots:

- **Go realtime hardening** (reconnect, backpressure, partial-delivery edge cases) is the kind of work that compounds — every "we'll handle that later" turns into a bug after launch. Treat phases 2–4 as a single hardening pass, not three separate features.
- **Attachments + cloud storage** — chat's upload frequency is higher than the cloud_storage plugin has been exercised at. Expect to surface and fix existing bugs there.

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
- E2E encryption (architecturally incompatible with server-side search and notification fan-out — would require dedicated design)
