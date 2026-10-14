# Forum Plugin

A discussion forum: categories, titled topics with flat replies, likes, attachments, watching, search and moderation. Topics are meant to be found and read months later, by members and, in categories the admin makes public, by search engines. Plain PHP on the request path: no service, no WebSocket, no polling.

## Pages

| URL | View | What it shows |
|---|---|---|
| `/forum` | `views/index.php` | The categories the visitor may read, with counts and latest topic |
| `/forum/{slug}` | `views/category.php` | A category's topics, pinned first then by latest activity; New topic; Watch new topics |
| `/forum/t/{id}/{slug}` | `views/topic.php` | A topic's posts, oldest first, a page at a time; the reply box under the last page |
| `/forum/search` | `views/search.php` | Full-text search over what the visitor may read; never static-cached (`'static_cache' => false`), since every query is its own page |

The category and topic routes are placeholders in the plugin's `serve.php`. A topic is found by its id; the slug is for readers and search engines, and a stale or missing slug redirects (301) to the current address. The category is not part of a topic's address, so moving a topic never breaks a link.

A link to one post is `/forum/t/{id}/{slug}?post={post_id}` (notifications, search results, the report queue, reply chips): the topic page sends each visitor to the page that post is on for them — a moderator sees held posts in between, a visitor does not — and a stale slug keeps the `?post=`.

Opened without `?page`, a topic page lands a signed-in member on their first unread post; a topic they have read through opens at its last post; a first visit opens at the top. Pages never write: once a topic page is shown, `forum.js` reports how far the member read (`forum_mark_read`).

A category the visitor may not read answers 404, whether or not it exists. Pages of non-public categories, held topics and the search page carry `noindex`.

## Data model

| Table | Class | Holds |
|---|---|---|
| `fcg_forum_categories` | `ForumCategory` | Name, slug, description, order, and the space columns: `fcg_read_rule`, `fcg_post_rule`, `fcg_tier_min_level`, `fcg_grp_group_id` |
| `ftp_forum_topics` | `ForumTopic` | Title, slug, pinned, locked, approved, and the counters `ftp_reply_count`, `ftp_last_post_id`, `ftp_last_post_time` |
| `fpo_forum_posts` | `ForumPost` | Markdown source `fpo_body`, the HTML it renders to `fpo_body_html` and the renderer version that made it, `fpo_is_first`, `fpo_reply_to_id`, `fpo_is_approved`, `fpo_edited_time` |
| `ftr_topic_reads` | `ForumTopicRead` | How far each member has read each topic (moves forward only) |
| `fwt_forum_watches` | `ForumWatch` | A member's `watching` or `muted` choice on a topic, or `watching` on a category |

Not forum tables: likes are core `Reaction` rows (entity type `forum_post`); attachments are core `EntityAttachment` rows over Files tagged `forum_attachment`; reports are core `ContentReport` rows; edit history is `ChangeTracking`.

**Counters** belong to the posts. `ForumPosting::recount()` recomputes them inside every transaction that adds, removes, approves or turns down a post, counting approved, live posts only. A member's reply never saves the topic row; the counter update is a direct write by the server.

**Rendered HTML** is made at write time with `MarkdownRenderer`'s restricted profile and soft line breaks, and stored with `MarkdownRenderer::VERSION`. The daily `ForumMaintenance` task renders again any post an older renderer made, so renderer improvements reach old posts.

**Deletion.** Topics and posts soft-delete. Removing an opening post removes its topic. A category soft-delete hides its topics with the same timestamp, and undelete brings back exactly those. Removing for good runs through the deletion rules: a category takes its topics and the watches on it, a topic its posts and the watches on it, a post its attachments (rows and Files) and likes. Content reports about a removed post stay in the queue's history.

## Access

Every read goes through `SpaceAccess` (see [Social Features § Spaces](../../../docs/social_features.md)), with one forum rule on top: a held post, and a topic whose opening post is held, is seen only by its author and the moderators.

| Read rule | Who reads |
|---|---|
| `public` | Everyone, signed out too; search engines index it |
| `members` | Any signed-in member |
| `tier` | Members whose subscription tier reaches `fcg_tier_min_level` |
| `group` | Members of `fcg_grp_group_id` |
| `staff` | Moderators only |

The post rule takes the same values except `public`; posting also needs the read rule. A `tier` rule with no tier set is closed, and an account that is deleted or disabled reads nothing, public categories included. A category with a problem (no name, a bad or reserved address, a tier or group rule without its tier or group) is refused by `save()` itself, whoever saves it. A **moderator** is a user whose permission reaches `ForumPost::$write_permission` (5): they read and post everywhere, edit or remove any post, pin, lock, move and restore topics, and approve held posts. The models enforce the same rules (`authenticate_read` / `authenticate_write`), so the REST and AI surfaces cannot step around them. Every action's descriptor names its `min_user_permission`.

Attachments are gated by `ForumAttachmentGate` (key `forum_post`, reference = the post id): a file is exactly as visible as its post, including to signed-out visitors in public categories.

## Behaviour

