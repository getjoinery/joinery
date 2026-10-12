# Boards

Kanban boards, lists and cards for clubs, nonprofits and small teams, wired into the site's members, notifications, calendar and files. One plugin, one price: no tiers, no seat limits, no board limits.

The guiding rule is **simplicity**. The card back is one flat screen with every action visible; notifications go only to people on a card; a feature that adds clutter without a strong case is not built.

## Where things live

```
plugins/boards/
├── plugin.json                  settings, signals, menus, styles, bootstrap
├── serve.php                    registers the attachment gate and the dashboard section
├── data/                        14 models (Active Record), one file per table
├── includes/
│   ├── Boards.php               roles, ordering, activity log, files, small helpers
│   ├── BoardsCards.php          everything that happens to a card, checklist or comment
│   ├── BoardsManager.php        the board itself: people, lists, labels
│   ├── BoardsPayload.php        how boards and cards are described to the browser
│   ├── BoardsNotifier.php       who is told what
│   ├── BoardsTemplates.php      starter boards, template boards, copies
│   ├── BoardsImporter.php       import from another kanban tool's JSON export
│   ├── BoardsUploads.php        attachment storage
│   ├── BoardsAttachmentGate.php content gate: a file opens for people with a role on its board
│   ├── BoardsAction.php         the wrapper every API action runs inside
│   ├── BoardsEmail.php          email-to-board and Make a card: addresses, domain, landing list, message to card
│   ├── BoardsInboundHandler.php the mailbox plugin's handler for mail sent to a member's board address
│   ├── bootstrap.php            the chunked-upload purpose
│   ├── calendar_item_sources/   BoardsCalendarItemSource
│   └── starter_boards.json      the four shipped starter boards
├── logic/                       one file per API action, plus boards_page_logic.php
├── views/profile/               the pages (auto-routed under /profile/boards)
├── admin/                       admin_boards (superadmins)
├── tasks/                       BoardsDueReminders
├── assets/js/boards.js          the app
├── assets/css/boards.css
└── tests/                       boards_model, boards_access, boards_notify, boards_import, boards_email (test-db); boards_delete (db)
```

## Data model

Tables come from `$field_specifications`; there are no migrations. Foreign-key columns are named owner prefix, then the referenced key (`bbm_bbd_board_id` is a `bbd_board_id` held on `bbm_`). Edges inside the plugin are also real database foreign keys with `ON DELETE CASCADE`, as a backstop to the PHP deletion rules.

| Class / table | What it is |
|---|---|
| `Board` / `bbd_boards` | A board: name, description, `bbd_visibility` (`invite_only` / `all_members`), `bbd_background` (a colour token, or `file:{id}` for an uploaded image), `bbd_is_template`, owner, archived and deleted times, and its email settings: `bbd_ied_inbound_email_domain_id` (the mail domain its addresses use; NULL is the default), `bbd_email_bls_list_id` and `bbd_email_position` (where a card made by email lands) |
| `BoardMember` / `bbm_board_members` | A person **or** a whole group on a board, with a role (`admin` / `editor` / `viewer`). Exactly one of `bbm_usr_user_id` and `bbm_grp_group_id`. |
| `BoardWatcher` / `bbw_board_watchers` | "Tell me about every new card on this board". Separate from membership, so it works for someone on the board only through a group. |
| `BoardList` / `bls_lists` | A column. Archived, never soft-deleted. |
| `BoardCard` / `bcd_cards` | A card. `bcd_bbd_board_id` is held on the card as well as its list, for one-query board loads and access checks; `prepare()` refuses a list from another board. `bcd_iem_message_id` is the stored mailbox message a card was made from: no database constraint across plugins, an explicit `null` deletion rule with `source_table`, and a partial unique index so one live card exists per message. |
| `BoardCardMember`, `BoardCardWatcher` | People on a card; explicit watch / unwatch (`bcw_muted`) |
| `BoardLabel`, `BoardCardLabel` | Labels (one of ten colour tokens, optional name) and their use |
| `BoardChecklist`, `BoardChecklistItem` | Checklists; an item can carry an assignee and a due date and can become a card |
| `BoardComment` / `bco_comments` | Markdown comments, soft-deleted |
| `BoardAttachment` / `bat_attachments` | A file (a gated `File`) or a link |
| `BoardActivity` / `bac_activity` | The card history **and** the change feed the poll reads |

A card moved to another board is recorded on each board as arriving or leaving, without naming the other board (the people on one may not be able to open the other).

Activity verbs: `card.created`, `card.moved`, `card.renamed`, `card.described`, `card.due_set`, `card.done`, `card.archived`, `card.restored`, `card.updated`, `member.added`, `member.removed`, `label.added`, `label.removed`, `checklist.item_done`, `comment.added`, `attachment.added`, `list.created`, `list.moved`, `list.archived`. A row whose `bac_data` carries `quiet: true` is for the poll only and is left out of the card's history list (a reorder, a checklist edit, a rename of a list); `card.updated` is the generic "something on this card changed".

