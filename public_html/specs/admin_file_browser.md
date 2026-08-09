# Admin File Browser — Sources, Default Listing, and an Explorer View

**Status: ACTIVE.** Part 0 is built and live; Parts 1–3 are the work.

**Which page this is.** `/admin/admin_files` (`adm/admin_files.php`) — the platform-wide
file listing, not the member Drive at `/drive`. Drive already scopes itself to
`fil_source = 'drive'` and has its own list/grid toggle; nothing here changes it. Two of the
ideas below (a source rail, an Explorer layout) would suit Drive later, and the spec names
where they'd be shared, but Drive is out of scope.

## What this is

The admin Files page is supposed to be where you go to see what's on the site. Right now it
shows everything the platform has ever stored, newest first, which in practice means page
after page of inbound mail attachments and sealed search-index blobs — and the files a
person actually put there are buried somewhere behind them. The filter dropdown offers four
choices, only one of which is about where a file came from.

Three changes: files the system made for its own use stop appearing at all; the listing
opens on deliberate uploads with everything else one dropdown pick away; and a toggle in the
top right switches the table for a browsable, Explorer-style pane.

---

## Part 0 — Thumbnails and type icons (BUILT)

Recorded here because it is the same surface and the reason the rest got looked at.

**The defect.** 389 of 391 image attachments had no thumbnail, so the listing rendered
broken-image glyphs. Variants are opt-in at ingestion — only `admin_file_upload_process`,
`entity_photos_ajax` and `drive_upload_complete` ever called `resize()` — while
`File::get_url($size)` mints a variant URL unconditionally and `/uploads/*` 404s when the
file isn't there. The mail path never opted in, so it minted URLs for bytes nobody made.

**What shipped:**

- `FileBlob::ensure_variant($size_key)` — builds one registered size on demand and returns
  its path. Local blobs only; a cloud variant would mean download → resize → upload inside a
  page request.
- `serve.php` `/uploads/*` calls it on a variant miss, **after** the `is_viewable()` gate, so
  an anonymous caller can't spend CPU on a URL-supplied size key.
- Variant writes go to a temp name and `rename()` into place — a listing fires one thumb
  request per row and dedup siblings share a stored name, so several requests really can race
  to write the same file.
- `FileBlob::resize()` returns `true` on success instead of `NULL`.
- `File::type_icon_url()` / `File::thumbnail_html()` + `assets/js/file-thumb.js` — a file
  whose thumbnail can't be shown renders a file-type icon rather than a broken glyph. The
  swap is client-side because "does this variant exist" is not answerable cheaply per row.
- `adm/admin_file.php` previews the original rather than the `content` variant, so opening an
  attachment never writes an 800px copy.

Cloud-stored blobs still can't gain variants after offload — deliberately deferred, see
[`DEFERRED_cloud_blob_variant_generation.md`](DEFERRED_cloud_blob_variant_generation.md).
With the icon in place that is cosmetic, not broken.

---

## Part 1 — Classify the source, not the file

**The problem in one line:** some files exist only so the system can do its job, and no
browse surface should list them.

The obvious move is a `fil_hidden` column. It is the wrong one. Hidden-ness is not a
property an individual file has — it is a property of *what made it*. Every
`mailfts_*.bin` is internal; there is no user-visible one. A per-row flag is state every
writer must set correctly forever, and one miss puts a sealed index blob in a listing. The
origin tag is already stamped at creation, already correct on every existing row, and needs
no migration or backfill.

The rule also already exists, written by hand, once: `logic/share_logic.php:153` excludes
`SOURCE_MAILBOX_SEARCH_INDEX` with `source_not`. This page is the second surface to need it.
That is the moment to declare it rather than write it again.

### The declaration

Each origin tag gets a label and two flags, declared next to the constants in
`data/files_class.php`:

| Source | Label | Internal | In the default view |
|---|---|---|---|
| *(none / legacy)* | Unclassified | no | **yes** |
| `user_upload` | Uploads | no | **yes** |
| `entity_photo` | Photos | no | **yes** |
| `email_attachment` | Mail attachments | no | no |
| `ai_chat_upload` | AI chat uploads | no | no |
| `drive` | Drive | no | no |
| `mail_import_archive` | Mail import archives | no | no |
| `mailbox_search_index` | Search index | **yes** | no |

**`mail_import_archive` is deliberately not internal.** The user uploaded that mbox on
purpose. Either it gets cleaned up properly after the import run, or it is sitting there
consuming space and the person paying for the space needs to be able to see it. Hiding it
would turn a storage leak into an invisible one.

