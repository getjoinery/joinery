# Joinery AI — File uploads (chat + recipes)

**Status:** Draft — **security review complete; all decisions resolved.** A+B (types &
transport), C (recipe attach-point: chat ships first, recipe attachments land with the
taint-gate change, not before), D (limits: image-handling posture settled, numeric caps
tunable), E (privacy: inherit `isPrivate()` as-is) are all decided. The eight-point threat
model below is worked through; §1's tool-authority crux is resolved (§1a). What remains is
building the feature itself — v1 scope is **chat only, images + PDF + plaintext family**
(Decision A+B phasing), and the must-verify list for the build is in
**Implementation notes** near the end.

**Prerequisite platform hardening — LANDED IN CODE (not part of the feature build):**
The review found three live, pre-existing holes the feature would have inherited; all are
fixed platform-wide and merged, independent of this spec:
- **Serve-back SVG XSS (§7)** — inline serving switched from a blocklist to an allowlist
  (`File::is_inline_safe_type()`); SVG and all non-raster types now download. The whole
  header set is owned by `File::serve_from_path()`, which every gated/signed stream calls.
  Also fixed the public path (`RouteHelper::serveStaticFile`).
- **No magic-byte MIME detection (§8)** — `File::save()` now detects `fil_type` from the
  stored bytes' magic numbers on every insert, so a spoofed type can't bypass the serve-back
  allowlist. Enforced inside the model, not per ingest path — every upload door, present and
  future, complies by construction.
- **ImageMagick on uploaded bytes (§2)** — image handling consolidated onto **GD**
  (`File::generate_resized` + `UploadHandler`); ImageMagick fully retired from first-party
  code and provisioning. Closes the Ghostscript-delegate RCE surface and makes per-node
  `policy.xml` hardening unnecessary.
- **Model affordances (§3, §5)** — the `File` model itself now embodies the review's rules:
  `File::is_image()` and the Multi `picture` filter key on the raster allowlist (never
  `LIKE 'image/%'`, so `image/svg+xml` is a plain file — no size variants, no thumbnail),
  and `File::is_owned_by($user_id)` provides the strict, sessionless ownership check §5
  specifies (no admin bypass, no shared-visibility bypass, deleted ⇒ false).

Everything else below (the encoder, ingress, recipe taint-gate change, DoS subprocess, IDOR
checks, extraction) is **design-decided but unbuilt** — it lands with the feature.
**Plugin:** `joinery_ai`
**Depends on:** `specs/implemented/joinery_ai_llm_providers.md` (canonical-IR provider
boundary), `specs/implemented/file_source_origin_tag.md` (`fil_source` tag),
`specs/implemented/file_private_storage.md` / `file_signed_urls.md` (private bytes).
**Aligns with:** `specs/joinery_ai_visual_compactor.md` — its "objects as rows"
forward rule dictates the chat storage model (each upload is its own box).

## Goal

Let a user hand the AI a file — a screenshot, a photo, a PDF, a spreadsheet — and
have the model actually read it, in **both** surfaces the platform already has:
the interactive **chat**, and the scheduled **recipes**. Do it once, in shared
plumbing, so a second surface never means a second implementation.

## In plain terms

Today the AI can only read text you type. This adds the ability to attach a file so
the model can see it. In chat, you drop a file into the composer and ask about it. In
a recipe, you attach a reference file to the recipe once (say, a portfolio PDF or a
baseline spreadsheet) and every scheduled run gets to consult it.

Under the hood there is one hard fact that makes this cheap: the AI runtime already
speaks in **content blocks** (the Anthropic message shape) as its internal format,
and every request — chat or recipe — funnels through one provider call. A file just
becomes one more block in a message. The Anthropic path forwards it untouched; the
OpenAI-compatible/Fireworks path translates it; that is the whole transport change.
Everything else is ingress (getting the file in) and storage (keeping it).

## What already exists (and is reused)

- **Canonical IR = Anthropic blocks.** Both `RecipeRunner` and `ChatRunner` build a
  `messages` array of content blocks and call one
  `LlmProviderInterface::createMessageStreamed()` through the shared `AgentLoop`
  (`AgentLoop.php:138`). Image/`document` blocks are structurally valid in that IR
  already — nothing produces them yet.
- **Anthropic passthrough.** `AnthropicProvider::createMessageStreamed()` posts
  `$params` verbatim (`AnthropicProvider.php:139`); an `image`/`document` block placed
  in a message flows to the API with **zero provider change**.
- **`File` model + `fil_source`.** Private, owner-scoped file storage with a source
  tag (`File::SOURCE_AI_CHAT_UPLOAD` already defined). `File::createFromBytes()`,
  `File::read_bytes()`, and `fil_private=true` give us store + read-back for free.
- **Untrusted-input contract.** `AiPromptBuilder::untrustedInputBlock()` is the shared
  place both surfaces mark content the model must not obey as instructions.
