# Boards Plugin Spec (kanban)

**Purpose:** A kanban-style board, list and card tool that runs inside a Joinery site. Clubs, nonprofits and small teams use it to track work. It is tied into the site's members, email, calendar and files. It is sold once at one price: there are no tiers, no seat limits and no board limits.

**Status:** IMPLEMENTED 2026-10-12: all of P1, committed and released (0.8.484; later fixes through 0.8.486), including the email features (§5.2 email-to-board, §5.5 Make a card) on the prerequisite below (Core Improvements Part 2, also built). Walked live on a relay-fronted docker site and a bare-metal site: the relay accepts a handler alias's `+tag` and refuses any other, a real Gmail message makes a card, a board that cannot take the email tells the sender, large attachments work with Drive off, the domain picker works. The results are in the live verification queue. Reply-by-email (§5.3) was dropped by the owner on 2026-10-11. P2 and the deferred list are not scheduled (owner, 2026-10-12). Still open, tracked outside this spec: the Joinery AI read scope for cards (the card models are not AI-readable until it exists) and an import picker for people who do not match a member. The plugin's developer doc is `plugins/boards/docs/overview.md`. Decisions taken while building are marked **Built as:** in the sections they touch.

**Plugin name:** `boards`. The product name is "Joinery Boards".

**Prerequisite (built):** **Core Improvements for Plugins**, Part 2 (Email handlers and personal addresses). It provides the general platform pieces that the email features in §5 are built on:

- plugin-owned email addresses;
- secret per-member addresses;
- buttons in the mailbox message reader.

The rest of Boards can be built in parallel, but §5.2 and §5.5 can't ship before it.

The same spec's Part 1 (recurrence, calendar invites, calendar de-duplication) is for the sibling plugins; Boards does not depend on it.

**Guiding rule: when in doubt, leave it out.** Reviewers keep naming simplicity as Trello's real product. Every feature here has to earn its place, and anything a small team would not miss within a month is deferred.

---

## 1. Research summary

What users value, from the most-cited to the least:

1. **Simplicity and speed.** Nontechnical people learn it in minutes. It sits "between a to-do list and full-blown project management". Capterra: 23.5k reviews, 92% positive.
2. **The visual board.** Drag-and-drop cards between lists, and everyone sees the same lanes.
3. **Card basics.** Members, due dates, labels, checklists, comments and attachments.
4. **Templates** for repeatable work.
5. **The three most-recommended Power-Ups:** Calendar, Custom Fields and Butler automation.
6. **Email-to-board** and reply-by-email. (Reply-by-email is not built: see §5.3.)

What users complain about, and our answer:

| Complaint | Our answer |
|---|---|
| The 2025 redesign hid everyday actions behind menus ("worst UI update… including Windows 8"). | The card back is one flat screen and every action is visible. The layout is a promise, not something to "refresh". |
| Basics are paywalled: Custom Fields, view-only access, guests, and a 10-collaborator cap on the free plan. | One price, everything included. |
| Notifications are "inconsistent and overwhelming". | Notifications go only to people on the card by default, and nobody is notified twice about the same thing. A daily digest follows in P2. |
| Repeating cards reuse one card, so there's no record of what was finished. | A repeating card makes a fresh card each time (P2). |
| Checklists aren't real subtasks. | Checklist items can have an assignee and a due date, and can be turned into a card. |
| There's no view across boards (Premium only in Trello). | "My cards" across all boards, and due dates on the personal calendar. |

The self-hosted alternatives each fall short somewhere:

- **Planka:** the closest look-alike, but calendar, guests and repeating cards are behind its Pro tier.
- **Wekan:** the most features, but cluttered and heavy.
- **Kanboard:** deliberately sparse, and doesn't update live.
- **Focalboard:** unmaintained.

None of them has real email integration.

Sources are listed at the end.

---

## 2. Scope

### P1 — the first sellable release

- **Boards, lists and cards:**
  - Drag and drop for cards and lists, including on touch screens.
  - Archive and restore for boards, lists and cards.
  - Move or copy a card to another list or board.
- **Card back:**
  - Title and Markdown description
  - Members
  - Labels
  - Due date with a done checkbox
  - Checklists, with optional assignee and due date per item
  - Comments with @mentions
  - Attachments, with an optional cover image
  - Activity history
  - Watch button
