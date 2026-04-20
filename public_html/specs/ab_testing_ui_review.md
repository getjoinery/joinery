# REMINDER: A/B Testing UI Review

**Status:** Deferred. The A/B testing framework shipped under
`specs/implemented/ab_testing_framework.md` with a functional admin panel
(`AbTestVersionsPanel`) and a cross-entity list (`/admin/admin_ab_tests`). The UI
was built to spec and verified end-to-end, but it has not yet had a real usability
pass.

## What to review

Walk through the admin experience once there's real data flowing and decide what
needs polishing. Candidate concerns to check:

### `AbTestVersionsPanel` (mounts on entity edit pages)

- **Variant creation form.** Each testable field gets a checkbox + input pair
  (`Override for this variant? [ ]`). Does that scale visually when an entity has
  5+ testable fields? Is the inherit-vs-override-empty distinction actually
  legible?
- **JSON field editing.** JSON-typed fields (e.g. `pag_component_layout`) render
  as a plain `<textarea>` accepting raw JSON. No syntax highlighting, no
  validation feedback. At minimum consider client-side JSON.parse on blur so a
  malformed value surfaces before submit.
- **Leaderboard readability.** At high trial counts the rate column is "xx.yy%" —
  fine on desktop, possibly cramped on narrow admin views.
- **Crown action.** Dropdown + button inline. When there are many variants the
  select may be unwieldy. Consider rendering variant stats inside the `<option>`
  labels (already does "name (rate%)") but that's truncated in some browsers.
- **Shared-entity disclosure.** For `PageContent` tests, the disclosure shows
  every page whose `pag_component_layout` includes this component. The styling is
  a plain bootstrap alert — may need to be more prominent for tests with a large
  fan-out.

### `/admin/admin_ab_tests` (cross-entity list)

- **Empty state** is just an empty table with no guidance. Could use a "Create
  your first test on any Page or PageContent" hint.
- **Entity labeling.** The label column uses `$entity_class::$prefix . '_title'`
  to show a friendly name. On a spot-check during verification, the title
  didn't always render (showed just `Page #1`). Either the lookup is flaky or
  the fallback is masking something. Diagnose.
- **Filtering / sorting.** Columns are rendered but not actually sortable —
  there's no querystring handling. Add sort toggles if the list grows.
- **Duplicate-active-test banner.** Only appears when the race has already
  happened. Fine as a safety net, but the prose could be clearer about what the
  admin is expected to do.

### General UX

- **Admin menu.** `/admin/admin_ab_tests` isn't in the sidebar yet — reach it only
  by typing the URL or from a link in `admin_pages` once the framework gets more
  visible.
- **Flash messages.** Error flashes are displayed once via
  `$_SESSION['abtest_flash_error']` but there's no success flash (e.g. after
  creating a variant). Silent success is fine for now; reconsider once the flow
  has real users.
- **Mobile.** The panel hasn't been exercised on narrow viewports. Tables and
  form layouts in the Bootstrap admin generally hold up, but verify.

## What triggered the review

Nothing broken — functional tests are green, end-to-end verification was clean.
This is a "built for correctness, polish later" note so the rough edges don't get
lost before the feature sees real use.

## When to run this review

After the first real A/B test has been configured and run through a full
draft → active → crowned cycle on a production site. The UX gaps that matter
are the ones that surface during real use; guessing at them in isolation will
over-invest in the wrong fixes.
