# Native Mobile Email — iOS & Android

Make the email surface native in the mobile apps: a granted member reads,
triages, and answers their mail in real native screens instead of the
webview. This is the first content surface to take the per-surface native
migration path the app platforms designed for — build the API, build the
screens, flip one navigation route.

**One spec for both platforms, deliberately.** The reusable work is shared:
the server-side mailbox API is written once and consumed by both apps, and
the screen inventory and UX model are identical on both. Only the rendering
language differs (Swift vs Kotlin — the platforms share no client code by
prior decision, `specs/android_app_platform.md`). Splitting per platform
would duplicate the API contract and screen list and let them drift.

## Dependencies

- `specs/implemented/file_signed_urls.md` — the core signed-URL file-serving
  capability this spec's attachment transport consumes.
- `specs/inbound_email_profile_mailbox.md` — the member grant surface, the
  grant-authorized attachment rule, and the web page that remains the
  fallback destination.
- `specs/ios_app_platform.md` / `specs/android_app_platform.md` — the
  navigation routing table (native screen + `fallback_url`) and the kits
  these modules plug into.

## Server-side: the mailbox API (written once, both apps consume it)

Follow the plugin API convention `plugins/dns_filtering/` established: logic
functions with `_api()` companions, exposed as
`POST /api/v1/action/inbound_email/{action}`, sessioned. Every action builds
a `MailboxViewer` for the key's user and goes through `MailboxService` —
the same shared brain the web AJAX endpoints use, so scoping, threading,
view semantics, and two-way-sync side effects live in exactly one place. No
new authorization logic anywhere: viewer scope is the single authority, the
same rule the profile mailbox spec establishes.

The actions mirror the reader's AJAX surface:

| Action | Purpose |
|---|---|
| `mailboxes` | The viewer's granted mailboxes with views (Inbox, All Mail, Spam), folders/labels, and unread counts |
| `thread_list` | Threads for a mailbox + view/folder/search filter, paged (50), same shapes as `mailbox_list.php` |
| `thread` | One full thread: messages, plain/HTML bodies, attachment metadata, inline-image references |
| `thread_action` | The reader's full action set: `mark_read`/`mark_unread`, `star`/`unstar`, `archive`/`unarchive`, `delete`, `mark_spam`/`mark_not_spam`, `set_membership` (labels/move), `create_folder` |
| `send` | Reply / reply-all / forward as the mailbox (multipart, attachments), via `MailboxSender` |

The web AJAX endpoints keep their existing JSON contracts; where extraction
is needed to share code with the `_api()` path, they become thin wrappers
over the same functions (the `dns_filtering` pattern).

### Attachments and inline images — signed URLs

Native clients (and the embedded HTML body view) can't authenticate file
fetches with web cookies, and streaming binary through the action API is the
wrong shape. Instead, the `thread` payload carries **short-lived signed
URLs**: for each attachment, and for each inline `cid:` image (the HTML body
is returned with `cid:` references rewritten to those URLs). The signing
capability is the core one from `specs/implemented/file_signed_urls.md`
(`File::mintSignedUrl()` — minting *is* the authorization statement): the
thread action mints only after the viewer-scope check that gated the thread
fetch, and the serving path validates signature + expiry with no session at
all. This slots in beside the grant-authorized serving rule from
`specs/inbound_email_profile_mailbox.md` — same authority (message → alias →
viewer scope), different transport for API clients.

## Client work — same screens, two languages

Two layered modules, mirroring how DNSFilterKit layers on JoineryKit:
**`JoineryMailKit`** (Swift package) and **`joinery-android-mail`** (Kotlin
module). Any Joinery app adds the module and gets the mail surface; the core
kits stay lean. Both register the native screen name `mailbox` with their
navigation shell, so the server-side route flip lights them up.

Screen inventory (identical on both platforms):

1. **Mailbox home** — switcher across granted mailboxes; views (Inbox, All
   Mail, Spam) and folders/labels; unread badges.
