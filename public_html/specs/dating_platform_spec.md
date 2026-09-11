# Dating Platform Spec: Core Extensions + Dating Plugin

**Purpose:** Define what's needed to build a dating site on Joinery, separated into reusable core platform features vs. dating-specific plugin features. MVP-focused.

---

## Design Principle: Core vs. Plugin

The guiding question: *"Would a non-dating platform also benefit from this feature?"*

- **Core** = Features useful to membership sites, marketplaces, professional networks, community platforms, etc.
- **Plugin** = Features that only make sense in a dating context.

This separation means the core work benefits every Joinery site, and the dating plugin is a relatively thin layer on top.

---

## Part 1: Core Platform Features

**Already implemented:** Extended User Profiles (bio/DOB/gender/visibility fields on `usr_users`), Notification Center (`specs/implemented/notification_center_spec.md`), Reaction System (`specs/implemented/reaction_system_spec.md`), Messaging Enhancements (`specs/implemented/messaging_enhancements_spec.md`), Pictures Refactor (`EntityPhoto`, `eph_entity_photos`).

What remains:

### 1.3 User Discovery / Member Directory

**Problem:** No way to browse or search other users. Membership orgs, professional networks, and community platforms all need a member directory.

**Features:**
- Browse users with filters (location, groups, custom fields)
- Search by name or keyword
- Paginated grid or list view
- Respects `profile_visibility` settings
- Respects block list
- Configurable: admin can enable/disable directory, choose which fields are filterable

**New Settings:**
- `member_directory_active` (bool) — feature toggle
- `member_directory_requires_login` (bool, default true)
- `member_directory_fields` (json) — which profile fields appear as filters

**Geolocation Support:** See **[Geolocation & PostGIS Spec](geolocation_postgis_spec.md)** for PostGIS setup, geocoding, spatial indexing on the address table, and distance queries.

### 1.5 Block System

**Problem:** Any platform where users interact needs the ability to block other users. Blocked users can't see your profile, message you, or appear in your results.

**New Model: `ubl_user_blocks`**
- `ubl_user_block_id` (serial, primary key)
- `ubl_usr_user_id` (int4, FK) — user doing the blocking
- `ubl_blocked_usr_user_id` (int4, FK) — blocked user
- `ubl_reason` (varchar 255, nullable)
- `ubl_create_time` (timestamp)
- Unique constraint on (user_id, blocked_user_id)

**Enforcement:** Block checks applied in:
- Member directory queries
- Message sending (reject if blocked)
- Profile viewing (404 or "user not found")
- Notification generation (suppress)

### 1.6 Report System

**Problem:** Any platform with user-generated content or user interaction needs content/user reporting and admin moderation.

**New Model: `urp_user_reports`**
- `urp_user_report_id` (serial, primary key)
- `urp_usr_user_id_reporter` (int4, FK) — who reported
- `urp_target_type` (varchar 50) — `user`, `message`, `post`, `photo`, etc.
- `urp_target_id` (int4) — ID of reported entity
- `urp_reason` (varchar 50) — `harassment`, `fake_profile`, `inappropriate_content`, `spam`, `other`
- `urp_details` (text, nullable) — free-form explanation
- `urp_status` (varchar 20, default `pending`) — `pending`, `reviewed`, `actioned`, `dismissed`
- `urp_admin_notes` (text, nullable)
- `urp_resolved_by_usr_user_id` (int4, nullable)
- `urp_create_time` / `urp_resolved_time` (timestamps)

**Admin Interface:**
- Report queue page with filtering by status and type
- Action buttons: dismiss, warn user, disable user, permanent ban
- Report statistics dashboard

---

## Part 2: Dating Plugin

Built as a standard Joinery plugin at `plugins/dating/`. All sections below are not started.

### 2.1 Dating Profile

**New Model: `dtp_dating_profiles`**
- `dtp_dating_profile_id` (serial, primary key)
- `dtp_usr_user_id` (int4, FK, unique) — one dating profile per user
- `dtp_looking_for` (varchar 50) — `men`, `women`, `everyone`
- `dtp_relationship_goal` (varchar 50) — `casual`, `long_term`, `marriage`, `friends`, `not_sure`
- `dtp_height_cm` (int2, nullable)
- `dtp_smoking` (varchar 20, nullable) — `never`, `sometimes`, `regularly`
- `dtp_drinking` (varchar 20, nullable) — `never`, `socially`, `regularly`
- `dtp_children` (varchar 30, nullable) — `no_children`, `have_children`, `want_children`, `dont_want`, `open_to_children`
- `dtp_education` (varchar 30, nullable) — `high_school`, `some_college`, `bachelors`, `masters`, `doctorate`, `trade_school`
- `dtp_occupation` (varchar 100, nullable)
- `dtp_prompts` (jsonb, nullable) — array of `{prompt_id, answer}` for conversation starter prompts
- `dtp_is_active` (bool, default true)
- `dtp_last_active_time` (timestamp)
- `dtp_create_time` / `dtp_update_time` / `dtp_delete_time` (timestamps)

