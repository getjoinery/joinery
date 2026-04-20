# Crush Match Plugin Spec

## Overview

An anonymous mutual-crush matching plugin for Joinery sites. Users register under a hashed identity, list people they have crushes on, and receive an anonymous chat connection if the crush is mutual — with no either party's identity revealed until both consent.

Primary use case: communities with recurring social contact (dance scenes, hobby groups, workplaces) where direct disclosure feels risky and the pool of people is familiar enough that names alone are recognizable.

---

## Core Flow

1. User registers with real name + alternate spellings + physical attributes + password + optional notification contact
2. User submits a crush list (minimum 5 entries) — each entry is a name; if multiple registered users share that name, the system presents a picker showing coarse attributes ("5'7, dark hair" vs "6'3, blonde") so the user can select the right person
3. The system checks for mutual matches: A listed B AND B listed A (direct user ID comparison — no fuzzy matching needed)
4. On mutual match, both users receive a notification and an anonymous chat opens
5. Either party may reveal their identity within the chat at their own discretion — no system-enforced flow

---

## Registration

### Name & Aliases

- User enters their primary display name (e.g., "Jennifer Smith")
- Optional alternate names/nicknames they go by (e.g., "Jen", "Jenny", "Jen Smith")
- All name variants are normalized before hashing:
  - Lowercase
  - Trim whitespace and punctuation
  - Collapse internal spaces
  - Strip common suffixes (Jr., Sr., III, etc.) — configurable
- Each normalized variant is stored as a separate name hash row in `crm_name_hashes`
- No plaintext name is stored anywhere in the plugin tables

### Disambiguating Attributes

To resolve name collisions (multiple "Jens" in a community), users answer a short set of physical attribute questions about themselves at registration. These are displayed — not matched against — when someone searches for a name that returns multiple results.

**Default attribute set (configurable per site):**

| Attribute | Answer type | Display example |
|---|---|---|
| Approximate height | Range bucket | "5'4\"–5'7\"" |
| Hair color | Multiple choice (dark/light/red/gray/bald/other) | "dark hair" |
| Build | Multiple choice (slim/medium/athletic/stocky) | "medium build" |
| Gender presentation | Multiple choice (masculine/feminine/androgynous/other) | "feminine" — optional, user may skip |

Attributes are stored as readable enum values (not hashed) since they are coarse and non-identifying on their own. They are used purely for display in the name disambiguation picker. The privacy risk of storing "height: 5'4–5'7, hair: dark" without a name is negligible.

### Notification Contact (Optional)

Users may optionally provide a notification contact so they don't have to poll the site. Two options:

**Option A — Email (server-encrypted):**
- Email is stored encrypted using a server-side AES key (not user-held)
- The notification email is content-free: "You have a match on [site name]. Log in to see." — no names, no identity
- The operator knows the email but the system never exposes it to other users
- Accepted tradeoff: the site operator could deanonymize in theory, but this is operationally equivalent to every major anonymous app

**Option B — PWA Push Notification (preferred):**
- No email address required
- User grants browser push permission; a subscription token is stored
- Notification content: same zero-identity message
- Works on mobile without an app store
- Spec should include service worker scaffolding

Users may also choose neither — they check the site manually.

### Password

Standard password hash (bcrypt). No plaintext stored.

### Account Linking (Optional, Future)

If the Joinery site has user accounts, an optional "link to your site account" step allows the plugin account to surface in profile or member areas. This is explicitly opt-in and out of scope for v1.

---

## Crush Entry

### Entry Form

1. User types the name of their crush as they know it
2. System looks up matching registered users by name hash (including aliases)
3. **If exactly one match:** crush entry is created immediately, no further action
4. **If multiple matches:** the system presents a picker — each candidate shown as their coarse attribute summary only, e.g.:

   > Who did you mean?
   > - 5'4–5'7, dark hair, medium build, feminine
   > - 5'8–6'0, light hair, slim, masculine

   The user selects one. No names, no photos — only the attribute summary is shown.
5. **If no match:** the name is stored as a pending crush. If that person registers later, the match engine will resolve it retroactively.

The picker shows attributes in a fixed, randomized order per session so that position doesn't imply ranking or identity.

### Minimum Crush Requirement

- Users must enter at least **5 crushes** before the system activates matching for their account
- Rationale: prevents a user from narrowing their match to 1-2 suspects by brute-forcing with a small list
- The minimum is configurable per site

### No Scoping by Proximity

The system does **not** scope matches by geography or community membership. This avoids drama in tight sub-groups (e.g., one dance studio) where a small pool would make the match obvious or awkward. The matching pool is site-wide.

### Crush Storage

Each crush entry stores:
- `crm_crusher_id` — the crush-entering user
- `crm_target_user_id` — the selected registered user (NULL if no match found yet — pending crush)
- `crm_target_name_hash` — normalized hash of the name they entered (retained for retroactive matching of pending crushes)
- `crm_created_time`
- `crm_status` — `pending` / `matched` / `expired`

No plaintext names are stored in crush entries. Once a pending crush resolves to a registered user, `crm_target_user_id` is populated and `crm_target_name_hash` is no longer needed for matching (retained for audit only).

---

## Match Detection

### Algorithm

Matching runs at crush-entry time and via a periodic scheduled task:

1. When User A enters a crush and selects User B from the picker (or the system auto-resolves to B):
   - Check if B's crush list contains a resolved entry pointing to A's user ID
   - If yes: mutual match — create match record and trigger notifications

2. When User B registers (new registration):
   - Scan all pending crush entries whose `crm_target_name_hash` matches B's registered name hashes
   - For each: if there are multiple name-hash matches across all registered users, leave the crush pending and notify the crusher to visit the site to disambiguate
   - If exactly one match (B is unambiguous): resolve the pending crush to B's user ID, then check for mutual match

