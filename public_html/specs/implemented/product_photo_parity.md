# Specification: Entity Photo Parity (Product + drift cleanup)

## Overview

Bring the platform's content entities onto one uniform primary-photo + photo-collection interface. Two problems today:

1. **`Product` has no photo support at all** — no `pro_fil_file_id`, no accessor methods, no admin photo UI. Every other public entity (`Page`, `Post`, `Event`, `Location`, `MailingList`) has the full interface.
2. **The existing entities themselves have drifted** — signature defaults and method completeness vary between `Page`/`Post`/`Event` on one side and `Location`/`MailingList` on the other. Specifically:
   - `Location::get_picture_link()` and `MailingList::get_picture_link()` default to size key `'content'` (Page/Post/Event default to `'original'`) and omit the `'full'` second arg when calling `File::get_url()`.
   - `get_primary_photo()` exists on `Post` and `Event` only. `Page`, `Location`, `MailingList` lack it.

Closing both gaps at once:
- Removes a special case in the SEO metadata spec ([`scrolldaddy_marketing_infrastructure.md`](scrolldaddy_marketing_infrastructure.md) Part A.2), which currently has to carve out a manual "first EntityPhoto" fallback for products.
- Lets any future generic helper that walks public entities (sitemap builder, search indexer, structured-data emitter) rely on a uniform `$entity->get_picture_link()` / `$entity->get_primary_photo()` interface across all seven entity types — with identical signatures and defaults.
- Unblocks product-detail pages having real OG images for social sharing.

This is platform-level consistency work — not ScrollDaddy-specific. It happens to unblock a ScrollDaddy marketing task, but the value is general.

**Caller-safety check for the default change on Location/MailingList:** every call site in the codebase that invokes `get_picture_link()` passes an explicit size key, except three on `Event` (safe — Event already defaults to `'original'`). No caller on `Location` or `MailingList` uses the no-arg form, so flipping the default from `'content'` to `'original'` is a no-op at runtime while standardizing the interface for future callers. Verified via grep across `views/`, `theme/`, `plugins/`, `data/`.

## Dependents

- [`scrolldaddy_marketing_infrastructure.md`](scrolldaddy_marketing_infrastructure.md) — Part A.2's Product row collapses from a special case to the standard pattern once this ships.

## Non-goals

- **Rewriting the `*_fil_file_id` denormalization.** The column acts as a cached pointer to "which photo is primary." Whether that's the right long-term design is out of scope — Product matches what exists today.
- **A generic `HasPhotos` trait or mixin.** Tempting to DRY the five methods into a shared trait across seven entities, but that's a cross-cutting refactor with its own risks. Product copies the established pattern verbatim; a trait extraction can come later if the pattern keeps multiplying.
- **Deeper photo-system rework** — no changes to `EntityPhoto`, `MultiEntityPhoto`, `PhotoHelper`, or the AJAX upload pipeline. This spec is strictly about bringing entities onto the existing interface.

---

## Scope of changes

### 1. Schema — add `pro_fil_file_id`

**File: `data/products_class.php`**

Add to `$field_specifications`:
```php
'pro_fil_file_id' => array('type'=>'int4'),
```

Add foreign-key action (so file-deletion nulls the pointer, matching every other entity):
```php
'pro_fil_file_id' => ['action' => 'null'],
```
in whatever FK-actions array the class uses (check neighbors — Page uses `$foreign_key_actions`).

`update_database` will create the column on next run.

### 2. Model methods — Page pattern verbatim

**File: `data/products_class.php`**

Add these five methods, copying the signatures and bodies from `data/pages_class.php:134–176` with field-prefix and entity-type substitutions:

```php
function get_picture_link($size_key='original'){
    if($this->get('pro_fil_file_id')){
        require_once(PathHelper::getIncludePath('data/files_class.php'));
        $file = new File($this->get('pro_fil_file_id'), TRUE);
        return $file->get_url($size_key, 'full');
    }
    return false;
}

function set_primary_photo($photo_id) {
    require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
    $photo = new EntityPhoto($photo_id, TRUE);
    $this->set('pro_fil_file_id', $photo->get('eph_fil_file_id'));
    $this->save();
}

function clear_primary_photo() {
    $this->set('pro_fil_file_id', NULL);
    $this->save();
}

function get_photos() {
    require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
    $photos = new MultiEntityPhoto(
        ['entity_type' => 'product', 'entity_id' => $this->key, 'deleted' => false],
        ['eph_sort_order' => 'ASC']
    );
    $photos->load();
    return $photos;
}

function get_primary_photo() {
    $file_id = $this->get('pro_fil_file_id');
    if (!$file_id) return null;
    require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
    $photos = new MultiEntityPhoto(
        ['entity_type' => 'product', 'entity_id' => $this->key, 'file_id' => $file_id, 'deleted' => false],
        [], 1
    );
    $photos->load();
    return $photos->count() > 0 ? $photos->get(0) : null;
}
```

