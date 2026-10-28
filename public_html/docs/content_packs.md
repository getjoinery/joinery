# Content Packs

A content pack is a zip that says "make sure these records and settings look like this". A
superadmin uploads it at **System → Content Packs** (`/admin/admin_content_packs`), reads what it
would change beside what the site holds, ticks what to apply and confirms. The site writes it
through its own models in one transaction and keeps a record of every apply.

A pack is data. It names models from a fixed list, fields those models open to packs, declared
settings, and text files inside its own zip. Nothing in it is run and nothing in it is extracted.
It is never schema: tables and columns come from the data classes through `update_database`.

Packs for one site live outside `public_html`, in `{site root}/content_packs/{site}/`, so no release
carries them. `tests/unit/shipped_tree_hygiene_test.php` fails if a deployment-named script appears
under `public_html/`.

## The file

A zip holding `pack.json` and the text files it names, side by side. A zip of one folder holding
those files reads the same, which is what zipping a pack's folder makes. At most 2 MB, and each file
at most 2 MB unpacked, in UTF-8.

```json
{
  "pack": "getjoinery-domain-product",
  "version": "1.0",
  "description": "Shown at the top of the check page.",
  "requires": { "plugins": ["store"] },
  "notes": "Shown in a box above the plan: what to do by hand afterward.",
  "entries": [
    { "id": "domain", "model": "Product",
      "match": { "pro_link": "domain-registration" },
      "match_also": [ { "pro_name": "Domain registration (1 year)" } ],
      "set": { "pro_name": "Domain registration (1 year)", "pro_description": { "file": "description.txt" },
               "pro_is_active": true },
      "on_create": { "pro_max_cart_count": 1 } },
    { "model": "ProductVersion",
      "match": { "prv_pro_product_id": { "ref": "domain" }, "prv_version_name": "One year" },
      "set": { "prv_price_type": "user", "prv_status": 1 },
      "on_create": { "prv_version_price": "0.00", "prv_order": 1 } },
    { "model": "ProductVersion",
      "unlisted": { "where": { "prv_pro_product_id": { "ref": "domain" }, "prv_status": 1 },
                    "set": { "prv_status": 0 } } },
    { "setting": "store_domain_registration_product_id", "value": { "ref": "domain" } }
  ]
}
```

Unknown keys anywhere refuse the pack, naming the key, so a pack written for a newer site fails by
name on an older one. A plugin in `requires.plugins` that is not active refuses the pack.

### Entries

**Record entry** (`model`, `match`). Finds at most one live row and makes its fields true.

| Key | Meaning |
|---|---|
| `match` | The fields that find the row: exactly one of the key sets the model declares. Written on create and kept equal on every apply. |
| `match_also` | Earlier identities, any columns, tried when `match` finds nothing. A row found this way takes the `match` values. |
| `set` | Written on create and kept equal on every apply. |
| `on_create` | Written only when the row is created, never compared afterward. A product created inactive stays active once someone activates it. |
| `id` | Lets other entries point at this row with `{"ref": id}`. |

Lookups ignore soft-deleted rows and compare text with surrounding whitespace trimmed. Two rows for
one lookup, or `match` and `match_also` naming different rows, refuse the pack.

**Unlisted entry** (`model`, `unlisted`). `where` picks rows; those no record entry in the pack
matched get `unlisted.set`. It sets fields and never deletes. When a record entry under the same
`where` is new, the unlisted rows change only if that record is created, so a product is never left
with no active version.

**Setting entry** (`setting`, `value`). Written with `Setting::put()`. Undeclared, `secret`,
`managed` and credential-named settings are refused.

### Values

A string, number, boolean or `null`; `{"ref": "<id>"}`, the key of another entry's row (created first
when new, so order in the file does not matter; a loop refuses the pack); or, in `set`, `on_create`
and a setting's `value`, `{"file": "<name>"}`, the contents of a file in the zip. A file the pack
does not use refuses it.

## What a pack may write

A model opens itself with two declarations; a model without them is refused by name.

```php
// The key sets a match must name, and the fields set, on_create and unlisted may write.
public static $content_pack_keys = [['pro_link']];
public static $content_pack_fields = ['pro_name', 'pro_short_description', ...];
```

A field is listed only when every rule the admin form holds for it lives in the model's
`prepare()`, because a pack saves through `prepare()` and `save()` and never through the form. The
API's field floor (`is_unwritable_field()`) applies as well.

| Model | Keys | Fields |
|---|---|---|
| `Product` | `pro_link` | `pro_name`, `pro_short_description`, `pro_description`, `pro_after_purchase_message`, `pro_fulfillment_provider`, `pro_fulfillment_ref`, `pro_max_cart_count`, `pro_is_active` |
| `ProductVersion` | `prv_pro_product_id` + `prv_version_name` | `prv_version_price`, `prv_price_type`, `prv_trial_period_days`, `prv_status`, `prv_order` |

Stripe product ids are not pack fields. Checkout mints a product's Stripe product in its mode the
first time it needs one (`StripeHelper::ensure_stripe_product()`).

## The check page

Each field is one of:

| State | Meaning | Ticked at first |
|---|---|---|
| **new** | The row will be created. | yes, the whole row |
| **change** | The site still holds what a pack last established, or the field is empty. | yes |
| **same** | Already right. | — |
| **edited here** | The site holds a value no pack established: an edit made on the site, or a field no pack has applied before. | no |

Values are compared with line endings as LF, text trimmed, and booleans and numbers by value. Long
values fold, with a line diff.

What a pack established is kept in `cpv_content_pack_values`, one row per field: every `match`,
`set` and setting value an apply wrote or found already **same**. An **edited here** field left
unticked gets no row.

## Applying

The plan is built again inside the apply's transaction and must match the one the owner was shown;
otherwise nothing is written and the page asks for a fresh check. Each row is saved with `prepare()`
and `save()` under the owner's session. Any failure rolls everything back. Every upload is a
`cpa_content_pack_applies` row: who, when, the file and its SHA-256, the plan as shown, the ticks,
each written field's before and after value, and the outcome.

## API

The page's steps are API actions, all superadmin with a browser session:

| Action | Input | Returns |
|---|---|---|
| `content_pack_check` | multipart file field `pack` | `apply_id`, `plan` |
| `content_pack_apply` | `apply_id`, `plan_hash`, `ticks` | `written` |
| `content_pack_history` | — | `applies` |

The engine is `includes/ContentPack.php`: `ContentPack::read($zip_bytes)`, `->plan()`,
`->apply($apply, $plan_hash, $ticks, $session)`. Tests: `tests/core/content_pack_test.php`.