3. The scheduled task handles any edge cases missed by the above (e.g., timing races, batch registrations).

### No Fuzzy Attribute Matching

Because the crusher explicitly selected a specific registered user via the picker, matching is a direct user ID comparison — no threshold or partial-match logic required. This eliminates false positives entirely.

### Match Record

`crm_matches` table:
- `crm_match_id`
- `crm_user_a_id`, `crm_user_b_id`
- `crm_matched_time`
- `crm_chat_id` — FK to the anonymous chat thread
- `crm_status` — `active` / `closed`

---

## Notifications

On mutual match:
- Both users receive their configured notification (email or PWA push)
- Message: "[Site name]: You have a mutual match! Log in to start an anonymous chat."
- No names, no match details in the notification

PWA push service worker is bundled with the plugin and registered on plugin activation.

---

## Anonymous Chat

### Chat UI

- Match triggers creation of a private chat thread
- Both users appear as "Anonymous A" and "Anonymous B" (or configurable labels like "Dancer A" / "Dancer B")
- No profile photos, no real names, no timestamps that might be deanonymizing
- Standard message input, read receipts optional (configurable — some users find them deanonymizing)

### Moderation

- Either user can **report** the chat (goes to site admin queue) — report submits the chat transcript
- Either user can **block and close** the chat immediately; the other party sees "This match is no longer active" with no explanation
- Blocked user cannot re-match with the same identity

### Chat Storage

Chat messages are stored in `crm_messages` linked to the match, not to user accounts. If a user permanently deletes their crush account, their messages in active chats are replaced with `[deleted]`.

---

## Identity Reveal

Reveal is entirely at the user's discretion within the chat. There is no system-enforced reveal flow — users simply share whatever they choose to share in conversation. The system never exposes either party's identity.

---

## Privacy Architecture

### What is stored (hashed/encrypted)

| Data | Storage | Notes |
|---|---|---|
| Real name | Hashed (SHA-256, salted per-user) | Each alias stored separately |
| Attribute answers | Readable enum values | Coarse buckets (height range, hair color category) — low re-identification risk without a name |
| Notification email | AES-256 encrypted (server key) | Only used to send match notifications |
| PWA push token | Plaintext | Non-identifying; rotates on re-subscription |
| Crush target names | Hashed | Same normalization as registration |
| Chat messages | Plaintext | Linked to match ID, not user account |

### What is never stored

- Real name in plaintext
- Email in plaintext
- Any location data
- Any link to a host Joinery site account (unless user explicitly opts in)

### Threat Model

- **Other users:** Cannot learn a match's identity without mutual consent
- **Passive observer / DB read:** Cannot recover real names from hashes; email not readable without server key
- **Site operator:** Can read notification emails (encrypted with server key) and chat messages — accepted tradeoff, disclosed in privacy policy
- **Brute force name guessing:** Salted hashes prevent rainbow table attacks; normalization ensures collisions only occur on genuinely equivalent names

---

## Admin Interface

`/admin/crush_match/`

- **Dashboard:** Active user count, pending matches, active chats, recent reports
- **Reports queue:** View flagged chat transcripts, block user accounts
- **Settings:**
  - Minimum crush count (default: 5)
  - Attribute questions (add/remove/reorder)
  - Notification method defaults (email / PWA / none)
  - Notification email template
  - Enable/disable new registrations
- **User management:** Search by partial name hash (admin enters a name, system checks if it's registered — for abuse cases), force-close matches, delete accounts

---

## Plugin Structure

```
plugins/crush_match/
  plugin.json
  data/
    crush_match_user_class.php       — registered identities
    crush_match_name_hash_class.php  — one row per name/alias hash
    crush_match_crush_class.php      — crush entries
    crush_match_match_class.php      — confirmed mutual matches
    crush_match_message_class.php    — chat messages
  logic/
    crush_match_register_logic.php
    crush_match_crush_entry_logic.php
    crush_match_chat_logic.php
    crush_match_match_engine.php     — matching algorithm
  views/
    crush_match/
      register.php
      dashboard.php         — user's active matches + crush list
      chat.php
  admin/
    admin_crush_match_dashboard.php
    admin_crush_match_reports.php
    admin_crush_match_settings.php
  includes/
    CrushMatchHasher.php    — name normalization + hashing utilities
    CrushMatchNotifier.php  — email + PWA push dispatch
  workers/
    service-worker.js       — PWA push subscription handler
  scheduled/
    run_match_engine.php    — periodic match sweep (registered as a scheduled task)
```

---

## Open Questions / Future Work

- **Account linking:** Opt-in connection to Joinery member profiles post-reveal
- **Expiry:** Should unmatched crushes expire after N months? Reduces stale data but may frustrate users
- **Multi-language name normalization:** Accented characters, non-Latin scripts
- **Duplicate detection UX:** What happens when User A tries to enter the same crush twice?
- **Age gating:** If the site serves mixed-age communities, age verification before registration
- **Crush list privacy:** Should users be able to see their own crush list after entry? (Probably yes, but carefully)
- **Match without chat:** Some users may want to confirm a match exists without opening a chat — "silent match" option

---

## Implementation Phases

**Phase 1 — Core matching**
- Registration with name hashing + attributes
- Crush entry with minimum enforcement
- Match engine (synchronous + scheduled)
- Match notification (email only)

**Phase 2 — Chat**
- Anonymous chat UI
- Report/block system
- Identity reveal flow

**Phase 3 — PWA push**
- Service worker
- Push subscription management
- Notification dispatch via push

**Phase 4 — Admin + polish**
- Full admin dashboard
- Settings UI
- Account linking (opt-in)
