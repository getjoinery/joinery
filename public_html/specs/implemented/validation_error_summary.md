# Validation Error Summary

## Problem

When a form fails client-side validation, nothing tells the person what is wrong or where. The validator marks each offending field with `is-invalid` and inserts a message next to it, then blocks the submission — but if the offending field is scrolled off screen or sits in a section the person was not working in, the page simply appears dead. Clicking Save does nothing, with no explanation anywhere they are looking.

This is not hypothetical. On 2026-07-25 the admin settings page on jeremytunnell.com could not be saved at all. The cause was `logo_link` holding a path to a theme logo that no longer existed on disk; its `remote` validator answered "does not exist", and that vetoed every submit. The field was in a different section of a long page, so there was no visible cue. Diagnosis needed access-log forensics — repeated `POST /api/v1/action/validate_server_file` with no accompanying `POST /admin/admin_settings` — to establish that the browser was never submitting at all. Any node carrying a stale theme path is silently unable to save settings, indefinitely.

The fix belongs in the validator, not in any one page: every form gets a summary of what failed, immediately above the submit button, with a link to each offending field.

## What the person sees

On a submit attempt that fails validation, a block appears directly above the submit button:

```
┌────────────────────────────────────────────────────┐
│  This form could not be saved — 2 fields need      │
│  attention:                                        │
│    • Link to Logo — Must start with / and file     │
│      must exist                                    │
│    • Contact Email — Enter a valid email address   │
└────────────────────────────────────────────────────┘
                    [ Submit ]
```

Each bullet is a link. Activating it moves to that field and focuses it. Fixing a listed field removes its bullet; fixing the last one removes the block. The block only appears in response to a submit attempt — never while someone is still typing.

## Design

### The container comes from PHP, the contents from either side

`FormWriterV2Base` emits one empty, hidden summary container immediately before the **first** submit button in the form:

```html
<div class="jy-error-summary" id="{form_id}_error_summary" role="alert" tabindex="-1" hidden>
  <p class="jy-error-summary-title"></p>
  <ul class="jy-error-summary-list"></ul>
</div>
```

Two producers fill it, with identical markup so the result is the same either way:

- **Client side.** `JoineryValidator` populates it when `validateForm()` returns false, and unhides it.
- **Server side.** When a form re-renders carrying `$this->errors` (server validation, or a rule the client cannot check), PHP fills the same container at render time. Today server errors show only per-field, so a server-rejected save has the same "page looks dead" problem when the field is off screen.

If the container is absent — a hand-rolled form, or cached markup from before this change — the validator creates one and inserts it before the first submit button, falling back to the end of the form when there is no submit button. This keeps every existing form working with no per-form edits.

### One item per invalid field

Each `<li>` is `<a href="#{target_id}">{label}</a> — {message}`, where:

**Label** resolves in this order, first hit wins:
1. `label[for="{field_id}"]` text within the form, with a trailing `*`, `:` or required-marker stripped
2. the field's `aria-label`
3. the field's `placeholder`
4. the field `name`, humanised (`joinery_ai_local_model` → "Joinery ai local model")

The raw field name is the last resort, not the default. A person reading "Link to Logo" knows where to go; reading `logo_link` they have to guess.

**Target id** resolves in this order:
1. the field's own `id` (FormWriter sets `id = options['id'] ?? name`)
2. `{name}_container`, which FormWriter already emits for every field
3. an id the validator generates and assigns, so a link always has somewhere to point

Radio and checkbox groups use the container, since no single input represents the group.

**Message** is the same string already shown inline next to the field. No second source of truth.

### Activating an item

The `href="#{target_id}"` is a real link — copyable, middle-clickable, keyboard reachable. A click handler additionally calls `preventDefault()` and does the move programmatically:

- scroll the field into view, honouring `prefers-reduced-motion` (no smooth scroll when it is set)
- `focus()` the field
- **no history entry** — a bare hash navigation would put an entry in the back stack for every error click, so the person's Back button walks their own error list instead of leaving the page

If the field turns out not to be visible at click time (it became hidden between validation and the click), open any ancestor `<details>` and remove `hidden` from ancestors before scrolling, so the link never dead-ends.

### Only reachable fields can be listed

`validateField()` already skips disabled fields and fields that fail `checkVisibility()` — anything inside a collapsed panel or a `visibility_rules` section is not validated. So the summary can only ever list fields the person can actually reach. This is an invariant the design relies on and should not be weakened: if hidden fields ever start being validated, the summary would link to things that cannot be seen or fixed.

### Live updates

Once visible, the summary is kept accurate by the existing per-field lifecycle:

- `clearError(field)` removes that field's item; when the list empties, the container hides again
- `showError(field, message)` updates that field's item if the summary is currently visible; it does **not** reveal the summary on its own, so blur-time errors while typing stay quiet

### Accessibility

`role="alert"` announces the summary when it appears. On a failed submit, focus moves to the container (`tabindex="-1"`), so a screen-reader user lands on the explanation rather than being left on a button that did nothing. The list is a real `<ul>` of real links, so it is navigable by the usual means.

### Opt-out