**Internal means "don't list this", not "this is secret."** It is a browse classification,
never access control — `File::is_viewable()` still gates every byte. This must be stated in
the code so nobody later mistakes it for a permission.

### The filter must exclude, not include

Unknown source stays **visible**. So the query is an exclude-list — `fil_source IN (...)`
never matches `NULL`, and an include-list would silently drop the 508 legacy rows that are
the site's actual history and, on this deployment, most of what there is to look at.

It also gives the better failure. A new subsystem that forgets to register shows up in an
admin listing where someone notices and classifies it; the opposite default makes new files
vanish silently.

`MultiFile` has `source_not` (single value, already parenthesized as
`(fil_source != 'x' OR fil_source IS NULL)` so NULL survives). This needs the plural
**`sources_not`**, same NULL-preserving shape, so a caller can exclude the internal set in
one filter.

### API surface

`File` gains, alongside the constants:

- `File::source_catalog()` — the table above, the single source of truth.
- `File::internal_sources()` — keys where internal is true. What `sources_not` gets.
- `File::default_view_sources()` / `File::source_label($key)` — for Part 2.

The constants block currently says fil_source is *"opaque to File — it stores and filters on
the string but attaches no behavior to any value."* This changes that, and the comment must
change with it. The trade is deliberate: two surfaces now need the same knowledge, so the
alternative is duplicating it in each and waiting for them to disagree.

---

## Part 2 — What the listing opens on, and the dropdown

**Default view:** the sources marked *in the default view* above — deliberate uploads,
entity photos, and legacy files. Mail attachments and everything else are still there, one
pick away, but they no longer bury the page.

The pick is labelled **"Uploaded files"**, not "Uploads & photos": it also carries the
unclassified legacy rows, which on this deployment are the overwhelming majority of it (see
*What the data actually looks like*). A label naming only two tags would be describing the
smaller half.

**Dropdown:** every non-internal source by label, plus the existing shape/state filters,
grouped so the two kinds of question stay distinct:

```
Show:  [ Uploaded files ▾ ]
         ── Origin ──
         Uploaded files           ← default
         All files
         Uploads
         Photos
         Mail attachments
         AI chat uploads
         Drive
         Mail import archives
         Unclassified
         ── Kind ──
         Images only
         Files only
```

Built by iterating `File::source_catalog()`, so a new source appears here the day it is
declared and never needs this page edited again. Internal sources are absent from the list
entirely — not present-but-excluded.

**"All files" means all non-internal files.** Search-index blobs are not reachable from this
page by any pick. If a superadmin ever needs to see them, that is a separate deliberate
surface, not a dropdown entry that undoes the classification.

`filter=no_email` disappears — "Mail attachments" and a default that excludes them replace
it. Nothing links to it.

---

## Part 3 — The Explorer view

A toggle in the top right of the Files card header switches between:

- **List** — today's table (thumb, name, type, uploaded, by). Unchanged.
- **Browse** — an Explorer-style pane: a source rail on the left, large tiles on the right.

```
┌─ Files ───────────────────────── [Upload file]  [Show: ▾]  [⊞ / ☰] ┐
│ ┌──────────────────┬────────────────────────────────────────────┐ │
│ │ Unclassified 508 │  ┌────┐  ┌────┐  ┌────┐  ┌────┐            │ │
│ │ Mail attach  493 │  │thmb│  │thmb│  │ 📄 │  │thmb│            │ │
│ │ AI uploads     4 │  └────┘  └────┘  └────┘  └────┘            │ │
│ │ Drive          2 │  name.png  shot.jpg  doc.pdf  logo.png     │ │
│ │ Import arch    1 │                                            │ │
│ │ Uploads        0 │  ┌────┐  ┌────┐                            │ │
│ │                  │  │thmb│  │ 📄 │                            │ │
│ └──────────────────┴────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────────┘
```

- **Rail** = the non-internal source catalog with live counts (one grouped
  `COUNT(*) … GROUP BY fil_source`), selecting one narrows the pane. It is the dropdown's
  origin group in spatial form; both write the same `filter` parameter, so a link into either
  lands the same place.
- **Tiles** use `File::thumbnail_html('content')` — real thumbnail where there is one, type
  icon where there isn't, which Part 0 already guarantees. Name under the tile, click opens
  `/admin/admin_file`.
