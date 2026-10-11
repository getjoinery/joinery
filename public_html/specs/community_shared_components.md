# Community Shared Components Spec

**Purpose:** The core pieces that more than one community feature needs, built once. The Chat plugin (`chat_plugin.md`) and the Forum plugin (`forum_plugin.md`) are the first two consumers; Messenger, Boards and later plugins adopt them as they are touched. Nothing here is chat- or forum-specific.

**Last Updated:** 2026-10-11

**Status:** Active — not yet implemented.

**Built by:** whichever consumer ships first builds each component, to this spec, in core (`/includes/`, `/data/`, `/assets/`). The second consumer finds it there.

---

## What is reused as it already exists

These need no work; the consumer specs just name them.

| Existing piece | Used for |
|---|---|
| `Reaction` (`rct_reactions`, `like` type) | Forum post likes |
| `RequestLogger::rate_limit_state()` | Per-user flood limits, one named bucket per feature |
| Signal bus + Notify (`docs/signals.md`, `docs/notifications.md`) | Every alert, in-app and email |
| `AccessGateProvider` / `AccessGateRegistry` | Gating an uploaded file to the space it was posted in |
| `TierGatedContentRegistry` | Spaces register so the tier admin counts them |
| `ChangeTracking` | Old body of an edited message or post, for moderators |
| `StaticPageCache::invalidateUrl()` | Public forum pages after a new reply |
| `SeoPageMetadata::register_entity_class()` | Sitemap and SEO tags for public topics |
| `UserBlock`, `Group`, `File`, `UploadHandler`, cloud storage | As today |
| `$index_specifications` (expression and `gin` indexes) | The full-text index, declared on the data class |

**Reaction is not used for chat's emoji reactions.** `rct_reactions` allows one reaction per user per entity regardless of type (a user cannot give a 👍 and a ❤️ to one message) and its entity id is `int4`. Changing that would alter the like/pass behaviour other plugins rely on. Chat keeps its own reaction table, as Messenger does.

---

## C1. Member-post markdown and mentions

**The problem.** `MarkdownRenderer` passes any link scheme (`javascript:`) and any image URL through. That is acceptable for admin-written docs and unsafe for text members write for each other. Open bug, tracked in the to-do `project_todo_markdown_renderer_unsafe_links`.

**Build.** A `member_post` profile on `MarkdownRenderer::render()`:

- Allowed: bold, italic, strikethrough, inline and fenced code, blockquotes, lists, soft line breaks, links.
- Links: `http`, `https`, `mailto` only. Every external link carries `rel="nofollow ugc noopener"` and `target="_blank"` — the forum's public pages are an SEO-spam target.
- Not allowed: headings, tables, horizontal rules, images (an uploaded attachment is the way to show an image).
- All input is HTML-escaped before parsing, as today.
- Tests in `tests/unit`: scheme allow-list, `javascript:`/`data:` rejected, the paren and quote payloads, nested code, mentions.

**Mentions.** Users have an optional, non-unique `usr_nickname` and no username, so a typed `@name` cannot name one person. Mentions are inserted by a picker: typing `@` opens a list, choosing a person writes `@[Sam Lee](user:42)` into the markdown source. A bare typed `@sam` mentions nobody.

- `MentionParser::userIds($markdown)` returns the mentioned user ids, for notifications.
- The renderer turns each token into a mention chip showing the name as written.
- The picker's data comes from one `/api/v1` action, `mention_search`: prefix match on name or nickname (never email), minimum two characters, at most 8 results, rate-limited, and restricted to people who may **read the space** being posted in (C2) — it is not a way to enumerate the user table.
- A mention of someone who cannot read the space is dropped at write time, so a post can never leak a space to an outsider.

---

## C2. Space access (who may read, who may post)

**The problem.** Chat channels and forum categories both need "who sees this, who may post here". Joinery's existing gating primitives are subscription tier level (`*_tier_min_level`), `Group` membership, and the access-gate provider. `usr_permission` is the admin scale (5 = admin, 10 = superadmin), not a member role, and cannot be the member-facing gate.

**A "space"** is any table row that holds posts: a channel, a forum category. A space declares these columns:

| Column | Meaning |
|---|---|
| `<p>_read_rule` | `public` (signed-out visitors too, indexable) / `members` (any signed-in user) / `tier` / `group` / `invite` / `staff` |
| `<p>_post_rule` | same set minus `public`; default `members` |
| `<p>_tier_min_level` | int4, used by whichever rule is `tier` (same column `TierGatedContentRegistry` counts) |
| `<p>_grp_group_id` | int4, used by whichever rule is `group` |

Admins (`usr_permission >= 5`) always read, post and moderate. `staff` means admins only (announcement spaces).

**Build.** `SpaceAccess`:

- `SpaceAccess::mayRead($space, $user_id)` and `mayPost(...)` — one decision, fail-closed on an unknown rule.
- `SpaceAccess::readableSql($table_columns, $user_id)` — the same decision as a SQL condition, so search, "all channels" lists and the sitemap filter in the database instead of loading rows and checking each.
- `invite` delegates to the consumer's own member table through a small `SpaceMembers` interface (`isMember($space, $user_id)`); chat has one, the forum has none in v1.
- Each consumer registers its table with `TierGatedContentRegistry`.

