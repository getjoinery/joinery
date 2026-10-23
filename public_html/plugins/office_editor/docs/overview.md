# Office Editor

Members open Word and Excel files from Drive in the browser, edit them alone or
together, and save them back. The editor (Collabora Online's open-source
edition, CODE, which runs the LibreOffice engine) runs on the same server as the
site, so a file never leaves it.

- **Opens:** `.docx`, `.xlsx`, `.odt`, `.ods`. New blank documents (`.docx`) and
  spreadsheets (`.xlsx`) from Drive's New menu.
- **Only Standard files.** A Private or Fortress file would put its plaintext in
  the editor's memory and temp files, outside what those levels promise. Their
  menu shows the opener greyed: "Private and Fortress files can't be opened on
  the server. Download to edit."
- **Servers of their own only.** A site in a container (a shared Docker host)
  is not offered the editor: its memory budget is far below what the editor
  needs, and a container has no way for the site to start a root service. The
  installer says so and installs nothing there.
- **Who may use it:** the tier feature `office_editing` (boolean, in
  `tier_features.json`). The site must also have the plugin active and its
  installer finished.

## What a member sees

Clicking a supported file in Drive opens `/profile/office_editor/edit?file={id}`
in the same tab: a thin bar (where the file is, its name, Saved / Saving…, and
Close) over the editor. Download stays in the ⋯ menu. A member with view-only
access gets the editor read-only.

The editor starts on demand. The first open after a quiet spell shows
"Starting the editor…" for a few seconds. Saving is automatic — every few
minutes, on Ctrl+S, and as the last person leaves. Close returns to the folder
once the site has kept what is in the editor: it asks the editor to save and
waits for the site's answer, not the editor's (the editor says "saved" as soon
as its own copy is written). A save the site refuses keeps the page open, with
the reason in the bar and the editor's own dialog (keep this copy, or discard)
in front of it; after no answer at all for 30 seconds it leaves, and the
editor's save as the last person leaves is the backstop.

People with Editor access to the file edit together and see each other's
cursors. When someone else's copy (a sync client, another device) lands while
the editor has the file open, the editor's next save is refused as "changed
underneath", and the editor asks whether to keep this copy or reload the other.
Keeping it saves it as a new version, so the other copy stays in Version
history.

**One editing session is one version.** The session's first save keeps what was
there before as a Drive version; its later autosaves replace the head it wrote.
An afternoon of typing is one entry in Version history.

The free edition runs in home mode, which shows no vendor pop-ups while at
most 10 documents and fewer than 20 connections are open on the server. It does
not refuse past those numbers: it puts an "unsupported version" banner in
front of everyone instead. So the site holds the line itself: opening an 11th
document, or a connection that would make the 20th, gets "The editor is busy:
too many documents are open on this site right now…" with a Download button.
Joining a document someone already has open counts only as a connection.

If the editor is stopped for being idle while a tab is still open (a laptop
that slept through it), that tab's connection is gone and nothing on the page
restarts the editor: reloading the page does.

## How it fits together

```
browser ──https──> Apache ──/browser, /cool (websocket)──> editor (127.0.0.1:9980)
                                                               │
site  <──http 127.0.0.1:9981 (WOPI, signed)────────────────────┘
```

- **The page** (`views/profile/edit.php`, `assets/office_editor.js`) calls the
  API action `office_editor/office_editor_open`. It asks the editor itself on
  every call (never a cached answer, which would outlive an idle stop), answers
  `starting` while it asks root to start the editor (the page asks again),
  refuses with reason `busy` when the editor's own counts say this open would
  pass its limits (`OfficeEditor::load()` / `wouldOverload()`), and otherwise
  answers `ready` with the editor's address and an access token. The page POSTs the token into the
  editor frame, so it never appears in an address the browser shows, and turns
  the editor's postMessage notices into the bar's status line:
  `Doc_ModifiedStatus` is "Saving…" while modified and "Saved" once not
  (the editor's own saves send no other answer). The editor reports a save the site refused
  (a conflict, quota, the file size limit, write access gone) to its page just
  as it reports one that landed, so on each "saved" the page asks
  `office_editor/office_editor_save_state`, which reads the refusal the WOPI
  host recorded on the file's span, and the bar says "Not saved: …" with the
  reason. The editor itself shows its own save-failed message, with a download,
  inside the frame. The page's own messages in place of the frame (starting,
  busy, could not open) each offer Download and Back to Drive.
- **The editor reaches the site** over WOPI, on a listener the installer adds:
  `127.0.0.1:9981`, which serves the WOPI paths and nothing else and marks each
  request with `JOINERY_OFFICE_WOPI`. `OfficeEditorWopi` answers only requests
  that carry that mark and arrived on a loopback local address (127.0.0.1 or
  ::1), are signed by the editor's proof key (`WopiProof`, checked against the
  key the editor publishes in its discovery document), and carry a valid token.
  Nothing off the machine can connect to the listener.
- **The token** (`OfficeEditorToken`) says "user U may read (or write) file F
  until T", for the origin the page was on. It is HMAC-signed with
  `office_editor_token_key` (minted on first use, SecretBox-sealed) and lives
  8 hours. What it allows is asked of Drive again on every request
  (`DriveHelper::can_read` / `can_write`, the file's level, the member's tier),
  so a revoked share or a file moved into a Private folder stops at the next
  request, not at expiry.

### The three requests

