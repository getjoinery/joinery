# A message is spent when it is shown, not when it is read

**Status: BUILT 2026-08-13 — db tier green. Replaces work item 4c of
`specs/core_api_mechanical_pass.md`, which proposed the wrong fix for a real
problem.**

## The problem 4c was actually looking at

Twenty logic files carry the same line:

```php
$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
```

and eighteen views carry the same loop:

```php
foreach ($page_vars['display_messages'] as $display_message) {
    if ($display_message->identifier == 'userbox') {
        echo PublicPage::alert($m->message_title, $m->message, $m->get_message_class());
    }
}
```

4c proposed deleting the twenty lines by having `process_logic()` inject
`display_messages` on every page. That cannot work, and the reason is the
actual defect underneath the duplication.

**Reading a message is what marks it as seen.** `get_messages()` sets
`clearable = TRUE` on everything it returns; `clear_clearable_messages()` then
deletes those, and the page footer calls it on every request. So fetching and
consuming are the same act. 229 pages call `process_logic()` and 18 views
display messages, so injecting on every page would consume — and destroy — a
pending message on the 211 pages that never show it. A message saved before a
redirect would vanish instead of appearing.

The duplication is a symptom. The framework makes displaying a message the
caller's job *and* makes reading one destroy it, so every page that wants to
show a message has to fetch it itself, and no page dares fetch one it will not
show.

The admin side already has this right, by accident of structure:
`AdminPage::renderFlashMessages()` fetches and renders in the same call, so
"fetched" and "shown" cannot diverge. This spec gives the public side the same
property on purpose.

## The change

**A message is spent when it is rendered.** Reading becomes free.

1. **`DisplayMessage` gains `$shown`** — the runtime fact, set when the message
   is actually emitted. `clearable` keeps its existing meaning: the author's
   intent, whether this message is one-shot or sticky. Conflating the two is
   the bug; separating them is the fix. (No caller passes `clearable = FALSE`
   today across 126 construction sites, so nothing changes behaviourally — but
   the constructor offers it, and after this it will mean what it says.)

2. **`SessionControl::get_messages()` stops marking.** It becomes a pure read,
   safe to call from anywhere, including code that will not display the result.

3. **`PublicPageBase::render_messages($identifier = NULL, $location = MESSAGE_DISPLAY_IN_PAGE)`**
   — fetches the messages addressed to a named slot, renders each through
   `static::alert()` so themes keep their markup, marks them shown, and returns
   the HTML. One call replaces the eighteen hand-rolled loops:

   ```php
   echo $page->render_messages('userbox');
   ```

   `identifier` is already how a message says which box on the page it belongs
   to. Making it the argument turns a filter each view remembered to write into
   the thing the view asks for.

4. **`clear_clearable_messages()` deletes what was shown**, not what was read:
   `$shown && $clearable`.

5. **`AdminPage::renderFlashMessages()`** marks through the same path, so there
   is one rule rather than two.

6. **The twenty hand-fetched lines are deleted**, and with them 4c's original
   goal — reached as a consequence rather than as the objective. No injection
   into `process_logic()` is needed: a view that renders a slot does not want
   the array, and a view that does not render one should not receive it.

## What this deliberately does not do

No change to when a message is *saved*, to `page_regex` matching, to
`display_location`, or to the markup any theme emits. A message that is shown
once and cleared behaves exactly as it does today. The difference is only that
a page which does not show a message no longer destroys it.

## Verification

- A message addressed to a slot survives a page that does not render that slot,
  and appears on the next page that does. This is the regression the current
  design cannot express and the reason 4c was refused.
- A message rendered once does not appear again.
- `get_messages()` called twice without rendering leaves the message pending.
- A sticky message (`clearable = FALSE`) survives being shown.
- Every one of the 18 views renders through `render_messages()`; grep finds no
  surviving hand-rolled loop over `display_messages`.
- Full db tier green.
