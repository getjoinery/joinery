# FormWriter Consolidation + Tailwind Theme Removal

**Status:** Implemented
**Version:** 1.0
**Sequence:** Do this **first** — it is a self-contained cleanup that is independent
of the CSS contract work. It is a prerequisite for
[css_platform_style_contract.md](css_platform_style_contract.md) /
[css_migration_plan.md](css_migration_plan.md): once there is a single HTML renderer,
the CSS migration's "flip FormWriter default classes to `.jy-*`" step touches one
renderer instead of three.

## Goal

Collapse FormWriter to **one HTML renderer** plus the JSON output renderer, delete
the two dead Tailwind themes, and drop core Tailwind support — while **preserving
the ability for plugins and themes to extend the core FormWriter**.

**This is not a rewrite.** The engine (`FormWriterV2Base`) and the HTML renderer
(`FormWriterV2HTML5`) keep their structure untouched. We remove two unused *sibling
renderers* and repoint a handful of consumers. No form logic is rewritten.

**Behavior is unchanged by this spec.** `FormWriterV2HTML5` keeps emitting today's
bare `.btn`/`.form-control` classes — they keep working via the existing CSS. The
switch of those defaults to `.jy-*` is **not** part of this spec; it happens later,
when the CSS contract lands (`css_migration_plan.md` Phase 2). Keeping them separate
is what lets this run first with zero visual change.

## Current shape

```
FormWriterV2Base            engine — all form logic
 ├─ FormWriterV2HTML5       HTML renderer            (keep)
 ├─ FormWriterV2Bootstrap   HTML renderer variant    (remove)
 ├─ FormWriterV2Tailwind    HTML renderer variant    (remove)
 └─ FormWriterV2JSON        JSON output format       (keep — not a CSS concern)

theme/{t}/includes/FormWriter.php   `class FormWriter extends FormWriterV2HTML5|Bootstrap`
includes/FormWriter.php             legacy alias: `extends FormWriterV2HTML5`
```

The renderer variants differ only in the class names / wrapper markup they emit —
**not** in any loaded framework (e.g. the "Bootstrap" renderer just emits
`.form-control`/`.btn` *names*; the joinery-system admin styles those locally with
no Bootstrap loaded). Once forms standardize on one vocabulary they are redundant.

## End state

- **`FormWriterV2Base`** (engine) and **`FormWriterV2HTML5`** (the single HTML
  renderer) remain. **`FormWriterV2JSON`** remains.
- **`FormWriterV2Bootstrap`** and **`FormWriterV2Tailwind`** are deleted.
- **Extensibility is preserved and unchanged:** plugins and themes extend the core
  FormWriter exactly as today — `class FormWriter extends FormWriterV2HTML5` (or
  `…V2Base`), overriding render methods. A future custom renderer is still just a
  subclass. We are removing two built-in variants, not the extension point.
- The two `cssFramework: tailwind` themes (`theme/tailwind`, `theme/devonandjerry`)
  are deleted; core Tailwind support is dropped.

## Consumers to repoint (from the investigation)

- **`theme/plugin/includes/FormWriter.php`** and
  **`theme/joinery-system/includes/FormWriter.php`** — `extends
  FormWriterV2Bootstrap` → `extends FormWriterV2HTML5`. (The other two `V2Bootstrap`
  subclasses live in `theme/devonandjerry` and `theme/tailwind`, which are deleted,
  so they need no repoint.)
- **`includes/StripeHelper.php`** — `new FormWriterV2Bootstrap(...)` → `new
  FormWriterV2HTML5(...)`.
- **`includes/AdminPage-uikit3.php`** — dead file (not referenced anywhere live);
  deleted.
- **`utils/forms_example_bootstrap*.php`** — demo scripts; deleted.
- **Legacy `includes/FormWriter.php`** — already `extends FormWriterV2HTML5`;
  conforms as-is. Leave it (or remove and repoint its 7 callers — cosmetic).
- **`theme/*/theme.json` `formWriterBase`** — a validation-only manifest field
  (`ThemeHelper` checks the file exists; it is **not** the instantiation path). It is
  already stale almost everywhere (`FormWriterHTML5`/`FormWriterBootstrap`/
  `FormWriterTailwind` — none are real files). Normalize every surviving theme's value
  to `FormWriterV2HTML5`. Pure metadata, no runtime effect.

**Caveat:** the Bootstrap and HTML5 renderers differ in wrapper markup, so a
repointed theme's form HTML shape changes. Fine in principle, but verify the forms
on each repointed theme (`plugin`, `joinery-system`) render correctly.

## Safe execution sequence

Order matters so nothing extends a class that is already gone.

1. **Cross-node check (read-only — the gate for theme deletion).** Confirm no
   managed node has `theme_template` set to `tailwind` or `devonandjerry`. (Requires
   approval to query production nodes.)
2. **Delete the two themes** `theme/tailwind` and `theme/devonandjerry` — this also
   removes their `V2Bootstrap`-extending `FormWriter.php`, so they cannot dangle.
3. **Repoint survivors** to `FormWriterV2HTML5`: `theme/plugin` +
   `theme/joinery-system` `FormWriter.php`, and `StripeHelper`.
4. **Delete** `FormWriterV2Bootstrap.php`, `FormWriterV2Tailwind.php`, the dead
   `AdminPage-uikit3.php`, and the `utils/forms_example_bootstrap*` demos.
5. **Verify** (below).

Steps 3–5 (the FormWriter side) do not depend on step 1; only the theme deletion
(step 2) is gated on the cross-node check. If the check is delayed, the FormWriter
consolidation can proceed and theme deletion follow.

## Verification

- `grep -rn 'FormWriterV2Bootstrap\|FormWriterV2Tailwind' .` returns nothing live.
- `php -l` on every changed file; `validate_php_file.php` on the repointed files.
- Load a public form (e.g. registration) and an admin form (joinery-system) — both
  render correctly through `FormWriterV2HTML5`.
- No reference to `theme/tailwind` or `theme/devonandjerry` remains.

## Out of scope (handled by the CSS specs)

- Flipping FormWriter's default classes from bare `.btn`/`.form-control` to `.jy-*`
  — deferred to `css_migration_plan.md` Phase 2 (it depends on the `.jy-*` contract
  existing). This spec leaves the defaults as-is.

## Files

**Deleted**
- `includes/FormWriterV2Bootstrap.php`, `includes/FormWriterV2Tailwind.php`
- `includes/AdminPage-uikit3.php`, `utils/forms_example_bootstrap*.php`
- `theme/tailwind/`, `theme/devonandjerry/` (gated on cross-node check)

**Modified**
- `theme/plugin/includes/FormWriter.php`, `theme/joinery-system/includes/FormWriter.php` — repoint to `FormWriterV2HTML5`
- `includes/StripeHelper.php` — repoint to `FormWriterV2HTML5`
- (optional) `includes/FormWriter.php` + its 7 callers — only if removing the legacy alias