- **Filters:** keyword, label, member, and due state (overdue / due this week / no date). Filters are kept in the URL.
- **My cards:** every card assigned to me across all boards, grouped by due date.
- **Board and card templates.** The plugin ships four starter boards: Event planning, Volunteer tasks, Content calendar and Simple pipeline. **Built as:** the starters are definitions in `includes/starter_boards.json` laid down on a new board when chosen — no seeded rows, so there is nothing to keep in step on upgrade. A board an admin marks as a template is copied the same way (lists, labels, cards with their checklists; no people or history).
- **Keyboard shortcuts:**

  | Key | Action |
  |---|---|
  | `n` | New card |
  | `f` | Filter |
  | `space` | Add or remove me |
  | `1`–`9` | Toggle a label |
  | `c` | Archive |
  | `?` | Show the shortcut list |

- **Access:** two board visibility settings (Invite only / Open to all members), three roles, and whole groups as members (§4).
- **Email:** board address (email-to-board), notifications, due-date reminders, and "Make a card" from the mailbox reader (§5). Notifications are not replyable.
- **Joinery wiring:** personal calendar, board-only attachments, mobile apps, member dashboard and Joinery AI (§7).
- **Board import** from another kanban tool's JSON export (§8).

### P2 — next release, same price

- **Daily digest:** one email a day, instead of one per update, for members who choose it. It needs its own send record, a task and a per-user hour, and the existing notification preferences page already gives each update or off, so it waits.
- **Custom fields:** text, number, date, dropdown and checkbox, with an option to show the value on the card front.
- **Board calendar view:** a month grid where dragging a card changes its due date.
- **Repeating cards:** a template card plus a schedule produces a fresh card for each occurrence.
- **Automation rules:** short "when X, do Y" rules, edited as plain sentences.
  - Triggers: card moved to a list, label added, checklist complete, due date approaching, card created by email, a site signal fires (for example `purchase.completed`).
  - Actions: move, assign, add a label, add a checklist from a template, set a due date, mark done, archive, notify.
- **Event planning boards:** a "Planning board" button on an event creates a board from the Event planning template and links the two both ways.

### Deferred (not scheduled)

