# Dating Platform Spec: Core Extensions + Dating Plugin

**Purpose:** Define what's needed to build a dating site on Joinery, separated into reusable core platform features vs. dating-specific plugin features. MVP-focused.

**Last Updated:** 2026-03-22

---

## Implementation Status

| Feature | Status | Notes |
|---------|--------|-------|
| **Core Platform** | | |
| 1.1 Extended User Profiles | **DONE** | Fields added to `users_class.php` (2026-03-22) |
| 1.2 Notification Center | **DONE** | Data model, logic, views, AJAX all implemented. Notification preferences not yet built. |
| 1.3 User Discovery / Member Directory | Not started | Deferred to Phase 2 per plan |
| 1.4 Reaction System | **DONE** | Separate spec: [Reaction System Spec](implemented/reaction_system_spec.md) (2026-03-22) |
| 1.5 Block System | Not started | |
| 1.6 Report System | Not started | |
| 1.7 Messaging Enhancements | Not started | Basic point-to-point messaging exists (`msg_messages`), but no conversation threading, read status, or conversation models |
| **Dating Plugin** | | |
| 2.1 Dating Profile | Not started | `plugins/dating/` directory does not exist |
| 2.2 Dating Preferences | Not started | |
| 2.3 Match System | Not started | |
| 2.4 Discovery Engine | Not started | |
| 2.5 Message Gating | Not started | |
| 2.6 Admin Verification | Not started | |
| 2.7 Interest Tags | Not started | Groups system exists but no interest category integration |
| **Infrastructure** | | |
| Geolocation / PostGIS | Not started | PostGIS extension not installed; spec exists |
| Pictures Refactor | **DONE** | `EntityPhoto` model implemented (`eph_entity_photos` table) |

---

## Design Principle: Core vs. Plugin

The guiding question: *"Would a non-dating platform also benefit from this feature?"*

- **Core** = Features useful to membership sites, marketplaces, professional networks, community platforms, etc.
- **Plugin** = Features that only make sense in a dating context.

This separation means the core work benefits every Joinery site, and the dating plugin is a relatively thin layer on top.

---

## Part 1: Core Platform Features

These are new core features that fill gaps in the platform for ANY interactive/social use case.

### 1.1 Extended User Profiles -- STATUS: DONE (2026-03-22)

**Problem:** Users currently have only name, email, photo, phone, timezone. Any community or membership platform needs richer profiles.

**New fields on `usr_users`** (via `$field_specifications` in `data/users_class.php`):
- `usr_bio` (text) - Free-form about me, 500 char limit
- `usr_date_of_birth` (date) - Stored securely, displayed as age only in public contexts
- `usr_gender` (varchar(30)) - Open text or constrained list (site-configurable)
- `usr_profile_visibility` (varchar(20)) - 'public', 'members_only', 'private'

These are core user attributes. The `usr_users` table already has profile-type fields (`usr_nickname`, `usr_organization_name`, `usr_pic_picture_id`, `usr_timezone`), so this extends the existing pattern.

**Location & Geography:** Address data (city, state, country) and geocoded coordinates (lat/lng, PostGIS geography column) live on the existing `usa_users_addrs` table, not on `usr_users`. Users already have addresses; geocoding extends that existing data. See **[Geolocation & PostGIS Spec](geolocation_postgis_spec.md)** for full details.

**Principle:** Core features go in core tables. Plugin-specific fields (dating preferences, relationship goals, etc.) go in plugin tables. Address and geography data stays in the address table where it belongs.

### 1.2 Notification Center -- STATUS: DONE

See **[Notification Center Spec](notification_center_spec.md)** for full details on the in-app notification system, data models (`notifications`, `notification_preferences`), existing UI scaffolding, and delivery strategy.

### 1.3 User Discovery / Member Directory -- STATUS: Not started (Phase 2)

**Problem:** No way to browse or search other users. Membership orgs, professional networks, and community platforms all need a member directory.

**Features:**
- Browse users with filters (location, groups, custom fields)
- Search by name or keyword
- Paginated grid or list view
- Respects `profile_visibility` settings
- Respects block list
- Configurable: admin can enable/disable directory, choose which fields are filterable

**New Settings:**
- `member_directory_active` (bool) - Feature toggle
- `member_directory_requires_login` (bool, default true)
- `member_directory_fields` (json) - Which profile fields appear as filters

**Geolocation Support:** See **[Geolocation & PostGIS Spec](geolocation_postgis_spec.md)** for PostGIS setup, geocoding, spatial indexing on the address table, and distance queries.

### 1.4 Reaction System -- STATUS: DONE (2026-03-22, separate spec)

