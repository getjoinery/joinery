# Joinery AI — Reader-Mode Page Fetching

**Status:** Implemented
**Plugin:** `joinery_ai`
**Last Updated:** 2026-06-26
**Touches:** `fetch_url` recipe tool (shared by recipes and the forthcoming
[chat assistant](joinery_ai_chat_assistant.md))

## Goal

When the AI reads a web page, give it the *article*, not the whole noisy
document. Today `fetch_url` hands the model a flat text dump of the entire page —
nav bars, cookie banners, footers, sidebars, and link soup all mixed in with the
actual content. That wastes tokens (especially painful on the local 14B model,
where context is tight) and buries the answer in boilerplate.

This spec upgrades the tool to produce a clean, structured **reader view** by
default — main content only, as Markdown — and lets the model fall back to the
full-page dump when it explicitly needs everything.

The model picks per call. It does not have to think about HTML parsing, encoding,
or stripping — it just gets readable text, the same way a person gets Reader View
in their browser.

## Current state

`FetchUrlTool::extractReadableBody()` already does the safety-critical and
plumbing work we keep as-is:

- SSRF validation + IP pinning on every URL and redirect hop (`UrlSafetyValidator`).
- 2 MB raw download cap, 15s timeout, 5-redirect ceiling.
- Charset detection and conversion to UTF-8.
- A 50,000-char output cap with truncation notes.

What's crude is only the **extraction step** — `htmlToText()`:

```php
// drop <script>/<style>/<noscript>, turn block tags into newlines,
// strip_tags(), decode entities, collapse whitespace.
```

That keeps *every* run of visible text on the page. It has no notion of "main
content vs. chrome," so a 5-paragraph article arrives wrapped in 40 lines of
menu items and a footer. This spec replaces that one method's job; nothing else
about the tool changes.

## Proposed design

### Two extraction modes, model-selected

Add one optional `mode` argument to the tool's input schema:

- **`reader`** *(default)* — run the downloaded HTML through a readability
  extractor (main-content detection), then convert that subtree to Markdown.
  Headings, lists, tables, and link text survive; nav/ads/scripts/styles are
  gone; images collapse to their alt text or are dropped. This is the
  token-cheap view and the right answer almost always.
- **`full`** — the current behaviour: strip the *whole* page to flat text. The
  escape hatch for pages where main-content detection legitimately discards what
  the model wants — search-result listings, doc indexes, dashboards, link hubs,
  anything that isn't one article.

The model chooses by reading the tool description. Default to `reader` so the
cheap, clean view is what it gets unless it has a reason to ask for the firehose.

**Why not a literal-HTML mode?** Raw markup is almost never useful to an LLM —
it's the most expensive possible representation and the model has to mentally
parse tags to find the text anyway. The two modes above cover "give me the
article" and "give me everything," which is the real axis. We are deliberately
*not* adding a third mode that returns untouched HTML.

### The reader pipeline

For `mode: "reader"` on an HTML response, all over PHP's built-in `DOMDocument`
(no library):

1. **Parse** — load the UTF-8 HTML into `DOMDocument` (warnings suppressed; real
   pages have malformed markup and `loadHTML` tolerates it).
1a. **Stash embedded data** — before anything is stripped, capture the page's
   structured-data blobs (see *Embedded-data harvest* below) for use only if the
   visible walk comes back thin. They live in `<script>` tags, which step 2
   removes, so they must be grabbed first.
2. **Strip chrome by tag** — remove every `script style noscript nav header
   footer aside form iframe svg button` node outright. These are noise with no
   article text.
3. **Strip chrome by class/id** — remove elements whose `class` or `id` matches a
   junk pattern: `nav|menu|sidebar|footer|header|comment|share|social|cookie|
   banner|promo|advert|related|recommend|popup|modal|newsletter|subscribe`. This
   is what kills cookie bars, share widgets, and "related posts" rails.
4. **Pick the main block** — if a `<main>` or `<article>` element survives, use
   it. Otherwise score the remaining block-level containers by text length minus
   a link-density penalty (a `<div>` that is mostly `<a>` text is a menu, not
   prose) and take the winner. ~30 lines.
5. **Walk to Markdown** — a single recursive DOM walk emits Markdown: `h1–h6` →
   `#…`, `li` → `- `, `a` → `[text](href)`, `strong/b` → `**`, `em/i` → `*`, `p`
   and block tags → blank-line separators, `img` → dropped. ~40 lines.
6. **Tidy** — reuse the existing `collapseWhitespace()` and the 50,000-char cap.

The returned block keeps today's shape:

```
Source: <final-url>

<page title>

<markdown body>
```

The page `<title>` (which we already have from the document) is prepended as the
header so the model always gets a name for the source.

### Embedded-data harvest (the JS-render workaround)

Many JavaScript-rendered pages — single-page apps, Next.js sites — paint their
visible body client-side, so the DOM walk above finds almost nothing. But those
same pages almost always **ship the content in the initial HTML anyway**, parked
in script tags for the framework to hydrate. We can read that directly without
running any JavaScript:

- **`<script type="application/ld+json">`** — schema.org structured data. Articles
  expose `headline`, `articleBody`, `author`, `datePublished` as plain fields.
- **`<script id="__NEXT_DATA__">`** (Next.js) and similar `window.__INITIAL_STATE__`
  / `__NUXT__` blobs — the page's data payload as JSON.
- **OpenGraph / `<meta>` tags** — `og:title`, `og:description`, `article:*`. A
  shallow floor: always present, but only a summary.

