# Office Editing on Drive — Exploratory Spec

**Status:** Sketch / not committed to implementation. Scope note (2026-07-16): the Drive
layer this spec originally sketched has since been built — see
`specs/implemented/drive_core.md` and `specs/implemented/drive_encryption.md`. This spec
now covers **only the Office-editing engine integration on top of the built Drive**.

## Goal

Let members **open, edit, and save** Microsoft Office documents (`.docx`, `.xlsx`,
`.pptx`) in the browser, inside the existing Drive workspace. Target ~50% feature
coverage — "basic editing" — without destroying the documents users bring in.

## The core reframe

The Office file format is the *easy* part. A `.docx`/`.xlsx`/`.pptx` is a ZIP of XML,
and mature libraries already read/write them (PhpSpreadsheet, PHPWord, and Python
equivalents). Pulling values out and writing them back is a tractable, well-trodden job.

The *hard* part is the editing surface:

1. **In-browser WYSIWYG layout fidelity** that roughly matches Word/Excel/PowerPoint —
   a multi-year effort *per format*. Spreadsheets additionally need a formula engine
   (hundreds of functions + recalculation); presentations are a per-slide vector canvas.
2. **Round-trip preservation.** "50% coverage" naively built means "silently delete the
   other 50% on every save." Real documents carry features we won't implement; we must
   preserve XML we don't understand while editing the parts we do. This is the actual
   engineering wall.

**Conclusion:** do not build the editing engine. Integrate an existing open-source engine
that already solved fidelity + round-trip, and build the parts that fit our platform.

## What we own vs. what we borrow

| Layer | Decision | Why |
|---|---|---|
| File storage, folders, sharing, permissions, versioning | **Built** — Drive shipped 2026-07-11 (`specs/implemented/drive_core.md`) | Platform-level; this spec consumes it. Search text-extraction and Office thumbnails remain to build here. |
| OOXML editing engine (render + edit + round-trip save) | **Borrow** (OnlyOffice or Collabora) | A decade of work already done under an OSS license. |
| Headless format ops (text extraction for search, thumbnail/preview generation, server-side conversions) | **Borrow libraries** (PhpSpreadsheet / PHPWord / LibreOffice headless) | Cheap, no editor needed. |

The borrowed editor runs as a **separate server** (Docker), and the editor UI runs in the
**user's browser**. Our PHP never parses OOXML for the editing path — it only implements a
host-side contract for load/save.

## Editor engine options

Both follow the identical pattern: a standalone Dockerized server + a browser editor +
a server-to-server load/save contract our PHP implements. Neither is a `composer` library.

### Option A — OnlyOffice Document Server
- **Package:** `onlyoffice/documentserver` Docker image (Node.js + C++).
- **Open a doc:** page loads OnlyOffice `api.js`, instantiates the editor in a `<div>`,
  handed a JSON config containing `document.url` (a URL on *our* server the Document
  Server downloads the file from). The whole config is signed as a **JWT** with a shared
  secret. PHP job = build JSON + sign it. OnlyOffice ships an official PHP example.
- **Save a doc:** config carries a `callbackUrl`. Document Server POSTs there when editing
  ends / on intervals, with a link to the edited file. Our PHP endpoint downloads it and
  writes back to storage. That handler *is* the save path.
- **License:** **AGPLv3** (community edition). AGPL reaches network use — see fork below.