See **[Reaction System Spec](implemented/reaction_system_spec.md)** for full details. Polymorphic `entity_type` + `entity_id` pattern (same as EntityPhoto, ChangeTracking). Works with any entity: users, events, posts, products, etc. Supports likes, favorites, bookmarks, passes. Dating plugin adds match semantics on top.

### 1.5 Block System -- STATUS: Not started

**Problem:** Any platform where users interact needs the ability to block other users. Blocked users can't see your profile, message you, or appear in your results.

**New Model: `user_blocks`**
- `ubl_user_block_id` (serial, primary key)
- `ubl_usr_user_id` (int4, FK) - User doing the blocking
- `ubl_blocked_usr_user_id` (int4, FK) - Blocked user
- `ubl_reason` (varchar 255, nullable)
- `ubl_create_time` (timestamp)
- Unique constraint on (user_id, blocked_user_id)

**Enforcement:** Block checks are applied in:
- Member directory queries
- Message sending (reject if blocked)
- Profile viewing (404 or "user not found")
- Notification generation (suppress)

### 1.6 Report System -- STATUS: Not started

**Problem:** Any platform with user-generated content or user interaction needs content/user reporting and admin moderation. This is non-negotiable for safety.

**New Model: `user_reports`**
- `urp_user_report_id` (serial, primary key)
- `urp_usr_user_id_reporter` (int4, FK) - Who reported
- `urp_target_type` (varchar 50) - 'user', 'message', 'post', 'photo', etc.
- `urp_target_id` (int4) - ID of reported entity
- `urp_reason` (varchar 50) - Category: 'harassment', 'fake_profile', 'inappropriate_content', 'spam', 'other'
- `urp_details` (text, nullable) - Free-form explanation
- `urp_status` (varchar 20, default 'pending') - 'pending', 'reviewed', 'actioned', 'dismissed'
- `urp_admin_notes` (text, nullable) - Admin resolution notes
- `urp_resolved_by_usr_user_id` (int4, nullable)
- `urp_create_time` / `urp_resolved_time` (timestamps)

**Admin Interface:**
- Report queue page with filtering by status and type
- Action buttons: dismiss, warn user, disable user, permanent ban
- Report statistics dashboard

### 1.7 Messaging Enhancements -- STATUS: Not started

**Problem:** The existing messaging model is functional but minimal. Interactive platforms need conversation threading and read status.

**Enhancements to existing `messages` or new `conversations` model:**

Option A: Add fields to existing messages:
- `msg_is_read` (bool, default false)
- `msg_read_time` (timestamp)
- `msg_conversation_id` (int4) - Group messages into conversations

Option B: New conversation model with messages as children:

**New Model: `conversations`**
- `cnv_conversation_id` (serial, primary key)
- `cnv_create_time` / `cnv_update_time` / `cnv_delete_time` (timestamps)
- `cnv_last_message_time` (timestamp) - For sorting conversations

**New Model: `conversation_participants`**
- `cnp_conversation_participant_id` (serial, primary key)
- `cnp_cnv_conversation_id` (int4, FK)
- `cnp_usr_user_id` (int4, FK)
- `cnp_last_read_time` (timestamp) - For unread indicators
- `cnp_is_muted` (bool, default false)
- `cnp_delete_time` (timestamp) - User can "delete" conversation for themselves

**Recommendation:** Option B. Conversations as a first-class entity supports group messaging in the future and cleanly separates "conversation metadata" from "message content." The existing `messages` table gets a `msg_cnv_conversation_id` FK added.

**UI Enhancements:**
- Conversation list view (inbox) with most recent message preview
- Unread conversation count in header
- Read indicators in conversation view

---

## Part 2: Dating Plugin

These features only make sense for a dating site. Built as a standard Joinery plugin at `plugins/dating/`.

### 2.1 Dating Profile -- STATUS: Not started

**New Model: `dating_profiles`** (plugin data model)
- `dtp_dating_profile_id` (serial, primary key)
- `dtp_usr_user_id` (int4, FK, unique) - One dating profile per user
- `dtp_looking_for` (varchar 50) - 'men', 'women', 'everyone' (or site-configurable options)
- `dtp_relationship_goal` (varchar 50) - 'casual', 'long_term', 'marriage', 'friends', 'not_sure'
- `dtp_height_cm` (int2, nullable) - Height in centimeters (display converts to ft/in based on locale)
- `dtp_smoking` (varchar 20, nullable) - 'never', 'sometimes', 'regularly'
- `dtp_drinking` (varchar 20, nullable) - 'never', 'socially', 'regularly'
- `dtp_children` (varchar 30, nullable) - 'no_children', 'have_children', 'want_children', 'dont_want', 'open_to_children'
- `dtp_education` (varchar 30, nullable) - 'high_school', 'some_college', 'bachelors', 'masters', 'doctorate', 'trade_school'
- `dtp_occupation` (varchar 100, nullable)
- `dtp_prompts` (jsonb, nullable) - Array of {prompt_id, answer} for conversation starter prompts
- `dtp_is_active` (bool, default true) - User can pause/unpause their dating profile
- `dtp_last_active_time` (timestamp) - For "recently active" indicators
- `dtp_create_time` / `dtp_update_time` / `dtp_delete_time` (timestamps)

