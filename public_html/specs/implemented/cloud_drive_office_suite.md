# Office Editing on Drive

**Purpose:** Members open a Word or Excel file in their Drive, edit it in the browser and save it back, without the file getting damaged. The target is everyday files: school papers, letters, resumes, to-do lists and household budgets.

**Status:** Implemented 2026-10-22. Phase 0 (measure and test) ran 2026-10-10 and passed G1–G4 (§6); G5 decided the same day (§1). Phases 1–3 built and run end to end on a test-Linode box: the db suites (office_editor_wopi, drive_openers), the live suite (office_editor_live) and browser checks passed, and the db gate passed; build notes in §7. Testing still owed after a release is in the live verification queue.

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
| Licensing | **Free Collabora edition (CODE) in home mode, from the MPL-licensed packages only.** A paid license waits until there is money for one. | Home mode shows users no Collabora popups while at most 10 files are open and fewer than 20 people are connected, about what a 1 GB node can hold anyway (§6.2). Past those numbers it does not refuse anyone; it shows everyone an "unsupported version" banner instead (§7), so the site enforces the limits itself and says "the editor is busy". Default CODE has no cap, but it shows a welcome popup and feedback prompts that can't be switched off. The editor server and office engine are MPL 2.0, which allows commercial and production use; Collabora's "not for production" is advice about support, not a license term (§6.5). Three rules keep us inside the licenses: skip the EULA-licensed `code-brand` package, let each node download from Collabora's repository rather than us hosting the packages, and never put "Collabora" in a product or feature name. Moving to a paid license later swaps the package in installer step 1 and drops the home-mode setting; nothing else in the plugin changes. |
| Multi-tenant Docker hosts | **Out of scope for v1: the editor is offered on bare-metal sites only.** The installer exits 0 "not applicable" inside a site container. | Those hosts give each site a fixed memory budget that starts at 256 MB, and the editor alone needs several times that. A site container also strips every setuid bit at start (`_site_supervisor.sh`), so the web user has no way to ask root to start the editor there. A shared, host-level editor for those hosts would be its own spec. |

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

1. Adds Collabora's apt repository and signing key, then installs `coolwsd` and the office-engine packages it depends on, all MPL 2.0. It does **not** install `code-brand`: that package (styling and theme images) is under Collabora's EULA, "all rights reserved" (§6.5). Phase 1 confirms the editor opens, edits and saves without it; Phase 0 ran with it installed. The node downloads straight from Collabora's repository, so Joinery never hosts or redistributes Collabora's binaries. No font packages are needed: Collabora bundles metric twins of Times New Roman, Arial, Calibri and Cambria (§6.4).
2. Writes `coolwsd.xml`:
   - listen on loopback only (`127.0.0.1` and `::1`);
   - TLS terminated by Apache, not coolwsd;
   - the WOPI host allowlist set to the loopback listener only (`http://127.0.0.1:9981`, §4.1), which is the only address the editor reaches the site on;
   - `num_prespawn_children` = 1. Idle memory was the same at 1 and 4, and 1 means fewer processes.
   - `home_mode.enable` = true: no Collabora popups while at most 10 documents are open and fewer than 20 connections (§1, §7). The site holds those limits itself, reading the editor's own counts from its metrics on loopback (`security.enable_metrics_unauthenticated`, which needs the admin console on; with no admin user configured it admits nobody).
   - no added capabilities; on bare metal Collabora isolates each document with namespaces.
3. **Memory guard.**
   - On bare metal: a systemd drop-in sets `MemoryMax` on `coolwsd.service`. When Collabora hits the cap, its overflow goes to swap and opens slow down, while Postgres and Apache stay untouched (§6.2).
   - `memproportion` is only a soft limit: above it Collabora unloads idle documents, but it never refuses a new one.
4. **Proxy.**
   - Apache gets a drop-in, `conf-available/joinery-office-editor.conf`. It proxies `/browser` and `/hosting/capabilities`, plus `/cool` with `upgrade=websocket`, keeping the visitor's Host. `mod_proxy_http` handles the upgrade itself, so `proxy_wstunnel` is not needed. The editor's conversion and admin endpoints are refused.
   - The same drop-in adds the loopback listener the editor reaches the site on (§4.1).