| Request | | |
|---|---|---|
| `GET /office_editor/wopi/files/{id}` | CheckFileInfo | name, size, owner and user ids, `UserCanWrite`, `LastModifiedTime`, `PostMessageOrigin`; no Save As, renames or locks |
| `GET …/contents` | GetFile | the head bytes; starts the file's editing span |
| `POST …/contents`, `X-WOPI-Override: PUT` | PutFile | saves; 409 `{"COOLStatusCode":1010}` when changed underneath, 413 past quota or the tier's file size limit |

These are a protocol surface whose paths and headers the editor dictates, not
`/api/v1` actions.

### Saving

`OfficeEditorWopi::save()` lands bytes the way a Drive upload lands a new
version: the quota is checked under the owner's quota lock, the write is held
against a trash of the owner's tree, and usage, the change feed (`content`) and
the sync memo follow, so sync clients pick the edit up.

An **editing span** (`OfficeEditorSession`, one row per file) starts when the
editor loads the file (GetFile) and ends with the save made as the last person
leaves (`X-COOL-WOPI-IsExitSave`), or with the next load — a document closed
after its last save makes no exit save, and its span simply lasts until the
file is next loaded. It is per file, not per
person: the editor saves with whichever participant's token it picks, so
everyone editing together shares one span.

- `LastModifiedTime` is when the head blob was stored. The editor echoes it
  back on its next save; a different value now is a conflict, answered at once.
- A save that is not a replace (a span's first, or one with no span) goes
  through `FileVersion::save_new_content` guarded by the head that compare just
  agreed: moved since (a sync upload between the check and the write), it is a
  conflict too.
- Its later saves go through `FileVersion::replace_head_content`, which replaces
  only the head the span wrote: anything else there, it is a conflict.
- A save with no timestamp is the member choosing to keep their copy: it goes in
  as a new version, unguarded.

## Install and run

`provisioning/install_office_editor.sh` (the plugin's `host_installer`, run as
root by the host converger once the plugin is active):

1. Refuses a container site, a server without systemd or apt, an architecture
   other than amd64/arm64, and a server under 2 GB of memory with no swap
   (1 GB with swap is enough). A refusal is written to
   `/etc/joinery/office_editor/refused`, which the Plugins page shows. A
   container site's check refuses too (`ProvisioningCheckRefused`), so the
   page offers no installer command there.
2. Adds the vendor's apt repository and installs `coolwsd` with
   `--no-install-recommends`. The server downloads it from the vendor; Joinery
   hosts nothing. The vendor's branding package (`code-brand`, under an
   all-rights-reserved licence) is never installed. The editor bundles metric
   twins of Times New Roman, Arial, Calibri and Cambria, so no font packages.
3. Writes `/etc/joinery/office_editor/coolwsd.xml`: the packaged configuration
   with `lok_allow` and `post_allow` emptied, so a document cannot make the
   editor fetch from loopback or a private network. A systemd drop-in runs the
   editor with it, listening on loopback only, TLS left to Apache, the WOPI host
   allowlist holding the loopback listener alone, one pre-spawned child, home
   mode, the metrics answered without a login (on loopback; the admin console
   that serves them admits nobody, since no admin user is configured; any
   process on the server can read them, open file names included, which is no
   more than the same processes can already read of the editor's working
   copies and the site), and
   `MemoryMax` at half the server's memory
   (at least 400 MB). Past the cap the editor's overflow goes to swap and opens
   slow down; the database and web server are untouched. The editor is not
   started at boot, and is stopped again when the package first installs
   (the package starts it).
4. Adds `conf-available/joinery-office-editor.conf`: `/browser`,
   `/hosting/capabilities` and `/cool` (websocket included) proxied to the
   editor with the visitor's Host kept, its conversion endpoints, metrics and
   admin console refused, and the loopback listener. The listener checks the
   request line as sent (`THE_REQUEST`): the site's rewrite hands a request on
   to serve.php internally, and the check runs again for that. Every proxy line has `retry=0` (a refused
   connection while the editor was stopped must not hold Apache off for a
   minute) and `disablereuse=On` (no idle connection outlives its browser).
5. Installs `/usr/local/sbin/office-editor-ctl` and a sudoers rule that lets the
   web user run exactly `office-editor-ctl start` and `office-editor-ctl status`.
6. Adds `/etc/cron.d/joinery-office-editor`: every minute, `idle-check` stops
   the editor once it has had no document open for 30 minutes (its own count,
   `kit_assigned_count`, from the metrics).
7. Writes `/etc/joinery/office_editor/installed`. The site reads it to know the
   editor exists here (`OfficeEditor::installed()`).

The provisioning check `OfficeEditorHealth::checkInstalled` passes when the mark
is there and `sudo -n office-editor-ctl status` answers.

Moving to a paid licence later swaps the package in step 2 and drops home mode
in step 3; nothing else changes. Never put the vendor's name in a product or
feature name.

## Tests

- `plugins/office_editor/tests/office_editor_wopi_test.php` (`db`): token,
  listener, proof, permissions and level asked per request, quota, conflicts,
  one version per span (two people, exit save, a sync upload inside a span).
- `plugins/office_editor/tests/office_editor_live_test.php` (`live`, on a
  server where the installer ran; skips elsewhere): a three-page document
  loaded through the real signed WOPI path, edited and saved over the editor's
  websocket protocol — new version, the word in the file, page count unchanged
  — and the capacity limit (an 11th document refused as busy, joining one
  already open allowed).
- `tests/functional/drive/openers_test.php` (`db`): the Drive hook itself.
