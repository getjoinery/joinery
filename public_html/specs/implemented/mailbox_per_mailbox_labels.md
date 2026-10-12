# Mailbox Labels Belong to a Mailbox

**Purpose:** Make each mailbox's labels its own. Only that mailbox's members see them, deleting one affects only that mailbox, and two mailboxes can each have a "Receipts".

**Last Updated:** 2026-10-12

**Status:** Built 2026-10-12 (mailbox 1.141.0, migration `ilb_001_labels_belong_to_a_mailbox`). The release-upgrade and browser checks are in the live verification queue. Owner decision J4, raised by the mail apps Thunderbird run (`specs/mail_apps_plugin.md` § Live Thunderbird re-run).

What was built and checked:
- The migration ran on dev: "Meditation" became 3 labels, one per mailbox. It also cleared 1 dangling folder binding and 42 filter labels pointing at a missing label.
- `per_mailbox_labels_test` passes (28 checks).
- Every mailbox and mail apps suite passes (142 tests).

Note:
- The rule enforcement in `InboundLabelMember::apply()` goes further than this spec planned (`setMembership()` alone): every apply path gets it.

## The problem

A label today is one row in a site-wide list, and its name is unique across the whole site (`ilb_inbound_email_labels.ilb_name`). The labels spec chose this so a domain-wide filter could apply a label (`specs/implemented/inbound_email_labels.md`). The same spec also said the reader's label scope is the active mailbox, and that was never enforced. On a site with several members:

- A mailbox with no connected source lists every label on the site. A new mailbox showed another member's "Meditation", in the web reader, the mobile apps and Thunderbird.
- Deleting a label (`MailboxService::deleteLabel()`) strips it from every mailbox's messages and unbinds every account's folder bound to it. Any member with access to any mailbox can do it.
- Two members who each create "Receipts" share one label.
- A label id from a request is checked for existence only, never for whose it is:
  - `set_membership` and `delete_label` in `thread_action`;
  - `folder_id` in `thread_list`;
  - a filter's label;
  - mail apps' `label:<id>` folder key.

Messages themselves never leak, because every message query is scoped by mailbox access. What leaks is label names, and the power to change other members' labels.

## The rule

**A label belongs to exactly one mailbox (`ilb_iea_inbound_email_alias_id`). Its name is unique within that mailbox. A message carries only labels of its own mailbox.**

Everything below follows from that rule.

## Schema

- `ilb_iea_inbound_email_alias_id` int8, the owning mailbox. It is nullable in the declaration only so that existing rows can be filled in by the migration below. Every write path sets it.
- **The name moves to a new column `ilb_label_name`** varchar(255), not null, with `unique_with => ilb_iea_inbound_email_alias_id`. `ilb_name` is retired (its declaration is removed).
  - Why a new column: the updater only ever adds uniqueness rules, never drops them, so changing `ilb_name` in place would leave its site-wide rule enforcing (memory `reference_unique_with_never_dropped`).
  - The retired `ilb_name` keeps its old unique rule, but new rows leave it NULL, and NULLs never collide. Nothing reads it after the migration. It goes on a later cleanup pass like any retired column.
- Foreign key action: a mailbox's deletion takes its labels with it (`cascade`; `ilm_` membership rows go through `InboundEmailLabel::softDelete()` as today).

## The primitives (`InboundEmailLabel`)

- `getByName(int $alias_id, string $name)` and `findOrCreate(int $alias_id, string $name)`. The site-wide forms are removed, not kept beside them, so a missed caller fails loudly.
- `belongsTo(int $alias_id): bool`.
- `MultiInboundEmailLabel` gains an `alias_id` option.

## Every place that changes

**Creating and finding by name** (all become (mailbox, name)):
- `MailboxService::createFolder()`, which is reached from the reader, the mobile apps and mail apps.
- `InboundImapFolder::ensureLabel()`, using the account's mailbox. It also re-mints the label when the bound label is deleted, missing, or another mailbox's, instead of returning a stale id (dev has one such binding today).
- `MailArchiveImporter::applyLabels()` and `undo()`, using the run's mailbox.
- `mailbox_filters_logic.php`:
  - `_filter_resolve_label()`, for "Create new label…";
  - `_filter_import_confirm()`, the Gmail filter import.
- The iOS phase-3 fixture and gate (`tests/functional/ios/phase3_fixtures.php`, `phase3_gate.sh`).

