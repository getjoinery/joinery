# Plugin Settings on Their Own Tab

## Problem

Every active plugin's settings form is inlined into the bottom of the General Settings page. `adm/admin_settings.php` scans for `plugins/{plugin}/settings_form.php` and `include`s each one inside the single page-wide `<form>`, under a "Plugin Settings" heading.

Four plugins ship a settings form today — `joinery_ai` (206 lines), `mailbox` (155), `dns_filtering` (42), `store` (18) — so roughly 420 lines of unrelated fields hang off the end of a page that is already long. That grows with every plugin.

Three concrete consequences:

- **One broken field disables everything.** All of it is one form, so a single invalid field anywhere vetoes the whole submit — including every plugin's settings. On 2026-07-25 a stale `logo_link` on jeremytunnell.com made the entire settings page unsavable, plugin settings included. See `validation_error_summary.md`.
- **One Save writes everything.** The form posts every setting on the page — 164 rows in the jeremytunnell case. Fewer fields per form means a smaller blast radius per save.
- **Unrelated things share a page.** Finding the AI provider setting means scrolling past captcha keys, blog options, and tracking config.

## What changes

Plugin settings move to a sibling tab, `/admin/admin_settings_plugins`, alongside the tabs that already exist:

```
[ General Settings ] [ Payment Settings ] [ Email Settings ] [ Plugin Settings ]
```

This follows the established pattern rather than inventing one. Settings are already split across sibling pages with `AdminPage::tab_menu()`, and plugin-owned settings already have a precedent on their own tab: the store plugin's Payment Settings at `/plugins/store/admin/admin_settings_payments`.

Each plugin keeps its existing `settings_form.php` unchanged. The new page renders the same includes, one section per plugin, in its own form.

## Design

**New page + logic, mirroring Email Settings.** `adm/admin_settings_plugins.php` plus `adm/logic/admin_settings_plugins_logic.php`. The existing per-tab logic files are the precedent; reusing `admin_settings_logic()` is the wrong move because it does General-tab-specific work — the theme-activation check and the `preview_image` increment both read `$input['preview_image']` directly, which is undefined on a POST that has no such field.

**One form per plugin section, each with its own Save.** A broken field in one plugin must not be able to block saving another — that isolation is the whole point of the move, so it applies between plugins too, not just between plugins and core. Each section is an independent `<form>` with its own submit button.

Consequences to honour:

- **No outer form wraps the sections.** Nested forms are invalid HTML; the page renders a tab strip, then N sibling forms.
- **One FormWriter per section**, with a distinct form id (`plugin_settings_{plugin}`) so field ids, CSRF tokens, and the emitted validator config stay separate.
- **The include contract is unchanged.** Every existing `settings_form.php` expects `$formwriter`, `$settings` and `$session` to be ambient, and none of them opens a form, closes one, or adds a submit button — the including page owns all three. Verified across all four current forms. So the new page sets up a fresh `$formwriter` before each `include` and adds the Save button after it, and no plugin file changes.
- **Each form declares which plugin it is** via a hidden field, so the logic knows whose settings it is being asked to write.

**Write only the submitting plugin's declared settings.** The logic builds its allowed set from the `settings` block of that one plugin's `plugin.json` and writes nothing else. The existing settings logic writes any setting whose name appears in the POST, so this is a tightening rather than a port: a crafted POST to the current pages can write an arbitrary setting row. Per-section forms make the narrower scope natural — each save has exactly one plugin's worth of legitimate keys.

**Settings of deactivated plugins stay invisible.** An inactive plugin contributes no form, so its stored rows persist but are not shown or editable. An inactive plugin's settings are not actionable, and `PluginManager` already owns the install/uninstall lifecycle that removes them for good.

**Extract the tab list once.** The `$tab_menus` array is currently copy-pasted into `admin_settings.php` and `admin_settings_email.php`, and adding a fourth tab would mean editing three files and remembering the fourth. Move the list into a single shared helper that every settings tab calls, passing only its own current-tab label. That is one place to change when a tab is added, and one migration point if `declarative_admin_tabs.md` is later built — that spec replaces the *source* of the list, not its consumers.

**Hide the tab when it would be empty.** If no active plugin has a `settings_form.php`, the tab does not render — the same conditional treatment Payment Settings already gets for the store plugin.

## Files

- `adm/admin_settings_plugins.php` — new; the tab strip and one independent form per plugin section
- `adm/logic/admin_settings_plugins_logic.php` — new; writes only the submitting plugin's declared settings
- `adm/admin_settings.php` — remove the plugin-settings scan and include block (~lines 1340–1361) and the `<h2 id="plugin-settings">` heading; adopt the shared tab helper
- `adm/admin_settings_email.php` — adopt the shared tab helper
- wherever the shared tab helper lands (likely alongside the other admin page helpers) — new

Plugin `settings_form.php` files are untouched.

## Documentation

- `docs/settings.md` — state that plugin-owned settings are administered on the Plugin Settings tab, and that a plugin exposes them by shipping `settings_form.php` plus a `settings` block in `plugin.json`.
- `docs/plugin_developer_guide.md` — in the plugin settings section, point at the tab as where a plugin's form appears.
- `docs/admin_pages.md` — document the shared settings tab helper as the way to add a settings tab.

End state only — no mention of the fields having previously lived on the General page.

## Tests

Safe tier (`tests/unit/` or `tests/integration/`):
- the Plugin Settings tab appears when at least one active plugin has a `settings_form.php`, and not otherwise
- every settings tab page renders the same tab set (guards the extracted helper against drift)
- one form per active plugin with a settings form, each with a distinct form id, and **no nested forms** in the output
- the logic writes a setting declared by the submitting plugin
- the logic **ignores** a POST key not declared by the submitting plugin — including one declared by a *different* active plugin
- an inactive plugin with stored settings contributes no form and no fields
- General Settings no longer emits any plugin field

Browser check: each of the four plugins' sections renders and saves independently; an invalid field in one section does not block saving another; General Settings still saves with the plugin fields gone.

## Out of scope

- Moving Payment Settings under this tab. It is already its own tab and works.
- Reworking `admin_settings_logic()`'s write-anything-in-the-POST behaviour on the General and Email tabs. Worth doing, separate change.
- The declarative tab source in `declarative_admin_tabs.md`. This spec only ensures there is a single consumer-side place for it to plug into.
- Trimming the explainer prose the plugin forms carry. All four open with an intro paragraph (`joinery_ai`: "API keys and runtime caps for scheduled LLM recipes…"; `dns_filtering`: "Configure your ScrollDaddy DNS service settings below."), which the admin-page convention says belongs in `/docs/` rather than on the page. Pre-existing and unrelated to the move, but this tab is where it becomes visible as a pattern across four plugins at once — worth a separate cleanup pass.