5. Refuses to install on a node with under 2 GB of RAM **and no swap**, and logs why. A 1 GB node with 1 GB of swap handled 10 simultaneous editors (§6.2).

### 3.2 Starting and stopping

**Start on open, stop when idle.** Idle Collabora costs about 165 MB. On a 1 GB hosted node that is close to half of what the site leaves free (398 MB). A cold start takes 3–7 seconds (§6.1), an acceptable wait on first open.

- A web request has no root, so the installer adds a narrow `sudoers` rule that lets the web user run only `office-editor-ctl start|status`.
- Opening a document calls `start` and waits for discovery to answer. The page shows "Starting the editor…" meanwhile.
- A cron job stops the service after 30 minutes with no open documents.

---

## 4. Phase 2 — connect the editor to Drive

### 4.1 How the editor talks to Joinery

The editor never touches the database or the disk. It asks the site over WOPI: "tell me about file X", "give me its bytes" and "here are the new bytes". The site answers each request only after checking the token the editor carries.

**Endpoints:** routes declared in the plugin's `serve.php`:

- `GET /office_editor/wopi/files/{id}` returns CheckFileInfo.
- `GET /office_editor/wopi/files/{id}/contents` returns GetFile.
- `POST /office_editor/wopi/files/{id}/contents` accepts PutFile.

These are a protocol surface whose paths, verbs and headers Collabora dictates, the same standing exception as payment webhooks. They're not `/api/v1` actions. Everything the **browser** calls goes through API actions (§4.4).

**Who may call them:** only requests that came in on a listener bound to `127.0.0.1:9981`, which the installer's Apache drop-in adds and which serves these paths and nothing else. That vhost sets `JOINERY_OFFICE_WOPI`, and the handler also requires a loopback local address (`127.0.0.1` or `::1`, compared as addresses). Nothing off the machine can connect to that listener, so it admits exactly what a loopback client check would; the client address itself is not used. Requests must also carry a valid WOPI proof-key signature, checked against the key published by the local discovery endpoint. WOPISrc is `http://127.0.0.1:9981/office_editor/wopi/files/{id}`.

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

**Conflicts:** Collabora sends the file's last-modified time with each save (`X-COOL-WOPI-Timestamp`): the `LastModifiedTime` the site last gave it, which is the head blob's `fbb_create_time`. If the file changed underneath, for example a sync client uploaded a newer copy, the save is refused with Collabora's "document changed" status code. The user then chooses which copy to keep; keeping theirs resends without the timestamp and saves as a new version. The timestamp compare is only the first answer: the real check is made where the head is written, under the file's row hold (the first save is guarded by the content the editor loaded, later saves replace only the head the session wrote), because a sync upload can land between the compare and the write. No WOPI lock table is needed: one editor server per node already coordinates everyone editing the same file.

**Version churn:** autosaves arrive every few minutes. If each one became a Drive version, a single afternoon of typing would push the real history past the `drive_versioning_depth` limit. So **one editing session produces one version**:

- The first save of a session demotes the old head to a version, as normal.
- Later autosaves in the same session replace the head blob in place, adding no new version.
- The session is the file's **editing span**, one per file: it starts when the editor loads the file (GetFile) and ends with the save made as the last person leaves (`X-COOL-WOPI-IsExitSave`) or the next load. It is not keyed on a token: the editor saves with whichever participant's token it picks, so a per-token session would turn every change of saver into a new version.

This needs one new method beside `save_new_content`, with its own test.

### 4.4 Browser-side actions (API, `_logic_descriptor()`)

- `office_editor_open`: checks access and protection level, starts the editor if needed (§3.2) and returns the editor URL, token and TTL.
- `office_editor_create`: creates a blank .docx or .xlsx in a folder the member can write to, using empty templates shipped with the plugin. It runs the same quota checks as an upload.

---

## 5. Phase 3 — user interface, gating and tests

### 5.1 The Drive hook (core)

Drive is core, and the editor is a plugin, so Drive needs a place where a plugin can say "I can open these files". Drive itself never names Collabora or the plugin. With the plugin off, nothing registers, and Drive behaves exactly as it does today.

**Registering.** The plugin's `bootstrap` (loaded by `PluginBootstraps`, the same path the vault and mailbox plugins use to register their hooks) calls:

- `DriveHelper::registerOpener($key, $spec)`. `$spec` holds:
  - `label`: the menu text, e.g. "Open in editor".
  - `extensions`: e.g. `docx`, `xlsx`, `odt`, `ods`.
  - `url($file, $user_id)`: returns the page that opens this file for this member, or `null` when it can't right now. The member's tier lacks the feature, say, or the file isn't at the standard level.
  - `explain($file, $user_id)` (optional): one line for when `url` returned `null` for a reason the member should see, e.g. "Private files can't be opened on the server. Download to edit."
- `DriveHelper::registerCreator($key, $spec)`. `$spec` holds `label` (e.g. "Document") and `action`, the API action that creates the blank file and returns its opener URL.

**Listing.** `DriveHelper::file_export()` adds `open_with` to each file it returns, as a list of `{key, label, url}` or `{key, label, explain}`:
- Only openers whose extensions match are asked.
- Only Drive's own files get openers. Another feature's file shown under "Also in your account" stays download-only, as now.
- Nothing is added in Trash.
- Openers load only when the listing is for a browser session. Sync clients and the mobile app don't receive the field.

The folder listing carries the registered creators the same way, as `create_options`.

**In the page** (`assets/js/drive.js`):
- **Clicking a file** goes to its first `open_with` URL in the same tab. With no opener, the click downloads the file, as it does today.
- **The ⋯ menu** lists each opener's label above Download. An opener carrying only `explain` shows its label greyed, with the explanation underneath.
- **The toolbar's "New folder" button becomes a New menu:** Folder, then each creator. Choosing a creator asks for a name, calls its action and goes to the returned URL.

**Tests:** the Drive API suite gains a test opener. It checks:
- registration, and the extension match;
- `open_with` present for a standard Drive file, and absent in Trash, for other features' files and for sync clients;
- `explain` returned when `url` gives `null`;
- `create_options` in the folder listing.

### 5.2 What the member sees

1. **Opening a file.** Clicking a .docx, .xlsx, .odt or .ods file at the standard level opens it in the editor, the way Google Drive does. Download stays in the ⋯ menu. Members with view-only access get the editor in view mode.
2. **First open after a quiet spell.** "Starting the editor…" shows for 3–7 seconds (§3.2).
3. **The editor page** (`/profile/office_editor/edit?file={id}`) fills the screen.
   - A thin top bar holds the folder path, the file name, "Saved" / "Saving…" (from the editor's status messages) and **Close**.
   - Saving is automatic, every few minutes and on close; Ctrl+S saves now.
   - Close returns to the file's folder. The session adds one entry to Version history (§4.3).
4. **New → Document / Spreadsheet** asks for a name, creates the blank file in the current folder and opens it.
5. **Shared files:** people with Editor access edit together and see each other's cursors.
6. **Private and Fortress files** still download when clicked. The menu shows **Download to edit** with one line explaining why.
7. **Server full.** When opening would pass the home-mode limits (an 11th document, or a 20th connection), the member sees "The editor is busy: too many documents are open on this site right now. Try again in a few minutes, or download the file." with a Download button. The site decides this before the editor is asked (§7): Collabora itself never refuses in the free edition.
8. **Changed elsewhere.** A save refused for a timestamp conflict (§4.3) says the file was changed somewhere else, and offers to keep this version or reopen the other.
9. **Phones:** the editor's own phone layout is used, with no extra work.

### 5.3 Gating

- **Per site:** the plugin is active, the host installer succeeded, and the node meets the memory floor.
- **Per member:** a new tier feature, `office_editing` (boolean), in the plugin's `tier_features.json`.

### 5.4 Tests

- **WOPI host suite** (`db` tier): token mint/verify, expiry, wrong-file and wrong-user refusal, non-loopback refusal, proof-key failure, permission revoked mid-session, level changed mid-session, quota exceeded, timestamp conflict, and one version per session.
- **Live test** (`live` tier): on a node with the editor installed, open corpus file #1, change a word, save, and confirm a new version exists and the PDF page count is unchanged.
- **Cap test** (`live` tier): hold 10 different files open; the 11th gets the "editor is busy" answer, and joining one of the 10 still opens.

---

## 6. Phase 0 results (2026-10-10)

**Setup.** Collabora CODE 26.04.5 was installed from Collabora's apt repository: 58 s to install, ~700 MB of disk. Each box ran the public Joinery release, installed by the standard installer:

| Box | Shape | Setup |
|---|---|---|
| A | 1 GB, 1 vCPU, 1 GB swap | bare metal, full mail stack |
| B | 2 GB, 1 vCPU | bare metal |
| C | 4 GB, 2 vCPU | Docker host, one site container under the default run spec |
| D | 4 GB | load driver: one headless Chromium per simulated editor, each typing a sentence every 6 s |

All four boxes were deleted afterwards.

**How memory was counted.** "Real memory" is the anonymous (unreclaimable) PSS of Collabora's processes, plus the box's MemAvailable. Plain PSS also counts Collabora's ~650 MB of program files. The kernel can drop and re-read those, which is why naive totals (including the community's ~1.2–1.3 GB idle reports) look so much larger.

