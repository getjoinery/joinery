# Content Packs

**Status: implemented 2026-10-28. First cut (§6, f7b0109f2), plugin packs (§6a, 59a08a5d0) and phase 2, pages and blocks (§7), built and tested on dev; reviewer2 reviewed the design and both rounds of code. Phase 3 is deferred (owner, 10-28) and gets its own spec when a site needs it; fragment edits too. Not yet run live: the getjoinery applies of the product and pages packs, a test-mode purchase, REST B2 and plugin packs. They are in the live verification queue.** Draft 3 had reviewer2's design review folded in (F1-F17). The developer doc is `docs/content_packs.md`. Rewritten from the deferred 07-16 design, which copied raw table rows
from one site to another and ran from the command line only. getjoinery no longer has SSH, so the
hand-written apply scripts in `content_packs/getjoinery/` can no longer be run, and
`easy_signup_completion.md` WP7 (the Managed hosting and domain products on getjoinery) is blocked
on this. The field notes from the 08-09 build are kept below, each with the answer this design
gives.

## 1. What it is

A content pack is a file that says "make sure these records look like this". The owner uploads it
on the site's admin page, sees every change it would make with the old and new value side by side,
ticks what to apply, and confirms. The site then writes it through its own models in one
transaction and records what it did.

A pack is data. It never contains code, SQL, file paths or class names outside a fixed list, and the
site never runs anything from it. Anything a pack can do, an admin could already do through the
site's own edit screens.

What a pack is not:

- **Not schema.** Tables and columns come from the data classes through `update_database`. A pack
  only writes records into tables that already exist.
- **Not a site copy.** Moving a whole site is `site_copy.md`.
- **Not in the release.** Packs live in `{site root}/content_packs/{site}/`, outside `public_html`,
  as the scripts do today. `tests/unit/shipped_tree_hygiene_test.php` already fails if a
  deployment-named script appears under `public_html/`. An uploaded pack is kept in the target's
  apply record. The engine reads it from PHP's upload temp file, and nothing in it is extracted.

## 2. The file

A pack is a zip holding `pack.json` and, optionally, text files for long copy.

```
hosted_product/
  pack.json
  description.md
  after_purchase.md
```

```json
{
  "pack": "getjoinery-hosted-product",
  "version": "3.2",
  "description": "The Managed hosting product: $12.99 a month, no trial, created inactive.",
  "requires": { "plugins": ["store", "server_manager"] },
  "notes": "Activate the product once Provisioning Setup says a hosted order can be fulfilled.",
  "entries": [
    {
      "id": "hosted",
      "model": "Product",
      "match": { "pro_link": "managed-hosting" },
      "match_also": [ { "pro_link": "automatic-install-hosted" }, { "pro_name": "Automatic Install, hosted" } ],
      "set": {
        "pro_name": "Managed hosting",
        "pro_short_description": "We create the server, install your site, ...",
        "pro_description": { "file": "description.md" },
        "pro_after_purchase_message": { "file": "after_purchase.md" },
        "pro_fulfillment_provider": "customer_cloud",
        "pro_fulfillment_ref": 1
      },
      "on_create": { "pro_is_active": false, "pro_max_cart_count": 1 }
    },
    {
      "model": "ProductVersion",
      "match": { "prv_pro_product_id": { "ref": "hosted" }, "prv_version_name": "Hosting" },
      "set": { "prv_version_price": "12.99", "prv_price_type": "month", "prv_trial_period_days": null },
      "on_create": { "prv_status": 1, "prv_order": 1 }
    },
    {
      "model": "ProductVersion",
      "unlisted": { "where": { "prv_pro_product_id": { "ref": "hosted" }, "prv_status": 1 },
                    "set": { "prv_status": 0 } }
    }
  ]
}
```

And the domain pack's setting entry:

```json
{ "setting": "store_domain_registration_product_id", "value": { "ref": "domain" } }
```

### 2.1 Entry kinds

**Record entry** (`model` + `match`). Finds at most one record and makes its `set` fields true.

- `match`: the fields that find the record. They must be one of the key sets the model declares
  (§3). The `match` values are written on create and kept equal on every apply, like `set`. So a
  record found through `match_also` takes the new key, and the plan shows that as a change line
  (the hosted script updated `pro_link` this way, `apply_hosted_product.php:298`).
