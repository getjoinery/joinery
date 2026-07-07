# Admin pages — render flash messages instead of silently clearing them

**Status:** Ready to implement.
**Touches:** `includes/AdminPage.php`, three admin pages that hand-render
messages today (consolidation), one docs paragraph. No schema, no new files.

## The bug

When a save on an admin page is refused, the admin sees the form re-render
with no explanation — the error text is generated and then destroyed without
ever being shown.

The plumbing: `process_logic()` (`includes/LibraryFunctions.php:1410`)
handles a `LogicResult::error(...)` that carries data by saving a
`DisplayMessage` flash into the session (`$session->save_message(...)`) and
letting the page re-render. That contract assumes the page chrome renders
pending flash messages. `AdminPage` never does — no `get_messages()` call
anywhere in it — and `admin_footer()` calls
`$session->clear_clearable_messages()` (`includes/AdminPage.php:66`), which
deletes them: `DisplayMessage` is constructed with `clearable = TRUE` by
default, so the "only clear what was fetched" safety in
`clear_clearable_messages()` doesn't apply. Every admin page therefore
swallows every flash message, every request.

Observed live: the joinery_ai recipe form's save-time taint gate returns
`LogicResult::error('Tainted-write opt-in required: ...')` — the save is
correctly refused, and the admin gets a blank re-rendered form.

A handful of admin pages already worked around this by fetching and
rendering their own messages (`adm/admin_static_cache.php`,
`adm/admin_scheduled_tasks.php` via its logic file,
`adm/admin_cloud_storage.php` via its logic file). That per-page opt-in is
the wrong layer — the fix moves it into `AdminPage` and removes the
workarounds.

## The fix

### 1. `AdminPage` renders pending messages

In `AdminPage::admin_header()`, immediately after the `BeginPage` /
`BeginPageNoCard` output (so the alerts sit at the top of the content area
on every admin page), render and emit all pending messages:

```php
private function renderFlashMessages(): string {
    $session = SessionControl::get_instance();
    // NULL location = both GLOBAL and IN_PAGE — admin has one message region.
    $messages = $session->get_messages($_SERVER['REQUEST_URI'] ?? '', NULL);
    $out = '';
    foreach ($messages as $msg) {
        $alert_class = 'alert-info';
        if ($msg->display_type == DisplayMessage::MESSAGE_ERROR)            $alert_class = 'alert-danger';
        elseif ($msg->display_type == DisplayMessage::MESSAGE_WARNING)      $alert_class = 'alert-warning';
        elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) $alert_class = 'alert-success';
        $out .= '<div class="alert ' . $alert_class . '" role="alert">';
        if ($msg->message_title) $out .= '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
        $out .= htmlspecialchars($msg->message);
        $out .= '<button type="button" class="alert-close" aria-label="Close">&times;</button>';
        $out .= '</div>';
    }
    return $out;
}
```

This is the exact markup and type→class mapping
`adm/admin_cloud_storage.php:35-48` uses today — the `alert-*` variants all
exist in the joinery-system theme (`theme/joinery-system/assets/css/style.css:864-867`),
and the message becomes visible with zero per-page changes.

Timing note (why this works without redirects): admin views call
`process_logic()` *before* `$page->admin_header(...)` — e.g.
`plugins/joinery_ai/views/admin/edit.php` runs logic on line 13 and the
header on line 17 — so an error saved during this request's logic pass is
already pending when the header renders. `admin_footer()`'s
`clear_clearable_messages()` stays exactly as it is; with the header now
rendering first, it becomes the correct "shown once, then gone" cleanup
instead of a silent destroyer.

### 2. Remove the per-page workarounds

Same request, three pages stop double-rendering (the central render would
otherwise duplicate them):

- `adm/admin_static_cache.php` — delete the `get_messages()` call (line ~306)
  and its render loop.
- `adm/admin_scheduled_tasks.php` + `adm/logic/admin_scheduled_tasks_logic.php:184`
  — delete the `display_messages` page-var and the view's render loop.
- `adm/admin_cloud_storage.php` + `adm/logic/admin_cloud_storage_logic.php:226`
  — same.

## Pinned decisions

- **Render in `admin_header()`, clear in `admin_footer()`** — no redirect
  pattern change, no new session mechanics, no JS toast system. The
  `.alert-close` button behaves exactly as it does on the cloud-storage page
  today.
- **Fetch with `display_location = NULL`** (all locations). The admin layout
  has no separate global-banner region; everything renders in the one spot.
- **Scope is `AdminPage` only.** Public-page message rendering (checkout,
  security pages, PublicPage themes) is untouched by this spec.
- **No changes to `process_logic()` or `SessionControl`.** The saving side
  of the contract is fine; only the admin rendering side was missing.

## Verification

1. `php -l` + `validate_php_file.php` on every modified file.
2. Reproduce the observed failure: on `/admin/joinery_ai/edit`, configure a
   pipeline recipe with the `email_triage` job and leave "Allow tainted
   writes" unchecked, submit — a red alert with the "Tainted-write opt-in
   required" message must appear at the top of the form (and the recipe must
   still not save).
3. The three consolidated pages still show their own save/action messages
   (e.g. clear the static cache and confirm the confirmation message
   renders once, not twice).
4. Confirm a message renders only once: reload the page after step 2 — the
   alert must be gone (footer cleared it after display).

## Docs

`docs/admin_pages.md`: state that `AdminPage` renders pending session flash
messages automatically at the top of the content area — a logic file
surfaces a user-visible failure by returning `LogicResult::error(...)` with
its data payload, and no admin page should call `get_messages()` or render
message alerts itself. Current-state voice only.