- **Writing** uses the platform's markdown editor in its restricted form (`markdown_restricted`, no heading, image or table buttons) with the `@` mention picker (`mention_space` = `forum_category:{id}`). Mentions of people who cannot read the category are saved as plain text; kept mentions carry the person's real name.
- **Holding.** `forum_hold_first_posts` (default 2) holds a member's posts until that many have been approved. A held post is invisible to others, takes no part in the counters, and sends no alerts until approved (`/plugins/forum/admin/admin_forum_held`, or the Approve button on the topic page). Holding one tells the admins (`forum.post_held`). Turning down is only for posts still waiting; a published post is removed instead.
- **Post limit.** `forum_post_rate_limit` posts per `forum_post_rate_window` seconds per member (default 3 per 60), topics and replies together. Over it, the save is refused with the wait and the text stays in the editor.
- **Locks.** A locked topic refuses replies, and authors' edits and removals, from everyone but moderators.
- **Authors** edit their own posts any time (shown as "edited"); they may remove a topic only while nobody else has posted in it, held posts included. An edit that mentions someone new tells them.
- **Mentions** keep their person's real name; a post keeps at most 20 (`MentionParser::MAX_PER_POST`), and a mention of anyone who cannot be mentioned there — no such account, deleted, disabled, never activated, or unable to read the category — is saved as plain text.
- **Uploads** are limited per member (`ForumUploads::UPLOAD_RATE` per `UPLOAD_WINDOW` seconds) and to `ForumUploads::MAX_UNCLAIMED` files not yet posted.
- **Watching.** Starting or replying to a topic watches it unless the member muted it. Muting a topic silences it entirely, mentions included.

## Notifications

Declared under `signals` in `plugin.json`; delivered through Notify with each member's preferences.

| Signal | Who |
|---|---|
| `forum.topic_created` | Members watching the category |
| `forum.reply_posted` | Members watching the topic, and the author of the post the reply answers |
| `forum.mention` | Each person the post mentions (an edit tells only people it mentions for the first time) |
| `forum.post_held` | The admins (permission 5+, enabled): a post is waiting for approval |

Nobody hears about their own post; a mentioned person gets the mention instead of a reply alert; muted members hear nothing; only people who can still read the category are told — decided for the whole recipient list in one query (`SpaceAccess::readableUserIds`). An admin who wants every new topic in a category watches it, like anyone else.

## Search

`ForumSearch` matches post bodies, and topic titles for opening posts, with `FullTextSearch`, ANDed with `SpaceAccess::readableSql()` in the same query. Best match first by default, newest first on request, with a category filter. Held posts appear only for moderators. Signed-out visitors search the public categories.

## Public pages, the cache and SEO

Public category and topic pages are served to signed-out visitors from the static page cache. Every change that alters one (a reply, an edit, a removal, a pin, a lock, a move) clears that topic's pages, its category's list, the forum home and the sitemap in one pass (`ForumCache`). A change to who may read a category clears the whole forum, and so do turning the forum off (`forum_active` declares `clearsPages`) and the upkeep task re-rendering posts.

`forum_topic` and `forum_category` are registered with `SeoPageMetadata` from `serve.php`, so their pages get their title, description and Open Graph tags from the single emitter. Their `seo_public_records()` list only live public categories and live, approved topics in them for the sitemap; `/forum` is declared in `publicPaths`.

## API actions

All under `/api/v1/action/forum/…`, browser session, `forum_active` required.

| Action | Does |
|---|---|
| `forum_topic_create` | Start a topic: `category_id`, `title`, `body`, `attachments` |
| `forum_post_create` | Reply: `topic_id`, `body`, `reply_to_id`, `attachments` |
| `forum_post_edit` | Edit a post (and an opening post's title) |
| `forum_post_delete` | Remove a post; an opening post removes its topic |
| `forum_post_source` | A post's markdown, for editing or quoting |
| `forum_topic_set` | Moderators: `pin`, `unpin`, `lock`, `unlock`, `move`, `delete`, `restore` |
| `forum_post_moderate` | Moderators: `approve` or `reject` a held post |
| `forum_like` | Like or unlike a post (not your own) |
| `forum_watch_set` | `watching`, `muted` or `none` on a topic; `watching` or `none` on a category |
| `forum_mark_read` | Move the member's reading position forward |
| `forum_upload` | Store a file (multipart `file`, `category_id`) to attach to the post being written |
| `forum_search` | Search; also open to signed-out callers (`allow_guest`) |

Uploads are the uploader's own private Files until the post that names them is saved; then they are gated on the post. Unclaimed uploads are removed after a day by `ForumMaintenance`.

## Admin

- `/plugins/forum/admin/admin_forum_categories` — categories, with delete and undelete.
- `/plugins/forum/admin/admin_forum_category` — create or edit a category and its rules.
- `/plugins/forum/admin/admin_forum_held` — posts awaiting approval (its menu entry shows the count).
- `/plugins/forum/admin/admin_forum_topics` — every topic, and removed ones to restore or remove for good.

Reported posts appear in the core report queue (`/admin/admin_content_reports`), where an admin can remove the post (or its topic) or lock the topic.

## Settings

| Setting | Default | |
|---|---|---|
| `forum_active` | 1 | Turns the forum on |
| `forum_hold_first_posts` | 2 | Posts held until approved, per new member |
| `forum_page_size` | 20 | Posts per topic page |
| `forum_topics_page_size` | 30 | Topics per category page |
| `forum_post_rate_limit` / `_window` | 3 / 60 | Post limit per member |
| `forum_max_attachment_mb` | 10 | Largest attachment |

## Tests

| Suite | Tier | Covers |
|---|---|---|
| `forum_unit` | safe | Index expressions match search, slugs and titles, reserved addresses |
| `forum_access` | test-db | Every read rule against every surface: pages, models, SQL lists, search, sitemap, mention picker, attachments, held posts |
| `forum_posting` | test-db | Topics, replies, edits, locks, moves, removal, holding, the post limit, mentions, likes, reading position, counters, reports, re-rendering |
| `forum_notify` | test-db | Who hears about each signal, once |