Notes:
- `entity_type` string is `'product'` (lowercase, matches naming convention).
- Default size key is `'original'` and second arg is `'full'` — matches Page/Post/Event (the majority pattern).
- `get_primary_photo()` matches the newer Post/Event convention; not every older entity has it, but adding it to Product makes the interface complete.

### 3. Deletion cleanup

**File: `data/products_class.php`** — extend `permanent_delete()` (or whichever hook the class uses) to clean up EntityPhotos, mirroring `Post::permanent_delete()`:

```php
require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
$photos = new MultiEntityPhoto(
    ['entity_type' => 'product', 'entity_id' => $this->key]
);
$photos->load();
foreach ($photos as $photo) {
    $photo->permanent_delete();
}
```

Soft-delete leaves photo records intact (same as every other entity — a restored product keeps its photos).

### 4. Admin UI — PhotoHelper grid

**File: `adm/admin_product_edit.php`**

Add the PhotoHelper grid, matching `adm/admin_page.php:306–323`:

```php
$product_photos = $product->get_photos();
PhotoHelper::render_photo_card('grid', 'product', $product->key, $product_photos, [
    'set_primary_url' => '/admin/admin_product_edit?pro_product_id=' . $product->key,
    'card_title'       => 'Product Photos',
    'editable'         => $photo_editable,
    'primary_file_id'  => $product->get('pro_fil_file_id'),
]);
```

Rationale for the grid (Style A) over the simpler `FormWriter::imageinput()` dropdown (Style B used by Post): products benefit from multiple gallery images and a clearly designated primary. The richer UX matches commerce expectations and matches how Page — a peer content entity — already works.

The `set_primary_photo($photo_id)` form handler on the edit page invokes the model method (same wiring as `admin_page.php`).

### 5. AJAX endpoint — add `product` to the entity class map

**File: `ajax/entity_photos_ajax.php`**

The set-primary-photo handler uses a hardcoded `$entity_class_map` (around line 126) that currently lists event/user/location/mailing_list/post/page. Add:

```php
'product' => ['class' => 'Product', 'file' => 'data/products_class.php'],
```

The rest of the endpoint is polymorphic and reads `entity_type` from the request — no other changes needed.

### 6. Drift fixes — Location, MailingList, Page

Standardize the existing entities so the interface is uniform across all seven.

**6a. `Location::get_picture_link()` — `data/locations_class.php`**

Change the default size key from `'content'` to `'original'` and pass `'full'` as the second arg to `File::get_url()`. Final form:

```php
function get_picture_link($size_key='original'){
    if($this->get('loc_fil_file_id')){
        require_once(PathHelper::getIncludePath('data/files_class.php'));
        $file = new File($this->get('loc_fil_file_id'), TRUE);
        return $file->get_url($size_key, 'full');
    }
    return false;
}
```

**6b. `MailingList::get_picture_link()` — `data/mailing_lists_class.php`**

Same transformation: default `'original'`, add `'full'` second arg.

```php
function get_picture_link($size_key='original'){
    if($this->get('mlt_fil_file_id')){
        require_once(PathHelper::getIncludePath('data/files_class.php'));
        $file = new File($this->get('mlt_fil_file_id'), TRUE);
        return $file->get_url($size_key, 'full');
    }
    return false;
}
```

**6c. Add `get_primary_photo()` to `Page`, `Location`, `MailingList`**

Post and Event have this method; Page/Location/MailingList don't. Add it to the three missing entities, substituting field prefix and `entity_type` string per entity. Template (Page shown):

```php
function get_primary_photo() {
    $file_id = $this->get('pag_fil_file_id');
    if (!$file_id) return null;
    require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
    $photos = new MultiEntityPhoto(
        ['entity_type' => 'page', 'entity_id' => $this->key, 'file_id' => $file_id, 'deleted' => false],
        [], 1
    );
    $photos->load();
    return $photos->count() > 0 ? $photos->get(0) : null;
}
```