`new JoineryValidator(form, { errorSummary: false })` suppresses it, and a FormWriter form option passes that through. Intended for compact inline forms (a single search box) where a summary is noise. On by default: the failure this fixes is silent, and silence is the problem.

## Integration points, decided once

| Where | Change |
|---|---|
| `FormWriterV2Base` | Emits the container before the first submit button; fills it from `$this->errors` on re-render. **Base, not renderer** — there are seven theme subclasses of FormWriter (`theme/*/includes/FormWriter.php`) plus `includes/FormWriter.php`, and none should need to know about this. |
| `FormWriterV2HTML5` | No change. Inherits. |
| `FormWriterV2JSON` | Opts out of the markup. It emits field/error data for API and native-app consumers; rendering is the client's business. Its error payload already carries per-field messages, which is what a native form needs. |
| `assets/js/joinery-validate.js` | Builds, populates, links, live-updates, focuses. |
| `assets/css/joinery-styles.css` | Styles `.jy-error-summary*` under `.jy-ui`, using the existing `--jy-color-danger` token. Vanilla CSS — no framework, per the theme rules. Must be legible in both the admin theme and public themes. |
| Hand-rolled `<form>` markup (14 files under `views/` and `adm/`) | No change. They get the JS-injected fallback container if they attach the validator. |

## Files to change

- `assets/js/joinery-validate.js` — bump `@version` 1.1.1 → 1.2.0
- `includes/FormWriterV2Base.php` — container emission + server-error fill; bump version and add a `@changelog` line
- `includes/FormWriterV2JSON.php` — explicit opt-out
- `assets/css/joinery-styles.css` — `.jy-error-summary` block

## Documentation

- `docs/validation.md` — new subsection under **1. Client-Side JavaScript Validation**, after *Styling Classes*, covering the summary, the label/target resolution order, the visible-fields invariant, and the `errorSummary` option. Also extend *AJAX Validation (Remote Check)* to note that a failing remote rule now names itself in the summary.
- `docs/formwriter.md` — under **7. Validation Integration**, document the emitted container and its placement, and note the JSON writer's opt-out.

Both describe the end state only — no "previously", no migration narrative.

## Tests

**Safe tier, PHP** (`tests/unit/formwriter_error_summary_test.php`):
- the container is emitted exactly once, immediately before the first submit button
- it is emitted for a form with no validation rules too (server errors can still occur)
- `FormWriterV2JSON` emits no such markup
- given `setErrors()`, the rendered container is unhidden and contains one item per field, each linking to that field's resolved target id
- a form with no submit button still renders valid markup

**Browser verification** (record in the spec's results section when done):
- the jeremytunnell case: a settings page with a broken `logo_link` shows the summary naming "Link to Logo", and the link jumps to it
- clicking an item does not add a history entry (Back still leaves the page)
- fixing one of two fields removes only its bullet
- a `visibility_rules`-hidden invalid field is neither validated nor listed
- the summary reads correctly in the admin theme and one public theme

### Browser verification results (2026-08-06, dev.getjoinery.com)

All five checks passed:

- **The jeremytunnell case:** on `/admin/admin_settings`, a bogus `logo_link`
  value blocked the submit and the summary appeared naming "Link to logo —
  Must start with / and the file must exist" (the `remote` rule's own
  message), with focus moved to the summary. Clicking the item scrolled
  ~10,000px to the field's section and focused the field. Nothing was saved
  (the field was empty before and was restored after).
- **No history entry:** `history.length` unchanged across item clicks, and
  `location.hash` stays clean (the move is programmatic).
- **Live updates:** on `/login` (public theme, PublicPageFalcon chain) an
  empty submit showed "2 fields need attention:"; filling Email removed only
  its bullet and the title flipped to the singular; filling Password hid the
  block entirely.
- **Hidden fields:** on `/admin/admin_public_menu_edit`, a required rule on
  `pmu_link` while its `visibility_rules` section was hidden produced no
  bullet and no validation; its stale bullet from an earlier visible-state
  submit was removed on the next attempt.
- **Themes:** rendered and styled in the admin theme (screenshot taken:
  danger-bordered box directly above Submit) and the public login page.

## Open decisions

1. **Placement with multiple submit buttons.** The container is emitted before the *first* submit button, which is deterministic and server-renderable, but on a form whose actions are far apart (Save at top, Delete at bottom) the summary may not be adjacent to the button that was actually clicked. The alternative — JS moves the container next to `e.submitter` on each failed attempt — is more contextual but makes placement unpredictable for authors and unstylable from PHP. **Recommendation: first submit button.** Revisit only if a real form is awkward.
2. **Wording of the title.** Proposed: "This form could not be saved — N fields need attention:". "Saved" is wrong for forms that search or send. A neutral "N fields need attention:" always reads correctly. **Recommendation: the neutral form**, overridable per form.

## Out of scope

- `aria-describedby` wiring between each field and its inline error message — a real accessibility gap, but independent of this change.
- Surfacing *server-side* validation failures that arrive via a redirect rather than a re-render; those land in the session message system and are a different path.
- The data problem behind the motivating incident (stale `logo_link` values on deployed nodes). This change makes it self-diagnosing, not self-healing.