**Profile Prompts System:** Users pick 3 prompts from a site-configured list ("A perfect first date for me is...", "I'm looking for someone who...", etc.) and write short answers. Stored as JSON in `dtp_prompts`. Admin manages available prompts.

### 2.2 Dating Preferences / Dealbreakers

**New Model: `dpr_dating_preferences`**
- `dpr_dating_preference_id` (serial, primary key)
- `dpr_usr_user_id` (int4, FK, unique)
- `dpr_age_min` (int2, default 18)
- `dpr_age_max` (int2, default 99)
- `dpr_distance_max_km` (int4, default 80) — ~50 miles
- `dpr_looking_for` (varchar 50)
- `dpr_height_min_cm` (int2, nullable)
- `dpr_height_max_cm` (int2, nullable)
- `dpr_relationship_goal` (varchar 50, nullable) — NULL = any
- `dpr_dealbreakers` (jsonb, nullable) — fields where mismatch = hard filter

**Filter Logic:** Age range and distance are always hard filters. Other preferences are soft (affect ranking) unless marked as dealbreakers in the JSON field.

### 2.3 Match System

The core reaction system handles raw like/pass data. The dating plugin adds match detection on top.

**New Model: `dtm_dating_matches`**
- `dtm_dating_match_id` (serial, primary key)
- `dtm_usr_user_id_1` (int4, FK) — lower user ID (canonical ordering)
- `dtm_usr_user_id_2` (int4, FK) — higher user ID
- `dtm_cnv_conversation_id` (int4, FK, nullable) — auto-created conversation
- `dtm_matched_time` (timestamp)
- `dtm_unmatched_time` (timestamp, nullable)
- `dtm_unmatched_by_usr_user_id` (int4, nullable)
- Unique constraint on (user_id_1, user_id_2)

**Match Logic:**
1. User A likes User B (creates `rct_reactions` row with `entity_type='user'`)
2. Check if User B already has a like for User A
3. If yes: create `dtm_dating_matches` row, create conversation, notify both
4. If no: store the like; optionally notify B ("someone new likes you" for free tier, full reveal for premium)

**Unmatch:** Soft-deletes the match, hides the conversation, prevents future likes (or allows re-liking after a configurable cooldown).

### 2.4 Discovery Engine

**Discovery Logic (`plugins/dating/logic/discover_logic.php`):**

Filter pipeline:
1. Start with all active users of preferred gender
2. Exclude: already liked, already passed, blocked users, self
3. Apply hard filters: age range, max distance, dealbreakers
4. Apply soft ranking: distance (closer first), recently active (more recent first), profile completeness
5. Paginate results

**SQL Approach (using PostGIS):**
```sql
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
ORDER BY distance_km ASC, dp.dtp_last_active_time DESC
LIMIT 20 OFFSET :offset;
```

`ST_DWithin` uses the GiST spatial index on the address table to eliminate far-away users before computing exact distances. See **[Geolocation & PostGIS Spec](geolocation_postgis_spec.md)**.

**Views:**
- Card-based browse view (one profile at a time, swipe-style)
- Grid view option
- Profile detail modal / page

### 2.5 Message Gating

Only matched users can message each other when the dating plugin is active.

**Implementation:** Plugin hook/filter on message send. Before sending, check if sender and recipient have an active match; reject with an appropriate error if not. Admin messages bypass this check. No core code changes required.

**Setting:** `dating_message_requires_match` (bool, default true) — admin can disable to allow open messaging.

### 2.6 Admin Verification (MVP)

**MVP: Manual Admin Verification**
- Add `dtp_is_verified` (bool, default false), `dtp_verified_time` (timestamp), `dtp_verified_by_usr_user_id` (int4) to the dating profile
- Admin marks profiles verified from the user admin page
- Verified badge displayed on profile cards and detail pages

Post-MVP: AI selfie matching, government ID upload, video verification.

### 2.7 Interest Tags