- **Clients and outside guests:** people who aren't site members, with access to one board. They need a guest account type. For P1, every board user is a site member.
- **Public read-only board link.**
- **Card voting**, using core reactions.
- **Table view.**
- **Personal inbox list.**
- **Card dependencies and a timeline/Gantt view.**
- **Map view.**
- **Card mirroring.**
- **Start dates.**
- **Sealed (encrypted) boards.** A shared board would need key grants for many people at once, as in messenger groups. P1 runs at the Standard protection level. A one-person board has no grants to manage, so a sealed personal board (Private level, for a member's own to-do list) is the cheaper first step if this is ever built. Because "Make a card" is Standard-only (§5.5), a sealed board would also need its own way to bring mail in.
- **Live push updates.** Polling is the platform's design (§7), and a few seconds' delay is fine for a board.

---

## 3. Data model

All tables come from `$field_specifications` and are created by `update_database` / plugin sync. Each model gets a Multi class. `deleted` filtering is free because each soft-deletable table has a `*_delete_time` column.

Foreign-key columns follow the platform convention of owner prefix, then the referenced key: `bbm_bbd_board_id` is a `bbd_board_id` held on `bbm_`.

| Class / table | Key columns |
|---|---|
| `Board` / `bbd_boards` | `bbd_name`, `bbd_ied_inbound_email_domain_id` (nullable: the mail domain this board's email addresses use; null means the default; added with the email features, declared `array('action' => 'null', 'source_table' => 'ied_inbound_email_domains')`), `bbd_description`, `bbd_visibility` (`invite_only` / `all_members`, shown as "Invite only" and "Open to all members"), `bbd_background` (color or image File id), `bbd_is_template`, `bbd_usr_user_id_owner`, `bbd_archived_time`, `bbd_delete_time` |
| `BoardMember` / `bbm_board_members` | `bbm_bbd_board_id`, `bbm_usr_user_id` **or** `bbm_grp_group_id` (exactly one), `bbm_role` (`admin`/`editor`/`viewer`). Unique per (board, user) and per (board, group). |
| `BoardWatcher` / `bbw_board_watchers` | `bbw_bbd_board_id`, `bbw_usr_user_id`. A row means "tell me about every new card on this board". It is separate from membership, so it works the same for someone who is on the board only through a group. Rows are removed when the user loses access. |
| `BoardList` / `bls_lists` | `bls_bbd_board_id`, `bls_name`, `bls_position` (numeric(20,6) — see Ordering), `bls_archived_time` |
| `BoardCard` / `bcd_cards` | `bcd_bls_list_id`, `bcd_bbd_board_id` (denormalized for one-query board loads and access checks), `bcd_title`, `bcd_description`, `bcd_due_time` (UTC), `bcd_is_done`, `bcd_reminder_sent_for_due_time`, `bcd_position` (numeric(20,6)), `bcd_cover_fil_file_id`, `bcd_is_template`, `bcd_source` (`web`/`email`/`import`/`ai`), `bcd_iem_message_id` (added with the email features; see below), `bcd_usr_user_id_creator`, `bcd_archived_time`, `bcd_delete_time` |
| `BoardCardMember` / `bcm_card_members` | `bcm_bcd_card_id`, `bcm_usr_user_id` |
| `BoardCardWatcher` / `bcw_card_watchers` | `bcw_bcd_card_id`, `bcw_usr_user_id`, `bcw_muted` (true means the user unwatched explicitly) |
| `BoardLabel` / `blb_labels` | `blb_bbd_board_id`, `blb_name`, `blb_color` (one of 10 fixed tokens), `blb_position` |
| `BoardCardLabel` / `bcl_card_labels` | `bcl_bcd_card_id`, `bcl_blb_label_id` |
| `BoardChecklist` / `bck_checklists` | `bck_bcd_card_id`, `bck_title`, `bck_position` |
| `BoardChecklistItem` / `bci_checklist_items` | `bci_bck_checklist_id`, `bci_text`, `bci_is_done`, `bci_usr_user_id` (nullable), `bci_due_time` (nullable), `bci_reminder_sent_for_due_time`, `bci_position` |
| `BoardComment` / `bco_comments` | `bco_bcd_card_id`, `bco_usr_user_id`, `bco_body` (Markdown), `bco_source` (`web`/`email`/`ai`), `bco_edited_time`, `bco_delete_time` |
| `BoardAttachment` / `bat_attachments` | `bat_bcd_card_id`, `bat_fil_file_id` **or** (`bat_url` and `bat_name`) for a link, `bat_usr_user_id` |
| `BoardActivity` / `bac_activity` | `bac_bbd_board_id`, `bac_bcd_card_id` (nullable), `bac_usr_user_id` (nullable for system/email), `bac_verb`, `bac_data` (jsonb), `bac_create_time` |

**Built as:** `bcd_iem_message_id`, `bbd_ied_inbound_email_domain_id` and two landing columns on the board (`bbd_email_bls_list_id`, `bbd_email_position`, which the spec's "admin picks the list and top or bottom" needs) are created with the email features. Positions, labels, checklist and item positions are `numeric(20,6)` because the platform has no float column type.

**`bcd_iem_message_id`** is a nullable int8 holding the stored mailbox message the card came from. Decided 2026-10-11: its `$foreign_key_actions` entry is `array('action' => 'null', 'source_table' => 'iem_inbound_email_messages')` and it has **no** `foreign_key` field-spec entry, so there is no database constraint across plugins. The explicit `source_table` resolves whether or not the mailbox plugin is active, and the rule only runs when a mailbox message is deleted. (Without the declaration, the column's `iem` prefix would register it as `prevent` once the mailbox plugin is active, and purging the email would be refused.) If the message is deleted, the card stays intact and its back link reads "The original email is no longer available". The column is unique among live cards (a partial unique index), so a message delivered twice cannot make two cards; the handler treats a unique violation as "already handled".

**Ordering.** Positions are fractions. A drop between two cards takes the midpoint, so one drag is one row update. When the gap between two neighbours drops below `Boards::MIN_POSITION_GAP` (1e-5, ten times the stored resolution), that list is renumbered 1024, 2048, … in the same transaction. **Built as:** the browser sends the neighbouring ids (`prev_id`, `next_id`), not a position; the server reads their real positions, so a stale drag still lands beside the right cards.

**Activity feed.** `bac_activity` serves as both the card's history and the change feed the poll action reads (§7). The P2 digest reads it too.

Verbs: `card.created`, `card.moved`, `card.renamed`, `card.described`, `card.due_set`, `card.done`, `card.archived`, `card.restored`, `card.updated` (any other change to a card; carries `quiet: true` so it feeds the poll without cluttering the history), `member.added`, `member.removed`, `label.added`, `label.removed`, `checklist.item_done`, `comment.added`, `attachment.added`, `list.created`, `list.moved`, `list.archived`.

**Deletion.** See the deletion system doc.

- Boards soft-delete. Deleting a board cascades a soft delete to its cards.
- A permanent board delete removes every child row and its attachment Files.
- Archived cards are ordinary rows with `bcd_archived_time` set. Archive is the everyday undo; delete is for admins.

**Limits.** These are settings, not tiers, and exist only to protect the server:

- `boards_max_attachment_mb` (default 25)
- `boards_max_cards_per_board` (default 5,000). This is a safety ceiling against runaway automation or import loops.

---

## 4. Access

There is one helper and one rule: `Boards::roleFor(Board $board, int $user_id): ?string`. It returns `admin`, `editor`, `viewer` or `null`. Every logic action calls `Boards::boardFor()` / `Boards::cardFor()` (which call `Boards::requireRole($board, $user_id, 'editor')`, or the needed level) before doing anything.

Resolution order:

1. **Superadmins** (permission 10) get `admin` on every board, so they can clean up abandoned boards. Other site admins (5–9) get no special access: they see a board only through the steps below, like any member.
2. **A direct user row**, if there is one.
3. **The highest role among the user's groups** on this board.
4. **On an Open to all members board, any logged-in member** gets `viewer`. Joining through the board's "Join" button gives them `editor`.
5. **Otherwise `null`.** The board is invisible, and its URLs return 404 rather than 403, so its name doesn't leak.

| Role | Can do |
|---|---|
| Admin | Rename and delete the board; manage members, labels and visibility; everything editors can do |
| Editor | Create, edit, move and archive lists and cards; comment; attach files |
| Viewer | Read the board and download attachments |

**What "Invite only" means.** It is an access rule, not encryption. Boards run at the Standard protection level, and "Private" and "Fortress" are reserved for the platform's encryption levels. The board settings page says so plainly: "Only people added to this board can open it. Superadmins can open every board."

**Group membership is live.** Someone who joins the group sees the board immediately; someone who leaves loses it. Only `user`-category groups can be added.

**Models.** Board child models override `authenticate_read` / `authenticate_write` to call the same helper, so the API surfaces and AI surface can't bypass the role check.

---

## 5. Email

This is the headline feature. The mechanics come from **Core Improvements for Plugins**, Part 2 (Email handlers and personal addresses):

- handler aliases;
- secret per-member addresses;
- reader buttons.

This section covers only what Boards does with them.

If the mailbox plugin is inactive, the inbound features (5.2, 5.5) are hidden, and outbound notifications still work. This is checked with `class_exists('InboundPersonalAddress')`.

### 5.1 The Boards address

Boards declares one handler (`"inboundHandlers": {"boards": {"class": "BoardsInboundHandler", "label": "Boards"}}`) and so owns one alias, `boards@<domain>`.

- **Choosing the domain (decided 2026-10-11: per board).** Each board has its own mail domain, so a site with several domains can keep each board's addresses on the right one (Club A's board on `clubA.org`, Club B's on `clubB.org`). The choice is one of `InboundHandlerRegistry::eligibleDomains()`; a board that has not chosen uses `defaultDomain()`. A board admin sees the picker in the board's email panel **only when two or more domains are eligible** — on a one-domain site there is nothing to choose and nothing to show. Choosing a domain calls `InboundHandlerRegistry::ensureAlias('boards', $domain)` (and `sync.php` does so for the default on activation). Members' addresses on a board keep the same token when its domain changes; one already given out at the old domain keeps working, because the alias there is never removed. There is no `boards_email_domain` setting.
- **No eligible domain.** The board's email panel says email-to-board needs the mailbox plugin with a Standard-level domain, and shows nothing else.
- **The board's domain stops being eligible** (the domain is raised to Private, or its relay is behind): the panel says email is off for this board and why, instead of showing addresses that do nothing.
- **Stored copies.** Every message to the alias is stored like any store-mode mail, so the `boards@` mailbox keeps a copy that admins can open under the usual mailbox grants.

`BoardsInboundHandler::handle($msg, $tag)` runs the standard check from the core-improvements spec:

1. `InboundPersonalAddress::resolve('boards', $tag)`.
2. `senderIs()`.
3. The board role.

The address's `ref_type` is always `board`: it is an email to the board (§5.2).

Anything that fails a check is left stored and not acted on.

**Built as (§5):** the `boards@` alias is made the first time a board's email panel needs it (or when a board admin chooses a domain), not at plugin sync, so a site that never uses email gets no extra mailbox. The panel is the board menu's **Email to this board** section, served by the `boards_email` action (not offered to Joinery AI). The sender-mismatch notice is the in-app-only signal `boards.email_refused`, sent at most once an hour per member so a leaked address cannot flood them. The card's back link opens the reader through `/profile/mailbox/mailbox?open=N`, which the mailbox reader gained for it. An `@name` shortcut matches a board member's nickname, first name, first+last name or display name, case-insensitively and ignoring spaces, dots and dashes, and only when it names exactly one member; `#label` works the same on label names.

### 5.2 Email-to-board

Every board member who is a user has a secret address for each board: `InboundPersonalAddress::addressFor('boards', $user_id, 'board', $board_id)`. The board menu's **Email to this board** panel shows it, with a copy button and a **Reset address** button. Reset revokes the address and mints a new one.

- **Who is the sender.** The address decides it: the card is created *as that member*. If `senderIs()` fails, no card is made, and the member gets one in-app notification: "An email to your board address came from an address that isn't yours, so no card was made."
- **The card:**
  - The subject becomes the title (after stripping `Fwd:`/`Fw:`).
  - The plain body becomes the description, with signatures stripped.
  - The stored message's attachment Files are adopted as board-gated card attachments (no bytes copied; `BoardsUploads::adopt()`).
  - `bcd_iem_message_id` points at the stored message.
  - `bcd_source` is `email`.
- **Where it lands.** The board admin picks the list and whether new cards go to the top or the bottom. The default is the first list, at the bottom.
- **Subject shortcuts.** `#labelname` applies a board label and `@username` adds a board member. Unknown names stay in the title.
- **Roles.** Viewers have no address; adding a card needs `editor`.

### 5.3 Reply-by-email — dropped

**Decision (owner, 2026-10-11): reply-by-email is not built.** A notification email links to the card, and the comment is written there. It carries no Reply-To, no thread headers and no per-recipient address, so Notify and the outbound email path are unchanged.

Why: it carried nearly all of the email feature's security and support risk (a forgeable `From` header guarding a secret address, reply-text extraction across every mail client, per-recipient plumbing in Notify) for a saving of one click. Email-to-board (§5.2) has no equivalent in the app and stays.

**When addresses are revoked** (board addresses only):

- When a member resets their address (the **Reset address** button).
- When a board is permanently deleted: all of its addresses.
- When the member's account is deleted (the address table's own deletion rule).

Losing access to a board does not revoke anything: the handler checks the board role and that the account is active at the moment a message arrives, so a stale address does nothing.

### 5.4 Notifications and watching

Watching follows Trello's model, but quieter by default.

**Card watchers** are:

- card members;
- anyone who commented on the card;
- anyone who clicked **Watch**;
- minus anyone who clicked **Unwatch** (`bcw_muted`).

**Board watchers** (`bbw_board_watchers`) are members who chose "Tell me about every new card" in the board menu. The default is off.

**Signals** are declared in `plugin.json`. Each has a `notify` block with `"default_email": true`, and is dispatched with `recipients`.

- `default_email` matters: without it, a targeted recipient with no saved preference gets no email at all.

| Signal | Recipients | When |
|---|---|---|
| `boards.assigned` | the user added | someone adds you to a card or to a checklist item |
| `boards.mentioned` | the mentioned user (if they're on the board) | an @mention in a comment or description |
| `boards.card_activity` | card watchers | a comment, a move to another list, a due date change, done, or an attachment |
| `boards.card_created` | board watchers | a new card on the board |
| `boards.due_soon` | card members (or the checklist item's assignee) | `boards_due_reminder_hours` before the due time (default 24) |

**Rules:**

- Nobody is notified about their own action (Notify drops `source_user_id`).
- When one action would reach the same person twice, such as an @mention inside a comment on a card they watch, the code that dispatches the signals sends only the mention. It works out both recipient lists and removes the mentioned users from the `card_activity` list before dispatching. Notify itself is unchanged.
- **Member control** is the existing notification preferences page. Each Boards signal is listed there. A member can turn its email off, or mute it entirely. Boards adds no preferences UI of its own.

**Due reminders.** The `BoardsDueReminders` task (every run) finds cards and checklist items that are not done, have a due time inside the reminder window, and where `*_reminder_sent_for_due_time` differs from the due time. For each one it dispatches `boards.due_soon` and then sets that column to the due time.

- A reminder therefore goes out once.
- It goes out again only if the due date moves.

### 5.5 Make a card from the mailbox

When the mailbox plugin is active, the message reader gains a **Make a card** button. It is a reader action from the core-improvements spec:

```json
"mailboxMessageActions": {
    "boards_make_card": { "label": "Make a card", "icon": "tasks", "url": "/profile/boards/from_message" }
}
```

- **Standard-level mailboxes only** (decided 2026-10-11). The button is hidden on messages in Private and Fortress mailboxes, and the picker page refuses a sealing mailbox before reading anything: copying decrypted mail into a Standard-level card would break the level's promise.
- **The picker page.** It reads the message through `MailboxViewer::canReadMessage()` and shows a small picker: board, list, and a title prefilled from the subject. If the user isn't an editor on any board, it says so.
- **What the card gets:**
  - the message's plain body as the description, plus a link back to the message;
  - `bcd_iem_message_id` set;
  - the message's attachments, adopted as board-gated card attachments without copying bytes (`BoardsUploads::adopt()`, which calls `File::createFromExistingBlob()` and attaches the new File).
- **Who can see what.** The card's back link opens the message only for people who can already read that mailbox, under the existing mailbox access grants. Anyone else sees "From an email in a mailbox you can't open".

---

## 6. Boundary with the shared inbox

Boards turns email into **work for site members**, and that's all. It never talks to people outside the site:

- No public intake address. Only board members' secret addresses create cards.
- No replying to an outside sender from a card.
- No customer conversation threads on cards.

A team answering outside mail (`help@`, `info@`) is the mailbox group-collaboration spec's job. Sending identities, signatures and threads already live in the mailbox. Building a help desk into Boards would cost the simplicity that is Boards' whole appeal.

The two meet only at **Make a card** (§5.5): an email in a shared mailbox can become a task on a board, with a link back to the email.

---

## 7. Joinery integration

| System | How Boards uses it |
|---|---|
| **API** | Every action is a logic action with a `_logic_descriptor()`, exposed at `/api/v1/action/boards/{action}` and called from page JS through the session + CSRF client. No `/ajax/` endpoints. Actions: `boards_board`, `boards_card`, `boards_card_save`, `boards_card_move`, `boards_list_save`, `boards_list_move`, `boards_comment_save`, `boards_checklist_save`, `boards_members_save`, `boards_upload`, `boards_poll`, `boards_my_cards`, `boards_import`, `boards_email_panel`, `boards_make_card_from_message`. **Built as:** also `boards_board_save` (create, update, archive, restore, delete, template, watch) and `boards_label_save`; `boards_email_panel` and `boards_make_card_from_message` wait for §5. |
| **Live updates** | `boards_poll(board_id, after_activity_id)` returns the activity rows above the cursor, plus the current state of each card and list they touch. `joinery-poll.js` calls it every `boards_poll_seconds` (default 5) while the board is visible, and stops while the tab is hidden. This is modeled on `messenger_poll`. |
| **Files** | Attachments are `File` rows with `fil_private` set and the access provider `boards_board` and the board id as the ref. `BoardsAttachmentGate implements AccessGateProvider` answers "has any role on that board", copying `MessengerAttachmentGate`. Uploads go through an `UploadPurposeRegistry` purpose registered in the bootstrap. Thumbnails, range requests and cloud offload come for free. |
| **Calendar** | `BoardsCalendarItemSource` in `includes/calendar_item_sources/` projects not-done cards with a due date where the subject is a card member, plus checklist items assigned to them. They appear as all-day items when the time is midnight local, timed otherwise, and link to the card. The calendar's own summaries and the native apps pick them up automatically. |
| **Notifications** | Core Notify, as described in §5.4. |
| **Groups** | Groups can be board members (§4). The @mention picker uses the board's resolved member list. |
| **Member dashboard** | A `ProfileDashboardRegistry` section, "My cards due this week". |
| **Mobile apps** | A `profileMenu` entry (`/profile/boards`, icon `tasks`, an existing icon) puts Boards in the iOS and Android apps as a web view. Drag and drop must work with touch, using pointer events. |
| **Joinery AI** | **Built as:** the models are NOT AI-queryable (`$ai_readable` / `$ai_writable_fields` omitted) — the AI read path never calls `authenticate_read()`, so it would let a site admin (5–9) read boards they cannot open; the `boards_*` actions declare `ai_agent: confirm` instead. Re-enabling reads needs a row-scope hook in joinery_ai. Original text: `BoardCard` and `BoardComment` set `$ai_readable`, with `bco_body` and `bcd_description` in `$ai_untrusted_fields`, because email-born text came from outside. `$ai_writable_fields` on `BoardCard`: title, description, due time, list. Every AI write goes through the existing approval queue. AI then gets "summarize this board" and "draft cards from this email thread" for free. |
| **Admin** | One admin page, `/plugins/boards/admin/admin_boards`, for superadmins only (permission 10): every board with owner, member count, card count and last activity; open, archive, restore and delete (all POST buttons); and the plugin settings. |

**Pages** (all under `/profile/boards`, auto-routed from `views/profile/`):

- `/profile/boards`: the board list. Shows my boards, templates and boards open to all members, plus **New board** (blank or from a template).
- `/profile/boards/board?id=N`: the board. The card back opens as a modal over it, with the URL `…&card=M`, so a card link opens the card directly.
- `/profile/boards/my_cards`
- `/profile/boards/import`
- `/profile/boards/from_message?message_id=N`: the "Make a card" picker (§5.5).

**Front end.** Vanilla JS and CSS, using the `.jy-ui` kit and no frameworks. One `assets/js/boards.js` and one `assets/css/boards.css`. The card back is a single column of sections, with all actions in a fixed sidebar of labelled buttons: Members, Labels, Due date, Checklist, Attach, Cover, Move, Copy, Archive. Nothing is hidden behind a "…" menu.

**Forms.** Text fields on the card back (title, description, comments) save through API actions as part of an interactive app, not through page forms. The board settings and import screens are ordinary FormWriter forms.

---

## 8. Board import

`/profile/boards/import` accepts Trello's board JSON export (Board menu → Print, export and share → Export as JSON).

**What comes across:**

- the board name and background color;
- lists in order, including archived lists, kept archived;
- labels with names and colors (mapped to the nearest of our 10);
- cards with title, description, due date, done state, position and archived state;
- **all** checklists and their items, including done state;
- comments;
- members.

**Member matching.**

- Trello members are matched to site users by email address. The export only includes emails Trello chose to share, so the import screen also lists each unmatched Trello member with a picker to choose a site user or "skip".
- Comments by unmatched people are kept, with the text "Originally by {Trello full name}:" in front.

**Attachments.**

- Link attachments come across as links.
- Uploaded Trello attachments come across as **links back to Trello**, with a note on the card: "Attachment kept in Trello: download it there and attach it here if you still need it."
- The site never fetches attachment bytes. Trello serves uploaded attachments only to authenticated requests, and fetching user-supplied URLs from the server would add a server-side request forgery risk and a background job to manage.
- With nothing to download, the import runs inline in one request.

**Not imported:** Power-Up data, custom fields (until P2, then mapped), and Butler rules.

---

## 9. Packaging and sale

- `plugin.json`: `"license": "Joinery-Commercial"`, `"requires_entitlement": true`, and `"status": "beta"` until the full P1 checklist below passes.
- Sold as one store product with perpetual updates, through the existing ownership / license-key path.
- **One-line pitch:** *Kanban simplicity, every feature included, no per-seat pricing, on your own site and wired into your email.*

---

## 10. Testing

Each item below is a test. Every test uses the shared harness and an `@joinery-test` header.

**`plugins/boards/tests/boards_model_test.php`** (test-db):

- Position midpoint and renumbering.
- Role resolution: direct user, group, superadmin, an admin below 10 with no override, an Open to all members board, and no access (404).
- Archive and restore.
- Permanent-delete cascade.

**`plugins/boards/tests/boards_access_test.php`** (test-db):

- Every API action refuses a viewer's writes and a stranger's reads.
- The attachment gate allows board roles and refuses others.

**`plugins/boards/tests/boards_email_test.php`** (test-db):

- Email-to-board creates a card as the address's user.
- A `From` mismatch makes no card and notifies the user, and the message stays stored.
- A reset board address stops working.
- A revoked address, a viewer, or an empty message adds nothing.

**`plugins/boards/tests/boards_notify_test.php`** (test-db):

- Recipients for each signal are correct.
- A recipient with no saved preference gets email (`default_email`).
- `mentioned` suppresses `card_activity` for the same person.
- A due reminder goes out once, and again only after the due date moves.
- Board watchers who gain access through a group get `card_created`, and stop when they leave the group.

**`plugins/boards/tests/boards_import_test.php`** (test-db): imports a fixture board export with several checklists per card, archived lists and unmatched members.

The mailbox and core tests for the shared email pieces belong to the core-improvements spec.

**Live walk:**

- Create a board from a template, drag cards, and assign yourself.
- Receive a notification at the dev inbound domain and follow its link to the card.
- Forward an email to the board address and see the card.
- Do this on a phone width as well.

---

## 11. Decisions

- **Board users are site members only** (owner, 2026-10-10). Clients and outside guests are a deferred item (§2).
- **Boards and the shared inbox stay separate** (owner, 2026-10-10). See §6.
- **Visibility is named "Invite only" / "Open to all members"** (owner, 2026-10-10). "Private" and "Fortress" are reserved for the encryption levels.
- **Only superadmins (10) can open every board** (owner, 2026-10-10). Admins at 5–9 get no override.
- **Simplicity wins ties** (owner, 2026-10-10). A feature that adds clutter without a strong case is deferred.
- **Reply-by-email is dropped** (owner, 2026-10-11). See §5.3. Email-to-board and Make a card stay.

---

## Sources

- Capterra, Trello reviews: https://www.capterra.com/p/211559/Trello/reviews/
- The Register, "Trello redesign may be 'worst in tech history'" (2025-08-06): https://www.theregister.com/2025/08/06/trello_redesign_as_bad_as/
- Trello Substack, "Is it time to leave Trello?" with comments: https://trello.substack.com/p/is-it-time-to-leave-trello/comments
- Atlassian community, card-back redesign feedback: https://community.atlassian.com/forums/Trello-questions/Feedback-on-Card-Back-Redesign-Loss-of-efficiency-and/qaq-p/3262304
- Atlassian support, creating cards by email: https://support.atlassian.com/trello/docs/creating-cards-by-email/
- Atlassian community, feature requests (recurring, dependencies, subtasks): https://community.atlassian.com/forums/Trello-questions/How-do-I-make-due-dates-quot-cascade-quot-on-a-card/qaq-p/1385506
- Repeating Tasks for Trello (Product Hunt): https://www.producthunt.com/p/repeating-tasks-for-trello/repeating-tasks-for-trello
- Best Power-Ups roundup (Computerworld): https://www.computerworld.com/article/3432546/10-trello-power-ups-for-maximum-productivity.html
- Trello pricing 2026 (Activepieces): https://www.activepieces.com/blog/trello-pricing-2026-plans-costs-and-hidden-fees
- Self-hosted alternatives compared (SSD Nodes, 2026-08): https://www.ssdnodes.com/learn/self-hosted-trello-alternatives
- Planka vs Wekan (DEV): https://dev.to/selfhostingsh/planka-vs-wekan-which-kanban-board-to-self-host-44h7
- Trello limitations review (Software Advice): https://www.softwareadvice.com/ca/project-management/trello-profile/reviews/
