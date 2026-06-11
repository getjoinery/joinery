# Documentation Audit: The New Plugin Developer Experience

## Method

A plugin spec (`specs/linkvault_plugin.md`) was written from the perspective of a brand-new
Joinery user, using **only** the distributable agent file
(`maintenance_scripts/install_tools/default_agents_template.md` — the content seeded into
`agf_agent_files` as the customer baseline) and the `/docs/` guides, with no source code
consulted. Every assumption the docs forced was recorded. Afterward, each claim was verified
against the actual codebase. This spec catalogs the results: confirmed documentation errors,
cross-document contradictions, stale content, gaps, and a handful of real platform issues the
exercise surfaced.

**Severity key:** 🔴 would produce broken code or a security mistake · 🟡 misleads or wastes a
developer's time · ⚪ cosmetic/hygiene.

---

## Part 1: Confirmed Errors (docs say X, code does Y)

### 1.1 🔴 `new Session($settings)` — class does not exist
- **Where:** `docs/plugin_developer_guide.md` "Core File Guarantees" example (`$session = new Session($settings); if (!$session->is_logged_in())`).
- **Reality:** There is no `Session` class anywhere in the codebase. The only pattern is `SessionControl::get_instance()`.
- **Fix:** Replace the example with `SessionControl::get_instance()`.

### 1.2 🔴 `getFormWriter('form1', 'v2', [...])` — wrong signature, would error
- **Where:** `docs/formwriter.md` (Getting Started, Auto-Filling, Edit Forms, Model Form Helpers — pervasive), `docs/admin_pages.md` (Edit Form, FormWriter best-practice), `docs/plugin_developer_guide.md`.
- **Reality:** `AdminPage::getFormWriter($form_id = 'form1', $form_options = [])` (`includes/AdminPage.php:33`) and `PublicPageBase::getFormWriter($form_id = 'form1', $options = [])` (`includes/PublicPageBase.php:106`). There is no `'v2'` parameter; passing the string lands in `$options` and breaks the `array_merge`. **Zero** production call sites use the three-argument form — real code calls `$page->getFormWriter('form1', ['model' => $x, ...])`.
- **Fix:** Remove the `'v2'` argument from every example in all three docs.

### 1.3 🔴 `LogicResult::success()` does not exist
- **Where:** `docs/formwriter.md` §7 "Server-Side Validation" example.
- **Reality:** `includes/LogicResult.php` defines exactly `redirect()` (line 18), `render()` (line 30), `error()` (line 42).
- **Fix:** Change the example to `LogicResult::render(...)` or `redirect(...)`.

### 1.4 🔴 Two-argument logic signature taught by the two canonical guides
- **Where:** `docs/logic_architecture.md` — nearly every example uses `function foo_logic($get_vars, $post_vars)` and `process_logic(product_logic($_GET, $_POST))`, including the patterns sections and the plugin example. `docs/admin_pages.md` — the "Complete Template" and all page-type patterns use the same dead convention.
- **Reality:** Every sampled logic file (core, adm, plugins) uses `function foo_logic(array $input): LogicResult`, and views call `process_logic(foo_logic(array_merge($_GET, $_POST)))` — e.g. `logic/register_logic.php:6`, `adm/logic/admin_order_delete_logic.php:12`, `plugins/inbound_email/logic/admin_inbound_email_reader_logic.php:15`. The Plugin Developer Guide explicitly states there is only one signature — and it is correct; the other two docs contradict it at scale.
- **Fix:** Rewrite all examples in `logic_architecture.md` and `admin_pages.md` to the single-`$input` convention. This is the highest-volume fix in the audit: the two documents a developer reads to learn the pattern are the two that teach the dead one.

### 1.5 🔴 `get_permission_level()` does not exist
- **Where:** `docs/logic_architecture.md` "Permission Check Pattern".
- **Reality:** The method is `get_permission()` (`includes/SessionControl.php:907`).
- **Fix:** Rename in the example.

### 1.6 🔴 `validation.md` demonstrates a removed function and contradicts itself
- **Where:** `docs/validation.md` §1 note (line ~96) correctly says `set_validate()` was **removed**; §3 "Quick Example" then demonstrates `echo $formwriter->set_validate($validation_rules);`; §4 Step 2 builds an entire admin page around it; §6 labels it "Legacy V1 `set_validate()` (**still works**)"; §7 troubleshooting asks "Is set_validate() being called?".
- **Reality:** `set_validate` does not exist on any class (grep: zero definitions). The §4 example also stacks four more dead patterns: V1 positional FormWriter calls (`textinput('Product Name', 'pro_name', 'form-control', 100, ...)`), `header('Location: ...'); exit;` instead of `LogicResult::redirect()`, a `/adm/...` URL, and a `?msg=saved` query-string message — each individually banned elsewhere in the docs.
- **Fix:** Delete §3's legacy example, rewrite §4 to the V2/LogicResult pattern, delete the "still works" claims in §6/§7. This file actively trains a new developer to write pre-migration code.

### 1.7 🔴 Wrong field-specification dialect in the Plugin Developer Guide's first model example
- **Where:** `docs/plugin_developer_guide.md` "Data Models": `'mdt_id' => ['required' => true, 'type' => 'int']`, `'mdt_name' => ['type' => 'varchar', 'length' => 255]`, `'mdt_created' => ['type' => 'timestamp', 'default' => 'now()']`.
- **Reality:** The schema system consumes `'type' => 'int8'` + `'serial' => true` + `'is_nullable' => false`, `'varchar(255)'`, `'timestamp(6)'` (see `data/users_class.php:72-115`, `docs/example_class.php`). `length` is not a schema key; `required` is a *validation* key distinct from `is_nullable`. The same guide's later "Table Creation (Automatic)" section uses the correct dialect — the document contradicts itself, and a new developer can't tell which example creates a working table.
- **Fix:** Rewrite the first example in the real dialect; add one sentence distinguishing `required` (validation) from `is_nullable` (schema).

### 1.8 🔴 Plugin setting