Substitutions per entity:
- `Page` → `pag_fil_file_id`, `'page'`
- `Location` → `loc_fil_file_id`, `'location'`
- `MailingList` → `mlt_fil_file_id`, `'mailing_list'`

After this, every entity exposes the same five-method surface:
`get_picture_link()`, `set_primary_photo()`, `clear_primary_photo()`, `get_photos()`, `get_primary_photo()`.

### 7. Backfill

On `joinerytest.site` this is confirmed a no-op — `SELECT COUNT(*) FROM eph_entity_photos WHERE eph_entity_type = 'product'` returned 0. But the platform is deployed to multiple sites (docker-prod containers, etc.) where an external process or older code path may have written product rows, so **ship the backfill as a migration regardless**. It's idempotent — the `WHERE pro_fil_file_id IS NULL` + `EXISTS` filter makes it a no-op everywhere there's nothing to do.

Populate `pro_fil_file_id` from the lowest-sort-order non-deleted EntityPhoto per product:

```sql
UPDATE pro_products p
SET pro_fil_file_id = (
    SELECT eph_fil_file_id FROM eph_entity_photos
    WHERE eph_entity_type = 'product'
      AND eph_entity_id = p.pro_product_id
      AND eph_delete_time IS NULL
    ORDER BY eph_sort_order ASC
    LIMIT 1
)
WHERE pro_fil_file_id IS NULL
  AND EXISTS (
    SELECT 1 FROM eph_entity_photos
    WHERE eph_entity_type = 'product' AND eph_entity_id = p.pro_product_id AND eph_delete_time IS NULL
  );
```

Wrap as a migration in `/migrations/migrations.php` — settings-style data migration, not schema. The migration must run **after** `update_database` materializes `pro_fil_file_id` (which it will, since migrations run as part of the same admin-utilities pipeline and `update_database`'s schema-sync step precedes migrations).

---

## Implementation Checklist

### Product parity
- [ ] Add `pro_fil_file_id` (int4, nullable) to `$field_specifications` in `data/products_class.php`
- [ ] Add `'pro_fil_file_id' => ['action' => 'null']` to the FK-actions array
- [ ] Run `update_database` to materialize the column
- [ ] Implement `get_picture_link()`, `set_primary_photo()`, `clear_primary_photo()`, `get_photos()`, `get_primary_photo()` in `Product` — verbatim copy of Page pattern with `pro_` / `product` substitutions
- [ ] Extend `Product::permanent_delete()` to purge EntityPhotos for `entity_type='product'`
- [ ] Add PhotoHelper grid UI to `adm/admin_product_edit.php` + wire `set_primary_photo($photo_id)` form POST handler
- [ ] Verify (or update) `ajax/entity_photos_ajax.php` accepts `entity_type='product'`
- [ ] Package the backfill UPDATE as a migration in `/migrations/migrations.php` (confirmed no-op on joinerytest, but may apply on other deployments)
- [ ] Run `php maintenance_scripts/dev_tools/validate_php_file.php data/products_class.php`
- [ ] Manual smoke test: edit a product in admin, upload two photos, reorder, set one as primary, verify `$product->get_picture_link('og_image')` returns the primary photo's URL; delete the product permanently and verify EntityPhotos are gone

### Drift cleanup (existing entities)
- [ ] `data/locations_class.php` — change `get_picture_link()` default size key to `'original'` and pass `'full'` as second arg
- [ ] `data/mailing_lists_class.php` — same change as above (`'original'` default, `'full'` second arg)
- [ ] Add `get_primary_photo()` to `data/pages_class.php` (entity_type `'page'`)
- [ ] Add `get_primary_photo()` to `data/locations_class.php` (entity_type `'location'`)
- [ ] Add `get_primary_photo()` to `data/mailing_lists_class.php` (entity_type `'mailing_list'`)
- [ ] Run `php maintenance_scripts/dev_tools/validate_php_file.php` on each edited file
- [ ] Grep-verify: `grep -rn 'get_picture_link()' views/ theme/ plugins/ data/` finds only the three known Event-side no-arg call sites (safe — Event is unaffected)
- [ ] Smoke-test a Location detail view and a MailingList detail view to confirm their public pages still render an image at the expected size

---

## Out of Scope
- Extracting the five methods into a `HasPhotos` trait.
- Rethinking whether `*_fil_file_id` denormalization is the right long-term design.
- Product variants or per-variant photos — `Product` photos attach to the product, not variants. If variant photos become a requirement, that's a separate data model question.