---

## C3. Content reports and the moderation queue

**The problem.** Neither a chat room nor a forum is safe to open without "report this" and a place for an admin to act on it. Nothing like it exists today (blog comments only have an approved flag).

**Build.**

- **`rpt_content_reports`**: reporter, `rpt_entity_type`, `rpt_entity_id` (int8), a reason code (`spam` / `abuse` / `off_topic` / `other`), an optional note, status (`open` / `actioned` / `dismissed`), handled-by and handled-time. One open report per reporter per entity.
- **A handler registry** (`ContentReportHandler`, keyed by entity type, like `AccessGateRegistry`): each consumer supplies a label, a preview, a link to the content in context, and the actions an admin may take (hide the item, delete it, lock the topic).
- **The queue** at `/admin/admin_content_reports`: open reports newest first, preview and context link, action buttons (POSTs, not links).
- **The report action** is an `/api/v1` action, rate-limited.
- **A signal**, `content.reported`, declared with a `notify` block so admins are alerted.

Moderators in v1 are admins. A per-space moderator role is a clean later addition; the handler registry already isolates "who may act".

---

## C4. Full-text search helper

**The problem.** Chat search and forum search want the same thing: Postgres full-text over a body column, filtered to what the searcher may read. The expression in the query must match the index expression exactly or the index is not used, which is easy to get wrong twice.

**Build.** `FullTextSearch`:

- `FullTextSearch::indexSpec($column)` returns the entry for the data class's `$index_specifications`: a `gin` index on `to_tsvector(<config>, <column>)`. No generated column and no migration; `update_database` creates it.
- `FullTextSearch::match($column, $query)` returns the SQL condition using `websearch_to_tsquery` (so quoted phrases and `-exclusions` work) and its bound parameter.
- `FullTextSearch::snippet(...)` returns a result excerpt with the matched words marked; all other text is escaped.
- The language configuration comes from one setting, default `english`.
- Callers always combine `match()` with `SpaceAccess::readableSql()`; a search that skips the access filter is a defect.

Mailbox search (sealed, its own index) and Drive content search stay as they are.

---

## C5. Push notifications

Moved here from `chat_plugin.md` because the forum, the native apps' mail push (`push_notification_relay.md`) and later features all send through it. Chat builds it first.

- **`psh_push_destinations`** — one table for every place a user can be alerted. Columns: user, `kind` (`web` / `ios` / `android`), the endpoint or token, optional API key id, failure count, last-seen time, and a JSON `details` column for what differs by kind (a browser's p256dh key and auth secret and user agent; a phone's app id and followed mailboxes). Lives in `/data/`.
- **A push driver interface** — register, send, retire — with one driver per transport. The first build ships the `web` driver: it sends straight to the browser vendor's push service with the site's own VAPID key, no Joinery relay. The `native` driver (APNs / FCM through the Joinery relay) is `push_notification_relay.md`'s work.
- **One content rule.** A caller supplies a full text and a generic text; each driver declares whether it may carry readable text, and the sender picks accordingly. Web may carry text; native never does. Protected content (a Private conversation set to Nothing leaves unsealed, a Private mailbox) is always sent generic, enforced in this one place rather than by each consumer.
- **One cleanup path.** Sign-out, key revocation, and retiring a dead destination (a browser's 410 Gone, a token rejected repeatedly) all go through the same code.
- **VAPID keypair** generated on first deploy, stored in `Globalvars_site.php` as `vapid_public_key` and `vapid_private_key`. Public key exposed to clients via a small config endpoint.
- **Service worker** at `/service-worker.js` — registers for push events, displays notifications, handles click-to-open routing.
- **Push channel** added to Notify, alongside the existing in-app and email channels, sending through the shared sender. Same per-user opt-in model. Other triggers (such as the mailbox signal subscriber) call the same sender without going through Notify.
- **Composer dependency** `minishlink/web-push` for the web driver.
- **iOS Safari web push** has specific PWA-install requirements and limitations. Cross-browser push needs explicit test coverage; push that works on Chrome desktop is not evidence that it works on iOS.

---

## C6. Attachments on posts

**The problem.** Chat messages and forum posts both carry uploaded files. Messenger's attachment table (`msa_`) is bound to a message and carries sealing columns that neither consumer has; the photo table (`eph_`) is images only and has an `int4` entity id.

**Build.** One polymorphic `eat_entity_attachments` table: `eat_entity_type`, `eat_entity_id` (int8), `eat_fil_file_id`, filename, mime type, byte size, sort order, create and delete times. Upload goes through `UploadHandler` and cloud storage; each consumer registers an `AccessGateProvider` so a file is exactly as visible as the space it was posted in (the `MessengerAttachmentGate` pattern). Image thumbnails come from the existing `File` resize path. Messenger keeps `msa_`.

---

## Build order

The shared pieces are small and independent; each is built when its first consumer reaches it.

1. **C1** (markdown profile, mentions) and **C2** (space access) — before either plugin's phase 1.
2. **C4** (search) and **C6** (attachments) — when the first consumer needs them.
3. **C3** (reports) — before either plugin opens to members.
4. **C5** (push) — chat's last phase.