- **Shared helpers.** `AgentLoop`, `AiPromptBuilder`, `LlmProviderException`,
  `LlmProviderFactory`, `RecipeToolRegistry` are already the unification seam — the
  new encoder joins them.

## The shared core (identical for both surfaces)

### 1. A `File` → canonical content-block encoder (route by type, text-first)

One new helper (natural home: `includes/AiAttachment.php`, beside `AiPromptBuilder`).
It detects the file's **real** MIME (magic bytes, not the client's extension), routes
it to the cheapest door the model can consume, and returns the canonical block(s) for
a message's `content` array — enforcing type/size policy and untrusted framing.

| Detected type | Default transport | Canonical block |
|---|---|---|
| `image/*` (png, jpeg, webp, gif) | vision | `{type:'image', source:{type:'base64', media_type, data}}` |
| `application/pdf` with a text layer | **extract text** (cheap) | `{type:'text', text}` |
| `application/pdf` scanned / no text layer | vision fallback | `{type:'document', source:{base64 pdf}}` |
| docx / xlsx / csv / txt / md / json / html | **extract text** | `{type:'text', text}` |
| anything else | rejected at ingress | — |

**Text-first is the default** because a native PDF document block costs Claude a
per-page text+image ingest, while extracted text is often 5–20× cheaper for a
text-heavy doc. Vision is the fallback for genuinely visual content (charts, scans,
diagrams) or when the user asks the model to *look* at it. Both
`ChatRunner::buildHistoryMessages()` and `RecipeRunner::run()` call this one encoder,
so the block shape can never drift between surfaces.

**Per-chat override — original files (`aic_attachment_mode`).** The user can flip a
chat from the extract-text default to **original mode**, where a file is sent whole
whenever the model has a native door for it: every PDF goes as a `document` block
(layout, tables, and charts survive; the model sees the real pages), and HTML goes as
raw markup in an inert text block instead of stripped visible text. Types with no
native door are unaffected — docx/xlsx are still extracted (no provider ingests them
natively), csv/txt/md/json extraction *is* the full content, and images are always
sent whole in both modes. The mode is one more input to the same encoder — the
routing table's "default transport" column is what it varies; nothing else about the
pipeline changes. Stored per conversation (`aic_attachment_mode`: `extract` default /
`original`), edited in the existing per-chat settings sheet alongside model and
temperature. The mode is **read at send time and applies to the whole history that
turn** — `buildHistoryMessages()` rebuilds every turn, so flipping the mode re-routes
all of the chat's attachments on the next send, exactly like changing the model or
temperature; nothing is frozen per-attachment. The trade is explicit: original mode
buys fidelity at per-page vision cost, which is exactly why extract stays the default.

**Extraction tooling** (pure-PHP Composer — no per-node system packages):
`smalot/pdfparser` for PDF text; PhpOffice (`PhpWord`/`PhpSpreadsheet`) for docx/xlsx;
native for csv/txt/md/json; tag-strip for html; scanned-PDF OCR (Tesseract) deferred.
All extraction runs on **already-stored, MIME-validated bytes**, never fetches by URL,
and runs with XML entity resolution disabled under size/time/memory caps (Security §2–4).

### 2. Provider translation (the only transport change)

- **Anthropic / (Claude):** no change. Passthrough (`AnthropicProvider.php:139`).
- **OpenAI-compatible + Fireworks:** extend
  `OpenAiCompatibleProvider::appendCanonicalBlocks()`
  (`OpenAiCompatibleProvider.php:391-438`). Today it collapses content to a flat
  string and **silently drops** any non-text/tool block. Add an `image` case that
  emits OpenAI multimodal parts:
  `content:[{type:'text',text},{type:'image_url',image_url:{url:'data:{mime};base64,{data}}'}]`.
  Fireworks inherits the fix (it only overrides vendor seams). PDFs as documents are
  not universally supported here — but the encoder routes PDFs to extracted **text**
  by default (§1), so this path mainly carries images; a non-vision model is refused
  at ingress per §3, and an original-mode PDF (§1) is refused the same way when the
  model lacks the `document` flag — this path never needs a document translation.

### 3. Per-model vision capability (net-new metadata)