### 6.1 Idle cost and cold start

| | A (1 GB) | B (2 GB) | C (container) |
|---|---|---|---|
| Available, site idle, no Collabora | 398 MB | 1,315 MB | 3,348 MB |
| Collabora idle, real memory | 164 MB | 164 MB | 169 MB |
| Available, Collabora idle | 458 MB (see note) | 1,144 MB | 3,073 MB |
| Cold start (service start → discovery answers) | 3.9–5.6 s | 4.0–7.2 s | 3.2 s |

On box A the kernel made room by moving the idle spam filter (rspamd) into swap (263 MB), so available memory went *up*. rspamd is the largest process on a bare-metal node: 227–261 MB.

`num_prespawn_children` at 1 and at 4 measured the same.

### 6.2 Editors at once (small files: papers, resume, letter, budget, to-do list)

| Editors | B real mem | B min available | B slowest site page | A real mem | A min available | A swap | A slowest site page | C real mem | C slowest site page |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 220 MB | 1,091 MB | 0.35 s | 229 MB | 412 MB | 273 MB | 0.21 s | 231 MB | 0.03 s |
| 3 | 298 MB | 1,000 MB | 0.29 s | 343 MB | 305 MB | 284 MB | 0.06 s | – | – |
| 5 | 365 MB | 939 MB | 0.82 s | 362 MB | 286 MB | 285 MB | 0.20 s | 353 MB | 0.03 s |
| 10 | 554 MB | 687 MB | 0.92 s | 424 MB | 207 MB | 437 MB | 1.05 s | 547 MB | 0.04 s |
| 22 (staggered) | – | – | – | – | – | – | – | 987 MB | 0.04 s |

- **Per small document: 37–55 MB.**
- **Large files:**
  - A 5,000-row workbook with cross-sheet formulas adds ~120 MB.
  - A 650 KB workbook dense with conditional formatting adds ~250 MB on its own (available memory fell by ~410 MB).
  - On box A, that workbook plus two small files ran without trouble.
- **Open time:** one editor alone sees the document in 2.2–5.6 s. Ten opening at the same instant took 13–17 s. That number is mostly the load driver: server load stayed under 2.
- **Same file, 3 editors:** Collabora coordinated them itself, with no save conflicts.
- **No out-of-memory kills on any box, in any run.** Postgres, Apache and rspamd stayed up throughout.
- **Memory guard:**
  - `memproportion` is soft. At 15% it unloaded idle documents but still opened all 6.
  - `MemoryMax=400M` on `coolwsd.service` worked: 8 editors all ran, the overflow went to swap (168 MB), opens slowed to 13–19 s, and nothing else on the box was affected.

### 6.3 Containers and proxying (box C)

- **Privileges.** Collabora runs inside the site container under the default run spec, with no added capabilities. Without `SYS_CHROOT`/`MKNOD` it disables mount namespaces and bind mounting, and isolates each document with a **Landlock jail** (a kernel filesystem sandbox, ABI 4), which it logged as created successfully. On bare metal it uses user and mount namespaces.
- **Memory and speed** matched bare metal.
- **Proxy.**
  - With the host vhost exactly as templated (`ProxyPass / http://127.0.0.1:{PORT}/`), the editor page loads but its websocket never connects.
  - Adding `upgrade=websocket` to that line fixes it.
  - The container Apache needs `/browser`, `/hosting/discovery`, `/hosting/capabilities` and `/cool` (with `upgrade=websocket`).

