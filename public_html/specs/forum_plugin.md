# Forum Plugin Spec

**Purpose:** A discussion forum for Joinery sites: categories, topics with titles, flat replies, likes, attachments, watching, search. Topics are meant to be found and read months later, by members and — in categories the admin makes public — by search engines. Chat (`chat_plugin.md`) is the live-conversation product; this is the durable one.

**Last Updated:** 2026-10-11

**Status:** Active — not yet implemented.

**Plugin name:** `forum`

**Prerequisite:** `community_shared_components.md` — member-post markdown and mentions (C1), space access (C2), content reports (C3), full-text search (C4), push (C5), attachments (C6). The forum builds C1–C4 and C6 before its phase 1 needs them; C5 comes from chat or is built here if the forum ships first.

---

## Scope

### In scope (v1)

- Categories (one level), each with its own read and post rule: public, members, tier, group, staff
- Topics with a title, flat replies, optional "in reply to" pointer
- Markdown (the member-post profile), `@` mentions, attachments
- Likes on posts
- Pin, lock, move, soft-delete topics and posts (admins)
- Report a post; admin moderation queue
- Watch a topic or category; in-app, email and push alerts through Notify
- Unread markers per topic
- Search (full-text, filtered to what the searcher may read)
- Public categories readable signed-out, indexable, in the sitemap
- Optional hold-for-approval of a new member's first posts

### Explicitly out of scope (v1)

