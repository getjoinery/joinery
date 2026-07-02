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

Attachment chips link to the admin download endpoint
(`admin_inbound_email_attachment`), which is staff-gated and, for
file-backed rows, authorizes with the private-File owner-or-admin rule — a
granted member fails both. Attachments also come in two backings:
file-backed (bytes are a private File) and raw-backed (bytes are extracted
from stored raw MIME, or single-part-fetched live from the source IMAP
account), so no File-level mechanism can cover them all.

The fix is a member download endpoint,
`/profile/inbound_email/attachment`, that authorizes **both** backings the
same way: the viewer may access the alias of the attachment's message
(`MailboxViewer`; NULL-alias messages stay superadmin-only). After the
grant check, file-backed rows read bytes via `File::read_bytes()` (which
deliberately does not authorize — the caller gates) and raw-backed rows
retrieve exactly as the admin endpoint does. The retrieval half is
extracted into a shared include so the two endpoints differ only in their
authorization posture. The reader's chip URL becomes a config value so
each mount points at its own endpoint. (Signed URLs —
`docs/file_signed_urls.md` — are the transport the native app consumes
later for file-backed attachments, `specs/mobile_native_email.md`; a
sessioned web page doesn't need them.)

Inline (cid) images in inbound bodies are not rewritten by the reader for
any viewer today — there is no inline manifest and no cid resolution. The
member page inherits that parity; inbound cid rendering is its own future
item, not part of this spec.

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
