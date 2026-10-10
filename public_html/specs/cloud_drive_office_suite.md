# Office Editing on Drive

**Purpose:** Members open a Word or Excel file in their Drive, edit it in the browser and save it back, without the file getting damaged. The target is everyday files: school papers, letters, resumes, to-do lists and household budgets.

**Status:** Active. **Phase 0 (measure and test) comes first and gates everything else.** Phases 1–4 are written so the build can start as soon as Phase 0 passes. Their details may change with what Phase 0 finds.

**Plugin name:** `office_editor`.

**Builds on:** the Drive Core spec (files, folders, sharing, versions, quota) and the Drive Encryption spec (protection levels).

---

## 1. Decisions already made

| Decision | Choice | Why |
|---|---|---|
| Build our own editor, or embed one? | **Embed.** | A homemade editor converts the file into its own format and back again. Whatever it doesn't understand (footnotes, section breaks, comments) is silently dropped on save. For people opening their own files, that's unacceptable. Embedding also gives real pages, which school papers need. |
| Which editor? | **Collabora Online.** | It runs the LibreOffice engine, has real page layout and is MPL-licensed. The alternative, OnlyOffice, is AGPL and caps simultaneous users in its free edition. |
| Where does it run? | **On the node, next to the site.** | Files never leave the user's own server. |
| Formats | **.docx and .xlsx** (plus .odt/.ods, which come free). Blank new documents of both types. | This matches the target users. PowerPoint is out of scope for v1. |
| Protection levels | **Standard files only.** Private and Fortress files show "Download to edit" instead. | The editor has to read the file's plaintext. For Fortress that breaks the promise outright. For Private it would put plaintext in the editor's memory and temp files outside the vault window. Private files could be added later in their own spec. |
| Multi-tenant Docker hosts | **Out of scope for v1.** | Those hosts give each site a fixed memory budget that starts at 256 MB, and the editor alone needs several times that. A shared, host-level editor for those hosts would be its own spec. |

### What the editor does and doesn't preserve

Collabora is not a lossless passthrough. LibreOffice reads the file into its own model and writes it back out. For basic documents that is very mature. Word-only features LibreOffice lacks (some content controls, SmartArt, certain field types) can change on save. Two things protect users:

1. Every save keeps the previous content as a Drive version, so the original is always one click away.
2. Phase 0 measures this directly against a corpus of real everyday files (§2.3).

---

## 2. Phase 0 — measure and test before building

**Goal:** answer five questions with numbers, then make a go/no-go call. None of this phase touches the Joinery repo. It runs on throwaway boxes in the **test Linode account**, using a minimal throwaway file host (§2.2) in place of the real Drive integration.

**Q0.1 Memory.** What does the editor cost when idle, per open document and per concurrent editor? Does it fit a 1 GB hosted node at all?

**Q0.2 Cold start.** How long from "start the service" to "document visible"? This decides whether the editor can be started on demand and stopped when idle.

**Q0.3 Containers.** Does it run inside a site container with our dropped privileges (`--cap-drop=ALL` plus six capabilities, no systemd)? What does it lose without its sandboxing privileges?

**Q0.4 Fidelity.** Do everyday files survive open → edit → save without visible damage?

**Q0.5 Licensing and nag.** What does the free CODE build show users, does it cap users or documents, and what would removing that cost?

### 2.1 Test boxes (test Linode account)

| Box | Shape | Why |
|---|---|---|
| A | **Nanode 1 GB**, bare-metal site installed by the stackscript | This is exactly the hosted-tier node shape. Answers "does it fit at all?" |
| B | **Linode 2 GB**, bare-metal site | The likely minimum supported size. |
| C | **Linode 4 GB**, Docker host with one site container at default run spec | Answers Q0.3 under the real capability set. |
| D | **Linode 4 GB**, load driver | Runs the headless browsers that act as concurrent editors. It stays off dev because of the one-heavy-job rule, and off the box under test so the driver doesn't distort the numbers. |

Collabora installs from its official apt repository (`coolwsd` + `code-brand`), the same way the real installer will (Phase 1). The Docker image is not used for the measurement because the production install isn't Docker-in-Docker.