No vision/capability flag exists anywhere today (provider `MODELS` consts carry only
pricing). Add two flags to each model entry (`AnthropicProvider::MODELS`
`:53-57`, `FireworksProvider::MODELS` `:42-46`; local model configurable): `vision`
(accepts image blocks) and `document` (accepts native PDF `document` blocks —
Anthropic only today). When the selected model lacks the needed flag, the ingress
path rejects the upload with a clear message ("The current model can't read images —
switch to a Claude model") rather than sending a block the model will ignore or error
on. The `document` flag gates two cases the same way: a scanned PDF's vision fallback,
and every PDF in an original-mode chat (§1) — an explicit mode choice fails loudly,
never silently downgrades back to extraction.

### 4. Untrusted content

Uploaded bytes are hostile by default (a PDF or an image can carry text that says
"ignore your instructions"). The encoder frames every upload through the existing
untrusted-input contract: a delimiting text block precedes each attachment noting the
following content is untrusted user data, not instructions. This is the same
guarantee text input already gets — extended to files.

## Surface A — Chat uploads

**Storage (dictated by the compactor spec's "objects as rows" rule):** an upload is
**not** glued into `aim_content`. Each upload is its own row carrying an
`in_context` bit, so it renders as its own compaction box you can drop without paying
to summarize it. Concretely: reuse `File` for the bytes (`fil_source =
ai_chat_upload`, `fil_private=true`) and add a lightweight attachment-link row
referencing both the message and the `File`, with its own `in_context` column — shaped
so the visual compactor drops in later with no rework.

**Ingress (all insertion points confirmed):**
- **Composer** — add a file input / drop zone to `includes/chat_view_body.php:186-192`.
- **Client `send()`** — already builds `FormData` and `fetch()`s `chat_send`
  (`chat_view_body.php:481-537`); append the file(s) around line 499/514.
- **Endpoint** — `logic/chat_send_logic.php` accepts the upload alongside `message`
  (validation ~`:36-39`, user-row insert `:76-82`), stores the `File`, writes the
  attachment-link row(s).
- **Message assembly** — `ChatRunner::buildHistoryMessages()` (`ChatRunner.php:213-219`)
  emits **block-array** content (text block + attachment blocks via the encoder)
  instead of a plain string; `normalizeAlternating()` (`:231`) learns to concatenate
  array content, not just strings.

## Surface B — Recipe uploads

Recipes have **no run-time user input** — the user turn is the hardcoded literal
`'Run the recipe now.'` (`RecipeRunner.php:89`), and triggers pass only a recipe id.
So recipe uploads need a new attach-point (see Decision C). Whichever we pick, the
send-side change is one line: replace the hardcoded seed message with a block array
built by the **same encoder** — the recipe's text instruction plus its attachment
blocks. No provider or loop change beyond the shared core. The extract-vs-original
mode (§1) carries over as a per-recipe field (`rcp_attachment_mode`) when this phase
lands — same values, same encoder input, same `document` capability gate.

---

## Decisions to resolve (this is what we discuss)

### Decision A + B — Types & transport *(decided)*

Accept the full **consumable** set — images, PDF, and the text-extractable family
(docx, xlsx, csv, txt, md, json, html) — and **route each type to its cheapest door,
text-first, vision as fallback** (encoder table above). "Broad" is bounded by
consumability + parser cost, not an arbitrary allowlist: a type earns inclusion when
we have a safe extractor or a vision path for it. Formats with neither door
(`.zip`, `.mp4`, `.exe`) are rejected at ingress — accepting them would only surface
an error.

Text-first is the **default**, not a ceiling: a per-chat `aic_attachment_mode`
setting lets the user send original files instead where a native door exists (PDF as
`document` block, HTML as raw markup — §1). It changes only which door the encoder
picks, gated by the model's `document` capability (§3); the accepted-type set and
ingress rejection are identical in both modes.

**Phasing — decided:** ship **images + PDF + plaintext family first** (v1);
docx/xlsx are a fast follow in their own pass (they add the PhpOffice dependency and
the ZIP/XXE parser surface — Security §2–3, including the install-time XXE assertion
test). The phased order exercises the whole shared core and the highest-value types
first, and keeps the office-parser surface out of the initial change.

### Decision C — Where a recipe file attaches *(resolved: C1 + C4 phasing)*

**Decided:** ship chat uploads first (**C4 phasing**); recipe attachments land later as
**C1** (on the recipe definition), gated on the §1 taint-gate change. Chat delivers most
of the value and exercises the whole shared core; recipe uploads are held until an
attachment is a recognized untrusted source in `TaintGate` (§1 "Resolved"), because
without that a recipe upload can drive an unattended write. The options considered:

- **C1 — On the recipe definition *(chosen for the recipe phase)*.** A `rcp_*` attachment
  link; every run consults the same reference file(s). Matches recipes being static config
  ("here's my portfolio, track it weekly"). Edit-form UI + swap the seed message.
- **C2 — Per run (at manual "Run Now").** Adds a run-input concept the system lacks;
  scheduled runs can't supply per-run files anyway, so it's a narrow, larger build.
- **C3 — A `read_file` tool.** The LLM pulls an owner-uploaded file on demand. Fits the
  tool model but tool-result media is awkward and puts the choice in the model's hands.
- **C4 — Defer recipes to a later phase *(chosen for phasing)*.** Ship chat uploads first;
  recipes reuse the finished encoder later.

### Decision D — Limits *(image-handling posture settled; numeric caps still tunable)*

Per-file size cap (base64 inflates ~33%; providers cap request/image size), max files
per message/recipe, and how to handle oversized images. Proposed starting point: images
≤ ~5 MB each, PDFs ≤ ~10 MB, ≤ 5 attachments per message. Tunable via settings.

**Oversized-image handling — decided (tiered, see Security §2):** not a binary
downscale-vs-reject. Read dimensions with `getimagesize()` (header-only, no pixel decode);
**reject before any decode** above a sane dimension ceiling (~50 MP — the image
decompression-bomb guard); **pass through untouched** when within the provider's
byte+dimension limits (the common case, zero decode); **downscale only the
oversized-but-under-ceiling minority via GD, inside the §4 timeout+memory subprocess.**

### Decision E — Privacy note (not a blocker) *(resolved: inherit isPrivate() as-is)*

Providers expose `isPrivate()`. Verified values: **local = private**
(`OpenAiCompatibleProvider.php:69-72`), **Fireworks = private** — a vetted no-train remote,
so it suppresses the warning (`FireworksProvider.php:91-93`), **Anthropic = not private**,
so it triggers the warning (`AnthropicProvider.php:83-84`). Note `isPrivate()` therefore
means "on-device **or** vetted no-train remote," not "the bytes never leave the box" — a
Fireworks upload still goes off-box but shows no warning.

**Decided:** v1 inherits the surface's existing `isPrivate()` behavior unchanged — no
upload-specific warning or gate. No provider does anything special here today, and the
existing flag is acceptable for uploads. A stricter "warn on any off-box provider for
uploads" notice, or a "private-models-only for uploads" gate (and whether that gate would
permit no-train remotes or only on-device), is a possible later toggle, not v1.

## Up-front integration inventory

Everything the feature touches, decided once:

| Site | Change |
|---|---|
| `includes/AiAttachment.php` (new) | `File`/bytes → canonical block(s); type+size policy; untrusted framing; extract-vs-original routing per `aic_attachment_mode` |
| `AnthropicProvider.php` | none (passthrough verified `:139`) |
| `OpenAiCompatibleProvider::appendCanonicalBlocks()` `:391-438` | add `image` → `image_url` translation; Fireworks inherits |
| `AnthropicProvider::MODELS` `:53-57`, `FireworksProvider::MODELS` `:42-46` | add `vision` + `document` capability flags; local model configurable |
| model validation (`ChatControls.php:45`, `chat_controls_logic.php:22`) | gate uploads on selected model's `vision`/`document` flags |
| `data/ai_conversations_class.php` + per-chat settings sheet | `aic_attachment_mode` column (`extract` default / `original`), edited alongside model/temperature |
| `AiPromptBuilder::untrustedInputBlock()` | frame uploaded content as untrusted (reused) |
| `data/ai_conversation_messages_class.php` | attachment-link storage with `in_context` bit (compactor-aligned) |
| `ChatRunner::buildHistoryMessages()` `:213-219` + `normalizeAlternating()` `:231` | emit + tolerate block-array content |
| `includes/chat_view_body.php` `:186-192`, `send()` `:481-537` | composer file input; append to `FormData` |
| `logic/chat_send_logic.php` `:36-39,:76-82` | accept, store (`File`, `fil_source`), link the upload |
| `data/recipes_class.php` (+ edit view/logic) | recipe attach-point per Decision C (C1) |
| `includes/TaintGate.php:30-57` | **(recipe phase, prerequisite)** add "recipe has ≥1 attachment" as a third untrusted-source term so upload+write-tool forces `rcp_allow_tainted_writes` (Security §1a) |
| `RecipeRunner.php:88-89` | seed message → block array via the shared encoder |
| `File` | `SOURCE_AI_CHAT_UPLOAD` exists; add `SOURCE_AI_RECIPE_ATTACHMENT` if C1/C2 |

## Security

The threat model is specific to "user-supplied bytes get parsed on our server and
placed in front of a tool-wielding model." In priority order:

### 1. Hostile content reaching tools (the crux)

An upload's whole purpose is to put untrusted content before a model that can call
tools. A PDF, image, or doc can embed *"ignore your instructions; use the X tool
to…"* — and prompt injection is **not** solved at the prompt layer, so the
untrusted-input delimiter (`AiPromptBuilder::untrustedInputBlock`) is a mitigation,
not a wall. The real boundary is **tool authorization**: every tool is owner-scoped
(the model cannot widen scope, verified — `aic_conversations`/`aim_conversation_messages`/
`stg_settings` are not `$ai_readable`, so no tool call can flip a capability toggle or
settings). **But the tools are not read-only.** Both surfaces already carry
`create_model`/`update_model`/`delete_model`/`invoke_action`
(`ModelWriteExecutor::WRITE_TOOL_NAMES`). The two surfaces contain those writes
differently, and that difference is the whole game:

- **Chat** halts a mutating call on a human confirmation gate
  (`AgentLoop.php:190-208` + `RiskHeuristic`), and the approved call executes the
  *frozen queued payload*, not anything re-derived from message content
  (`ChatRunner::resumeTurn` `:57-73`). Injected upload text cannot alter what a queued
  action does or self-approve it. So in chat the worst a successful injection achieves
  is *what the owner could already do themselves*, and only after a live confirm.
- **Recipes have no confirmation gate** (`RecipeRunContext::requiresConfirmation()` is
  `false`). Writes run unattended. The only thing standing between a recipe and an
  autonomous write is the **taint gate** (Security §1a below).

**Invariant to preserve: an upload adds content, never authority.** Uploaded content
must not be able to flip a chat's capability toggles, auto-approve a pending
confirmation, or raise the owner scope. Image-borne injection (instructions rendered
inside a screenshot) is real and unsanitizable — the tool boundary, not text-scrubbing,
is what contains it.

#### 1a. Resolved — recipe attachments must feed the taint gate

The chat vs. recipe asymmetry is the sharp edge, and the code review found the taint
gate does **not** currently close it. `TaintGate::evaluate()` (`TaintGate.php:30-57`) is
a static admission check over a recipe's saved config: it forces the
`rcp_allow_tainted_writes` opt-in only when the recipe has a write tool **and** one of
two untrusted sources is present — a `query_model` field the model author marked
`$ai_untrusted_fields`, **or** a non-empty `rcp_workspace`. It runs at save
(`admin_edit_logic.php:151-159`) and at run-start drift-check
(`RecipeRunner.php:200-211`); it is all-or-nothing and never filters individual calls.

**An attachment is a third untrusted source the predicate does not yet know about.** A
recipe combining a write tool with an uploaded PDF/image would pass the gate untouched —
no opt-in forced — and drive an unattended write from injected file content. That is the
live hole behind this feature's recipe surface.

**The fix (prerequisite for recipe uploads):** an upload is untrusted input, so
attaching a file to a recipe must feed the taint predicate exactly like a workspace or an
untrusted model field. `TaintGate::evaluate()` gains a third untrusted-source term —
"recipe has ≥1 attachment" — so any recipe pairing an upload with a write tool requires
the explicit `rcp_allow_tainted_writes` acknowledgment. This lands **with** recipe
uploads (they do not ship before it). Chat is unaffected: its per-call confirmation gate
already contains the same risk, and the review confirmed a queued action executes its
frozen payload, immune to later message content.

**Invariant to preserve: an upload adds content, never authority.** Uploaded content
must not be able to flip a chat's capability toggles, auto-approve a pending
confirmation, or raise the owner scope. **Verified in code:** `aic_conversations`,
`aim_conversation_messages`, and `stg_settings` are not `$ai_readable`, so they never
enter `ModelRegistry` and no `query_model`/`*_model` call can reach them; the toggles are
writable only via session-owned, CSRF-gated browser requests
(`chat_set_capabilities_logic.php`, `ApiAuth::authenticateBrowserSession`); and a running
turn snapshots its tool list once (`ChatRunner.php:165`) so a mid-turn toggle flip cannot
widen an in-flight turn. Image-borne injection (instructions rendered inside a screenshot)
remains real and unsanitizable — the tool boundary, not text-scrubbing, is what contains
it.

**Note the deferred cliff.** When write tools expand or a member-facing recipe surface
lands (`specs/joinery_ai_write_tools.md`), re-derive this boundary rather than assume it —
and remember `set_workspace`/`save_note` are writes the taint gate deliberately excludes
from `WRITE_TOOL_NAMES`, and `fetch_url`/`web_search`/market-data outputs are neither
taint sources nor delimiter-wrapped today.

### 2. Parser attack surface *(verified + platform consolidated onto GD)*

Extractors parse hostile bytes server-side. **Prefer pure-PHP parsers**
(`smalot/pdfparser`, PhpOffice) so a malformed file causes at worst a PHP
exception/DoS, not the native memory-corruption RCE a C library can. Detect real MIME by
magic bytes and route on that — never the client extension (see §type spoofing).

**Image handling: GD, not ImageMagick — done, platform-wide (pre-existing hole closed).**
The review found the platform ran *ImageMagick* on every uploaded image (`File::generate_resized`
and `UploadHandler`'s scaler), under an `/etc/ImageMagick-6/policy.xml` that did **not**
coder-allowlist — so a spoofed PS/EPS/PDF could reach the Ghostscript delegate (native RCE),
independent of this feature. Rather than fence ImageMagick with a per-node `policy.xml`,
the engines were **consolidated onto GD** (verified to cover every platform format —
jpeg/png/gif/webp/avif; no PDF-raster/HEIC/TIFF need):
- `File::generate_resized()` ported to GD (`data/files_class.php`), preserving the exact
  crop-to-aspect + downscale-only geometry, source-format output, and transparency
  (alpha for png/webp/avif; palette-index for gif, guarded so opaque gifs aren't altered).
- `UploadHandler`'s imagick / ImageMagick-convert paths removed; the three engine
  dispatchers force GD by construction, and its GD scaler gained webp/avif cases.
- This **moots the `policy.xml` hardening entirely** — no ImageMagick means no
  coder/delegate/Ghostscript surface and no per-node config to maintain. GD and ImageMagick
  share the underlying raster codecs (libjpeg/png/webp), so this doesn't dodge a
  libwebp-class decoder CVE (OS-patched either way); the win is removing the coder/delegate
  machinery GD lacks.

For the AI upload path specifically, keep the decoder off hostile bytes wherever possible:
pass in-limit images through **untouched** (no decode); use `getimagesize()` (header-only)
to reject dimension bombs (> ~50 MP) before any decode; and **downscale only the
oversized-but-sane minority via GD, inside the §4 timeout+memory subprocess**.

### 3. XXE + SSRF through office/SVG XML *(verified: environment is default-safe; three decisions locked)*

docx/xlsx are XML; a parser that resolves external entities can read local files
(`file:///etc/passwd`) or reach internal URLs (cloud metadata endpoint) and fold them
into the extracted text → exfiltration. **Verified on the dev box:** libxml is 2.9.14 on
PHP 8.3, where external-entity loading is **off by default** — nothing opts in unless code
passes `LIBXML_NOENT` (which we never do). The platform already has the safe pattern to
copy: `simplexml_load_string($xml, ..., LIBXML_NONET)` (`inbound_email_filter_class.php:514-517`)
and `FetchUrlTool::loadDom()` (`FetchUrlTool.php:266-273`, `DOMDocument` +
`LIBXML_NONET`, no `NOENT`). SSRF is separately well-covered by `UrlSafetyValidator`
(all-record DNS resolution + connection pinning defeats rebinding; blocks
CGNAT/link-local/metadata; fails closed), and extraction never fetches — the only SSRF
path in would be an XXE-triggered fetch, which the default-off entities already block.

Decisions:
- **HTML text extraction uses `DOMDocument` + `LIBXML_NONET`, dropping `<script>`/`<style>`/
  `<head>` and taking visible text — never `strip_tags()`.** `strip_tags()` keeps the CSS/JS
  *bodies* as garbage; on a representative page it produced ~6.7 KB of noise vs `DOMDocument`'s
  328 bytes of clean content. It is the worst-of-both "halfway" option. Original mode
  (`aic_attachment_mode`, §1) passes the raw markup through as an inert text block under the
  untrusted-input framing — a routing choice, not a security path: the bytes are never parsed
  at all, so it carries no extra parser risk.
- **PhpOffice: pin a known-good version and rely on its built-in XXE scanner + libxml
  default-off; do not hand-roll a redundant per-parse entity lockdown** (that would be a
  band-aid fighting the library's own guard). Add **one install-time assertion test** that
  feeds an external-entity payload through the extractor and asserts it did not resolve, so a
  future libxml/PhpOffice downgrade fails CI instead of silently re-opening XXE.
- **SVG is rejected at ingress, never rasterized or vision-routed.** Rasterizing would pull in
  the native image-decoder surface (§2); text-extracting it has near-zero value. Robustness
  rule: the vision route (`png/jpeg/gif/webp`) and the text-extract route
  (`docx/xlsx/csv/txt/md/json/html`) are both **strict allowlists keyed on detected MIME** —
  never "starts with `image/`" or "contains xml." Every finfo guise of an SVG
  (`image/svg+xml`, `text/xml`, `application/xml`) falls through to reject; the only processor
  it can reach is the inert `DOMDocument`+`LIBXML_NONET` HTML path, iff finfo calls it
  `text/html`. The `File` model already follows the same rule: `is_image()` and the Multi
  `picture` filter are keyed to the raster allowlist, so an SVG is never a "picture"
  anywhere in the platform.

### 4. Zip-bomb / resource exhaustion (DoS) *(verified: the assumed caps do NOT exist — real controls specified below)*

A tiny docx or PDF can expand to gigabytes or pin CPU with millions of objects. The
original plan assumed the "detached CLI worker already caps memory + time (the recipe
pattern)." **Verified false.** The worker is `exec("php … &")` with no `timeout`, no
`ulimit`, no cgroup (`RecipeWorkerSpawner.php:95-106`, `ChatWorkerSpawner.php:28-36`);
both CLI entrypoints call `set_time_limit(0)` (`run_recipe.php:45`, `run_chat_turn.php:55`);
`memory_limit` is never set and the CLI default on the box is **-1 (unlimited)**,
`max_execution_time` **0**. The only time guard is cooperative, checked *between*
`AgentLoop` iterations (recipe 90s / chat 240s, `AgentLoop.php:98`) — it cannot interrupt a
hang *inside* one iteration, which is exactly where extraction runs. The reapers
(`RecipeDispatcher.php:45-57`, `ChatAsync.php:108-124`) only flip a DB status row; they
never kill the process. So a bomb runs unbounded on RAM and CPU today.

Real DoS protection must be **added**, as two mechanisms (coreutils `timeout` is present):
- **Input caps in the parent, before parsing.** Reject on raw upload size (Decision D)
  *before* opening the bytes; for ZIP formats (docx/xlsx) sum the entries' *uncompressed*
  sizes and entry count and reject bombs before decompressing. This is the primary control
  for the zip-bomb threat. (`FetchUrlTool.php:22-23,171-174` is the reusable streaming-cap
  pattern for the byte dimension.)
- **Parse in a short-lived `timeout` + `memory_limit` subprocess**, e.g.
  `timeout 20 php -d memory_limit=256M <extract.php> <file>`. This gives hard memory *and*
  CPU/wall-clock ceilings, isolated from the worker: a bomb kills only the child, and the
  parent reads the exit code (124 = timed out, 137 = killed/OOM), marks the attachment
  un-extractable, and continues cleanly. **Do not** cap memory in-process via `ini_set`
  instead — a memory fatal there is uncatchable and takes the whole worker down ungracefully
  (losing the DB-failure cleanup); the subprocess is what makes the cap safe. Also cap the
  extracted-text output length (`FetchUrlTool`'s 50k-char cap is the model).

Cost is minor and bounded: ~tens of ms subprocess spawn per *extractable* file (not images),
under the ≤5-attachment cap; a small extraction CLI + exit-code handling; settings-driven
thresholds. It makes the parent worker simpler and safer, not more fragile.

### 5. IDOR on the file reference

The client posts a file id to attach — a malicious client could post *someone else's*
`fil_file_id`. The model provides the correct affordance: **`File::is_owned_by($user_id)`**
— strict ownership equality plus not-deleted, sessionless, **no admin bypass and no
group/event/tier sharing**. It exists precisely because `is_viewable()` is a trap here:
"viewable" is strictly broader than "owned" (owner-**or-admin** for private files, plus
shared files the user does not own), and it requires a `$session` and throws without one,
which the detached send-side worker does not have.

Two checks in two contexts, both through `is_owned_by()`:

- **Attach time** (browser request; session + CSRF present). Require
  `$file->is_owned_by($session_user_id)` **and** the composer to own the target
  (`aic_owner_user_id` / `rcp_owner_user_id === session user id`).
- **Send time** (detached CLI worker; **no session**). Reload the `File` and require
  `$file->is_owned_by($run_owner_id)` (`aic_owner_user_id` for chat,
  `rcp_owner_user_id` for recipes) before `read_bytes()`. This re-derivation is what
  catches a file reassigned, deleted, or swapped between attach and run (TOCTOU) —
  the not-deleted condition is inside `is_owned_by()`.

Never trust a client-supplied id, and never trust the attachment-link row alone at send
time — re-check the underlying `File`'s owner against the run owner.

### 6. Exfiltration to the provider (privacy)

The upload leaves the box to a third-party model unless a private/local model is
selected (`LlmProviderInterface::isPrivate()`). Sensitive-data disclosure is inherent
to the feature. v1 inherits the surface's model choice and **documents** it; a
"private-models-only for uploads" gate is a later toggle.

### 7. Stored XSS on serve-back *(fixed — was a live, pre-existing hole)*

If an uploaded file is rendered back in the browser (thumbnail/preview), an SVG/HTML
payload becomes stored XSS from our own origin. The review found the private-serve path
did **not** enforce this: it decided inline-vs-download with `strpos($type,'image/')!==0`
(`serve.php`), and `image/svg+xml` starts with `image/`, so SVGs were served inline —
and browsers execute `<script>` inside an inline-rendered SVG. `nosniff` does not help,
because the declared type is already `image/svg+xml`. The public path
(`RouteHelper::serveStaticFile`) set neither `nosniff` nor a disposition.

Fixed (platform-wide, independent of this feature):
- The inline decision is an **allowlist**, not a blocklist:
  `File::is_inline_safe_type()` returns true only for `INLINE_SAFE_TYPES`
  (`image/png|jpeg|gif|webp|avif`). Everything else — SVG, HTML, PDF, office, unknown —
  is served `Content-Disposition: attachment`.
- The full header set (stored `Content-Type`, `nosniff`, allowlist disposition,
  caller-chosen `Cache-Control`) is owned by **one model method,
  `File::serve_from_path()`** — every gated or signed stream in `serve.php`
  (private-cloud, signed, gated-local) calls it, so a serving branch cannot
  half-apply the policy. Gated local files also stream `Cache-Control: private`
  so permission-restricted bytes never land in shared caches.
- The one path that can't use the model — `RouteHelper::serveStaticFile()`, which
  serves first-party assets and the pre-boot public-upload fast path before the
  app boots — always sends `X-Content-Type-Options: nosniff` and forces
  `attachment` for the script-capable-as-document types
  (`image/svg+xml`, `text/html`, `text/xml`, `application/xml`);
  CSS/JS/fonts/raster/PDF still serve inline there. That divergence is by design
  (assets must render inline; no `File` row is in hand), and it still upholds the
  security invariant: no script-capable document ever renders inline from our origin.

No backfill needed for already-stored rows — the platform is pre-launch
([[project_no_production_users]]); the serve-time allowlist protects them regardless, and
new uploads store a detected type (§8).

### 8. Type spoofing & cost abuse *(detection now at the storage layer)*

A `.png` that is really a PDF (or an SVG, or an `.exe`) must route by **detected** MIME,
not the client's extension/Content-Type, or it bypasses the type gate. This is enforced
**inside the model, at the one chokepoint every ingest path shares**: `File::save()` runs
finfo magic-byte detection (`File::detect_mime_file()`) on every insert and stores the
detected type as `fil_type`; the caller-supplied value survives only when the bytes
aren't on disk yet or finfo can't decide. Enforcement was deliberately moved out of the
individual upload paths — a per-call-site version was audited and found already missing
one door (the entity-photos endpoint stored the client's claimed type verbatim), which is
the failure mode of caller-discipline invariants. The AI encoder (§1) still detects
independently before routing to vision/extract, but the stored `fil_type` is trustworthy
for the serve-back allowlist (§7). Every creation path — `createFromBytes` (and inbound
email through it), admin upload, entity photos, anything written later — inherits the
guarantee by construction.

Cost abuse is unchanged: uploads inflate tokens (vision PDFs especially); per-file size
caps, per-message count caps (Decision D), and the text-first default shrink the blast
radius, and `CostGuard` / monthly token caps remain the economic backstop. Original mode
(§1) raises per-file cost by design — the same caps and `CostGuard` bound it, and it is
the chat owner's own spend either way.

## Implementation notes — the invariants the platform cannot enforce for you

Most of this spec's security posture is enforced by construction (`File::save()`
detection, `serve_from_path()` headers, `is_owned_by()` semantics) — an implementation
cannot accidentally undo it. The following is the short list that **is** caller
discipline, and it is where review attention goes. Treat each as a requirement, not a
suggestion:

1. **Ownership is checked twice, and only via `is_owned_by()`.** Attach time:
   `$file->is_owned_by($session_user_id)` **and** the conversation is owned by the same
   user. Send time (detached worker, **no session** — `is_viewable()` throws there):
   reload the `File`, require `is_owned_by($run_owner_id)` before `read_bytes()`. Do
   not trust the attachment-link row alone at send time; do not substitute
   `is_viewable()` or `is_owner_or_admin()` at either site (§5 explains why they are
   traps, not alternatives).
2. **Every attachment block goes through the one encoder, and the encoder always
   emits the untrusted-input framing.** No call site builds an image/document/text
   attachment block by hand — if a second place constructs blocks, that is the defect,
   independent of whether its output looks correct.
3. **Extraction runs only in the `timeout` + `memory_limit` subprocess** (§4). Do not
   "simplify" to `ini_set('memory_limit')` in the worker — an in-process memory fatal
   is uncatchable and kills the worker's cleanup path; the subprocess is what makes the
   cap safe. The parent must handle exit codes 124 (timeout) and 137 (OOM/kill) by
   marking the attachment un-extractable and continuing the turn.
4. **Capability gating happens at ingress, with a user-facing message** (§3). A
   missing `vision`/`document` flag rejects the upload at `chat_send`; it never
   silently drops the block, silently downgrades original mode to extraction, or lets
   the provider return a confusing API error.
5. **Type decisions key on detected MIME only.** The encoder routes on magic-byte
   detection (stored `fil_type` is detection-backed per §8); the client's filename,
   extension, and claimed Content-Type are display metadata. No `strpos('image/')`,
   no extension maps.
6. **Platform conventions apply to the new pieces.** The attachment-link table is a
   normal data class: schema lives in `$field_specifications` (never a migration),
   with a deletion strategy (`$foreign_key_actions` — deleting a conversation or a
   message must not orphan links or leak `File` rows). `aic_attachment_mode` is a
   field-spec addition with a plain-value default (`'extract'`). The composer UI is
   vanilla JS in the existing theme; the settings-sheet field follows the existing
   sheet's pattern. `smalot/pdfparser` arrives via Composer under the existing
   `composerAutoLoad` setting, not a system package.

When the build is done, verify each item above against the diff explicitly — they are
exactly the points a plausible-looking implementation gets wrong.

## Out of scope (v1)

- Scanned-image PDFs / OCR (Tesseract) — extraction covers text-layer PDFs only;
  a no-text-layer PDF falls back to a vision `document` block (§1), not OCR.
- Audio/video.
- Generating/returning files *from* the model (this is input only).
- A "private models only" upload gate (Decision E) beyond documentation.
- Per-box manual restore of a dropped attachment — that rides on the compactor spec.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` (current-state voice):
an "Attachments" section covering supported types, the shared encoder, the per-model
vision gate, the untrusted-content framing, and how chat vs. recipe ingress differ.
Note the attachment-as-its-own-row model alongside the compactor's objects rule.