### Order

Positions are fractions stored as `numeric(20,6)`. A drop between two neighbours takes the midpoint, so one drag changes one row. When the gap between two neighbours falls below `Boards::MIN_POSITION_GAP` the whole list is renumbered 1024, 2048, … in the same call. The browser sends the neighbours' ids (`prev_id`, `next_id`) rather than a position: the server reads their real positions, so a stale drag still lands beside the right cards. A neighbour that is on another list is ignored; naming only one neighbour places the card between it and whatever is really beside it.

### Deletion

- A board **soft-deletes** with all its cards stamped with one timestamp; `undelete()` brings back exactly the cards that delete took and leaves alone any deleted earlier.
- **Permanent delete** removes every row under the board and the attachment Files and background image (`Board::permanent_delete()` collects the Files first, so it works whether the rows go through the deletion rules or the database's foreign keys).
- Archive is the everyday undo for boards, lists and cards. Deleting a card needs a board admin.

## Access

One function decides everything: `Boards::roleFor(Board $board, int $user_id): ?string` returns `admin`, `editor`, `viewer` or `null`. Every API action calls `Boards::boardFor()` / `cardFor()` (which call it) before doing anything, and each model's `authenticate_read` / `authenticate_write` call it too.

Resolution order:

1. **Superadmins** (permission 10) are `admin` on every board. Site admins at 5–9 get **no** special access.
2. A **direct row** for the person.
3. The **highest role among the person's groups** on the board. Group membership is read live; only `user`-category groups count. A board admin can put on a board only groups they are in themselves (a group's name and size are site information); a site admin can offer any.
4. On an **Open to all members** board, any signed-in member is a `viewer`; joining gives `editor`.
5. Otherwise `null`: the board does not exist as far as that person is concerned. Every action answers "Board not found" (or "Card not found"), so a board's name never leaks.

| Role | Can do |
|---|---|
| Admin | Rename, archive and delete the board; manage people, labels and visibility; everything an editor can |
| Editor | Create, edit, move and archive lists and cards; comment; attach files |
| Viewer | Read the board and open attachments; watch |

An **archived** board is read-only for everyone (an admin can restore it). A board always keeps at least one admin. When someone loses access (removed, or the board narrows from Open to Invite only) `BoardsManager::reconcileAccess()` takes them off the cards, watches and checklist assignments.

"Invite only" is an access rule, not encryption: boards run at the Standard protection level and the board settings say so.

## API actions

Every action is a logic file with a `_logic_descriptor()`, called by page JS through `window.joineryApi.post('boards/<action>', body)` (browser-session credential, CSRF handled). Times are ISO-8601 UTC strings both ways.

| Action | Does |
|---|---|
| `boards_board` | Read a board page (board, role, lists, cards, labels, people, poll cursor), or with no `board_id` the board list and starters |
| `boards_card` | Read the card back; `move_targets` adds the boards and lists the person could move it to |
| `boards_poll` | Everything changed above a cursor (see below) |
| `boards_my_cards` | Every open card and assigned checklist item across boards, soonest due first |
| `boards_board_save` | `create` (blank, `starter`, or `template_id`), `update`, `archive`, `restore`, `delete`, `set_template`, `watch`, `unwatch` |
| `boards_list_save` / `boards_list_move` | Add, rename, archive, restore; drag |
| `boards_card_save` | `create`, `update`, `archive`, `restore`, `delete`, `copy`, `set_template`, `add_member`, `remove_member`, `add_label`, `remove_label`, `watch`, `unwatch`, `set_cover`, `add_link`, `remove_attachment` |
| `boards_card_move` | Drag a card, within or across lists, including to another board |
| `boards_comment_save` | `create`, `update` (own), `delete` (own, or a board admin) |
| `boards_checklist_save` | Checklists and items: `create`, `rename`, `delete`, `move`, `item_add`, `item_update`, `item_delete`, `item_move`, `item_to_card` |
| `boards_members_save` | `add_user`, `add_group`, `set_role`, `remove`, `join`, `leave`, `search_people` (names and pictures only), `search_groups`, `members` |
| `boards_label_save` | `create`, `update`, `delete`, `move` |
| `boards_upload` | Attach a multipart `file` to a card, claim a finished chunked upload (`file_id`), or set a board background (`purpose: background`) |
| `boards_import` | `preview` and `import` a board from another kanban tool's JSON export |
| `boards_email` | The board menu's email panel: `panel`, `reset` (editor), `set_domain`, `set_landing` (board admin). Not offered to Joinery AI: a person's secret address is not something an agent reads or resets. |