Uses the existing groups system with a new category:
- Create groups with `grp_category = 'interest'`
- Users select interests during profile setup
- Interests displayed on profile
- Discovery algorithm boosts profiles with shared interests
- Admin manages available interest tags

No new data model needed — uses existing `groups` + `group_members`.

---

## Part 3: MVP Scope

### Remaining Core Work (Phase 1)
- Block System (1.5)
- Report System (1.6)

### Core Deferred to Phase 2
- User Discovery as a standalone member directory (1.3) — in Phase 1, discovery is dating-plugin-only; the generalized directory comes later

### Dating Plugin (Phase 1)
1. Dating Profile (2.1)
2. Dating Preferences (2.2)
3. Match System (2.3)
4. Discovery Engine (2.4)
5. Message Gating (2.5)
6. Admin Verification (2.6)
7. Interest Tags (2.7)

### Explicitly NOT in MVP
- Compatibility scoring / personality quizzes
- Super likes / boost / premium discovery features
- Activity status (online/offline indicators)
- Profile view tracking ("who viewed me")
- Typing indicators or real-time WebSocket features
- Photo moderation AI
- Icebreaker prompts in conversations
- Travel/passport mode
- Video profiles
- Speed dating events integration

### MVP User Flow

1. **Register** → **Complete Profile** (bio, DOB, gender, address, photos)
2. **Set Dating Preferences** (age range, distance, looking for)
3. **Discover** → browse profiles one at a time or in grid
4. **Like or Pass** → like sends to match engine
5. **Match** → both liked each other → notification + conversation created
6. **Message** → chat within the conversation
7. **Block/Report** → safety controls available at any point

### MVP Subscription Tiers

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

### Files to Create

```
# Core additions
data/
  user_blocks_class.php            # Block system
  user_reports_class.php           # Report system

adm/
  admin_reports.php                # Report moderation queue
  admin_report_view.php            # Single report detail

# Dating plugin
plugins/dating/
  plugin.json
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
- `rct_reactions` → match detection fires on new reaction where `entity_type='user'`
- `conversations` → auto-created on match
- `notifications` → sent on match, new message, new like (if premium)
- `ubl_user_blocks` → enforced in discovery queries
- `urp_user_reports` → available from profile view and conversation
- `groups` (category=`interest`) → displayed on profile, used in discovery ranking
- `subscription_tiers` → controls like limits, filter access, "see who liked you"

**No core code changes needed for:**
- Message gating (plugin middleware/hook)
- Discovery algorithm (plugin-only logic)
- Match detection (plugin-only logic triggered by like events)
- Dating profile fields (plugin data model)

### Geolocation

See **[Geolocation & PostGIS Spec](geolocation_postgis_spec.md)** for PostGIS setup, geography columns on the address table, geocoding, spatial indexing, and legacy code cleanup.

---

## Part 5: Post-MVP Roadmap

1. **Profile View Tracking** — "who viewed me" (premium feature, drives upgrades)
2. **Activity Status** — online/recently active indicators
3. **Compatibility Scoring** — leverage survey system for personality matching
4. **Super Likes** — limited per day, more for premium
5. **Boost** — appear at top of discovery for N hours
6. **Photo Verification** — selfie-matching or video verification
7. **Events Integration** — speed dating, mixers using existing event system
8. **Icebreaker Prompts** — suggested first messages based on profile
9. **Advanced Recommendation Algorithm** — ML-based matching, collaborative filtering
10. **Real-Time Features** — WebSocket for typing indicators, online status, instant messages

---

## Open Questions

1. **Gender model:** Simple 3-option (man/woman/nonbinary) or flexible (free text, multiple select)? This has significant UI and filtering implications.

2. **Mobile:** Is the MVP web-only, or do we need to consider a mobile app from the start? The existing API could support a mobile client, but the discovery UX is very different on mobile vs. desktop.

3. **Like notification to free users:** Show "someone liked you" (with blur) to drive upgrades, or hide completely? The blur approach is the standard monetization play.

---

## Related Specs

- **[Geolocation & PostGIS Spec](geolocation_postgis_spec.md)** — PostGIS setup, geocoding, spatial indexing, legacy geo code inventory and cleanup
- **[Reaction System](implemented/reaction_system_spec.md)** — polymorphic like/pass/favorite system (implemented)
- **[Messaging Enhancements](implemented/messaging_enhancements_spec.md)** — conversation threading, inbox UI (implemented)
- **[Notification Center](implemented/notification_center_spec.md)** — in-app notification system (implemented)
