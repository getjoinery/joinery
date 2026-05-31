# Spec: FormWriter / JoineryValidator multi-action submit handling

**Status:** Both fixes implemented (central JS + FormWriter option); follow-ups open.
**Date:** 2026-05-31
**Component:** `assets/js/joinery-validate.js` (JoineryValidator), FormWriterV2 forms.

---

## Summary

Two related defects broke multi-action FormWriterV2 forms (forms with more than one
submit button, e.g. Save / Save & Write to disk / Delete). Both stem from the same root:
`JoineryValidator` validates, then re-submits programmatically via
`HTMLFormElement.submit()`, which does not behave like native submission.

1. **Submitter identity loss.** `HTMLFormElement.submit()` **does not include the clicked
   submit button's name/value**. Native submission includes the submitter; programmatic
   `form.submit()` does not. The server therefore cannot tell *which* button was pressed.

2. **No per-button validation bypass.** The validator runs full form validation on *every*
   submit regardless of which button was clicked, ignoring the submitter's `formnovalidate`
   attribute. Native HTML lets a Delete/Cancel button opt out of validation per-button;
   the validator did not, so a secondary action on a form with empty required fields was
   blocked client-side.

Both are fixed centrally:

- The validator captures `e.submitter`, reattaches its name/value as a hidden input before
  `form.submit()` (fixes #1), and skips validation when the submitter sets `formnovalidate`
  (fixes #2).
- `FormWriterV2::submitbutton()` gains a `formnovalidate` option (alias `skip_validation`)
  so the per-button opt-out is reachable through the FormWriter API rather than hand-rolled
  HTML.

## Scope correction (important)

An earlier verbal description of this bug claimed it affected "every form with multiple
submit buttons." **That was an overstatement.** The actual blast radius is roughly an
order of magnitude smaller — a handful of forms. Two facts bound it:

1. **The validator only attaches to forms that have validation rules.**
   `FormWriterV2Base::outputJavascriptValidation()` returns early and instantiates **no**
   validator when `getAllValidationRules()` is empty (`includes/FormWriterV2Base.php:2449-2453`).
   (It still emits a `<script>` that logs "No validation rules found" — but constructs no
   JoineryValidator.) A rules-free form submits natively, so the submitter is preserved and
   the form is **not** affected.

2. **Form processing is gated on the HTTP method, not the button name.**
   The standard gate is `LibraryFunctions::isFormSubmission()` → `$_SERVER['REQUEST_METHOD'] === 'POST'`
   (`includes/LibraryFunctions.php:19-21`). So an ordinary single-action form still processes
   normally even when the submitter name is stripped. This is why saving works platform-wide
   despite the bug, and why it went largely unnoticed.

Therefore the affected set is the **intersection**: forms that *both* carry validation rules
(validator attaches) *and* branch server-side on a specific submit-button **name**.

The raw "≥2 `submitbutton()` calls" count (20 files) is **not** the blast radius — most of
those render the *same* button name (`btn_submit`) across mutually exclusive state branches
or in separate single-button forms, distinguished by hidden fields rather than by which
button was clicked. Example: `adm/admin_comments.php` renders `btn_submit` four times
(Approve/Unapprove/Delete/Undelete) — not affected.

## Root cause

`assets/js/joinery-validate.js`, submit handler (pre-fix):

```js
this.form.addEventListener('submit', async (e) => {
    e.preventDefault();              // cancel native submission (loses nothing yet)
    const isValid = await this.validateForm();   // runs for EVERY button, no opt-out
    if (isValid) {
        this.form.submit();          // programmatic submit — DROPS the submitter button
    }
});
```

Two distinct failures live in this handler:

1. **Submitter dropped.** `HTMLFormElement.submit()` bypasses the submit event *and* omits
   the submitter's name/value. With multiple submit buttons, or any server branch keyed on
   a button name, the server receives none of the button names and falls through to its
   default path.

2. **Validation not button-conditional.** `validateForm()` runs unconditionally, and the
   handler never inspects `e.submitter.formNoValidate`. A Delete/Cancel button on a form
   with empty required fields is blocked client-side even though native HTML (and the
   server action) would let it through. FormWriter also had no way to *mark* such a button:
   `submitbutton()`'s option set (`id`, `class`, `disabled`, `onclick`) had no
   `formnovalidate`, so the opt-out wasn't even expressible without hand-rolled HTML.

The second failure was previously masked on the affected forms because they are **edit**
forms with pre-populated required fields, so Delete happened to pass validation. It is a
latent trap for any multi-action form whose required fields can be empty.

## Affected forms (inventory)

Files whose server logic branches on a submit-button name (beyond the universal
`isFormSubmission()` gate):

| Logic file | Branches on | Current state |
|---|---|---|
| `adm/logic/admin_agent_file_edit_logic.php` | `btn_save_and_write`, `btn_delete` | **Confirmed broken.** "Save & Write to disk" silently behaved as plain "Save"; "Delete" likewise degraded. Empirically reproduced and fixed during investigation. |
| `plugins/joinery_ai/logic/admin_edit_logic.php` | `btn_delete` (gate already worked around) | **Partially worked around.** A comment at line 46 already notes "form.submit() … strips the submitter button — so isset($input['btn_submit']) is unreliable" and gates on `REQUEST_METHOD` instead. The `btn_delete` branch (line 51) is still affected when the validator is active. |
| `plugins/joinery_ai/logic/admin_note_logic.php` | gate `isset($input['btn_submit'])`, plus `btn_delete` | **Confirmed fully broken pre-fix.** The note form *does* carry validation rules (`rcn_title` required, `plugins/joinery_ai/views/admin/note.php:41`), so the validator attaches, strips `btn_submit`, and the entire save/delete block (line 20) is skipped — the form appears to do nothing. (Its Delete is a hand-rolled `<button name="btn_delete">`, not a FormWriter `submitbutton()`; same outcome.) |
| `adm/logic/admin_seo_page_edit_logic.php` | `btn_delete` | Delete branch affected when the validator is active. |

The comment in `admin_edit_logic.php` is direct evidence the bug was **previously
discovered and worked around locally**, never fixed centrally — which is exactly how a
narrow-but-real bug accumulates ad hoc patches.

## Fix (implemented)

### Part 1 — preserve the submitter (`assets/js/joinery-validate.js`, v1.0.8 → v1.0.9)

- Capture `const submitter = e.submitter;` at the top of the submit handler (before any `await`).
- On successful validation, before `form.submit()`, reattach the submitter as a hidden input:

```js
if (submitter && submitter.name) {
    let preserved = this.form.querySelector('input[type="hidden"][data-joinery-submitter]');
    if (!preserved) {
        preserved = document.createElement('input');
        preserved.type = 'hidden';
        preserved.setAttribute('data-joinery-submitter', '1');
        this.form.appendChild(preserved);
    }
    preserved.name = submitter.name;
    preserved.value = submitter.value || '';
}
```

This restores the name/value native submission would have sent. Single-button forms are
unaffected functionally (they now also post their one button name, matching native
behavior). `form.submit()` does **not** re-fire the submit event, so there is no recursion.

### Part 2 — per-button validation bypass (validator v1.0.9 → v1.0.10 + FormWriter)

- **Validator:** read the submitter's `formNoValidate` flag (before any `await`) and skip
  `validateForm()` when set, submitting straight through (the submitter is still preserved
  by Part 1):

  ```js
  const skipValidation = !!(submitter && submitter.formNoValidate);
  ...
  let isValid;
  if (skipValidation) {
      isValid = true;
  } else {
      this.isValidating = true;
      isValid = await this.validateForm();
      this.isValidating = false;
  }
  ```

- **FormWriter:** `submitbutton()` now accepts a `formnovalidate` option (alias
  `skip_validation`). `prepareSubmitData()` (`includes/FormWriterV2Base.php`) passes it
  through, and all three theme renderers (`FormWriterV2HTML5`, `FormWriterV2Bootstrap`,
  `FormWriterV2Tailwind`) emit the HTML `formnovalidate` attribute:

  ```php
  $formwriter->submitbutton('btn_delete', 'Delete', ['formnovalidate' => true]);
  ```

  This makes the per-button opt-out reachable through the FormWriter API instead of
  hand-rolled `<button>` HTML. Server-side validation is unaffected — `formnovalidate`
  only skips the *client* check, so a server action behind such a button must not assume a
  valid model.

### Why central, not per-form

Fixing each logic file to use hidden "action" fields would be N band-aids at the wrong
layer and would leave the trap in place for every future form. One change at the validator
repairs all affected forms at once, makes the `admin_edit_logic.php` workaround unnecessary
(though harmless), and prevents recurrence. Exposing `formnovalidate` on `submitbutton()`
keeps multi-action forms inside the FormWriter API rather than pushing developers back to
hand-rolled buttons (as `admin_note` had done).

## Verification

- **Empirical, original symptom:** `admin_agent_file_edit` "Save & Write to disk" wrote the
  DB but not disk and redirected to the edit page (the plain-save path) — proving
  `btn_save_and_write` never arrived.
- **Fix, functional test:** loaded the fixed validator against a real two-submit-button form;
  clicking the secondary button left `name="btn_save_and_write"` (value `""`) in the form at
  the moment `form.submit()` fired. Pre-fix the hidden input is absent.
- **Origin serves the fix:** confirmed via cache-busted fetch (see deployment caveat).
- **Part 2, formnovalidate:** a `submitbutton('btn_delete', 'Delete', ['formnovalidate' =>
  true])` renders the `formnovalidate` attribute; clicking it on a form with an empty
  required field skips client validation and submits (with the submitter preserved). To
  confirm after edge propagation — see follow-up #2.

## Deployment caveat (Cloudflare edge cache)

`dev.getjoinery.com` serves `/assets/*` through Cloudflare with `max-age=43200` (12h) and the
`<script>` tag has **no cache-busting query string**. The fix is live at origin immediately
but reaches browsers only after the edge cache expires (verify origin with
`?cb=<unique>`; standard CF cache key includes the query string). There is no per-file
version query, so a fix to shared JS/CSS cannot be forced to propagate without an edge purge.

## Follow-ups (open)

1. After edge propagation, confirm the delete/secondary actions on
   `admin_note`, `admin_seo_page_edit`, and `joinery_ai/admin_edit` now work end-to-end.
2. ~~Determine whether the `admin_note` form carries validation rules.~~ **Resolved:** it
   does (`rcn_title` required) → it was fully broken pre-fix. Confirm it now saves end-to-end
   after edge propagation, and verify Delete works with `rcn_title` empty (this is the
   `formnovalidate` path — see #6).
3. Optional cleanup: the `REQUEST_METHOD` workaround + comment in `joinery_ai/admin_edit_logic.php`
   is now redundant; leave or simplify.
4. Decide whether to add asset cache-busting (`?v=`) to the `joinery-validate.js` includes so
   future correctness fixes propagate immediately (touches ~10 `PublicPage*.php` files —
   separate change).
5. ~~Add a short note to `docs/formwriter.md` documenting that multi-action forms may rely on
   the submitter name.~~ **Done:** see the "Multi-Action Forms" subsection under Validation
   Integration in `docs/formwriter.md` (covers both submitter preservation and
   `formnovalidate`).
6. ~~Migrate the affected forms' secondary actions to the FormWriter `formnovalidate`
   option.~~ **Done:**
   - `plugins/joinery_ai/views/admin/note.php` — hand-rolled `<button name="btn_delete">`
     replaced with `submitbutton('btn_delete', 'Delete', [... 'formnovalidate' => true])`.
   - `plugins/joinery_ai/views/admin/edit.php` — same migration (recipe Delete).
   - `adm/admin_seo_page_edit.php` — already used `submitbutton('btn_delete', ...)`; added
     `'formnovalidate' => true`.
   - `adm/admin_agent_file_edit.php` (the original-symptom form) — already used
     `submitbutton('btn_delete', ...)` and `isFormSubmission()`; added `'formnovalidate' =>
     true` so Delete works regardless of required fields.
   - **Also fixed a latent gate bug in `admin_note_logic.php`:** it gated the whole
     save/delete block on `isset($input['btn_submit'])`. Since the Delete button posts only
     `btn_delete`, that gate skipped deletes even after Part 1 preserved the submitter.
     Changed to `LibraryFunctions::isFormSubmission()` (the standard POST-method gate),
     matching `admin_seo_page_edit_logic.php` and `joinery_ai/admin_edit_logic.php`. This is
     why a button-name gate is the wrong pattern for multi-action forms — branch on the
     button name *inside* a method gate, never gate on it.