`boards_card_save`, `boards_card_move`, `boards_comment_save`, `boards_checklist_save` and `boards_board_save` declare `ai_agent: confirm`, so Joinery AI can draft and change cards, and every call waits in the owner's approval queue. Changes to a card or board lock its row and re-read it first, so two people changing the same card a moment apart cannot undo each other.

### The poll

`boards_poll(board_id, after_activity_id, people_stamp)` returns the activity rows above the cursor, the current state of each card they touched (and the ids that are gone), the lists and labels as they stand, the board's own fields and role, and the people when their stamp changed. The page calls it through `assets/js/joinery-poll.js` every `boards_poll_seconds` while the tab is visible. Activity ids are the cursor, so `Boards::log()` takes a transaction-scoped advisory lock per board: ids become visible in the order they were taken, and a poll never skips a row that commits late.

## Notifications

Six signals, declared in `plugin.json`. Five have `default_email: true` (a person with no saved preference still gets the email) and `supports_topic: false` (so they list on the member notification preferences page, where each can be turned off or muted); `boards.email_refused` is in-app only:

| Signal | Recipients | When |
|---|---|---|
| `boards.assigned` | the person added | added to a card or a checklist item |
| `boards.mentioned` | the person mentioned | an @mention in a comment or description |
| `boards.card_activity` | the card's watchers | a comment, a move to another list, a due date change, done, an attachment |
| `boards.card_created` | board watchers | a new card on the board |
| `boards.due_soon` | card members (or the item's assignee) | `boards_due_reminder_hours` before the due time |
| `boards.email_refused` | the member whose address it was | an email to their board address made no card: it came from an address that isn't theirs, or the board could not take it (archived, view-only, no list yet); at most one an hour |

A card's watchers are its members, anyone who commented and anyone who pressed Watch, minus anyone who pressed Unwatch, and only people who can still open the board. Nobody is told about their own action, and when one action would reach a person twice (an @mention in a comment on a card they watch) only the mention is sent. A mention counts only when the person's `@name` is in the text and they can open the board. `BoardsDueReminders` (every run) reminds once per due time: it records the due time it reminded about in `*_reminder_sent_for_due_time`, so it sends again only if the date moves. Done, archived and template cards are skipped.

## Email

Email is the mailbox plugin's machinery (handler aliases, personal addresses, reader actions; see its overview) and exists only while that plugin is active: everything checks `BoardsEmail::available()`. With it off, the board menu has no email panel, the reader has no button, and outbound notifications go on working.

**Addresses.** `plugin.json` declares one handler (`inboundHandlers.boards` → `BoardsInboundHandler`), so Boards owns one alias, `boards@<domain>`, per mail domain it uses. Each editor and admin has a secret address for each board: `InboundPersonalAddress::addressFor('boards', $user_id, 'board', $board_id, $domain_id)`, shown in the board menu's **Email to this board** panel with Copy and **Reset address**. A viewer has none. The alias is made the first time a panel needs it, not at plugin sync, so a site that never uses email gets no extra mailbox.

**Domain.** Each board has its own mail domain (`bbd_ied_inbound_email_domain_id`), chosen from `InboundHandlerRegistry::eligibleDomains()`; a board that has not chosen uses `defaultDomain()`. A board admin sees the picker when two or more domains are eligible, and also when the board's own domain has gone off and at least one other is eligible. A member's address keeps its token when the board changes domain, and one already given out keeps working because the alias at the old domain is never removed. When a board's chosen domain stops being eligible (raised to Private, or its relay is behind) the panel says email is off for the board and why; with no eligible domain at all it says what is needed.

**Email-to-board.** `BoardsInboundHandler::handle($msg, $tag)` runs the mailbox plugin's standard check: the tag resolves to a member and a board; `senderIs()` (the message is from that member's own address, and the sending domain vouches for it); then the member's account is active, they are an editor on a board that is not archived. A failing message is left stored and not acted on; a wrong sender, an archived board, a viewer role or a board with no list tells the member, once an hour in all, in the app. The card is made as that member: the subject is the title (`Re:`/`Fwd:` stripped; `#label` applies a board label and `@name` adds a board member, each only when it names exactly one thing on the board, otherwise it stays in the title), the plain body is the description without its signature, the message's attachments are adopted, `bcd_source` is `email` and `bcd_iem_message_id` points at the stored message. A board admin picks the list and whether new cards go to its top or bottom (default: the first list, at the bottom). Losing access to a board does not revoke an address: the role is read when the message arrives. Addresses are revoked by **Reset address** and when the board is permanently deleted; a deleted account's go with it.