### 6.4 Fidelity

**Corpus.** 18 files, each opened, typed into, saved through the editor, then compared part by part and re-rendered to PDF:
- **Real Word and Excel files:** 6 Word and 6 Excel from the Apache POI test collection (checkboxes, footnotes, different-first-page headers, pictures, tracked changes, comments; charts, conditional formatting, data validation, pivot table, formulas).
- **Everyday documents** generated on Word's default template: MLA paper with footnotes and a page-number header, resume, letter with logo and table, monthly budget with pie chart, 5,000-row workbook, and a to-do list with modern checkboxes.

**Survived:**
- footnotes, page-number fields, different-first-page headers;
- double spacing, Times New Roman, hanging indents;
- tables and images;
- legacy checkboxes (9, the same 5 ticked) and modern checkbox controls (6, the same 3 ticked);
- tracked changes (new typing in a tracking-on document was itself recorded as a tracked insertion);
- comment text and threads;
- charts;
- all 9 conditional-formatting rule types, including data bars, icon sets and color scales;
- dropdown validation, the pivot table, frozen panes, currency formats and formulas.

**Page counts were unchanged for every Word file,** including the 10-page MLA paper.

**Lost:** a picture pasted *inside a comment*.

**Rewritten harmlessly:**
- Word 2010's duplicate styles part and empty footnote/endnote parts are dropped;
- Excel's shared formulas are written out one per cell;
- unused number formats are removed;
- files usually shrink 30–80%.

**Fonts:** Collabora bundles Liberation Serif/Sans (metric twins of Times New Roman/Arial), Carlito (Calibri) and Caladea (Cambria). No extra packages are needed.

**Not checked:**
- how saved files look in real Microsoft Word (the Windows test VM has no Office);
- files exported from Google Docs/Sheets (no account);
- page counts were compared LibreOffice to LibreOffice only.

### 6.5 Licensing facts