When the visible walk comes back thin (below the floor below), the tool falls to
this harvested data — preferring `articleBody`/`headline` from JSON-LD, then the
OG/meta summary — and renders it as the Markdown body with a note that the page
was read from embedded data rather than visible text. It is **not** JS execution;
it is reading the JSON the page already handed us. Zero dependencies, ~30–50 lines.

This recovers the common SPA case for free. It does not help a page that fetches
its content over the network *after* load and embeds nothing — that genuinely
needs a browser, which stays out of scope (see *Out of scope*).

### Fallback is automatic — a three-tier escalation, not a separate mode

`reader` mode degrades gracefully through three tiers, each kicking in only when
the one above yields less than a small floor (e.g. < 200 chars of text) or
raises. The model never picks a tier; it just gets the best result available:

1. **Visible DOM walk** — the main-content extraction above. The common case.
2. **Embedded-data harvest** — JSON-LD / framework blob / OG meta, for
   JS-rendered pages whose visible body is empty.
3. **`full` strip** — today's whole-page flatten, for anything the first two miss.

When tier 1 falls through, the result carries a one-line note naming what
happened, e.g.:

```
…(visible page was empty; read from the page's embedded data)
…(reader view found little content; showing full-page text)
```

This is fail-*open to a worse-but-working* result, not fail-closed — the model
still gets the page, just less cleanly, and is told what tier produced it. It is
not a band-aid for a weak heuristic; it's the correct behaviour for the classes
of pages each tier is meant to catch, and it's why no single tier has to be
perfect to be worth shipping.

### Non-HTML responses are unchanged

JSON, plain text, CSV, and anything without `html` in its Content-Type bypass
extraction entirely and return as-is today. That stays true in both modes —
`mode` only governs how HTML is handled.

## No new dependencies

Both halves are hand-rolled over PHP's built-in `DOMDocument` (ext-dom, already
present platform-wide) — no Composer packages.

This is a deliberate call. A library port of Mozilla's Readability (~1500 lines)
earns its keep on the long tail of adversarial and broken markup, but here that
long tail is already absorbed by the automatic `full` fallback: when the
heuristic comes up short, the model still gets the page. For a token-trimming
convenience, a vendored dependency isn't worth the upkeep. HTML → Markdown is
mechanical enough (~40 lines) that a library would be pure convenience.

The cost we accept: the heuristic will occasionally leave a stray sidebar in or
under-extract an oddly-structured page where the library wouldn't. The failure
mode is *noisier*, not *broken* — and the embedded-data and `full` tiers are the
escape hatches.

Total new code is ~160 lines (DOM walk, embedded-data harvest, tier switch), all
inside `FetchUrlTool`.

## Security

Fetched page content is **untrusted, attacker-controllable input** — that is
already true today and this spec does not change the trust posture. Two points to
keep straight:

- **Reader mode is a structure filter, not a trust filter.** Extracting the main
  content and converting to Markdown removes *noise*, not *intent*. The output is
  exactly as untrusted as the raw page. It must continue to flow through whatever
  untrusted-content handling the [chat assistant](joinery_ai_chat_assistant.md)
  applies to external tool results (nonce-wrapping / taint). No part of this
  change may be read as "reader-mode output is sanitized."
- **No new network surface.** Extraction and Markdown conversion are pure
  string/DOM transforms on already-downloaded bytes. The SSRF guard, IP pinning,
  redirect re-validation, and size/time caps are untouched and still run first.
  One `DOMDocument` caveat: load the HTML with `LIBXML_NONET` (and without
  `LIBXML_DTDLOAD`) so the parser can never be coaxed into fetching an external
  DTD or entity while parsing attacker-controlled markup.

## Out of scope

- Caching fetched/extracted pages. Each call re-fetches, as today.
- **Live JavaScript execution** — screenshots, headless-browser rendering, or any
  engine that runs the page's scripts. The embedded-data harvest recovers the
  *common* SPA case (content shipped in the HTML) without running JS; a page that
  fetches its body over the network after load and embeds nothing is the
  remaining gap. Closing it needs a real browser, which is a separate, heavier,
  setting-gated decision (a self-hosted render service the tool calls over HTTP)
  — deliberately not in this spec.
- Per-recipe configuration of extraction mode. The model decides per call; there
  is no admin toggle.
- Changing `web_search` — it returns search snippets and is unaffected.

## Implementation outline

1. Add the `mode` property (`enum: ["reader","full"]`, default `reader`) to
   `FetchUrlTool::inputSchema()` and document it in `description()`.
2. Keep today's `htmlToText()` as the `full`-mode path, unchanged.
3. Add a `htmlToReaderMarkdown()` path: `DOMDocument` load (`LIBXML_NONET`) →
   stash embedded data → strip chrome by tag → strip chrome by class/id → pick
   main block → walk to Markdown (images dropped).
4. Add the embedded-data harvest helper: read JSON-LD `articleBody`/`headline`,
   framework blobs (`__NEXT_DATA__` etc.), and OG/`<meta>` as the tier-2 source.
5. Wire the three-tier switch in `extractReadableBody()` — visible walk →
   embedded harvest → `full` strip, each gated on the < 200-char floor or an
   exception, with the matching one-line note.
6. Keep the existing whitespace collapse, output cap, and `Source:` header for
   all paths.
7. Run `php -l` and `validate_php_file.php` on the tool; bump the plugin version
   in `plugin.json`.

## Docs

On implementation, update the `fetch_url` tool's entry in the joinery_ai plugin
docs (the recipe-tools reference) to describe the two modes and the default.
Per the docs rule, describe only the end state — the reader/full split as it
exists, not "previously it stripped the whole page."
