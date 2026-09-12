# Joinery editor: one editing surface for HTML and markdown fields

**Status:** Implemented 2026-09-12 (WP1–WP4, same day as the spec). Replaces
Trumbowyg (and with it the platform's last jQuery load) and absorbs the
markdown editor (`assets/js/markdown-editor.js`) so the platform has one
editor with two dialects. Owner decisions: the browser's built-in editing
commands ("the easy way"); D1 decided, cleanup runs in JavaScript only.

Two things settled during the build, both in the tests:

- **Clean up is reversible through its own button, not native undo.** Chrome
  keeps the outermost block of the old content whenever the whole selection
  is replaced through `insertHTML` (probed: `<div class="x">…</div>` survives
  every variant, so the button could never produce exact output through the
  undo stack). The button therefore writes the surface directly and reads
  **Undo clean up** until the next edit; one saved string, cleared on input.
- **A component's DB schema row catches up on the next sync.** On dev the
  `custom_html` row still carries the old schema until "Sync with Filesystem"
  (admin Plugins page) or an upgrade runs; that is the deployment step for
  every node and it is a database write, so it is the owner's.

## The goal, in one sentence

**One editor, configured per field, that saves what the author wrote, changes
only what the author changed, and tidies markup only when the author presses
the button or the developer declared the field prose-only.**

## The problem in plain terms

Every rich-text field on the platform is a Trumbowyg editor loaded from one
place, the textbox renderer in `includes/FormWriterV2HTML5.php`. Trumbowyg
rewrites the field's markup whether or not the author touched it, in three
separate ways:

1. **A "semantic" pass runs on every keystroke, paste, mode toggle and on
   load.** It wraps loose text in paragraphs, deletes empty paragraphs and
   rebuilds every `div`. Because it runs at load, opening an edit form and
   pressing Save rewrites content the author never touched.
2. **An empty-wipe on sync.** When the editing surface has no visible text and
   none of `hr`, `img`, `embed`, `iframe` or `input`, the textarea is set to an
   empty string. A block that is only an SVG, a spacer, or a video is erased
   on save.
3. **A paste whitelist of 23 tags.** `section`, `div`, `span`, `figure`,
   `button` and every class on them vanish when pasted.

This is exactly what a prose field wants and exactly what a markup field
cannot survive. It is what broke the getjoinery install page. The
`custom_html` component was hand-switched to a plain textarea on dev and
getjoinery in August, but component schemas are synced from the JSON files
on every upgrade (`utils/upgrade.php` → `utils/sync_extensions.php` →
`ThemeManager::syncComponentTypes()`, filesystem wins), so both rows are
back to `richtext` and the destructive editor is live on that field
everywhere. Verified on dev 2026-09-12.

Trumbowyg is also the only reason jQuery is served: the CSP round vendored
`assets/vendor/jquery-3.7.1/` (untracked today) purely so the editor could
load without a CDN.

Alongside it the platform carries a second, unrelated editor for markdown
fields: its own toolbar, its own view switcher, its own CSS and asset
emission. Two toolbars that do the same job in two languages.

## Where the editors are used

Eleven admin pages pass `htmlmode`: email edit, users message, post edit,
product edit (twice), event edit (twice), event session edit, location edit,
item edit, and component edit for every `richtext` field. Four component
types declare `richtext`: `text_block`, `text_with_image`, `tabs`,
`accordion`; `custom_html.json` still declares it in the file. One page
passes `markdownmode`: help edit. No page touches either editor's JavaScript
directly, so the swap is contained in the renderer plus assets.

## Design

### One editor, two dialects

The editor is one wrapper, one toolbar, one view switcher, one fullscreen,
one set of keyboard shortcuts, one pair of link and image popovers, one
asset emission. What differs per field is the **dialect**, chosen by the
existing `htmlmode` / `markdownmode` options:

| | `html` dialect | `markdown` dialect |
|---|---|---|
| Field value | HTML | markdown source |
| Editing surface | a contenteditable div over the textarea | the textarea itself |
| How a button works | browser editing command on the surface | rewrites the selected text (`**bold**`, `- item`) |
| Views | `visual`, `source` | `write`, `split`, `preview` |
| Preview | the surface is the preview | server-rendered through the `markdown_preview` action, as today |
| Cleanup | the button and `editor_cleanup` | not applicable |
| Paste | browser default (or cleaned, see below) | browser default: plain text |

A button is a **command name** (`bold`, `h2`, `ul`, `link`, ...). Each
dialect implements the commands it supports; the toolbar shows only those.
The markdown implementations are the ones in `markdown-editor.js` today,
moved over unchanged: `replaceRange` through `insertText` so edits join the
native undo stack, prefix toggling for headings, quotes and lists, list
continuation on Enter, the debounced server preview.

**Why markdown does not get a visual surface.** A WYSIWYG markdown editor
converts HTML to markdown on every keystroke. That needs a JavaScript
markdown grammar (the second grammar the markdown editor was built to avoid,
because it drifts from `MarkdownRenderer`) and it rewrites the whole document
on save, which is the diff churn the same editor was built to prevent for
docs under version control. It would recreate the Trumbowyg problem in a
second language. Markdown stays a text surface with a preview.

Files:

- `assets/js/joinery-editor.js` — wrapper, toolbar binding, views,
  fullscreen, popovers, both dialects.
- `assets/js/html-cleanup.js` — the cleanup routine on its own, so the
  headless test can load it without the editor.
- `assets/css/joinery-editor.css`.
- `includes/FormWriterV2HTML5.php` — one `editorChrome($dialect, $options)`
  and one `emitEditorAssets()`. The Trumbowyg block, the jQuery loader, and
  the markdown-specific chrome and asset methods go.

Deleted: `assets/js/markdown-editor.js`, `assets/css/markdown-editor.css`,
`assets/vendor/Trumbowyg-2-26/`, and `assets/vendor/jquery-3.7.1/` (untracked;
it must simply not be added).

### Fit with what exists (checked 2026-09-12)

Four things the editor has to get right to sit in the platform without a
seam, found by reading the code rather than assumed:

1. **Validation skips fields it cannot see.** `joinery-validate.js` skips a
   field whose `checkVisibility()` is false. Trumbowyg hides its textarea
   with a 1px, zero-opacity box, not `display:none`, which is why `required`
   and `minlength` on the users-message field still work today. The HTML
   dialect hides the textarea the same way. A `display:none` textarea would
   silently switch validation off for every rich-text field.
2. **Rich text inside a repeater is a single-line input today (B1).**
   `repeater_row()` calls a FormWriter method named after the sub-field
   type; there is no `richtext` method, so the `content` field of `tabs` and
   `accordion` falls through to `textinput`. The repeater maps `richtext`
   to `textbox` with `htmlmode`, and the editor initialises on rows the
   repeater adds after load (the repeater's add handler dispatches
   `jy-repeater-row-added` on the new row; the editor listens for it, the
   same way it initialises on `DOMContentLoaded`). Cloned rows carry the
   chrome markup already, so nothing is generated in JavaScript.
3. **Two option paths.** `prepareTextboxData()` in the base class carries
   only `htmlmode` into `renderTextbox`; `editor_view` and `editor_cleanup`
   are added there so the delegate path and the public `textbox()` agree.
4. **Component schemas come from the files.** Every upgrade rewrites
   `com_config_schema` from `views/components/*.json`. Changing
   `custom_html.json` is the whole of the deployment; no database write
   anywhere. The reverse also holds: a hand edit to a row does not survive
   the next upgrade.

Same as today, not made worse, and not addressed here: an `iframe` in
content whose host is not in the CSP `frame-src` list renders blank in the
editing surface (and on the page); toggling between visual and source views
resets the surface's native undo history; `plugins/server_manager/includes/publish_upgrade.php`
has a comment naming the Trumbowyg path, updated when the directory goes.

### The textarea is the field, always

- The textarea keeps its `name`, stays in the form, and is what the form
  posts. Validation (`joinery-validate.js`) reads it as it does today.
- **HTML dialect:** the surface writes to the textarea on every `input` event
  and after every command. **It never writes before the first edit.** Open,
  look, Save is byte-identical to what was loaded. This one rule closes
  defects 1 and 2 above. The surface writes its full serialised content every
  time; there is no "no visible text means empty" rule.
- **Markdown dialect:** the textarea is the surface, so this holds by
  construction.
- `source` view (HTML) shows the textarea itself. Toggling back to `visual`
  parses the textarea into the surface. Edits made in source view are never
  lost, and a field never edited visually is never re-serialised.

### What "faithful" means (HTML dialect)

The browser's own parse-and-serialise is the floor. Invalid nesting gets
repaired, `<br/>` becomes `<br>`, entity forms may change. Anything the DOM
can hold survives: `section`, `div`, classes, inline styles, `data-*`, SVG,
comments. That is the promise; byte-identical output after a visual edit is
not.

### Commands

| Command | html | markdown |
|---|---|---|
| `bold` `italic` | yes | yes |
| `del` `sup` `sub` | yes | — |
| `code` | — | yes (inline) |
| `p` `h1`–`h4` `quote` | yes (block format menu) | `h1`–`h3`, `quote` (line prefix) |
| `pre` / `codeblock` | yes | yes |
| `ul` `ol` | yes | yes |
| `link` | popover: URL, text, new tab | popover: URL, text |
| `image` | popover: URL, alt, width | popover: URL, alt |
| `hr` | yes | — |
| `table` | — | yes |
| `align-left/center/right/justify` | yes | — |
| `removeformat` | yes | — |
| `cleanup` | yes (unless `editor_cleanup: none`) | — |
| `undo` `redo` | native | native |
| `source` | view | — |
| `fullscreen` | yes | yes |

HTML commands run through `document.execCommand` with
`defaultParagraphSeparator` set to `p` and `styleWithCSS` off. Anything the
editor inserts goes through `insertHTML`/`insertText` so it joins the native
undo stack. Engines disagree on the tag a command emits (`b`/`strong`,
`i`/`em`, `strike`/`s`/`del`); after a command the editor renames those tags
**inside the affected range only**. Nothing outside the selection is touched.
That is the whole of the always-on normalisation.

Keyboard: Ctrl/Cmd+B, +I, +K in both dialects; undo and redo are native.
Height matches today: 500px, 100% in fullscreen; markdown keeps its `rows`.
Disabled or readonly textarea → surface not editable, toolbar disabled. Many
editors on one page work; assets are emitted once.

### Cleanup (HTML dialect)

One routine, `cleanup(html) → html`, with a fixed rule set. It is the
"basic editing" behaviour Trumbowyg gave every field, made explicit and
optional.

Keep, with the listed attributes only:

| Tags | Attributes kept |
|------|-----------------|
| `p br h1 h2 h3 h4 h5 h6 blockquote pre code strong em del sub sup ul ol li hr table thead tbody tr th td` | none, except `style` reduced to `text-align` only |
| `a` | `href title target rel` |
| `img` | `src alt width height` |
| `iframe` | `src width height allow allowfullscreen` |
| `video` | `src controls width height poster` |

Rename: `b`→`strong`, `i`→`em`, `s`/`strike`→`del`, `div`→`p`.

Drop with their contents: `script style head meta link title object form
input button textarea select template`, and comments.

Unwrap, keeping children: every other tag (`span section article font
center` and the Office `o:p` family included).

Structure: wrap top-level text and inline runs in `p`; a `p` that ends up
holding a block (a renamed `div` inside a `div`, a list inside a paragraph)
is unwrapped so blocks never nest in paragraphs; remove `p` and inline
elements with no text and no `img`/`br`/`hr`/`iframe`/`video` inside; unwrap
an inline element nested in the same tag; collapse `&nbsp;` runs to a space
outside `pre`. `pre` contents are kept verbatim. `href`/`src` with a
`javascript:` or `data:` scheme are removed. Order: drop, rename, unwrap,
attributes, then structure, so each pass sees only tags the earlier passes
allowed.

The rule table is data (one object at the top of `html-cleanup.js`), so a
future profile is another table, not another routine.

Cleanup runs in the browser only (D1, decided). It is an editing
convenience for admin authors, not a security control; every current caller
is an admin page at permission 5 or above. A member-facing rich-text field,
if one ever ships, needs a server-side sanitizer, which is a different tool
and gets its own spec then.

### The PHP options

`textbox()` keeps `htmlmode` and `markdownmode` as the dialect selectors.
Two options are added; `markdown_mode` is retired (one caller, help edit,
moves to `editor_view`).

**`editor_view`** — the opening view. HTML: `visual` (default) or `source`.
Markdown: `write` (default), `split` or `preview`. A view from the wrong
dialect throws at definition time.

**`editor_cleanup`** — HTML dialect only; throws with `markdownmode`:

| Value | Button | Paste | Load | Before submit |
|-------|--------|-------|------|---------------|
| `button` (default) | shown; cleans the whole surface, then reads "Undo clean up" until the next edit | browser default | nothing | nothing |
| `always` | shown | clipboard HTML is cleaned before insertion; plain text inserted as text | surface cleaned (textarea untouched until first edit) | if edited, surface cleaned and written |
| `none` | hidden | browser default | nothing | nothing |

`always` is the developer saying "this field holds prose": whatever is saved
through it is clean, but an untouched field is still saved untouched.
`none` is for a field whose whole point is markup.

The before-submit step runs from a capture-phase `submit` listener, so the
textarea is already clean when `joinery-validate.js` reads it. A field whose
content cleans down to nothing then fails `required` the way it should.

Component schemas pass both through: a `richtext` field may carry
`"cleanup"` and `"view"`, mapped by `adm/admin_component_edit.php` alongside
the other per-type options. `custom_html.json` declares
`"cleanup": "none", "view": "source"`; `text_block` and `text_with_image`
take the defaults. (A `markdown` component field type would also need the
component renderer to call `MarkdownRenderer` at display time; that is a
component-system change and is out of scope here.)

### Markup and CSS

One wrapper, `.jy-ed[data-jy-editor="html|markdown"]`, with `.jy-ed-toolbar`,
`.jy-ed-views`, `.jy-ed-panes`, and per-dialect panes (`.jy-ed-surface`,
`.jy-ed-preview`). The toolbar HTML is emitted by PHP from a per-dialect
button table, as the markdown chrome is today; the script binds by
`data-jy-ed-action` and `data-jy-ed-view`. No inline script.

## Work packages

**WP1 — Editor core.** `joinery-editor.js`, `joinery-editor.css`, the
renderer change with `editorChrome` / `emitEditorAssets`, FormWriter version
bump (2.5.0), `editor_view` and `editor_cleanup` with definition-time
validation. Markdown dialect carried over from `markdown-editor.js` with
`fullscreen` added. Help edit moved to `editor_view`. Old assets, Trumbowyg
and jQuery deleted. Every `htmlmode` and `markdownmode` page opens and saves.

**WP2 — Cleanup.** `html-cleanup.js` with the rule table above; the button;
`always` behaviour on paste, load and submit.

**WP3 — Components and repeaters.** Pass `cleanup` and `view` through
`admin_component_edit.php`; update `custom_html.json` (schema keys and its
help text). Map `richtext` in `repeater_row()` and fire the row-added event
from the repeater script (B1). The upgrade sync carries the schema to every
deployment; no database write.

**WP4 — Tests and docs.** See below.

## Tests

- `tests/unit/joinery_editor_test.php` — safe tier, `env: dev-only`,
  `needs: [chrome]`. The runner treats a need it does not recognise as met,
  so `harness_unmet_needs()` in `tests/run.php` gains a `chrome` case
  (probe `command -v google-chrome`), otherwise the suite would fail hard
  on a box without a browser instead of skipping. Headless Chrome is
  confirmed working as the dev user
  (`google-chrome --headless=new --disable-gpu --no-sandbox --dump-dom`).
  Runs it over
  `tests/fixtures/joinery_editor/runner.html`, which loads `html-cleanup.js`
  and the editor, applies each fixture, and prints pass/fail into the DOM for
  the harness to read. Fixtures cover every row of the cleanup rule table,
  the Office paste shape, nested inline tags, an SVG-only block (must not be
  emptied), `pre` verbatim, the byte-identical-until-edited rule, and each
  markdown text command (bold wrap, heading toggle on and off, list
  continuation). The markdown editor has no test today; this gives it one.
- `tests/unit/formwriter_unknown_option_test.php` gains `editor_view` and
  `editor_cleanup`; `markdown_mode` becomes unknown.
- `tests/security/csp_header_test.php` needs no change: the editor adds no
  inline script and no CDN.
- Browser check on dev: each of the twelve pages, open, edit one word, save,
  confirm only that word changed in storage; `custom_html` opens in source
  view with no cleanup button; help edit opens split with a live preview;
  a `tabs` component gets an editor in every row, including a row added
  after load; the users-message form refuses an empty message (validation
  still sees the hidden textarea).

## Docs

- `docs/formwriter.md` — the `htmlmode` and `markdownmode` sections become
  one "Editor" section: the two dialects, `editor_view`, `editor_cleanup`,
  the byte-identical-until-edited rule, in current-state voice.
- `docs/component_system.md` — the `richtext` row and the two schema keys.

## Out of scope

- Server-side HTML sanitizing for untrusted authors.
- A visual (WYSIWYG) surface for markdown (rejected above).
- A `markdown` component field type.
- Wiring the image button to the platform image selector; it stays a URL
  popover, as today.
- Converting pasted HTML into markdown in the markdown dialect.