- **CODE, default mode:** free, and showed no document or connection cap: 22 documents were open at once. Every open shows an "Explore the New 26.04.5.1 — Collabora Online Development Edition" popup over the document, and its configuration says the welcome and feedback popups cannot be switched off in this mode.
- **CODE, `home_mode`:** no popups (verified) up to **10 open documents and 20 connections**. *Corrected 2026-10-22 (§7): it does not cap at those numbers; past them it shows an "unsupported version" banner to everyone connected.*
- Collabora describes CODE as being for testing, home use and small teams, "not recommended for production": a rolling release with no SLA.
- **What the packages say** (read 2026-10-10 from the 26.04.5 debs in Collabora's repository):
  - `coolwsd` (the editor server): `License: MPL-2`.
  - `collaboraoffice` (the office engine): "made available subject to the terms of the Mozilla Public License, v. 2.0" plus the usual third-party licenses (Apache, MIT, OFL fonts…), and "all rights are reserved to Collabora branding elements and Marks."
  - `code-brand`: `License: EULA`, "All Rights Reserved". The EULA text itself is not in the package.
  - None of them says anything about production, commercial use, hosting or user counts. MPL 2.0 has no such limits.
- **What Collabora staff say:** "CODE is open source, you're not required to buy support… for production use, we recommend COOL" (forum, 2025). Earlier (2021): "the intention is that our CODE binaries are not suitable for use in production in the enterprise. If you want that — build, brand and support it yourself", while also saying the CODE licensing policy hadn't been reviewed and that trademark and CSS theming are proprietary. Read together: a stated preference and a branding claim, not a published license restriction.
- **Trademark policy:** product, feature, service and domain names may not include Collabora's marks without written permission, and nothing may imply an endorsement.
- **Collabora Online (paid):** priced per user per year. The list price is about €3 per user per month (on-premise, up to 99 users), and a partner quotes ~€21 per user per year. Shipping it to customer nodes would need a partner or hosting agreement, priced on request.
- **Our own build from the MPL source:** permitted, but the Collabora marks must come out. We would build and ship LibreOffice core plus Collabora Online ourselves, every release.
- **arm64:** Collabora's apt repository publishes arm64 packages. This was checked in their documentation only; nothing ran on ARM.

### 6.6 Verdict against §2.7

| # | Result |
|---|---|
| G1 | **Pass.** Box B with 5 editors: 939 MB available, slowest site page 0.82 s. With 10: 687 MB and 0.92 s. |
| G2 | **Pass, both branches.** Cold start 3–7 s; idle ~165 MB. §3.2 chooses start-on-open anyway, for the 1 GB nodes. |
| G3 | **Pass**, with one documented loss: a picture inside a comment. |
| G4 | **Pass.** No added capabilities; Landlock jail in containers. |
| G5 | **Decided 2026-10-10:** CODE in home mode, MPL packages only (§1). A paid license waits until there is money. |
| Box A | **1 GB nodes can offer editing when they have swap.** It handled 10 simultaneous editors with no kills, and the site stayed within ~1 s. The installer floor is therefore "2 GB, or 1 GB plus swap" (§3.1 step 5). |

---

## 7. Build notes (2026-10-22)

Phases 1–3 were built and run end to end on a 2 GB test-Linode box (bare metal, release 0.8.525 plus this tree; CODE 26.04.5.1). What the build settled, beyond the sections above:

- **Home mode does not cap.** With 10 documents held open, an 11th opened normally and every connected user got a dismissable banner: "This is an unsupported version of Collabora Online Development Edition (unbranded). To avoid the impression that it is suitable for deployment in enterprises, this message appears when more than 10 documents or 20 connections are in use concurrently." The source agrees: the hard refusal (`hardlimitreached`) exists only in builds with a support key (`wsd/ClientRequestDispatcher.cpp`); the free edition sends the soft `limitreached` notice (`COOLWSD::checkSessionLimitsAndWarnClients`). Phase 0 never pushed past the limit in home mode, which is how §6.5 came to say "caps". The site therefore enforces the limits itself (§5.2 item 7), from the editor's metrics: `kit_assigned_count` (documents), `document_active_views_all_count_total` (connections), and `doc_info` (which files, by WOPISrc).
- **The idle stop counts documents, not connections.** It reads `kit_assigned_count` from the same metrics; after the last tab closed it reached 0 within 3 s, and a busy mark aged past 30 minutes stopped the service.
- **The editor reaches the site on a listener of its own.** `127.0.0.1:9981`, which only processes on the machine can connect to and which passes only the WOPI paths. Its check reads `THE_REQUEST`: the site's `<Directory>` rewrite hands the request to serve.php internally, and a check on the rewritten path refused everything.
- **The vendor branding package is not needed.** Without `code-brand` the editor opens, edits and saves; the only difference is two 404s for branding files in the browser console.
- **`--o:` overrides work for plain keys and for the WOPI alias group** (`storage.wopi.alias_groups.group[0].host[0]`). `lok_allow` and `post_allow` are emptied in the editor's own copy of the packaged configuration; in 26.04 `post_allow` gates only the conversion endpoints, so copy and paste between documents (`/cool/clipboard`) is unaffected.
- **The editor's own saves send no `Action_Save_Resp`.** The page reads "Saved" from `Doc_ModifiedStatus` going false.
- **Cold start**, stopped to document on screen: 4.9 s on the 2 GB box.
- **Live suite** (`office_editor_live`, 14 checks) passed on the box: a 3-page document loaded through the signed WOPI path, edited and saved; a new version; the word in the file; 3 pages before and after; the capacity limit.

- **A refused save looks like a save to the page.** Over quota, Collabora showed its own "Save failed because the document is too large or exceeds the remaining storage space…" dialog with a download, switched to read-only, and posted `Doc_ModifiedStatus` false exactly as after a good save. The WOPI host therefore records each refusal on the file's span, and the page asks `office_editor_save_state` a moment after each "saved"; the bar then read "Not saved: there is no room left in this Drive.", and "Saved" again after the quota was lifted.

- **Close waits for the site, not the editor.** On the box, with a sync upload landed while the document had unsaved edits, Close stayed on the page with "Not saved: the file was changed somewhere else." and Collabora's "Document has been changed — Discard / Overwrite" dialog; Overwrite saved the editor's copy as a new version (the laptop's kept as the version before it), the bar went back to "Saved", and Close then left. A plain Close after an edit saved and left in 1.4 s.

Not repeated after Phase 0: opening on a 1 GB node with swap (the installer allows it).