- **Toggle** is an icon button, `aria-pressed`, remembered in `localStorage` so the choice
  survives navigation. Vanilla JS and CSS — joinery-system declares `"cssFramework": "html5"`
  and no framework enters over this.

### Render both, toggle with a class

The page renders its rows **once** and the two modes are presentation over the same markup:
a class on the container swaps table layout for tiles. No second query, no duplicated filter
or paging logic, no new endpoint — and it is exactly what Drive already does with
`.drv-view-list` / `.drv-view-grid` (`views/drive.php:287`).

The alternative — an `admin_files_list` API action feeding a JS-rendered pane — buys
filtering without reloads at the cost of a second code path for the same rows. Not worth it
here; the page already pages server-side and the toolbar already round-trips. Revisit only
if this grows infinite scroll or drag-select.

**Shared later, not now.** If Drive and this page both end up wanting a rail plus tiles, the
CSS is what should be lifted into `.jy-ui`, not the PHP — the two pages have genuinely
different data behind them (folders and quota on one side, origin tags on the other).

---

## Acceptance criteria

**Part 1**
- No `mailfts_*.bin` row appears at `/admin/admin_files` under any filter pick.
- Legacy `NULL`-source files still appear — the exclude-list preserves them.
- A source not in the catalog is treated as visible and unclassified, not dropped.
- `logic/share_logic.php:153`'s hand-written exclusion is replaced by the declared set, so
  there is one definition of internal.
- Nothing about `is_viewable()` changes; classification never widens or narrows access.

**Part 2**
- The page opens on uploads + photos + legacy; mail attachments are absent until picked.
- Every non-internal source is a dropdown pick, labelled from the catalog.
- Declaring a new source makes it appear in the dropdown with no edit to `admin_files.php`.
- Pager, sort and search compose with every pick.

**Part 3**
- The toggle switches modes with no page reload and no second query.
- The choice survives navigation and a browser restart.
- Rail counts match what each pick actually lists.
- A tile whose file has no thumbnail shows its type icon (Part 0), never a broken glyph.
- Keyboard reachable: the toggle is a real button, tiles are links, focus is visible.

## Docs to update

- `docs/admin_pages.md` — the source catalog and how a listing filters by it.
- `docs/drive.md` — the `fil_source` section, which currently describes the tag as carrying
  no behavior.
- `docs/plugin_developer_guide.md` — a plugin that stores files declares its source.

## What the data actually looks like

Live counts on dev, which are not what the mockup above implies:

| Source | Live rows |
|---|---|
| *(none / legacy)* | 508 |
| `email_attachment` | 493 |
| `mailbox_search_index` | 120 |
| `ai_chat_upload` | 4 |
| `drive` | 2 |
| `mail_import_archive` | 1 |
| `user_upload` | **0** |
| `entity_photo` | **0** |

**Both "deliberate upload" tags are empty**, so the default view is really "the 508 legacy
files" and the Photos and Uploads picks would both open on nothing.

This isn't a flaw in the classification — it's an accurate picture of when the tags were
introduced. Only two paths stamp them (`adm/logic/admin_file_upload_process_logic.php:173`
and `ajax/entity_photos_ajax.php:92`), both added after every file on this box already
existed. Anything uploaded from now on lands in the right bucket; everything older is
genuinely unclassified, and "Unclassified" is the honest label for it rather than a guess.

Two consequences worth deciding on:

- **Legacy must stay in the default view**, or the page opens empty on this deployment and
  on any other of the same vintage. Already specified above; this is why.
- **A backfill is possible but probably not worth it.** The 508 could be split into
  `user_upload` / `entity_photo` by looking at what references each row — entity photo
  tables, post bodies — but it is guesswork on the misses, and the only thing it buys is
  nicer bucketing of files that are already reachable. Recommend not doing it, and letting
  the classification apply going forward.

## Open items

- **Does `entity_photo` belong in the default view?** Included above on the grounds that
  someone deliberately uploaded each one. If the site has thousands of gallery photos they
  will crowd out ordinary uploads, and it should move out of the default. Moot on dev today
  (the tag has no rows), which is exactly why it should be decided on a real site.
- **Should the rail show sources with a zero count?** Showing them makes the catalog
  legible; hiding them keeps the rail short. Recommend showing, greyed — an empty "Drive"
  row tells you Drive exists and is empty, which is information.
- **`ai_chat_upload` sits oddly.** A user did upload it, but into a conversation, and it is
  arguably as much plumbing as a mail import archive. Left visible and non-default; worth a
  second look once there are more than one of them.