- Nested categories, tags (when added, reuse the blog's group-category tag pattern)
- Accepted answers, up/down voting, polls
- Per-category moderators, trust levels, badges, post counts, signatures
- Replying by email (a later addition on the platform's email-handler work)
- Private messages (Messenger), live chat (Chat)
- View counters — public pages come from the static page cache, so a count would be wrong

### Why not blog comments, and why not chat

Blog comments (`cmt_`) hang off one post, have no titles, categories, pinning, locking or move, and are rendered by a separate sanitiser. A forum topic is its own object. Chat messages are short, time-ordered and live; a topic is titled, ordered by activity, and read cold. They share components (the shared spec), not tables.

---

## Architecture

A plain PHP plugin on the platform's request path. There is no service to run, no WebSocket and no polling: a page shows what is in the database when it loads. Realtime would add nothing to a forum.

- **Views** under `plugins/forum/views/` (`/forum`, category and topic pages). Topic and category URLs carry placeholders, so `serve.php` routes bind the category and topic by slug.
- **Logic** under `plugins/forum/logic/`, exposed as `/api/v1` actions through `_logic_descriptor()`: `topic_create`, `post_create`, `post_edit`, `post_delete`, `topic_set` (pin / lock / move), `watch_set`, `search`. Page JavaScript calls them with the browser-session credential.
- **Admin** under `plugins/forum/admin/`, menu declared in `plugin.json`.
- **Settings** declared in `plugin.json` (`forum_post_rate_limit`, `forum_post_rate_window`, `forum_hold_first_posts`, `forum_page_size`).

### Public pages, SEO and the static cache

- A category whose `fcg_read_rule` is `public` is readable signed out. Its category and topic pages are served from the static page cache like any anonymous page. Pages for any other rule are never cached (they require a session) and carry `noindex`.
- **Fresh pages.** The cache is otherwise refreshed by a 1% random invalidation, which is too slow for a thread. Every write that changes a public page — new reply, edit, delete, lock, pin, move — calls `StaticPageCache::invalidateUrl()` for the topic page and the category's list page.
- **SEO.** `forum_topic` is registered with `SeoPageMetadata::register_entity_class()` (og type `article`) from the plugin's `serve.php`, so topic pages get title, description from the first post, canonical URL and open-graph tags from the single emitter, and public topics appear in `/sitemap`. The enumeration returns only topics in public categories, not deleted and not held.
- **Link spam.** Every link in a post gets `rel="nofollow ugc noopener"` (C1). Only signed-in members can post.

---

## Data Model

Tables use the standard `<prefix>_<table>` convention. Access columns follow the shared space shape (C2).

### `fcg_forum_categories`

| Column | Type | Notes |
|---|---|---|
| `fcg_forum_category_id` | `int8 serial` | PK |
| `fcg_name` | `varchar(100)` | |
| `fcg_slug` | `varchar(100)` | Unique among live rows |
| `fcg_description` | `text` | |
| `fcg_read_rule` | `varchar(20)` | `public` / `members` / `tier` / `group` / `staff` |
| `fcg_post_rule` | `varchar(20)` | `members` / `tier` / `group` / `staff` |
| `fcg_tier_min_level` | `int4 NULL` | Registered with `TierGatedContentRegistry` |
| `fcg_grp_group_id` | `int4 NULL` | |
| `fcg_sort_order` | `int4` | |
| `fcg_create_time`, `fcg_delete_time` | `timestamp(6)` | Soft delete |

The `invite` rule is not offered in v1; a member-only room is a `group` category.

### `ftp_forum_topics`

| Column | Type | Notes |
|---|---|---|
| `ftp_forum_topic_id` | `int8 serial` | PK |
| `ftp_fcg_forum_category_id` | `int8` | FK, indexed |
| `ftp_usr_user_id` | `int4` | Author |
| `ftp_title` | `varchar(200)` | |
| `ftp_slug` | `varchar(200)` | For the URL |
| `ftp_is_pinned`, `ftp_is_locked` | `bool` | |
| `ftp_reply_count` | `int4` | Denormalised, kept by the post save path |
| `ftp_last_post_id` | `int8 NULL` | |
| `ftp_last_post_time` | `timestamp(6)` | Topic order: pinned first, then this, descending |
| `ftp_create_time`, `ftp_delete_time` | `timestamp(6)` | Soft delete |

Index: `(ftp_fcg_forum_category_id, ftp_is_pinned DESC, ftp_last_post_time DESC)` for the category list.

### `fpo_forum_posts`

A topic's first post is its opening post (`fpo_is_first`); the topic row holds only the title and counters.

| Column | Type | Notes |
|---|---|---|
| `fpo_forum_post_id` | `int8 serial` | PK |
| `fpo_ftp_forum_topic_id` | `int8` | FK, indexed |
| `fpo_usr_user_id` | `int4` | Author |
| `fpo_is_first` | `bool` | |
| `fpo_body` | `text` | Markdown source, the truth |
| `fpo_body_html` | `text` | Rendered at write time (C1); re-rendered on edit |
| `fpo_reply_to_id` | `int8 NULL` | "In reply to" chip; the thread stays flat |
| `fpo_is_approved` | `bool` | False while held (see below); a held post is visible only to its author and admins |
| `fpo_edited_time` | `timestamp(6) NULL` | |
| `fpo_create_time`, `fpo_delete_time` | `timestamp(6)` | Soft delete |

Index: a `gin` full-text index declared with `FullTextSearch::indexSpec('fpo_body')` (C4).

### `ftr_topic_reads`

Per-user read position, one row per topic a member has opened: `ftr_usr_user_id`, `ftr_ftp_forum_topic_id`, `ftr_last_read_post_id`, `ftr_update_time`; unique on user and topic. A topic is unread when its `ftp_last_post_id` is above the marker.

### `fwt_forum_watches`

`fwt_usr_user_id`, `fwt_target_type` (`topic` / `category`), `fwt_target_id`, `fwt_level` (`watching` / `muted`); unique on user, type and id. Posting or replying in a topic sets `watching` on it unless the member has set it to `muted`.

### Not forum tables

| Need | Uses |
|---|---|
| Likes | `Reaction`, entity type `forum_post`, reaction `like` |
| Attachments | C6 `eat_entity_attachments`, entity type `forum_post`, with a forum `AccessGateProvider` |
| Reports | C3 `rpt_content_reports`, entity type `forum_post`, with a forum report handler |
| Edit history | `ChangeTracking` (old and new `fpo_body`, the editor, the time); admin-visible, the post shows only "edited" |

---

## Behaviour

- **Order.** Category pages list pinned topics, then by last activity. A topic page lists posts oldest first, paged at `forum_page_size`.
- **Edit and delete.** An author edits their own post at any time (the post shows "edited"); an admin edits or deletes any. Deleting the opening post deletes the topic. Delete is soft. Locked topics refuse new replies from everyone but admins.
- **Move** changes a topic's category; the old URL redirects to the new one.
- **Holding.** `forum_hold_first_posts` holds a member's first N posts for admin approval (the blog-comment `cmt_is_approved` idea). While held, the post is invisible to everyone else, takes no part in `ftp_last_post_time`, and sends no alerts. Approval releases it and sends them.
- **Mentions** are inserted by the picker (C1); a mention of someone who cannot read the category is dropped at write time.
- **Rate limit.** Per-user bucket `forum_post` through `RequestLogger::rate_limit_state()`: 3 posts per minute per user (topics and replies together). Over the limit the save is refused with `retry_after` and the text stays in the editor. Both numbers are settings; the defaults are starting values, not measured.
- **Search.** `FullTextSearch` over `fpo_body` and `ftp_title`, always combined with `SpaceAccess::readableSql()`. Unlike chat, results are ordered **best match first** (a forum post is long and durable, so relevance ranking carries information), with a newest-first toggle; the default scope is the whole forum with a category filter.

### Notifications

Signals are declared under `signals` in `plugin.json`, with `notify` blocks:

- `forum.topic_created` — topic recipients: members watching the category, and admins who opted in
- `forum.reply_posted` — targeted: watchers of the topic, plus the author of the post replied to
- `forum.mention` — targeted: the mentioned member

A member's alerts arrive in-app and, by their Notify preferences, by email; push (C5) follows the same preference. A held post dispatches nothing until approved. Email digests are Notify's concern, not the forum's.

---

## Integration Points (Up-Front Inventory)

| System | How the forum uses it | Modifications needed? |
|---|---|---|
| `community_shared_components.md` C1–C4, C6 | Rendering, access, reports, search, attachments | Built there |
| `Reaction` | Likes | No |
| Signal bus / Notify / push (C5) | Alerts | No — signals declared in `plugin.json` |
| `ChangeTracking` | Edit history | No |
| `StaticPageCache` | Public pages and their invalidation | No — uses `invalidateUrl()` |
| `SeoPageMetadata` | Topic SEO and sitemap | Register `forum_topic` from `serve.php` |
| `TierGatedContentRegistry` | Tier-gated categories counted in the tier admin | Register `fcg_forum_categories` |
| `RequestLogger` | Post rate limit | No |
| `Group`, `User`, `UserBlock` | Group rule; authors; blocked members' posts are collapsed for the blocker | No |
| Admin menu | Categories, topics, queue | Declared in `plugin.json` |
| `update_database` | Tables and the full-text index | None special |

---

## Build Phases

Each is deliverable on its own.

1. **Categories, topics, posts, public reading.** Shared C1 (markdown profile, bug fix) and C2 (space access) first. Data model, category/topic/post views, the create and reply actions, soft delete, pin, lock, move. Static cache invalidation and SEO registration.
2. **Admin UI.** Category CRUD with the access rules, topic management.
3. **Mentions, watching, notifications.** The mention picker, watch rows, the three signals, unread markers.
4. **Likes and attachments.** `Reaction` likes; C6 attachments.
5. **Reports and moderation.** C3 queue and the forum report handler; held first posts.
6. **Search.** C4 helper, search view and action.
7. **Push.** Nothing to build here beyond declaring the alerts; it works when C5 exists.

Phase 1 is the load-bearing one: the access rules and the cache invalidation are the parts a mistake in exposes private content or serves a stale thread.

---

## Complexity and Risk

- **Access is the risk.** A category's rule decides what an anonymous visitor, a static cache file, a sitemap and a search result may show. All four go through `SpaceAccess`; a test per rule against each surface is required, including that a non-public topic never enters the static cache or the sitemap.
- **Spam on public pages.** Mitigated by sign-in to post, `nofollow ugc`, rate limiting, reports, and optional first-post holding. It is not eliminated; an owner of a public forum should expect to moderate.
- **Denormalised counters** (`ftp_reply_count`, `ftp_last_post_id/time`) must change in the same save as the post, including on delete, hold and approve; a reconcile task recomputes them.

---

## Open Decisions

- **Default for `forum_hold_first_posts`.** `0` (nobody held) is friendliest for a private members' forum; `2` or `3` is the common defence for a public one. The setting exists either way; the question is what a fresh install ships with.

---

## Future

- Tags (blog group-category pattern), accepted answers and voting, polls
- Per-category moderators
- Reply by email
- Nested categories