**Make a card.** `mailboxMessageActions.boards_make_card` adds a **Make a card** button to a message in a Standard-level mailbox. `/profile/boards/from_message?message_id=N` opens the message through `MailboxMessageActions::openForAction()` (the mailbox's own access rule; a sealing mailbox is refused before anything is read), shows a picker (a list on a board the member can add cards to, and a title prefilled from the subject) and makes the card. A message that already has a card says so and links to it.

**Attachments** are adopted without copying bytes: `BoardsUploads::adoptMessageAttachments()` gives each mailbox attachment a new `File` that shares its refcounted blob and is gated on the board, so the card's people can open it and the mailbox's access rules are not what lets them. Inline images, sealed parts, blocked file types, files over `boards_max_attachment_mb` and anything past the first ten are skipped.

**The back link.** The card back shows where an email-made card came from, as that viewer may be told: a link into the reader (`/profile/mailbox/mailbox?open=N`) for someone who can open that mailbox, "From an email in a mailbox you can't open" for anyone else, and "The original email is no longer available" once the email is deleted (the card is untouched).

## Files

An attachment is a private-store `File` with `fil_source = boards_attachment` and the content gate `boards_board` pointing at the board, so anyone with a role on the board can open it and nobody else can; thumbnails, range requests and cloud offload come with the platform's file serving. A file up to the request limit arrives with `boards_upload`. A larger one travels over the platform's resumable chunked upload (`upload_init` / `upload_complete`, which work with Drive off) with purpose `boards_attachment` (registered in `includes/bootstrap.php`; opened against a board the person can edit) and is claimed onto a card with `boards_upload { card_id, file_id }`. Moving a card to another board re-gates its attachments on the new board.

## Joinery integration

- **Calendar:** `BoardsCalendarItemSource` shows not-done cards the person is on, and checklist items assigned to them, that have a due date: all-day when the due time is local midnight, timed otherwise, never blocking availability.
- **Member dashboard:** "My cards due this week" (`ProfileDashboardRegistry`).
- **Mobile apps:** the `profileMenu` entry puts Boards in the native apps as a web view.
- **Joinery AI:** works with cards through the `boards_*` actions, which declare `ai_agent` and check the board role on every call. The models are deliberately not offered as AI-queryable tables: the AI read path queries a table directly without calling `authenticate_read()`, so a site admin (5–9) could read cards on boards they cannot open, and a direct write would skip the activity log the poll depends on.
- **Admin:** `/plugins/boards/admin/admin_boards` (superadmins): every board with owner, access, card count and last activity; archive, restore, delete, undelete and remove for good (all POST buttons).

## Import

`/profile/boards/import` accepts the board JSON another kanban tool exports. It brings the name, description and background colour; lists in order (closed lists stay archived); labels (colours mapped to the nearest of ten); cards with title, description, due date, done and archived state; every checklist and its items (done state, due date, assignee); comments with their original dates; and members. People are matched to site members by email where the export carries one (the preview says only that an address matched, never who) and are then added to the board and its cards. Every comment is kept under the importer with "Originally by {name}:" in front: a name or email in a file the importer supplied proves nothing about who wrote it. Links come across as links. An uploaded file comes across as a link back to where it lives, with a note on the card: the site never fetches attachment bytes. The whole import runs in one request and sends no notifications.

## Text from other people

Descriptions and comments are Markdown. They are rendered on the server with `MarkdownRenderer::render($text, true, true)` (the restricted profile: only http, https, mailto and relative links, no images, no heading ids, external links open without the opener), so the browser can set the result as HTML. Notification titles are one line and cut to fit their column.

## Settings

| Setting | Default | |
|---|---|---|
| `boards_active` | on | Turns the app on; with it off the pages and API actions refuse |
| `boards_due_reminder_hours` | 24 | How long before it is due a card is reminded about |
| `boards_max_attachment_mb` | 25 | Per file |
| `boards_max_cards_per_board` | 5000 | A safety ceiling against runaway automation or import loops, not a plan limit |
| `boards_poll_seconds` | 5 | How often an open board asks for changes |

## Tests

`php tests/run.php test-db --filter=boards` runs `boards_model` (ordering, roles, archive, delete, cross-board moves, ceiling, mentions), `boards_access` (every action refuses a stranger and a viewer's writes; the models and the attachment gate refuse the same people), `boards_notify` (recipients per signal, preferences, watching, board watchers through a group, due reminders), `boards_import` and `boards_email` (a member's address, the domain choice, mail becoming a card through the real mailbox router, everything that must not make a card, Make a card, the back link). `php tests/run.php db --filter=boards_delete` removes a fully loaded board with the deletion rules live, and checks the three email columns clear instead of blocking (the test-db copy carries none; `boards_model` proves the same result through the foreign keys). The generic `models_crud` and `multi_models_crud` suites cover the 14 models.

## Not built yet

The second release's features (daily digest, custom fields, calendar view, repeating cards, automation rules, event planning boards) and the deferred list (guests, public links, voting, table view, dependencies, start dates, sealed boards, live push) are not scheduled; they are described in `specs/implemented/boards_plugin.md`.