### 2.2 Throwaway file host

This is a single PHP file on each test box, outside the Joinery tree. It implements just enough of WOPI to serve a folder of test files: `CheckFileInfo`, `GetFile` and `PutFile`. It has no authentication beyond a fixed token, and the boxes are destroyed afterwards. It exists so Phase 0 measures the editor rather than our integration.

A test page on the same box opens a file in the editor iframe, so the load driver can script "open, type, save, close".

### 2.3 Test corpus

The corpus is synthetic files only, built for this test, with no personal data. Every file gets a written "what must survive" list.

| # | File | Made in | Must survive |
|---|---|---|---|
| 1 | MLA school paper, 5 pages | Word | Times New Roman 12, double spacing, header with surname + page number, hanging-indent Works Cited, 3 footnotes, page count |
| 2 | Same paper | Google Docs → .docx export | Same as #1 (exported files carry different markup) |
| 3 | APA paper with title page and a table | Word | Section/page break, table borders, running head |
| 4 | Resume, two-column table layout | Word | Column widths, bullets, bold/italic, one page |
| 5 | Letter with letterhead image | Word | Image position, margins |
| 6 | To-do list with checkboxes | Word | Checkbox state (a content control: a known weak spot) |
| 7 | Monthly budget | Excel | SUM and simple formulas, currency format, a pie chart, frozen header row |
| 8 | Budget with conditional formatting and dropdown validation | Excel | Red/green rules, the dropdown list |
| 9 | Multi-sheet workbook, 5,000 rows, cross-sheet formulas | Excel | Formulas, recalculated values, open time |
| 10 | Same budget | Google Sheets → .xlsx export | Same as #7 |
| 11 | Blank new document, typed from scratch | Collabora | Opens cleanly in Word and Google Docs after saving |

**Fidelity method:** for each file, open it, change one word or one cell, save, then compare in three ways:

1. **Page count and layout.** Render the original and the saved copy to PDF with LibreOffice headless and compare page counts. Diff the page images and inspect any page that changed.
2. **Opens cleanly where the user's teacher or friend will open it.** Open the saved file in Microsoft Word (the Windows test VM, with Office installed if it isn't already) and check the "must survive" list by eye.
3. **Fonts.** Run every file once with only the default fonts installed, then again with the metric-compatible font packages (Liberation, Carlito, Caladea). Without matching fonts, Times New Roman and Calibri papers reflow and change page count. That alone can wreck a school paper, so this result decides whether the installer ships those fonts.

### 2.4 Measurements

Memory is measured as **PSS** (`smem`) and cgroup `memory.current`, never plain RSS. The editor's processes share most of their memory, and RSS counts that shared part once per process. The community reports of ~1.2–1.3 GB idle are probably RSS or container totals, which is part of what we are checking.