### Option B — Collabora Online (CODE)
- **Package:** `collabora/code` Docker image (LibreOffice under the hood).
- **Contract:** **WOPI** (Microsoft's "Web Application Open Platform Interface"), a
  standardized protocol rather than a bespoke one. Editor embeds in an `<iframe>`; our PHP
  implements a few WOPI REST endpoints: `CheckFileInfo` (metadata JSON), `GetFile` (stream
  bytes), `PutFile` (receive saved bytes).
- **License:** **MPLv2** for CODE (more permissive than AGPL). The polished, supported
  build is a paid Collabora product.

### The "API to PHP" in plain terms
There is no client SDK to call. The integration *is* a host-side contract we implement:
- **OnlyOffice:** sign a JWT config + handle a save callback.
- **Collabora:** implement three WOPI endpoints.
Either is a few hundred lines of PHP plus a container to run.

## Licensing fork (decide before building)

- **OnlyOffice / AGPLv3** — strong copyleft that extends to software offered over a
  network. We run it as a separate service and talk to it over HTTP/JWT, which is the
  intended integration boundary, but the AGPL implications for a hosted multi-tenant
  product need a deliberate read before committing.
- **Collabora CODE / MPLv2** — file-level copyleft, far friendlier to a commercial hosted
  product; the trade is that the free CODE build is positioned for smaller/dev use and the
  supported build is paid.

This is the single most important non-engineering decision and gates everything else.

## The Drive layer (already built — this spec consumes it)

Drive shipped 2026-07-11; see `specs/implemented/drive_core.md` for the authoritative
data model. What the editor integration builds against:

- **Folders + files** — `Folder` (`fol`) tree per member owner; files reuse `fil_files`
  with `fil_fol_folder_id`; physical bytes live in the refcounted `fbb_file_blobs` layer.
- **FileVersion** (`fvr`) — immutable snapshot rows; the editor's periodic and manual
  saves create versions, so round-trip never overwrites the only copy.
- **Sharing** — `FileAccessGrant` (`fga`, roles `viewer`/`editor` — there is no comment
  role) and `FileShareLink` (`fsl`, anonymous `/s/{token}` links). Editor access maps to
  the `editor` role.
- **Change feed / quota** — `FileChange` (`fch`) must record editor saves; quota is the
  `drive_storage_bytes` tier feature enforced at upload/version creation.

**Hard constraint — encryption:** Drive folders can be client-custody E2E encrypted
(`specs/implemented/drive_encryption.md`). The editor server necessarily reads plaintext,
so **Office editing is offered for plaintext folders only**; encrypted files never flow
through the editor contract. (Editable-or-encrypted is a per-folder choice — already
recorded in the drive_encryption spec.)

New build surface for this spec (all that remains):
- **Editor host contract** — a dedicated endpoint pair (JWT config + save callback for
  OnlyOffice, or WOPI `CheckFileInfo`/`GetFile`/`PutFile` for Collabora), gated through
  `SessionControl` + the share grants, writing saves as new `FileVersion` rows.
- **Search** — headless text extraction (PhpSpreadsheet/PHPWord/LibreOffice headless)
  feeds the existing search surface; no editor involved.
- **Thumbnails/preview** — LibreOffice headless renders preview images on upload/version.

## What "50% / basic editing" means here

Because we borrow the engine, baseline fidelity is high "for free" — the scoping is mostly
about *our* surface, not the editor's. In scope:
- Open/edit/save the three formats with round-trip preservation handled by the engine.
- Create-new-blank-document of each type.
- Folders, rename, move, soft-delete/restore, version history.
- Sharing with view/edit permission levels; member-to-member and (optionally) link sharing.
- Search by filename + extracted text; thumbnails.

Explicitly out (first pass):
- Real-time multi-cursor co-editing polish, comments/track-changes workflow surfacing,
  granular cell/range permissions, offline sync clients, and any custom rendering of our
  own (we defer entirely to the engine).

## Open questions / decisions to make later

1. **Engine + license:** OnlyOffice (AGPL) vs Collabora CODE (MPL). Gates everything.
2. **Tenancy of the editor server:** one shared Document Server/CODE container for all
   members vs. per-org isolation; how the JWT/WOPI tokens scope access.
3. **Storage handoff:** does the editor pull from a signed bucket URL directly, or proxy
   through our PHP so all access stays behind our permission checks? (Leaning proxy.)
4. **Versioning granularity:** keep every autosave callback as a version, or coalesce.

Resolved by the Drive build (2026-07-11): ownership is member-owned (`fol_usr_user_id`);
quota is the `drive_storage_bytes` tier feature — neither is open anymore.

## Notes for whoever picks this up

- This slots onto the built Drive (data model, permissions, versioning, quota, routing) —
  the new build is the editor host contract plus search/thumbnail extraction, not an
  editor and not a storage layer.
- Keep it platform-level: a generic file workspace + Office editing capability, not a
  product-specific feature. Any single product (e.g. ScrollDaddy) is one consumer, not the
  target.
- Before any build: a focused spike standing up one engine in Docker and round-tripping a
  single real `.docx` through download → edit → callback/PutFile → save will de-risk the
  whole contract in an afternoon.