**Profile Prompts System:**
Instead of a free-form bio only (which is already in core `usr_users` as `usr_bio`), dating sites use guided prompts like:
- "A perfect first date for me is..."
- "I'm looking for someone who..."
- "My most controversial opinion is..."

Prompts are stored in a settings/config table. Users pick 3 prompts and write answers. Stored as JSON in `dtp_prompts`. Admin can manage available prompts.

### 2.2 Dating Preferences / Dealbreakers -- STATUS: Not started

**New Model: `dating_preferences`** (plugin data model)
- `dpr_dating_preference_id` (serial, primary key)
- `dpr_usr_user_id` (int4, FK, unique)
- `dpr_age_min` (int2, default 18)
- `dpr_age_max` (int2, default 99)
- `dpr_distance_max_km` (int4, default 80) - ~50 miles
- `dpr_looking_for` (varchar 50) - Redundant with profile but allows asymmetry
- `dpr_height_min_cm` (int2, nullable)
- `dpr_height_max_cm` (int2, nullable)
- `dpr_relationship_goal` (varchar 50, nullable) - NULL = any
- `dpr_dealbreakers` (jsonb, nullable) - Fields where mismatch = hard filter

**Filter Logic:**
- Age range and distance are always hard filters
- Other preferences are soft (affect ranking) unless marked as dealbreakers in the JSON field

### 2.3 Match System -- STATUS: Not started

**Core reaction system** (1.4) handles the raw like/pass data. The dating plugin adds match detection.

**New Model: `dating_matches`** (plugin data model)
- `dtm_dating_match_id` (serial, primary key)
- `dtm_usr_user_id_1` (int4, FK) - Lower user ID (canonical ordering)
- `dtm_usr_user_id_2` (int4, FK) - Higher user ID
- `dtm_cnv_conversation_id` (int4, FK, nullable) - Auto-created conversation
- `dtm_matched_time` (timestamp)
- `dtm_unmatched_time` (timestamp, nullable) - If either user unmatches
- `dtm_unmatched_by_usr_user_id` (int4, nullable)
- Unique constraint on (user_id_1, user_id_2)

**Match Logic:**
1. User A likes User B (creates `rct_reactions` row with entity_type='user')
2. System checks: does User B already have a like for User A?
3. If yes: create `dating_matches` row, create conversation, send notifications to both
4. If no: just store the like, optionally notify B ("Someone new likes you" for free tier, or show who for premium)

**Unmatch:** Either user can unmatch. This soft-deletes the match, hides the conversation, and prevents future likes (or allows re-liking after a cooldown, configurable).

### 2.4 Discovery Engine -- STATUS: Not started

This is the core dating experience -- "who should I see next?"

**Discovery Logic (in `plugins/dating/logic/discover_logic.php`):**

**Filter Pipeline:**
1. Start with all active users of preferred gender
2. Exclude: already liked, already passed, blocked users, self
3. Apply hard filters: age range, max distance, dealbreakers
4. Apply soft ranking: distance (closer = higher), recently active (more recent = higher), profile completeness (more complete = higher)
5. Paginate results

**SQL Approach (using PostGIS):**
```sql
-- Core discovery query (simplified)
-- Geography lives on the address table; join through user's default address
SELECT u.*, dp.*,
  ST_Distance(a.usa_geography, ST_SetSRID(ST_MakePoint(:my_lng, :my_lat), 4326)::geography) / 1000 AS distance_km
FROM usr_users u
JOIN dtp_dating_profiles dp ON u.usr_user_id = dp.dtp_usr_user_id
JOIN usa_users_addrs a ON a.usa_usr_user_id = u.usr_user_id AND a.usa_is_default = TRUE
WHERE ST_DWithin(a.usa_geography, ST_SetSRID(ST_MakePoint(:my_lng, :my_lat), 4326)::geography, :max_distance_meters)
  AND u.usr_user_id NOT IN (SELECT rct_entity_id FROM rct_reactions WHERE rct_usr_user_id = :user_id AND rct_entity_type = 'user' AND rct_delete_time IS NULL)
  AND u.usr_user_id NOT IN (SELECT ubl_blocked_usr_user_id FROM ubl_user_blocks WHERE ubl_usr_user_id = :user_id)
  AND dp.dtp_is_active = true
  AND dp.dtp_looking_for IN (:my_gender, 'everyone')
  -- age filters on u.usr_date_of_birth
ORDER BY distance_km ASC, dp.dtp_last_active_time DESC
LIMIT 20 OFFSET :offset;
```