| Measurement | Boxes | How |
|---|---|---|
| Idle, service running, nothing open | A, B, C | With `num_prespawn_children` at 1 and at the default |
| Per document, small (#1, #4, #7) | B, C | Open one at a time; increment after each |
| Per document, large (#9) | B, C | Same |
| Concurrent editors: 1, 3, 5, 10 | B, C | Load driver, one browser per editor, each typing on its own file, plus a run with 3 editors on the same file |
| Box A under load | A | Open documents one at a time until the editor refuses or the box swaps hard. Record where that happens and whether Postgres or PHP got hurt. |
| Cold start | A, B, C | Service start → discovery endpoint answers → first document renders |
| CPU | B, C | Peak during open, steady state while typing |
| Disk | all | Install size, jail/temp size per open document |
| Memory guard | B | Set `memproportion` low and confirm the editor refuses new documents politely instead of the kernel killing something |

### 2.5 Container check (box C)

1. Install inside the site container under the default run spec. Record whether `coolwsd` starts, and what it logs about missing capabilities (it normally wants `SYS_CHROOT` and `MKNOD` for its per-document jails).
2. If it only runs in its no-capabilities mode, record the cost: extra memory or disk per document, slower open, and weaker isolation between documents.
3. Confirm it can live alongside the container's supervisor, which keeps only Postgres, PHP-FPM, Apache and cron alive. Decide how the editor process is kept alive or started on demand (§3.2).
4. Proxy the editor through **both** Apache hops (host → container → editor), including websockets, using `proxy_wstunnel`. That module is enabled nowhere today.

### 2.6 Licensing (desk research, no box)

- Read the current CODE terms: user/document caps, the welcome dialog, and the "not for production" wording.
- Price the paid route: a Collabora partner or subscription license that covers redistributing the editor to customer nodes.
- Check the third route: building from the MPL source ourselves and removing Collabora branding as their trademark terms require. What would keeping that build up to date cost us?
- Check whether arm64 packages exist. The agent ships for arm64, so some nodes may be ARM. Linode is x86-only, so this is a documentation check.

### 2.7 Pass / fail

Results get written into §6 of this spec. **Go** requires all of:

| # | Criterion |
|---|---|
| G1 | On box B (2 GB), 5 concurrent editors on small files leave ≥ 300 MB available, and the site keeps answering in under 1 s. |
| G2 | Cold start ≤ 10 s, **or** idle cost ≤ 300 MB PSS (low enough to leave it running). |
| G3 | Files #1–#5, #7, #8 and #10 come back with their full "must survive" list intact. #6 and #9 may show documented, minor losses. |
| G4 | Runs in the site container under a capability set we're willing to grant. |
| G5 | A licensing route the owner accepts (§2.6). |

**Box A (1 GB) has no pass bar.** Its result decides whether hosted nodes can offer editing at all, or whether the feature requires a 2 GB node. If it requires 2 GB, the plugin's installer refuses to install on smaller nodes and says why.

---

## 3. Phase 1 — install and run the editor (only after Go)

### 3.1 Installer

`plugins/office_editor/plugin.json` declares a `host_installer` (`provisioning/install_office_editor.sh`) and matching `provisioners` entries, so the Plugins page shows missing host state. The installer follows the standard contract: idempotent, root, non-interactive, exits 0 when not applicable, and re-runs at every container start so the install survives a rebuild.

The installer:

1. Adds Collabora's apt repository and signing key, then installs `coolwsd`, `code-brand` (or the licensed build chosen in G5) and the font packages Phase 0 found necessary.
2. Writes `coolwsd.xml`:
   - listen on loopback only (`127.0.0.1` and `::1`);
   - TLS terminated by Apache, not coolwsd;
   - the WOPI host allowlist set to this site only;
   - `memproportion`, `num_prespawn_children` and per-document limits set from the Phase 0 numbers;
   - the capability mode Phase 0 settled on.
3. Writes an Apache drop-in (`conf-available/joinery-office-editor-<site>.conf`) proxying `/browser`, `/hosting/discovery`, `/hosting/capabilities` and `/cool/` (websockets via `proxy_wstunnel`) to coolwsd. It also enables `proxy_wstunnel`. On Docker hosts this goes on both the host Apache and the container Apache. It's a drop-in because the main vhost template refuses operator edits.
4. Refuses to install below the memory floor from §2.7 and logs why.

### 3.2 Starting and stopping

Phase 0 decides between:

- **Always on.** This is chosen if the idle cost is small (G2's second branch).
- **Start on open, stop when idle.** A web request has no root, so the installer adds a narrow `sudoers` rule that lets the web user run only `office-editor-ctl start|status`. Opening a document calls `start` and waits for discovery to answer. A cron job stops the service after 30 minutes with no open documents.

---

## 4. Phase 2 — connect the editor to Drive

### 4.1 How the editor talks to Joinery

The editor never touches the database or the disk. It asks the site over WOPI: "tell me about file X", "give me its bytes" and "here are the new bytes". The site answers each request only after checking the token the editor carries.

**Endpoints:** routes declared in the plugin's `serve.php`:

- `GET /office_editor/wopi/files/{id}` returns CheckFileInfo.
- `GET /office_editor/wopi/files/{id}/contents` returns GetFile.
- `POST /office_editor/wopi/files/{id}/contents` accepts PutFile.

These are a protocol surface whose paths, verbs and headers Collabora dictates, the same standing exception as payment webhooks. They're not `/api/v1` actions. Everything the **browser** calls goes through API actions (§4.4).

**Who may call them:** requests from loopback only (`127.0.0.1` or `::1`, compared as addresses). They must also carry a valid WOPI proof-key signature, checked against the key published by the local discovery endpoint.

### 4.2 The access token

When a member opens a file, the site mints a token that says **"user U may read (or write) file F until time T"**, signed with HMAC under a new SecretBox-held key. It follows the same pattern as `File::mintSignedUrl()`, but is bound to a user and scoped for write.

- The TTL is 8 hours, and it is reported to the editor as `access_token_ttl`.
- The token is never put in a URL the browser shows. The editor page POSTs it into the iframe, as WOPI specifies.
- **Every request re-checks current permission** with `DriveHelper::can_read` / `can_write`, so revoking a share or moving a file into a Private folder takes effect on the next save, not at token expiry. A save refused this way tells the user it couldn't save and offers "Download a copy".

### 4.3 Saving

PutFile follows the same steps as a Drive upload:

1. Re-check write permission and that the file is still at the `standard` level.
2. Under `DriveHelper::quota_lock($owner)`, check `drive_storage_bytes`.
3. `FileBlob::createFromPath()`, then `FileVersion::save_new_content()`. If that fails, release the blob.
4. Run `DriveUsage::recompute`, `FileChange::record(KIND_CONTENT)` and `DriveHelper::forget_sync_meta`, so sync clients pick the change up.

**Conflicts:** Collabora sends the file's last-modified time with each save (`X-COOL-WOPI-Timestamp`). If the file changed underneath, for example a sync client uploaded a newer copy, the save is refused with Collabora's "document changed" status code. The user then chooses which copy to keep. No WOPI lock table is needed: one editor server per node already coordinates everyone editing the same file.

**Version churn:** autosaves arrive every few minutes. If each one became a Drive version, a single afternoon of typing would push the real history past the `drive_versioning_depth` limit. So **one editing session produces one version**:

- The first save of a session demotes the old head to a version, as normal.
- Later autosaves in the same session replace the head blob in place, adding no new version.
- The session is identified from the token and the editor's own session headers.

This needs one new method beside `save_new_content`, with its own test.

### 4.4 Browser-side actions (API, `_logic_descriptor()`)

- `office_editor_open`: checks access and protection level, starts the editor if needed (§3.2) and returns the editor URL, token and TTL.
- `office_editor_create`: creates a blank .docx or .xlsx in a folder the member can write to, using empty templates shipped with the plugin. It runs the same quota checks as an upload.

---

## 5. Phase 3 — user interface, gating and tests

### 5.1 Drive UI

- **Open in editor** on any .docx, .xlsx, .odt or .ods file at the standard level that the member can read. Read-only members get the editor in view mode.
- **New → Document / Spreadsheet** in a folder.
- On Private or Fortress files, the action reads **Download to edit**, with one line explaining why.
- The editor page fills the screen, keeps the Drive breadcrumb and a Close button, and shows "Saved" / "Saving…" from the editor's status messages.

### 5.2 Gating

- **Per site:** the plugin is active, the host installer succeeded, and the node meets the memory floor.
- **Per member:** a new tier feature, `office_editing` (boolean), in the plugin's `tier_features.json`.

### 5.3 Tests

- **WOPI host suite** (`db` tier): token mint/verify, expiry, wrong-file and wrong-user refusal, non-loopback refusal, proof-key failure, permission revoked mid-session, level changed mid-session, quota exceeded, timestamp conflict, and one version per session.
- **Live test** (`live` tier): on a node with the editor installed, open corpus file #1, change a word, save, and confirm a new version exists and the PDF page count is unchanged.

---

## 6. Phase 0 results

*(Fill in when Phase 0 runs: tables from §2.4, fidelity results per corpus file, the container finding, the licensing finding, then the go/no-go call against §2.7.)*