2. **Thread list** — paged, pull-to-refresh, search, swipe actions
   (archive, read/unread).
3. **Thread view** — collapsed/expanded messages; HTML bodies rendered in a
   sandboxed embedded web widget (WKWebView / Android WebView: JavaScript
   off, link taps open externally — standard native-mail practice); inline
   images via the signed URLs; attachments open into the system
   viewer/share sheet.
4. **Compose** — reply / reply-all / forward with quoted body and
   attachments from the system picker.
5. **Label/move picker** with create-folder.

**The v1 line:** everything a mailbox user can do in the web reader —
views, search, the full action set, labels, send. Not native in v1 (the web
reader remains for them): filter management and Gmail filter import, spam
settings, and admin oversight surfaces. Freshness is pull-to-refresh plus
refresh-on-foreground; push notification for new mail is a future spec.

## The route flip

Ship order per app: once an app release includes the mail module, the
`mailbox` navigation entry's destination becomes
`{type: "native", screen: "mailbox", fallback_url: "/profile/inbound_email/mailbox"}`.
Older shipped versions don't recognize the screen name and keep loading the
web page — the platform's version-skew rule, exercised for real for the
first time.

## Delivery phases & test gates

### Phase 1 — Server API

The `inbound_email/` action namespace, the `_api()` extraction, and signed
attachment/inline URLs.

**Gate:** functional tests (`plugins/inbound_email/tests/` +
`/tests/functional/api/`) — per-action viewer-scope proofs (a granted key
never reads or acts outside its aliases; a grantless key gets empty/denied);
action parity with the web reader verified at the database level
(read/star/archive/spam/membership state shared with the web UI); send
stores the outbound copy; signed URLs fetch without a session, expire, and
are denied for files outside the viewer's scope; web reader regression
suite passes untouched.

### Phases 2 & 3 — iOS and Android clients

Independent of each other once the API gate passes; either order. Each
delivers its module, screens, and the navigation registration.

**Gate (per platform, Simulator/emulator against `dev.getjoinery.com`,
seeding mail via `*@inbox.dev.getjoinery.com`):** UI test suite — read a
seeded thread; every thread action reflects in the web reader and vice
versa; search and paging parity; attachments open and inline images render;
a reply with an attachment round-trips (visible in the web reader's sent
state); grantless account sees the empty state; after the route flip, a
build without the mail module still lands on the web fallback.

## Acceptance checklist

1. A granted member reads, triages (full action set including labels and
   create-folder), searches, and replies natively on both platforms, and
   every change is visible in the web reader immediately (one source of
   truth).
2. Scope holds at the API: no action or fetch reaches an alias outside the
   viewer's grants; a grantless user gets clean empty states.
3. Attachments and inline images render natively via signed URLs; the same
   URLs expire and never leak across viewers.
4. Sending as the mailbox works from both apps with attachments; the sent
   copy appears in the web reader.
5. After the navigation flip, app versions without the mail module keep
   working on the web fallback.
6. `JoineryMailKit` and `joinery-android-mail` build standalone with no
   brand imports; the member app on each platform consumes them unchanged.

## Out of scope (future specs)

- Push notifications for new mail (rides a future platform push spec).
- Filter management, Gmail import, spam settings in-app — web reader.
- Compose-to-anyone (matches the reader: reply/reply-all/forward only).
- Offline cache and draft sync.
- Attachment upload size/type policy changes (server policy is unchanged).

## Versioning

- `plugins/inbound_email/plugin.json`: minor bump (new API surface,
  backward compatible).
- Bump `@version` on each modified file.

## Documentation deliverables (on implementation)

- `plugins/inbound_email/docs/overview.md` — an "API Surface" section
  modeled on `plugins/dns_filtering/docs/overview.md`'s: the
  `inbound_email/` action namespace, viewer scoping, signed URL transport.
- `docs/mobile_apps.md` — the mail modules as the first native content
  surface, and the route flip as the worked example of webview→native
  migration.