`ST_DWithin` uses the GiST spatial index on the address table to eliminate far-away users before computing exact distances. See **[Geolocation & PostGIS Spec](geolocation_postgis_spec.md)** for full details on PostGIS setup and distance queries.

**Views:**
- Card-based browse view (one profile at a time, swipe-style)
- Grid view option (see multiple profiles at once)
- Profile detail modal / page

### 2.5 Message Gating -- STATUS: Not started

**Dating-specific messaging rule:** Only matched users can message each other.

**Implementation:** Hook into the core messaging system. When the dating plugin is active:
- Before sending a message, check if sender and recipient have an active match
- If no match, reject with appropriate error
- Admin messages bypass this check
- This is implemented as a plugin hook/filter, not a core change

**Configurable:** Setting `dating_message_requires_match` (bool, default true). Site admin could disable this to allow open messaging.

### 2.6 Admin Verification (Simple MVP) -- STATUS: Not started

**Why MVP:** Users on dating platforms have heightened safety concerns. A "Verified" badge dramatically increases trust and engagement. The MVP version is simple and low-effort.

**MVP Approach: Manual Admin Verification**
- Add `dtp_is_verified` (bool, default false) to dating profile
- Add `dtp_verified_time` (timestamp)
- Add `dtp_verified_by_usr_user_id` (int4) - Admin who verified
- Admin can mark profiles as verified from the user admin page
- Verified badge displayed on profile cards and detail pages
- Verification criteria documented for admin team (e.g., "profile has real photo, name matches, not a duplicate")

**Not MVP:** AI selfie matching, government ID upload, video verification. These are post-launch enhancements.

### 2.7 Interest Tags -- STATUS: Not started

**Approach:** Leverage the existing **groups system** with a new category.

- Create groups with `grp_category = 'interest'`
- Users select interests during profile setup
- Interests displayed on profile
- Discovery algorithm boosts profiles with shared interests
- Admin manages available interest tags

**Seeded interests:** Music, Travel, Fitness, Cooking, Reading, Gaming, Hiking, Photography, Art, Movies, Dancing, Yoga, Sports, Food, Dogs, Cats, etc.

No new data model needed -- this uses existing `groups` + `group_members`.

---

## Part 3: MVP Scope

### What Ships First

**Core features (Phase 1):**
1. Extended User Profiles (1.1) - bio, DOB, gender, profile visibility
2. Like System (1.4) - like/pass on users
3. Block System (1.5) - block users
4. Report System (1.6) - report users with admin queue
5. Messaging Enhancements (1.7) - conversations, read status
6. Notification Center (1.2) - basic in-app notifications

**Core features deferred to Phase 2:**
- User Discovery as a standalone core feature (1.3) - in Phase 1, discovery is dating-plugin-only; generalized member directory comes later
- Notification preferences per type (1.2 partial) - Phase 1 just sends all notifications

**Dating plugin (Phase 1):**
1. Dating Profile (2.1) - dating-specific fields, prompts
2. Dating Preferences (2.2) - age/distance/gender filters
3. Match System (2.3) - mutual like = match
4. Discovery Engine (2.4) - basic filtered browse with distance sorting
5. Message Gating (2.5) - matches only
6. Admin Verification (2.6) - manual verified badge
7. Interest Tags (2.7) - via groups system

**Explicitly NOT in MVP:**
- Compatibility scoring / personality quizzes
- Super likes / boost / premium discovery features
- Activity status (online/offline indicators)
- Profile view tracking ("who viewed me")
- Typing indicators or real-time WebSocket features
- Photo moderation AI
- Icebreaker prompts in conversations
- Travel/passport mode
- Video profiles
- Speed dating events integration (though the event system is there for it later)

### MVP User Flow

1. **Register** (existing) -> **Complete Profile** (new: bio, DOB, gender, address, photos)
2. **Set Dating Preferences** (age range, distance, looking for)
3. **Discover** -> Browse profiles one at a time or in grid
4. **Like or Pass** -> Like sends to match engine
5. **Match!** -> Both liked each other -> Notification + conversation created
6. **Message** -> Chat within the conversation
7. **Block/Report** -> Safety controls available at any point