- `match_also`: earlier identities, tried in order when `match` finds nothing, so a renamed record
  is updated rather than doubled (the hosted script's `HOSTED_PREVIOUS_*`). Any declared column may
  be used here, since it only reads.
- Every lookup (`match`, `match_also`, `unlisted.where`) ignores soft-deleted rows, as the scripts
  (`apply_hosted_product.php:254`) and `get_by_link` do. Otherwise a deleted product holding the
  link would be found and revived by a `set`. Text values compare with surrounding whitespace
  trimmed, as the scripts did (`:347`).
- Two records found by one lookup, or `match` and `match_also` finding different records, refuses
  the whole pack and names them both. This is not hypothetical: `ProductVersion` has no uniqueness
  on (product, version name), and dev holds old products with the same version name three times.
- `set`: written on create and kept equal on every later apply.
- `on_create`: written only when the record is new, never compared afterward. This is how a product
  is created inactive without a later apply switching it back off after the owner activated it
  (the hosted script's "never silently reactivated").
- `id`: optional. Lets other entries point at this record.

**Unlisted entry** (`model` + `unlisted`). `where` picks a set of records; those that no record
entry in this pack matched get `unlisted.set`. This is the scripts' "retire any other active
version". It only sets fields, under the same field rules as `set`; it never deletes.

**Setting entry** (`setting` + `value`). Goes through `Setting::put`, which already refuses an
undeclared name (`data/settings_class.php:201-210`). A pack also refuses any setting declared
`secret` (`SettingsDeclarations::isSecret`, `includes/SettingsDeclarations.php:164`) and any name
matching the credential pattern (`SystemBase::CREDENTIAL_FIELD_PATTERN`,
`includes/SystemBase.php:80`). It refuses a setting declared `managed` as well
(`SettingsDeclarations::isManaged`, `includes/SettingsDeclarations.php:172`). Those are written by
the machine and never appear on a form.

### 2.2 Values

- Plain JSON values: a string, number, boolean or `null`.
- `{"ref": "<id>"}`: the key of another entry's record. The record is created first if it is new,
  so an entry may refer to an entry that appears later in the file. A cycle refuses the pack.
- `{"file": "<name>"}`: the UTF-8 contents of a file in the zip, read by name. A name holding `/`,
  `\` or `..`, a file that isn't there, and a file that is in the zip but unused all refuse the pack.
  Nothing is extracted to disk.

### 2.3 Strictness

Unknown keys anywhere refuse the pack, naming the key. This stands in for a format version: a pack
written for a newer site fails by name on an older one instead of being half understood. A pack is
at most 2 MB, and each member at most 2 MB uncompressed. The engine checks each member's size
(`ZipArchive::statIndex()`) before reading it.

`requires.plugins` lists plugins that must be active. One that isn't refuses the pack up front.

## 3. What a pack may write

Data is not code, but data can still do damage: a User row with permission 10 is just data. A pack
may write only what a model has explicitly opened to it.

A model opts in with two declarations:

```php
// Product
public static $content_pack_keys = [['pro_link']];
public static $content_pack_fields = ['pro_name', 'pro_short_description', 'pro_description',
    'pro_after_purchase_message', 'pro_fulfillment_provider', 'pro_fulfillment_ref',
    'pro_max_cart_count', 'pro_is_active'];

// ProductVersion
public static $content_pack_keys = [['prv_pro_product_id', 'prv_version_name']];
public static $content_pack_fields = ['prv_version_price', 'prv_price_type',
    'prv_trial_period_days', 'prv_status', 'prv_order'];
```

- A `model` name not on the list of opted-in models refuses the pack. The name is compared against
  that list and is never handed to the class loader on its own.
- A field not in `$content_pack_fields`, or one `is_unwritable_field()` refuses
  (`includes/SystemBase.php:2442`), refuses the pack. So a pack never writes a field the API's
  field floor forbids.
- The Stripe ids are on neither list. B1 (§8) mints them at checkout.

**Why an explicit field list and not "every API-writable field":** the product form carries rules
that are not in the model. It slugs `pro_link` (`admin_product_edit_logic.php:200-205`), refuses
an ownership tag on a product with subscription versions (`:183`), and refuses a subscription
version on a tagged product (`:59-64`). A pack saves through the model, so those rules wouldn't run.
A field joins the list only when its rules live in the model's `prepare()`. Some listed fields have
no rule anywhere but the form's dropdowns: a fulfilment provider key, a price type, a link's slug
form. B2 (§8) moves all of those rules into `prepare()`, and the lists above are safe only once B2
lands.

Columns the form stamps on create and a pack does not set are left NULL on a pack-created product:
`pro_created_by` (`admin_product_edit_logic.php:80-82`, nullable for products that predate it,
`products_class.php:118-120`), `pro_expires` and `pro_prg_product_group_id`. No reader treats NULL
differently from 0: every reader in the store plugin is a truthiness test (`ShoppingCart.php:431`,
`admin_product.php:66`, `admin_products.php:63`, `admin_product_logic.php:89`).

## 4. The check page

`/admin/admin_content_packs`, `@joinery-admin level=10`. Its three steps are API actions, so the
management node can drive the same steps later (§9):

| Action | Does |
|---|---|
| `content_pack_check` | Takes the uploaded zip. Validates it (§2, §3), stores it in a new apply record, and returns the plan. Writes nothing else. |
| `content_pack_apply` | Takes the apply record id, the plan hash it was shown, and the ticked rows. Builds the plan again, refuses if it differs from the one shown ("the site changed since you checked; check again"), then applies in one transaction. |
| `content_pack_history` | Lists earlier applies with their before and after values. |

All three declare `min_user_permission => 10` and `requires_browser_session => true`, the
pattern in `logic/backup_download_start_logic.php:40-50`. `content_pack_history` declares
`mutates => false`.

### 4.1 The plan

The page shows one block per record and one line per field:

| State | Meaning | Default |
|---|---|---|
| **new** | The record doesn't exist and will be created with `match` + `set` + `on_create`. | ticked, whole record only |
| **change** | The site still holds what a pack last established here (§4.2), or the field is empty, and the pack now says something else. An empty field holds no edit to protect. | ticked |
| **same** | Already right. Collapsed. | n/a |
| **edited here** | The site holds a value no pack established: someone changed it in the editor, or no pack has applied this field before. | **unticked**, highlighted |

Old and new values are shown side by side, with a line diff for long text. Values are compared
after normalizing: line endings to LF (the admin editor re-saves as CRLF), booleans and numbers by
value (`'t'`, `true` and `1` are equal, and so are `12.99` and `12.990`). `on_create` fields show
once, on new records only. The pack's `notes` and `description` show at the top.

Unticking a field leaves it as it is on the site. A new record is created whole or not at all, and
unticking it also skips every entry that refers to it. Each record an `unlisted` entry would
change is its own line. When a record entry under the same `where` is new, the unlisted lines apply
only if that record is created; the server enforces this, so unticking the new "Hosting" version
never leaves the product with no active version. The page says so beside those lines.

### 4.2 Knowing "change" from "edited here"

Telling the two apart needs to know what a pack last established. Each apply records the pack's
value for every `match`, `set` and setting field of every record it applies, whether the field was
written or already **same**: `cpv_content_pack_values`, one row per (model, record key, field) or
(setting name), holding the value and the apply that recorded it. An **edited here** field the
owner left unticked gets no row, because the site's value is not the pack's. Without a row for the
**same** fields, a field a pack never had to write would show as **edited here** forever, once a
later version of the pack changes it. The plan compares:

- current = new → **same**
- current = last established by a pack → **change**
- anything else, including no row at all → **edited here**

The first apply of a pack to an existing record therefore shows every difference as **edited
here**, unticked. That is the safe default: the owner sees the difference and ticks it on purpose.

## 5. Applying

- One database transaction. The plan is built again inside it, and compared with the hash the
  owner was shown before anything is written, so the stale check and the writes see the same rows.
  Any refusal or exception rolls everything back in a `finally`, and the record says so.
  PostgreSQL aborts the transaction on any failed statement, so the engine never tries to carry on
  after one.
- Records are written through the model: `set`, `prepare()`, `save()`. That runs the model's own
  validation (Product refuses a `pro_link` another product holds, `products_class.php:820-829`;
  `SystemBase::prepare()` checks declared unique fields, `includes/SystemBase.php:3111`) and its
  permission check with the owner's session (`save()` enforces write access,
  `includes/SystemBase.php:3164-3171`). `save()` joins the open transaction rather than starting its
  own.
- Refs are resolved as records are saved: a new record is saved before anything that refers to it.
- Settings go through `Setting::put`, which also clears the settings cache for that name
  (`data/settings_class.php:230`) and purges the page caches that show it (`:233`). Both happen
  outside the transaction. After a rollback, the next read fetches and renders the old value
  again, so neither does harm.
- Afterward, the apply record holds: who, when, the pack name and version, the file's SHA-256, the
  file itself, the plan as shown, the ticks, the outcome, and each written field's before and after
  value. `cpv_content_pack_values` is updated as §4.2 says: every field the pack established,
  written or already **same**.

New models: `ContentPackApply` (`cpa_content_pack_applies`, `$access_class = 'keys'`) and
`ContentPackValue` (`cpv_content_pack_values`, same class). The engine is `includes/ContentPack.php`:
`ContentPack::read($zip_bytes)`, `->plan()` and `->apply($ticks, $session)`. Tests call it directly.
The admin menu entry goes in `admin_menus.json`.

## 6. First cut: unblocks WP7 items 1 and 2

All built 10-28, uncommitted.

1. **B1**: checkout mints a missing Stripe product (§8).
2. **B2**: the form-only product rules move into the models: ownership vs subscription, the
   fulfilment provider key, the price type, the link's slug form (§8). `SystemBase` gains
   `value_changes()` (a rule judges only a value being written) and `slug_form()` (what
   `create_url()` makes before it looks for duplicates).
3. `ContentPack` (`includes/ContentPack.php`) with record, unlisted and setting entries; `{"ref"}`,
   `{"file"}`; the validation in §2-§3. A zip of one folder is read as that folder.
4. The opt-in on `Product` and `ProductVersion` (§3).
5. `ContentPackApply`, `ContentPackValue`, the three actions (`logic/content_pack_*_logic.php`), the
   admin page (`adm/admin_content_packs.php`) and the menu entry (System → Content Packs).
6. The two product scripts become packs in `content_packs/getjoinery/`: `hosted_product/`
   (from `apply_hosted_product.php` 3.2) and `domain_product/` (from `apply_domain_product.php` 1.0).
   The hosted script's detach of a leftover "Managed domain" requirement has no pack equivalent.
   Dev's product 4769 has no such attachment. On getjoinery, the owner checks the product's page
   once.
7. Tests (`tests/core/content_pack_test.php`, db tier on the test database, 55 checks):
   - the hosted pack on an empty store shows all **new**, applies, and a second check shows all **same**;
   - a field edited after an apply shows **edited here**, unticked, and survives an apply;
   - a pack edit to a field shows **change**, including a field that was **same** when the pack
     was first applied;
   - a rename is found through `match_also`, and the record takes the new key;
   - a soft-deleted product holding the link is not found or revived;
   - unticking the new version leaves the `unlisted` retirement unticked;
   - B2's rules refuse an unknown fulfilment provider, an unknown price type and an unslugged link
     when the value changes, and a legacy row holding one still saves untouched. The B2 and pack
     tests register `CustomerCloudFulfillment` themselves, as `managed_site_purchase_test.php:395`
     does, because the harness doesn't boot plugin `serve.php` files;
   - `unlisted` retires the other version;
   - each refusal in §2-§3 refuses by name and writes nothing;
   - a failure mid-apply leaves nothing behind;
   - a plan that went stale between check and apply is refused;
   - the setting is refused when secret, managed or undeclared.

**Proof before getjoinery:** check both packs on dev, which already holds products 4769 and 6702,
and read the plan by hand. Then apply on dev, and check again (all **same**). Done 10-28 through the
page: the domain pack was all **same**. The hosted pack was **same** except `pro_description`,
**edited here**: dev holds the 3.0 text (1,000 emails, "sends and receives") because 3.1 and 3.2
were never run on dev. It was left unticked, so dev's text is unchanged. The owner then uploads
them to getjoinery, reads the plan, applies, and walks a test-mode purchase. That purchase is what
mints the test-mode Stripe product, through B1.

WP7 item 3 (the quick start copy: B135, B136, and WP6's F4 and F5) is the pages pack of phase 2 (§7).

## 6a. Packs a plugin ships (built 10-28, owner's ask)

A plugin can carry default content as packs: `plugins/{name}/content_packs/{pack}/`, each folder
holding `pack.json` and its text files in the upload format.

- **When.** Each pack lands once per install. `PluginManager::activate()` applies it after the
  activation commits, and `sync()` applies any pack not applied since the plugin's
  `plg_installed_time`. A pack added in a later plugin version therefore lands at the next sync.
  Reinstalling applies the packs again. It runs after the commit, not inside `onActivate()`, because
  install leaves a plugin inactive and only an active plugin's classes load
  (`ClassAutoloader::flush()` runs after the commit, `AbstractExtensionManager::activate()`).
- **How.** `ContentPack::apply_defaults()` takes the plan built inside its own transaction and that
  plan's default ticks: new rows and **change** lines are applied, and **edited here** is left
  alone. That is the check page's default, with nobody to untick it. The writes run as
  `SystemBase::server_initiated_write()`. The plugin's files carry the pack, and an admin at 8 may
  activate a plugin while the apply record is a keys-class row.
- **Failure.** It never undoes the activation or stops a sync. The failure is a `failed` apply
  record (source `plugin:{name}/{pack}`), an error_log line and a sync message
  (`content_pack_messages`, printed by `update_database`), and the next sync tries again.
- **Record.** `cpa_source` (`upload` or `plugin:{name}/{pack}`). `cpa_file` holds the folder's files
  as JSON. The Content Packs page lists them with where they came from.
- **Once per install, not per version.** A new version of the same pack does not re-run. Re-running
  would bring back a row the owner deleted, because lookups ignore soft-deleted rows. A plugin that
  must change its content later ships a new pack.
- Code: `includes/PluginContentPacks.php`, `ContentPack::read_dir()` / `dir_members()` /
  `apply_defaults()` / `default_ticks()`, `PluginManager::activate()` and `sync()` 1.18. Test:
  `tests/core/plugin_content_packs_test.php` (13 checks), run as an admin at level 8.

## 7. Later phases

**Phase 2: pages and blocks. Built 10-28, uncommitted.**

- **The models.** `Page` is keyed on `pag_link` and opens `pag_title`, `pag_template`,
  `pag_component_layout` and `pag_published_time`. `PageContent` is keyed on `pac_location_name`
  (unique, `data/page_contents_class.php:69`) and opens `pac_title`, `pac_com_component_id` and
  `pac_config`. `Component` declares `com_type_key` as its key and opens no field.
- **Find entries** (`id`, `model`, `find`) replace the sketch's `requires.components`. A find names
  a row already on the site, by a declared key set, for other entries to point at with
  `{"ref"}`: a block's type is a ref to one. One mechanism covers what `requires.components` would
  have, and refuses up front by name ("This pack needs Component com_type_key = ..., which is not
  on this site"). A record entry on a model that opens no field is refused ("Use a find entry"),
  so a pack never creates a component type.
- **JSON columns.** In `set` and `on_create`, `{"merge": {...}}` sets the keys it names and leaves
  the rest. Each key is its own plan line (`pac_config.html`), compared, ticked and tracked in
  `cpv_content_pack_values` on its own. A list is a whole value: `pag_component_layout` is a list of
  refs. Refs and files are read at any depth inside either. Values compare as canonical JSON:
  object keys sorted, numbers as text, strings with CRLF as LF and trimmed. A bare object is
  refused, so `{"merge"}` is never implied.
- **A line waits for the new record it points at.** A line on an existing row whose value refers
  to a record the apply creates carries `needs` and is skipped when that record is unticked. The
  first cut wrote null in that case for any ref in `set` on an existing row. No pack hit it,
  because product refs sit in `match`.
- **A rename through `match_also`** shows the key line as **change**, ticked. The old value is
  the pack's own earlier identity, not an edit. The first cut showed it as **edited here**.
- **The form rules in the models** (as B2 did for products). `Page::prepare()` holds the link's slug
  form and requires a template to be a `page_...` view on the site; `views/page.php` requires
  it. `PageContent::prepare()` holds the editor's slug form (`generate_slug()`, which collapses
  `--`) and requires the type to be live and active, as the editor's dropdown does. Each rule
  checks a changed value only.
- **Config keys are not checked against the component's schema.** `com_config_schema` rows can lag
  the theme's files: dev's `marketing_hero` row has no `button_size`, which the getjoinery theme's
  file declares. The editor drops unknown keys rather than refusing them. The plan shows each key.
- **The check meets the models' rules** (reviewer2 R1). `plan_record()` puts each record through its
  model's `prepare()`, without saving, with every line the pack could write set. A refusal refuses
  the pack at check with the model's message (`dry_prepare()`). Otherwise a block of an inactive
  type, or a template the theme lacks, showed as **new** and failed only at apply, after the owner
  had ticked it. A pack model's `prepare()` must therefore only read; the four models' do (unique
  checks, a file lookup, the fulfilment registry). The first cut's mid-apply failure test moved
  to a value only the database refuses. On dev the pages pack is refused by name, because dev's
  theme has no `page_marketing`. With the getjoinery theme set in memory, it plans as below.
- **The check page** opens with a count of each state, unlisted rows included. It folds found rows
  and records already right into two lists, so a 100-record pack reads. It names the new records
  an item or a line waits for (R4).
- **Limits** (R2, R3): a merged key's `column.key` is at most 100 characters (`cpv_field`). A column
  sits in `set` or `on_create`, never both, so a pack cannot keep one key of a config and seed
  another only on create. Nothing needs that yet.
- **Not built: fragment edits** (`{"replace": [{"find", "with"}]}`). A whole-field line with its
  diff covers WP7: the owner reads the diff and ticks. Build it when a pack must change part of a
  field that the site also edits.
- `Page::save()` and `PageContent::save()` write content versions (`data/pages_class.php:222`), so
  the editors' history covers pack writes.
- Code: `ContentPack.php` 1.1, `admin_content_packs.php` 1.1, `pages_class.php`,
  `page_contents_class.php`, `components_class.php` 1.5.0. Test: `tests/core/content_pack_pages_test.php`
  (33 checks, test database). Code review: reviewer2 10-28, R1-R8; R1, R2 and R4 fixed and R3 noted.
  R5-R8 were notes needing no change.

**The getjoinery pages pack (WP7 item 3).** `content_packs/getjoinery/build_pages_pack.php` writes
`pages/` from `getjoinery_pages.php` 3.5: 14 pages, 86 blocks, 8 find entries, one `.html` file per
custom_html block, 420 KB. It takes the place of `seed_getjoinery_content.php`, which needs a shell.
Blocks are keyed `gj-{page}-{key}`, the form the editor saves. The seed's `gj-{page}--{key}` is
each block's `match_also`, because the editor rewrites that name the first time a block is saved
there. Checked on dev from the command line (a plan, nothing written): the 12 quick start blocks
and 2 pages dev holds are found by their old names. 39 lines are **same**, 12 renames are
**change**, and 5 HTML lines are **edited here**: the 3.5 copy (Domains-only token, the backup
bucket step, installer 2217113) against dev's older text. The other 12 pages and their 74 blocks
are **new**. 3.5 answers B135, B136 and F4: step 5 names every field. **F5 is still open:** 3.5
says the site "then deletes its copy", and F5 found that the copy stays when email is skipped.
That is the owner's wording to settle in `getjoinery_pages.php`. Rebuild the pack after it changes.

**Phase 3: public site navigation and redirects. Deferred (owner, 10-28)** until a site needs it.
It covers `public_menus` (the header menu visitors see) and `urls`, each keyed on a declared field,
never on a visible label. It never covers the admin sidebar, profile or settings menus: plugins
declare those in `plugin.json` (`adminMenu`, `profileMenu`, `settingsMenu`), `admin_menus.json`
declares core's, and `PluginManager::syncMenus()` keeps them in step. A pack never writes them.

**Later, no phase yet:**

- delivery from the management node, through the same actions with a superadmin key;
- an "undo this apply" button, built from the before values already recorded;
- writing a pack from existing records, to help authoring.

## 8. Bugs fixed on the way

**B1. A product saved by anything other than the product form couldn't be bought.**
`StripeHelper::get_or_create_price` created a missing Stripe *price* but threw "This product does
not have a stripe product id" for a missing *product*. The form minted only the current mode's id,
so a product created in test mode failed at its first live checkout as well.

Fixed: `StripeHelper::ensure_stripe_product()` mints and sets the id for the helper's mode.
`get_or_create_price` calls it and stores the id as a server write, as it already does for the price
id. The write carries one column. `save()` on an existing record writes only the columns `set()`
was called for and reads the rest back (`includes/SystemBase.php:3342-3367`), so an admin editing
the product at the same moment loses nothing. The product form calls the same method. The caller
list in `core_api_mechanical_test.php` names the new write. Test: `plugins/store/tests/stripe_product_self_heal_test.php`. A product written
through the model gets its test id from a buyer's checkout, then its live id in live mode, and a
Stripe refusal stores nothing. Two buyers checking out the same new product at the same moment can
each mint one, and the later save wins. The other is an unused product in the Stripe dashboard,
the same outcome the scripts already accepted. A product with no id in the checkout's mode now
passes through `save()`'s own checks at checkout. A legacy row that fails a newer rule is refused
with that rule's message instead of "no stripe product id". Both refuse.

**B2. Product rules that live only in the admin form.** Part of the first cut. Each rule moves into
the model's `prepare()`, and the form keeps its messages. Each rule checks a value only when it
differs from the value the record was loaded with (`SystemBase` keeps that in `$this->original`,
`includes/SystemBase.php:635-637`), or on a new record. A legacy row that fails a newer rule can
still be saved untouched. That includes the save that deactivates a product whose provider plugin
has since been turned off. A pack's typo is still refused, because it differs from what is stored.

- **Ownership vs subscription.** A REST write to `Product` (`$api_writable`, `products_class.php:41`,
  with no unwritable fields) can set `pro_ownership_tag` on a product with subscription versions,
  which the form refuses (`admin_product_edit_logic.php:183-190`). The other direction, a
  subscription version on a tagged product (`:59-64`), is not reachable over REST, because
  `ProductVersion` is not API-writable. A pack is its first writer outside the form. Both
  directions move.
- **Fulfilment provider.** `pro_fulfillment_provider` is a bare `varchar(32)`
  (`products_class.php:99`). The form only splits its dropdown's value (`admin_product_edit_logic.php:111-113`). At checkout an
  unknown key completes the charge and stamps `ord_error`: the buyer paid and nothing was granted
  (`cart_charge_logic.php:1424-1440`). `Product::prepare()` refuses a new non-empty key that
  `FulfillmentRegistry::get()` does not know. Providers register only from each plugin's
  `serve.php` on a web request, so the registry is empty from the command line. That is why the
  rule checks changed values only.
- **Price type.** `prv_price_type` is a bare `varchar(10)`. The form offers single, user, day,
  week, month and year (`admin_product_version_edit.php:49`). A pack writing "monthly" would make
  `is_subscription()` false, and a subscription would bill once. `ProductVersion::prepare()`
  refuses anything outside that set.
- **Link form.** The form slugs `pro_link` through `create_url` (`includes/SystemBase.php:585-587`).
  `Product::prepare()` refuses a link that `create_url` would change. It fails loudly rather than
  rewriting the value. The database has no unique index on `pro_link`; `prepare()`'s duplicate
  check (`products_class.php:822-826`) is the only guard, and it ignores soft-deleted rows.

**B3. A block's save left every page showing it cached with the old block.** `SystemBase::save()`
clears the cached copy of the model's own URL, and `PageContent` has none
(`includes/SystemBase.php:3701`). The static cache has no expiry, so a block edited in the editor
stayed stale for logged-out visitors until something else cleared the page. Fixed:
`PageContent::save()` clears each live page whose layout holds it.

**B4. A page shown as the home page stayed cached at `/`.** With `alternate_homepage` set to
`/page/{link}` (`serve.php:310-339`), the page is served at `/` too, and its save cleared only
`/page/{link}`. Fixed: `Page::invalidate_cached_copies()` clears both, and `Page::save()` clears `/`.

## 9. Not in this spec

- Copying a whole site's rows to another site, export, and install flavors (the 07-16 design). A
  plugin's own packs (§6a) are the install-time packs that remain. Site copy covers whole-site moves, and no install flavor is planned. If starter
  kits come back, they get their own spec.
- Deleting records. `unlisted` retires by setting a field. A pack that must delete waits for a model
  that declares what retiring means for it.

## 10. Field notes from the 08-09 build, and the answers

1. **References hidden inside JSON columns** (`pag_component_layout` holds block ids). Answered by
   refs inside JSON values (phase 2, built). A pack never carries a raw id.
2. **Blocks depend on component types that don't travel.** Answered by find entries (phase 2,
   built): a missing type refuses the check by name.
3. **Hash dedup can't tell "updated" from "new".** Answered by matching on declared keys, never on
   content.
4. **Drift is immediate, so a check run isn't optional.** It is the main screen (§4). Partial apply
   is the tickboxes.
5. **Menu rows need identity, and labels aren't it.** Answered by keys that are declared fields
   (phase 3, deferred).

## 11. Decisions

None open.
