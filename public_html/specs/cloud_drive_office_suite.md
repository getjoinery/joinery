# Cloud Drive + Office Editing — Exploratory Spec

**Status:** Sketch / thinking-out-loud. Not committed to implementation. Captures the
architecture discussion so we can come back to it.

## Goal

Stand up a Google-Drive-style file workspace where members can store files and
**open, edit, and save** Microsoft Office documents (`.docx`, `.xlsx`, `.pptx`) in the
browser. Target ~50% feature coverage — "basic editing" — without destroying the
documents users bring in.

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
| File storage, folders, sharing, permissions, versioning, search, thumbnails | **Build** (platform-level) | This is our wheelhouse; we already have an S3-compatible bucket, file management, and a permissions/session model. |
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

## The Drive layer (what we actually build)

Leverage the existing cloud storage + file handling. Sketch of the data model (follows the
platform's Active Record pattern; field specs drive schema via `update_database`):

- **Folder** — tree of folders per owner (member or org), parent pointer, name, soft-delete.
- **DriveFile** — a stored file: owner, folder, display name, storage key (in the
  S3-compatible bucket), mime/type, size, current version pointer, soft-delete.
- **FileVersion** — immutable snapshot rows so the editor's periodic saves and manual
  saves are versioned; storage key + size + created-by + created-time. Enables history and
  safe round-trip (we never overwrite the only copy).
- **FileShare** — grant of a file/folder to a user (or link/role) with a permission level
  (view / comment / edit), reusing the platform's permission model rather than inventing one.

Cross-cutting, all reusing existing platform systems:
- **Permissions/session** — gate every load/save through `SessionControl` + the share grants.
- **Routing** — front-controller routes for the workspace UI; a dedicated endpoint pair for
  the editor contract (config+callback for OnlyOffice, or the WOPI endpoints for Collabora).
- **Search** — headless text extraction (PhpSpreadsheet/PHPWord/LibreOffice headless) feeds
  the existing search surface; no editor involved.
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
5. **Org vs personal ownership model:** does Drive attach to members, orgs/groups, or both.
6. **Quota/limits:** storage caps per tier; tie into the existing subscription tier system.

## Notes for whoever picks this up

- This slots onto existing platform layers (cloud storage, file handling, permissions,
  routing, subscription tiers) — the new build is the Drive data model + the editor host
  contract, not an editor.
- Keep it platform-level: a generic file workspace + Office editing capability, not a
  product-specific feature. Any single product (e.g. ScrollDaddy) is one consumer, not the
  target.
- Before any build: a focused spike standing up one engine in Docker and round-tripping a
  single real `.docx` through download → edit → callback/PutFile → save will de-risk the
  whole contract in an afternoon.