**Listing** (only the mailbox's own):
- `MailboxService::mailboxFolderInfo()`, the no-feed branch.
- `_filter_label_options()`, which receives the filter's scope and today ignores it.

**Ids from requests** (refuse a label that is not the request's mailbox's, with the same answer as an unknown one):
- `MailboxService::setMembership()`. Each message is changed only when the label belongs to that message's mailbox. This is the one check that covers the reader, both mobile apps, mail apps and filters alike.
- `MailboxService::deleteLabel()`. Only the mailbox's own label, and only that mailbox's folder bindings.
- `MailboxService::listThreads()` (`folder_id`). A label from another mailbox lists nothing.
- `_filter_resolve_label()`, which today stores any integer it is given.
- `MailAppFolders::byKey()`, for `label:<id>`.
- `InboundEmailFilter::applyActionSet()`. A filter's label is applied only when it belongs to the message's mailbox.

**Filters:**
- **A domain-wide filter cannot apply a label**, since it has no single owning mailbox. This was the filters spec's own lean (`specs/implemented/inbound_email_filters.md` § Open decisions). For a domain scope, the panel shows the "Apply the label" control **disabled, with a line saying why** (a label belongs to one mailbox; make the filter for a single mailbox to label). The save refuses it too.
- A Gmail import into a domain scope keeps a filter's other actions and drops its labels, saying so in the import summary.
- None exist with a label today (0 on dev).

**IMAP feeds:**
- Re-pointing an account to another mailbox, in `admin_mailbox_imap_edit_logic.php` and `ImapFeedProvisioner`, clears its folders' label bindings. `ensureLabel()` then mints them for the new mailbox on the next sync.
- Messages already stored keep the labels of the mailbox they belong to.

**Read paths that need no change once the rule holds:**
- `threadFolderIds()`, `labelIdsByMessage()` and `MailboxMessageTimeline::currentLabels()`. They read a message's own labels, which are now always its mailbox's.
- `labelSql()` stays inside the surrounding mailbox scope.
- `PromotedRowRepair` moves memberships between two rows of the same mailbox.

**Native apps:** no change. The API shape is the same: the same `folders` with `{id, name, role}` and the same parameters. The list a mailbox returns gets shorter, and a foreign id is refused.
- In the all-mailboxes view, iOS and Android send no mailbox with `set_membership`, and `setMembership()` labels only the conversation's messages in the label's own mailbox.
- With a mailbox selected, they list only that mailbox's conversations.
- `create_folder` already sends the conversation's mailbox.

The web reader's conversation label control did send the list's mailbox (`state.aliasId`), which can differ in the all-mailboxes view. It now sends the conversation's (`mailbox_reader.js`).

## Existing labels: the migration

The migration is a data migration in mailbox's `migrations.php`. It is guarded on `ilb_label_name` existing, and is idempotent. For each live label:
1. **Its mailboxes** are those of its member messages, the accounts of its bound folders, and its per-mailbox filters.
2. **None:** soft-delete the label (it shows nowhere today).
3. **One:** set `ilb_iea_inbound_email_alias_id` and copy `ilb_name` into `ilb_label_name`.
4. **More than one:** the mailbox with the most members keeps the row. Every other mailbox gets its own copy under the same name. That mailbox's memberships, folder bindings and filters move to the copy.

The migration also cleans up dangling references:
- A folder binding to a missing label is cleared, so `ensureLabel()` re-mints it.
- A filter whose label is missing loses that action and keeps its others.

Dev today has one label ("Meditation", 6 messages across 3 mailboxes, so it becomes 3 labels), one folder binding to a missing label, and 42 filters pointing at a missing label.

**Mail apps** folder keys (`label:<id>` in `mau_`/`maf_`): a label that splits keeps its id for the mailbox that keeps the row. The other mailboxes' copies are new folders to their programs, which see the old one disappear. There are 0 such rows on dev.

## Docs and comments to update

- `plugins/mailbox/docs/overview.md`, the labels sections.
- `plugins/mail_apps/docs/overview.md`.
- `docs/mobile_apps.md` § labels.
- The class and method comments that say "global":
  - `inbound_email_labels_class.php`;
  - `inbound_imap_folders_class.php`;
  - `MailboxService.php`;
  - `mailbox_filters_logic.php`;
  - `mailbox_filters_panel.php`;
  - `mail_folder_create_logic.php`.

## Tests

- **Two mailboxes, one per member:**
  - each lists only its own labels;
  - both can have "Receipts" as two labels;
  - a label id of the other's is refused by `set_membership`, `delete_label`, `thread_list`, a filter save and mail apps' `byKey`;
  - deleting one leaves the other's messages labelled.
- **IMAP:**
  - two accounts with a folder of the same name get two labels;
  - re-pointing an account re-mints its labels for the new mailbox.
- **Filters:**
  - a domain-wide filter refuses a label;
  - a per-mailbox filter's label is applied only within its mailbox.
- **Migration:** a label shared by three mailboxes becomes three labels, with each membership, binding and filter following its mailbox, and a second run changes nothing.
- **Existing suites updated:**
  - `label_delete_test`, `filter_import_test`, `imap_syncer_test`, `imap_hardening_test`, `mail_archive_import_test`, `mailbox_trash_test`;
  - mail apps `folders`;
  - the iOS phase-3 gate.
- **Fixture check:** `mailbox_message_actions_test` and `inbound_handler_test` create messages through fixtures, so they are checked for any label creation.
