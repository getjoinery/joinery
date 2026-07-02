# Inbound Email — Member Mailbox at /profile

Give a regular member with a mailbox grant a place to read and answer their
mail: the existing Mailbox Reader, mounted under `/profile`, scoped to the
mailboxes they hold grants for. This is the **deferred member-mount path**
the reader was designed around (`specs/implemented/inbound_email_mailbox_reader.md`
§ Future) — the grant model (`ieg_inbound_email_mailbox_grants`) and the
`MailboxViewer` scoping seam (`accessibleAliasIds()` / `scopeAliasIds()`)
already exist; this spec opens the door they were built for.

Consumed by the iOS app platform (`specs/ios_app_platform.md`): the app's
Email surface is this page in an authenticated webview. The page is an
ordinary `/profile` view, so it needs no app-specific work.

## What exists / what changes

Today the reader lives at `/plugins/inbound_email/admin/admin_inbound_email_reader`
and every entry point — the page logic and all five AJAX endpoints
(`mailbox_mailboxes`, `mailbox_list`, `mailbox_thread`, `mailbox_action`,
`mailbox_send`) — hard-gates on permission ≥ 5. Underneath that gate,
`MailboxViewer` already scopes every read and write to the viewer's granted
aliases (permission 10 = all-access). The change is therefore small and at
one layer: **the entry gates move from "staff" to "signed in," and
`MailboxViewer` remains the sole authority on which mailboxes a viewer
touches.**

## The page

- **View:** `plugins/inbound_email/views/profile/mailbox.php` →
  auto-discovered at `/profile/inbound_email/mailbox` (no serve.php route).
- **Logic:** a profile counterpart of
  `admin_inbound_email_reader_logic.php` — requires a signed-in session
  (permission ≥ 1), builds the viewer via `MailboxViewer::fromSession()`,
  and renders the same reader UI (`assets/mailbox_reader.js` / `.css`, same
  CSRF token model). No duplicated reader code: extract whatever the admin
  page and this page share into a common include so both mount the one
  reader.
- **Empty state:** a signed-in user with no grants sees a short "no
  mailboxes are assigned to your account" state — not an error page.
- **Menu:** `plugin.json` gains a `profileMenu` entry — "Email" →
  `/profile/inbound_email/mailbox`, `visibility: "in"`, permission 1. It
  shows for all signed-in members; grantless users get the empty state.
  (Grant-conditional menu visibility is a platform concern, out of scope
  here.)
- The admin reader stays where it is, unchanged, for staff.

## The AJAX endpoints

Each of the five `mailbox_*` endpoints changes its gate from
`get_permission() < 5` to "signed in" (reject anonymous sessions). Before
loosening any gate, add a test asserting the endpoint's reads and writes are
viewer-scoped — the gate change ships only with proof that `MailboxViewer`
is actually consulted on that path. Superadmin all-access behavior
(permission 10: every mailbox plus "All mail" / "Unmatched") is a viewer
property and is unchanged.

**Send-as:** a grant means full access to the mailbox — reading it and
sending as it — matching the scoping `MailboxSender` already enforces. The
stale `canCompose()` seam on `MailboxViewer` (returns `false`, but the send
path never consults it) gets aligned rather than left drifting: it returns
`true` for any viewer with at least one accessible alias, and
`mailbox_send.php` consults it instead of a raw permission check.

## Attachments (the one real gap)

Message attachments are stored as **private Files**, and private-file
serving authorizes **owner-or-admin** (`data/files_class.php`,
`is_owner_or_admin`). A granted member is neither, so today they could see
an attachment chip but not download it. The fix keeps authority in one
place: the file-serving path for mailbox attachments authorizes via
`MailboxViewer` — serve the file when the viewer can access the alias of
the message the attachment belongs to (the `ima_inbound_message_attachments`
row links file → message → alias). Design the hook so the File model asks
the owning plugin, rather than the file server growing inbound-email
knowledge.

The same rule governs inline (cid) images in message bodies — verify they
render for a granted member, since they flow through the same private-file
gate.

## Tests

Additions to `plugins/inbound_email/tests/`:

- A granted member lists only their granted mailboxes; messages, threads,
  actions, and search never leak another alias (per-endpoint scope proofs,
  written before the gate change).
- A signed-in user with no grants: empty mailbox list, and every endpoint
  returns empty/denied rather than erroring.
- Anonymous requests to every endpoint are rejected.
- A granted member sends as the mailbox; a non-granted member cannot.
- A granted member downloads a message attachment and sees inline images; a
  non-granted member is denied the same file.
- Regression: the admin reader and superadmin all-access are unaffected.

## Versioning

- `plugins/inbound_email/plugin.json`: minor bump (new feature, backward
  compatible).
- Bump `@version` on each modified file.

## Out of scope

- Per-person read state on a shared mailbox (shared-per-mailbox stays the
  chosen semantics).
- Grant self-service (admins assign grants at the admin Mailboxes page).
- Grant-conditional profile-menu visibility.
- Compose-to-anyone (new mail to arbitrary recipients) — reply/reply-all/
  forward as the mailbox, as the reader supports.

## Documentation deliverables (on implementation)

- `plugins/inbound_email/docs/overview.md` — extend the Mailbox Reader
  section: the member mount at `/profile/inbound_email/mailbox`, the
  signed-in + grant-scope permission model, send-as semantics, and
  grant-authorized attachment serving.
- `docs/email_system.md` — cross-reference the member mailbox surface.
