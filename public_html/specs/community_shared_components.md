# Community Shared Components Spec

**Purpose:** The core pieces that more than one community feature needs, built once. The Chat plugin (`chat_plugin.md`) and the Forum plugin (`forum_plugin.md`) are the first two consumers; Messenger, Boards and later plugins adopt them as they are touched. Nothing here is chat- or forum-specific.

**Last Updated:** 2026-10-13

**Status:** Active. C1, C2, C3, C4, C6 and C6a built 2026-10-13/14 (the forum is their first consumer); C5 (push) is not built — chat builds it. Developer docs: docs/social_features.md (Spaces, Mentions, Full-text search, Attachments on posts, Content Reports), docs/formwriter.md (the restricted editor), docs/plugin_developer_guide.md (fileSources).

**Built differently from the text below, and why:**
- C4: the language is the constant `FullTextSearch::CONFIG`, not a setting — changing it changes every index expression, so a setting would silently stop the indexes being used. A data class writes its index entry literally (a property default cannot call `indexSpec()`), and its tests assert the two are equal.
- C1: `MarkdownRenderer` also gained block quotes (the editor's Quote button produced literal "> " text before) and `MarkdownRenderer::VERSION`, which a consumer storing rendered HTML keeps beside it.
- C2: `SpaceAccess::registerType()` / `resolve()` let the shared mention picker take a 'type:id' space reference.
- C1: one rule decides who can be mentioned (`MentionParser::mentionableName`: live, enabled, activated, may read the space), used by the picker and the save alike; a post keeps at most 20 mentions; the parser uses the renderer's own code patterns. The renderer strips its placeholder sentinel from input, refuses a target containing it, never makes a mention chip a link, and groups quotes in one linear pass.
- C2: an account that is not live and enabled reads nothing, public included; a tier rule with no level is closed; `readableUserIds()` answers a recipient list in one query.
- C3: the queue uses a new Notify audience `admins` (permission 5+, enabled, real people); a report row keeps `rpt_outcome` (what the action did); the queue filters open / actioned / dismissed; the handler decides its own actions (the forum offers remove and lock, no "hide"). A handler's own exception reaches the queue as its message.
- C6a: a clash with core or an active plugin refuses a plugin at validation (activate/install); every sync reports clashes; a usable tag with a stray key stays in the catalog as declared.
- C4: snippets mark matches found in the plain text, never inside an escaped entity.
- C6: `AccessGateRegistry` asks a provider about a signed-out visitor as user 0 instead of refusing outright, so a public post's attachment opens for visitors; the member-only gates refuse 0.

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

**One renderer, one profile for other people's text.** `MarkdownRenderer` already has it: `render($text, $soft_breaks, $restricted = true)`, used by Boards for comments and card descriptions. Link targets must be `http`, `https`, `mailto` or relative; no images; no heading ids, and headings pushed down two levels; links open in a new tab without the opener. Tests: `tests/unit/markdown_renderer_safety_test.php`. Every consumer (Boards, Forum, Chat) renders through that same profile. There is no second renderer and no per-consumer variant.

**Build** (changes to the existing profile, which Boards then gets too):

- Restricted links carry `rel="nofollow ugc noopener noreferrer"` (adds `ugc`, which tells search engines the link is user content — the forum's public pages are an SEO-spam target).
- Mention tokens render as chips (below).
- All input is HTML-escaped before parsing, as today.
- Tests added to the safety test: `ugc` on restricted links, mention tokens, a mention token cannot carry a link target.

**The editor is the existing one.** FormWriter's `textbox` with `markdownmode` (`assets/js/joinery-editor.js`): toolbar, Write / Split / Preview, preview rendered by the server through the `markdown_preview` action. Two changes:

- A `markdown_restricted` option on the field. The toolbar leaves out the buttons the restricted profile drops or the post should not use (H1–H3, image, table), and the preview call sends `restricted: true`.
- `markdown_preview` accepts `restricted` and renders through the restricted profile, so the preview is exactly what readers will see. It already requires a signed-in user, which every poster is.

**Mentions.** Users have an optional, non-unique `usr_nickname` and no username, so a typed `@name` cannot name one person. Mentions are inserted by a picker built into the same editor (an optional `mention_space` on the field turns it on and names the space whose readers it searches): typing `@` opens a list, choosing a person writes `@[Sam Lee](user:42)` into the markdown source. A bare typed `@sam` mentions nobody.

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

Moderators always read, post and moderate. `staff` means moderators only (announcement spaces). Who is a moderator is decided in one place, `SpaceAccess::mayModerate()` (below), never by a level written into consumer code.

**Build.** `SpaceAccess`:

- `SpaceAccess::mayRead($space, $user_id)` and `mayPost(...)` — one decision, fail-closed on an unknown rule.
- `SpaceAccess::readableSql($table_columns, $user_id)` — the same decision as a SQL condition, so search, "all channels" lists and the sitemap filter in the database instead of loading rows and checking each.
- `invite` delegates to the consumer's own member table through a small `SpaceMembers` interface (`isMember($space, $user_id)`); chat has one, the forum has none in v1.
- `SpaceAccess::mayModerate($space, $user_id)` — true when the user's session permission reaches the consumer's post model's `$write_permission`. Moderators are admins in v1; a per-space moderator role later changes only this method.
- Each consumer registers its table with `TierGatedContentRegistry`.

**How this fits `declared_permissions`.** That spec declares, on the admin scale, who may open a page, who may change or delete a row, and who may run an action, and it leaves reads to each model. Space access is a different question: the member-facing rule (public, members, tier, group, staff) for reading and posting. The two meet at three points, and each is built so that spec lands without changing a consumer:

- **Reads.** A space's models override `authenticate_read()` with `mayRead()`. That spec leaves `authenticate_read()` in place.
- **Writes.** "Author, or a moderator, and on create `mayPost()`" depends on the row's contents, which is the case where that spec keeps a model's own `authenticate_write()`. The post models declare `$write_permission` and `$delete_permission`, the properties that spec introduces, and `mayModerate()` reads them. One declaration serves both.
- **Actions.** Every consumer descriptor names `min_user_permission` explicitly, because that spec removes the default.

A write the server makes on a member's behalf (a reply updating its topic's counters) happens in the post's own save, never through a member-facing save of the parent row, so it is the one place that spec's server-write marker has to cover.

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

**Build.** One polymorphic `eat_entity_attachments` table: `eat_entity_type`, `eat_entity_id` (int8), `eat_fil_file_id`, filename, mime type, byte size, sort order, create and delete times. Upload goes through `UploadHandler` and cloud storage. Each consumer stamps its own origin tag on the file row (`fil_source`, e.g. `forum_attachment` / "Forum attachments"), so the admin Files page lists each kind separately, as it does board and message attachments. A plugin declares that tag in its own `plugin.json` (C6a, below). Each consumer also registers an `AccessGateProvider` so a file is exactly as visible as the space it was posted in (the `MessengerAttachmentGate` pattern). Image thumbnails come from the existing `File` resize path. Messenger keeps `msa_`.

### C6a. Plugins declare their own file categories

**The problem.** Every file carries an origin tag (`fil_source`) that says what made it, and `File::source_catalog()` says what each tag is called and whether listings show it. That catalog is a hard-coded list in core (`data/files_class.php`). Every plugin that wanted its own category has edited it, so core carries Boards', Mailbox's, Messenger's, Bug reports' and Joinery AI's categories even on a site that has none of those plugins.

**Build.** A plugin declares its tags in `plugin.json`:

```json
"fileSources": {
  "forum_attachment": { "label": "Forum attachments", "internal": false, "default_view": false }
}
```

- `File::source_catalog()` returns core's own tags merged with every plugin's declarations. It reads every plugin on disk, active or not, following the `SettingsDeclarations` pattern (a `glob` of `plugins/*/plugin.json`, cached for the request). An inactive plugin's files keep their name, and an internal tag (Mailbox's search index) stays unlisted when its plugin is off.
- A plugin may not declare a tag core already owns, and two plugins may not declare the same tag. Either is a validation error from the plugin manifest check, and at runtime core's entry wins.
- The plugin keeps its tag string as a constant on one of its own classes (e.g. `ForumUploads::SOURCE`), never on `File`.
- An undeclared tag (files left by a removed plugin) behaves as it does today: shown as its raw name, and listable, so it is noticed and not lost.
- Every reader of the catalog (`admin_files.php`, `DriveHelper`'s other-sources view, `internal_sources()`, `default_view_sources()`, `source_label()`, `source_is_listable()`) goes through `source_catalog()` already, so none of them changes.

**What moves out of core** (the tag strings stay the same, so no stored row changes):

| Tag | Moves to |
|---|---|
| `email_attachment`, `mail_import_archive`, `mailbox_search_index` | `mailbox` |
| `messenger_attachment` | `messenger` |
| `ai_chat_upload` | `joinery_ai` |
| `bug_report_image` | `bug_reports` |
| `boards_attachment` | `boards` |

**What stays in core:** `user_upload`, `entity_photo`, `drive`, `problem_report`, and the unclassified bucket.

**Core code that names a moving tag** switches to the tag string:
- `migrations/backfill_file_source_from_references.php`: four tags.
- `includes/UploadPurposeRegistry.php`: a doc example.
- `tests/functional/drive/other_sources_test.php` and `tests/functional/files/upload_purposes_test.php`: core tests that use plugin tags as fixtures. They create their own throwaway tags instead, so a core test never needs a plugin installed.

**Docs.** `docs/plugin_developer_guide.md` gains a `fileSources` section beside the settings one.

**Tests** (`tests/unit`): a declared plugin tag appears in the catalog with its label; an inactive plugin's internal tag stays internal; a collision with a core tag is refused by the manifest check; an undeclared tag reads as its raw name and is listable.

---

## Build order

The shared pieces are small and independent; each is built when its first consumer reaches it.

1. **C1** (markdown profile, mentions) and **C2** (space access) — before either plugin's phase 1. **C6a** (plugin-declared file categories) stands alone and goes in with them.
2. **C4** (search) and **C6** (attachments) — when the first consumer needs them.
3. **C3** (reports) — before either plugin opens to members.
4. **C5** (push) — chat's last phase.