### MVP Subscription Tiers (using existing tier system)

| Feature | Free | Premium |
|---------|------|---------|
| Browse profiles | Yes | Yes |
| Likes per day | 10 | Unlimited |
| See who liked you | Blurred / count only | Full reveal |
| Messaging (with matches) | Yes | Yes |
| Distance filter max | 80km | Unlimited |
| Advanced filters | No | Yes |

---

## Part 4: Architecture

### Directory Structure

```
# Core additions (profile fields in users_class.php, geo fields in address_class.php)
data/
  notifications_class.php          # In-app notifications
  notification_preferences_class.php
  reactions_class.php              # Generic reaction system (like/favorite/bookmark/pass)
  user_blocks_class.php            # Block system
  user_reports_class.php           # Report system
  conversations_class.php          # Conversation threading
  conversation_participants_class.php

views/
  notifications.php                # Notification list page
  conversations.php                # Inbox / conversation list
  conversation.php                 # Single conversation view

logic/
  notifications_logic.php
  conversations_logic.php

adm/
  admin_reports.php                # Report moderation queue
  admin_report_view.php            # Single report detail

ajax/
  notifications_ajax.php           # Mark read, get count
  reaction_ajax.php                # Reaction toggle/status/count

# Dating plugin
plugins/dating/
  plugin.json
  serve.php                        # Plugin routes
  data/
    dating_profiles_class.php
    dating_preferences_class.php
    dating_matches_class.php
  logic/
    discover_logic.php
    dating_profile_logic.php
    match_logic.php
  views/
    discover.php                   # Browse profiles
    dating_profile_view.php        # View a single profile
    dating_profile_edit.php        # Edit dating fields
    matches.php                    # Match list
  admin/
    admin_dating_dashboard.php     # Stats and overview
    admin_dating_verification.php  # Verify profiles
    admin_dating_settings.php      # Plugin settings
  assets/
    css/
    js/
  migrations/
    migrations.php                 # Seed interest tags, default prompts
```

### Key Integration Points

**Dating plugin hooks into core:**
- `reactions` -> match detection fires on new reaction where entity_type='user'
- `conversations` -> auto-created on match
- `notifications` -> sent on match, new message, new like (if premium)
- `user_blocks` -> enforced in discovery queries
- `user_reports` -> available from profile view and conversation
- `groups` (category='interest') -> displayed on profile, used in discovery ranking
- `subscription_tiers` -> controls like limits, filter access, "see who liked you"

**No core code changes needed for:**
- Message gating (plugin middleware/hook)
- Discovery algorithm (plugin-only logic)
- Match detection (plugin-only logic triggered by like events)
- Dating profile fields (plugin data model)

### Geolocation

See **[Geolocation & PostGIS Spec](geolocation_postgis_spec.md)** for PostGIS setup, geography columns on the address table, geocoding, spatial indexing, and legacy code cleanup.

---

## Part 5: Post-MVP Roadmap

Ordered roughly by impact and user demand:

1. **Profile View Tracking** - "Who viewed me" (premium feature, drives upgrades)
2. **Activity Status** - Online/recently active indicators
3. **Compatibility Scoring** - Leverage survey system for personality matching
4. **Super Likes** - Limited per day, more for premium
5. **Boost** - Appear at top of discovery for N hours
6. **Photo Verification** - Selfie-matching or video verification
7. **Events Integration** - Speed dating, mixers using existing event system
8. **Icebreaker Prompts** - Suggested first messages based on profile
9. **Advanced Recommendation Algorithm** - ML-based matching, collaborative filtering
10. **Real-Time Features** - WebSocket for typing indicators, online status, instant messages

---

## Open Questions

1. **Gender model:** Simple 3-option (man/woman/nonbinary) or flexible (free text, multiple select)? This has significant UI and filtering implications.

2. **Photo moderation:** MVP has manual admin review via reports. See **[Pictures Refactor Spec](implemented/pictures_refactor_spec.md)** open questions for `uph_is_approved` discussion.

3. **Mobile:** Is the MVP web-only, or do we need to consider a mobile app from the start? The existing API could support a mobile client, but the discovery UX is very different on mobile vs. desktop.

4. **Like notification to free users:** Show "someone liked you" (with blur) to drive upgrades, or hide completely? The blur approach is the standard monetization play.

---

## Appendix: Related Specs

- **[Geolocation & PostGIS Spec](geolocation_postgis_spec.md)** -- PostGIS setup, geocoding, spatial indexing, legacy geo code inventory and cleanup
