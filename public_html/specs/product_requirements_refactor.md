# Product Requirements Refactor Specification

**Status:** Proposed
**Created:** 2026-03-01
**Priority:** Medium

## Overview

Replace the hardcoded bitmask-based product requirements system (10 classes in `products_class.php`) with a unified, registry-based architecture. The current system has three parallel mechanisms for collecting customer information during checkout and registration — a rigid bitmask system, a flexible database-driven question system, and a half-implemented event extra info system. This refactor merges the first two into a single, extensible system and removes the third entirely.

No backward compatibility is maintained. The bitmask system, event extra info system, and all related code are removed entirely. A one-time data migration converts existing product configurations to the new system.

## Problem Statement

### Current Architecture: Two Parallel Systems

**System 1: Hardcoded Bitmask Requirements** (`products_class.php:25-607`)
- 10 requirement classes hardcoded directly in `products_class.php` (~580 lines)
- Stored as a single integer bitmask in `pro_requirements` column
- Each class identified by a power-of-2 ID (1, 2, 4, 8, 16, 32, 64, 128, 256, 512)
- Admin UI: checkbox list that sums values into one integer

| ID | Class | Label | Required? | Side Effects |
|----|-------|-------|-----------|-------------|
| 1 | FullNameRequirement | Name | Yes | Used for user creation in cart_charge_logic |
| 2 | PhoneNumberRequirement | Phone Number | Yes | Uses PhoneNumber model for rendering |
| 4 | DOBRequirement | Date of Birth | Yes | Custom 3-dropdown UI with JavaScript |
| 8 | AddressRequirement | Address | Yes | Creates Address objects, shows existing addresses |
| 16 | GDPRNoticeRequirement | GDPR Notice | Yes | Scrollable privacy notice + checkbox |
| 32 | RecordConsentRequirement | Consent to Record | Yes | Writes `evr_recording_consent` on EventRegistrant |
| 64 | EmailRequirement | Email | Yes | Used for user lookup/creation |
| 128 | UserPriceRequirement | User chooses price | No | Modifies item price calculation |
| 256 | NewsletterSignupRequirement | Newsletter Signup | No | Newsletter subscription |
| 512 | CommentRequirement | Comment | No | Stored in `odi_comment` field |

**System 2: Dynamic Question-Based Requirements** (already database-driven)
- `prq_product_requirements` table defines reusable requirements linked to Question objects
- `pri_product_requirement_instances` links requirements to specific products
- Questions support multiple types: short text, long text, dropdown, radio, checkbox, checkbox list
- Validated separately after bitmask requirements in `Product::validate_form()`

**System 3: Event Extra Info** (partially implemented, `events_class.php`, `event_registrants_class.php`)
- Flag `evt_collect_extra_info` on events triggers post-registration data collection
- Hardcoded fields on EventRegistrant: `evr_first_event`, `evr_other_events`, `evr_health_notes`, `evr_extra_info_completed`
- Separate user-facing form at `/profile/event_register_finish`
- Mostly commented out — profile reminders, admin display all disabled
- `evr_recording_consent` is the only actively used field, and it's already handled by `RecordConsentRequirement` in the bitmask system at checkout time

**Additionally:** A Survey system exists (`svy_surveys` → `srq_survey_questions` → `qst_questions`) that is fully built but disabled on events. Surveys are independent of all three systems above and are not affected by this refactor.

### Pain Points

1. **Cannot add new requirement types without code changes** — adding an 11th type requires modifying the abstract class, creating a new class, and deploying code
2. **No per-product customization** — can't change labels, ordering, or make individual requirements conditional per product
3. **Bitmask is opaque** — `pro_requirements = 97` is meaningless without decoding (1 + 32 + 64)
4. **580 lines cluttering products_class.php** — requirement classes have nothing to do with the Product model
5. **Side effects scattered in cart_charge_logic.php** — recording consent, comment storage, user creation all hardcoded in checkout logic by checking specific field names
6. **Two validation paths** — bitmask requirements and question requirements are validated in separate loops with different patterns
7. **Admin UI is split** — "Basic Requirements" checkboxes vs "Additional Requirements" checkboxes on product edit page
8. **Event extra info is redundant** — collects the same kind of data (health notes, preferences) that product requirements already handle, but through a separate half-built system with hardcoded fields instead of flexible questions

---

## Proposed Architecture

### Core Concept: Requirement Type Registry

Every requirement — whether it's a simple text field, a complex address picker, or a custom question — is a **requirement type** registered in a database table. Products reference requirements through the `pri_product_requirement_instances` junction table.

### New: `rqt_requirement_types` Table

```
rqt_requirement_type_id     int8 PK serial
rqt_class_name              varchar(255)    -- PHP class name, e.g. 'AddressRequirement'
rqt_label                   varchar(255)    -- Default display label
rqt_file_path               varchar(255)    -- Path relative to web root, e.g. 'includes/requirements/AddressRequirement.php'
rqt_is_system               bool            -- TRUE for built-in types (non-deletable)
rqt_default_config          jsonb           -- Default configuration for this type
rqt_delete_time             timestamp(6)
```

**Seeded with 11 types:**

| rqt_class_name | rqt_label | rqt_is_system | Notes |
|---|---|---|---|
| FullNameRequirement | Name | true | |
| PhoneNumberRequirement | Phone Number | true | |
| DOBRequirement | Date of Birth | true | |
| AddressRequirement | Address | true | |
| GDPRNoticeRequirement | GDPR Notice | true | |
| RecordConsentRequirement | Consent to Record | true | |
| EmailRequirement | Email | true | |
| UserPriceRequirement | User chooses price | true | |
| NewsletterSignupRequirement | Newsletter Signup | true | |
| CommentRequirement | Comment | true | |
| QuestionRequirement | Custom Question | true | Wraps a Question object |

### Modified: `prq_product_requirements` Table

Add columns to support the type registry:

```
prq_rqt_requirement_type_id    int4        -- FK to rqt_requirement_types (NEW)
prq_config                     jsonb       -- Per-requirement config overrides (NEW)
```

Existing columns retained:
- `prq_title` — overrides `rqt_label` when set
- `prq_qst_question_id` — used only when type is QuestionRequirement
- `prq_link`, `prq_fil_file_id` — kept for document/link requirements
- `prq_is_required`, `prq_order` — kept as-is

### Modified: `pri_product_requirement_instances` Table

Add columns for per-product customization:

```
pri_order                       int2        -- Display order for this product (NEW)
pri_config                      jsonb       -- Per-product config overrides (NEW)
```

### Unchanged: `oir_order_item_requirements` Table

No changes needed — already stores label/answer pairs generically.

---

## Requirement Type Interface

Each requirement type is a PHP class in its own file implementing a standard interface.

### `ProductRequirementInterface`

```php
interface ProductRequirementInterface {
    /**
     * Return the requirement type ID from rqt_requirement_types
     */
    public function get_type_id(): int;

    /**
     * Return the display label (may be overridden by prq_title)
     */
    public function get_label(): string;

    /**
     * Render form fields for this requirement
     * @param FormWriter $formwriter
     * @param User|null $user - logged-in user, if any
     * @param array $config - merged config (type defaults + prq overrides + pri overrides)
     */
    public function get_form($formwriter, $user = null, $config = array());

    /**
     * Validate submitted form data
     * @return array [$validation_data, $display_data]
     *   $validation_data: key=>value pairs merged into form_data
     *   $display_data: key=>value pairs for receipt display
     * @throws BasicProductRequirementException on validation failure
     */
    public function validate_form($data, $session = null, $config = array()): array;

    /**
     * Return client-side validation rules for JoineryValidation
     * @return array|null
     */
    public function get_validation_info($config = array());

    /**
     * Return any custom JavaScript needed by the form fields
     * @return string
     */
    public function get_javascript($config = array()): string;

    /**
     * Post-purchase hook — called after successful payment for each order item
     * Handles side effects like setting recording consent, newsletter subscription, etc.
     * @param array $data - the validated requirement data from the cart
     * @param OrderItem $order_item
     * @param User $user
     * @param Order $order
     */
    public function post_purchase($data, $order_item, $user, $order);
}
```

### Abstract Base Class

```php
abstract class AbstractProductRequirement implements ProductRequirementInterface {
    protected $config = array();
    protected $requirement_record = null;  // The prq_product_requirements row

    public function __construct($requirement_record = null, $config = array()) {
        $this->requirement_record = $requirement_record;
        $this->config = $config;
    }

    public function get_label(): string {
        // If prq_title is set on the requirement record, use it; otherwise use class default
        if ($this->requirement_record && $this->requirement_record->get('prq_title')) {
            return $this->requirement_record->get('prq_title');
        }
        return static::LABEL;
    }

    // Default no-op implementations
    public function get_javascript($config = array()): string { return ''; }
    public function get_validation_info($config = array()) { return null; }
    public function post_purchase($data, $order_item, $user, $order) {}
}
```

---

## File Structure

```
includes/
  requirements/
    ProductRequirementInterface.php     -- Interface definition
    AbstractProductRequirement.php      -- Base class with default implementations
    RequirementRegistry.php             -- Registry/factory class
    FullNameRequirement.php
    PhoneNumberRequirement.php
    DOBRequirement.php
    AddressRequirement.php
    GDPRNoticeRequirement.php
    RecordConsentRequirement.php
    EmailRequirement.php
    UserPriceRequirement.php
    NewsletterSignupRequirement.php
    CommentRequirement.php
    QuestionRequirement.php             -- Wraps Question model for dynamic questions

data/
    requirement_types_class.php          -- RequirementType + MultiRequirementType models (NEW)
    product_requirements_class.php       -- Modified (add rqt FK, config columns)
    product_requirement_instances_class.php  -- Modified (add order, config columns)
```

---

## RequirementRegistry Class

Central factory that loads and instantiates requirement types.

```php
class RequirementRegistry {

    /**
     * Get all requirement instances for a product, in order.
     * Single unified method replacing both Product::get_product_requirements()
     * and Product::get_requirement_instances().
     *
     * @param int $product_id
     * @return ProductRequirementInterface[]
     */
    public static function getProductRequirements($product_id): array;

    /**
     * Instantiate a requirement class from its type record.
     *
     * @param RequirementType $requirement_type - the rqt row
     * @param ProductRequirement|null $requirement_record - the prq row
     * @param array $config - merged config
     * @return ProductRequirementInterface
     */
    public static function instantiate($requirement_type, $requirement_record = null, $config = array()): ProductRequirementInterface;

    /**
     * Get all registered requirement types (for admin UI dropdowns).
     *
     * @return RequirementType[]
     */
    public static function getAllTypes(): array;
}
```

### `getProductRequirements()` Implementation

1. Load all `pri_product_requirement_instances` for the product (non-deleted), ordered by `pri_order`
2. For each instance, load the linked `prq_product_requirements` record
3. From the requirement record, load the `rqt_requirement_types` record
4. Merge config: `rqt_default_config` ← `prq_config` ← `pri_config`
5. Instantiate the class via `rqt_class_name` (require the file at `rqt_file_path`)
6. Return ordered array of `ProductRequirementInterface` instances

---

## post_purchase() Hook — Side Effect Migration

The current `cart_charge_logic.php` contains scattered field-name checks that trigger side effects. Each moves into the relevant requirement class's `post_purchase()` method.

| Current Code (cart_charge_logic.php) | Moves To |
|------|----------|
| `if(isset($data['record_terms'])){ $event_registrant->set('evr_recording_consent', TRUE); }` (lines 460-462, 493-495) | Removed entirely — consent stored in `oir_order_item_requirements` via `save_cart_data()` |
| `if(isset($data['comment'])){ $order_item->set('odi_comment', $data['comment']); }` (line 317-318) | `CommentRequirement::post_purchase()` |
| User creation from `$data['email']`, `$data['full_name_first']`, `$data['full_name_last']` (lines 282-288) | `FullNameRequirement::post_purchase()` and `EmailRequirement::post_purchase()` — or keep in cart_charge_logic since user creation is a checkout concern, not a requirement concern (see note below) |
| Receipt name from `$data['full_name_first'] . ' ' . $data['full_name_last']` (line 537) | Keep in cart_charge_logic — receipt assembly is a checkout concern |

**Note on user creation:** The `email` and `full_name` fields are used by `cart_charge_logic.php` to look up or create users. This is arguably a checkout-level concern rather than a requirement side effect. The `post_purchase()` hook is best suited for effects that are specific to the requirement type (recording consent on event registrants, storing comments on order items, subscribing to newsletters). User creation should remain in `cart_charge_logic.php` — it just reads from `$data` keys that were populated by the requirement validation.

### post_purchase() Calling Convention

In `cart_charge_logic.php`, after creating the order item and saving cart data, add a single loop:

```php
foreach (RequirementRegistry::getProductRequirements($product->key) as $requirement) {
    $requirement->post_purchase($data, $order_item, $user, $order);
}
```

The `$data` array, `$order_item`, `$user`, and `$order` are all available at that point. For requirements that need the event registrant (like RecordConsentRequirement), the `$order_item` has `odi_evr_event_registrant_id` set by the time post_purchase is called.

---

## Impact on Checkout Flow

### Product Form Rendering (`Product::output_product_form`)

**Before:**
```php
// Loop 1: bitmask requirements
foreach ($this->get_product_requirements() as $product_requirement) {
    $product_requirement->get_form($formwriter, $user);
}
// Loop 2: question requirements
$instances = $this->get_requirement_instances();
foreach($instances as $instance) {
    $requirement = new ProductRequirement(...);
    $question = new Question(...);
    $question->output_question($formwriter);
}
```

**After:**
```php
foreach (RequirementRegistry::getProductRequirements($this->key) as $requirement) {
    $requirement->get_form($formwriter, $user);
}
```

### Form Validation (`Product::validate_form`)

**Before:** Two separate validation loops with different patterns

**After:**
```php
foreach (RequirementRegistry::getProductRequirements($this->key) as $requirement) {
    list($validation_data, $display_data) = $requirement->validate_form($form_data, $session);
    if ($validation_data !== null) {
        $form_data = array_merge($form_data, $validation_data);
    }
    if ($display_data !== null) {
        $form_display_data = array_merge($form_display_data, $display_data);
    }
}
```

### JavaScript Output (`Product::output_javascript`)

**Before:** Separate loops for bitmask requirements and question requirements

**After:**
```php
foreach (RequirementRegistry::getProductRequirements($this->key) as $requirement) {
    echo $requirement->get_javascript();
    if ($requirement->get_validation_info()) {
        $validation_info[] = $requirement->get_validation_info();
    }
}
```

### Post-Purchase Processing (`cart_charge_logic.php`)

**Before:** Scattered field-name checks:
```php
if(isset($data['record_terms'])){
    $event_registrant->set('evr_recording_consent', TRUE);
}
if(isset($data['comment'])){
    $order_item->set('odi_comment', $data['comment']);
}
```

**After:**
```php
foreach (RequirementRegistry::getProductRequirements($product->key) as $requirement) {
    $requirement->post_purchase($data, $order_item, $user, $order);
}
```

---

## Config System

The `config` JSON columns (at type, requirement, and instance levels) allow per-type customization without code changes. Config is merged in order of precedence: **type defaults → requirement record → product instance**.

**Example configs:**

```json
// AddressRequirement
{"show_existing_addresses": true, "country_support": true}

// GDPRNoticeRequirement - customizable notice text
{"notice_text": "Custom privacy notice...", "checkbox_label": "I agree to the privacy policy"}

// UserPriceRequirement - set minimum price
{"min_price": 0, "label": "Suggested donation"}

// QuestionRequirement - references question ID (replaces prq_qst_question_id)
{"question_id": 42}
```

This means a site can set global defaults for a type, override per-requirement record, and further customize per-product attachment.

---

## Admin UI Changes

### Product Edit Page (`admin_product_edit.php`)

**Before:** Two separate checkbox sections
- "Basic Requirements" — checkboxes for 10 hardcoded types (summed into bitmask)
- "Additional Requirements" — checkboxes for database question requirements

**After:** Single unified "Requirements" section with checkboxes for all available requirements, grouped by type:

- **System requirements** — checkboxes for each system type (Name, Email, Phone, etc.), same UX as the current bitmask checkboxes
- **Custom question requirements** — checkboxes for each `ProductRequirement` record of type `QuestionRequirement`

Checking a box creates a `pri_product_requirement_instances` row linking the product to that requirement. Unchecking removes it. Display order is determined by the requirement type's `rqt_requirement_type_id` (system types first in a standard order, then custom questions), not manually sortable. `pri_order` is set automatically based on position in the list.

This keeps the admin UX nearly identical to today — the only visible change is that both sections merge into one checkbox list instead of two.

### Product Requirements Admin (`admin_product_requirements.php`)

**Before:** Simple list of custom question-based requirements only

**After:** Shows all requirement records with their type:
- Type column showing the `rqt_label`
- Filter by type
- Create new requirements of any registered type
- System types marked as non-deletable

### Product Requirement Edit (`admin_product_requirement_edit.php`)

**Before:** Title, question dropdown, link, file fields

**After:** All existing fields plus:
- Requirement type dropdown (`prq_rqt_requirement_type_id`)
- Config JSON editor or structured fields based on type
- Question dropdown shown only when type is QuestionRequirement

---

## Data Migration

A one-time migration script converts existing bitmask-based product requirements to the new registry system. This runs as a file-based migration.

### Migration Steps

**Step 1: Seed `rqt_requirement_types`**

Insert the 11 built-in types. Each row maps a `rqt_class_name` to its file path.

**Step 2: Create `prq_product_requirements` records for system types**

For each of the 10 bitmask requirement types, create one shared `prq_product_requirements` record:

```sql
-- Example: FullNameRequirement
INSERT INTO prq_product_requirements (prq_title, prq_rqt_requirement_type_id, prq_is_required)
VALUES ('Name', [rqt_id for FullNameRequirement], true);
```

This creates 10 reusable requirement records that products can reference.

**Step 3: Convert product bitmasks to instances**

For each product where `pro_requirements > 0`:

```php
$bitmask_map = [
    1 => $prq_id_for_fullname,
    2 => $prq_id_for_phone,
    4 => $prq_id_for_dob,
    8 => $prq_id_for_address,
    16 => $prq_id_for_gdpr,
    32 => $prq_id_for_record_consent,
    64 => $prq_id_for_email,
    128 => $prq_id_for_user_price,
    256 => $prq_id_for_newsletter,
    512 => $prq_id_for_comment,
];

$order = 0;
foreach ($bitmask_map as $bit => $prq_id) {
    if ($product->get('pro_requirements') & $bit) {
        $instance = new ProductRequirementInstance(NULL);
        $instance->set('pri_pro_product_id', $product->key);
        $instance->set('pri_prq_product_requirement_id', $prq_id);
        $instance->set('pri_order', $order++);
        $instance->save();
    }
}
```

**Step 4: Update existing question-based requirement records**

Set `prq_rqt_requirement_type_id` to the QuestionRequirement type for all existing `prq_product_requirements` rows that have `prq_qst_question_id` set:

```sql
UPDATE prq_product_requirements
SET prq_rqt_requirement_type_id = [rqt_id for QuestionRequirement]
WHERE prq_qst_question_id IS NOT NULL
AND prq_rqt_requirement_type_id IS NULL;
```

**Step 5: Set `pri_order` on existing question-based instances**

Existing `pri_product_requirement_instances` rows (from the old question system) need `pri_order` set. Give them order values starting after the bitmask requirements so they appear last (matching current behavior where questions render after bitmask requirements):

```sql
-- For each product, set pri_order on existing instances that lack it
-- using the current prq_order or a sequential default
```

---

## Plugin Extensibility

Plugins can register custom requirement types by creating a class file and a database record:

```php
// In plugin's registration
$type = new RequirementType(NULL);
$type->set('rqt_class_name', 'WaiverRequirement');
$type->set('rqt_label', 'Waiver Agreement');
$type->set('rqt_file_path', 'plugins/waivers/includes/WaiverRequirement.php');
$type->set('rqt_is_system', false);
$type->set('rqt_default_config', json_encode(['waiver_template_id' => null]));
$type->save();
```

The class implements `ProductRequirementInterface` and becomes available in the admin product edit UI automatically.

---

## Event Extra Info Removal

The event extra info system is removed entirely. Any information previously collected through it (first event, other events, health notes) should instead be collected as product requirements on the event's product using `QuestionRequirement` instances.

### What's Removed

**Database fields removed from `evt_events`:**
- `evt_collect_extra_info` — the toggle flag

**Database fields removed from `evr_event_registrants`:**
- `evr_recording_consent`
- `evr_first_event`
- `evr_other_events`
- `evr_health_notes`
- `evr_extra_info_completed`

**Note:** `evr_recording_consent` is also **removed** — see below.

### Files Affected

| File | Change |
|------|--------|
| `data/events_class.php` | Remove `evt_collect_extra_info` from `$field_specifications` |
| `data/event_registrants_class.php` | Remove `evr_recording_consent`, `evr_first_event`, `evr_other_events`, `evr_health_notes`, `evr_extra_info_completed` from `$field_specifications` |
| `logic/cart_charge_logic.php` | Remove `more_info_required` flag logic (lines 465-469) |
| `adm/admin_event_edit.php` | Remove `evt_collect_extra_info` hidden input (line 247) |
| `adm/logic/admin_event_edit_logic.php` | Remove `evt_collect_extra_info` from `$editable_fields` (line 137) |
| `adm/admin_event.php` | Remove "Collect Extra Info" display row (lines 228-229); remove commented-out extra info display block (lines 514-543) |
| `logic/profile_logic.php` | Remove commented-out extra info reminder (lines 98-103) |
| `views/profile/profile.php` | Remove commented-out extra info reminder (lines 27-41) |
| `views/profile/event_sessions.php` | Remove commented-out extra info reminder (lines 556-571) |
| `plugins/controld/views/profile/profile.php` | Remove commented-out extra info reminder (lines 29-43) |

### Files Deleted

| File | Reason |
|------|--------|
| `logic/event_register_finish_logic.php` | Entire file exists only for event extra info form submission |
| `views/profile/event_register_finish.php` | Entire file exists only for event extra info form |

### Replacement Strategy

For sites that need to collect "first event?", "health notes", etc. from event registrants:

1. Create `Question` objects for each piece of information (e.g., "Is this your first event?", "Any dietary restrictions or health notes?")
2. Create `ProductRequirement` records linking to those questions via the `QuestionRequirement` type
3. Attach those requirements to the event's product via `ProductRequirementInstance`

The data is then collected at checkout time (not post-registration) and stored in `oir_order_item_requirements` — queryable, flexible, and using the same system as all other product requirements. This is simpler than the old two-step flow (purchase, then separately fill in extra info).

### Recording Consent

`evr_recording_consent` is also removed from EventRegistrant. The `RecordConsentRequirement` type continues to collect consent at checkout, but instead of writing to a denormalized field on the registrant, the consent answer is stored in `oir_order_item_requirements` via `save_cart_data()` — which already happens for all requirement data. `RecordConsentRequirement::post_purchase()` becomes a no-op.

To query whether a registrant consented to recording, join through the order item:

```sql
SELECT oir.oir_answer
FROM oir_order_item_requirements oir
JOIN odi_order_items odi ON odi.odi_order_item_id = oir.oir_odi_order_item_id
WHERE odi.odi_evr_event_registrant_id = [registrant_id]
AND oir.oir_label = 'Consent to Record'
```

If this query pattern is needed frequently, a helper method on EventRegistrant or OrderItem can encapsulate it.

### Impact on Email Templates

The `event_reciept_content` email template may reference `more_info_required`. After removal, this variable will no longer be set. The template should be updated to remove any conditional block that displays "additional information needed" messaging.

---

## Removed Code

All of the following are deleted with no replacement or backward-compatible shim:

| What | Where | Lines |
|------|-------|-------|
| `BasicProductRequirement` abstract class | `products_class.php` | 25-56 |
| `FullNameRequirement` class | `products_class.php` | 58-103 |
| `PhoneNumberRequirement` class | `products_class.php` | 105-147 |
| `DOBRequirement` class | `products_class.php` | 149-231 |
| `AddressRequirement` class | `products_class.php` | 233-358 |
| `GDPRNoticeRequirement` class | `products_class.php` | 360-397 |
| `RecordConsentRequirement` class | `products_class.php` | 399-434 |
| `EmailRequirement` class | `products_class.php` | 436-479 |
| `UserPriceRequirement` class | `products_class.php` | 481-532 |
| `NewsletterSignupRequirement` class | `products_class.php` | 534-572 |
| `CommentRequirement` class | `products_class.php` | 574-607 |
| `BasicProductRequirementException` class | `products_class.php` | ~608 |
| `$REQUIREMENT_IDS` static array | `products_class.php` | 30-41 |
| `pro_requirements` field spec | `products_class.php` | 654 |
| `pro_requirements` column | `pro_products` table | — |
| Bitmask checkbox UI | `admin_product_edit.php` | 134-159 |
| Bitmask summation logic | `admin_product_edit_logic.php` | 46-51 |
| Scattered field-name checks in checkout | `cart_charge_logic.php` | 317-318, 460-462, 493-495 |
| `more_info_required` email flag logic | `cart_charge_logic.php` | 465-469 |
| `evt_collect_extra_info` field spec | `events_class.php` | 105 |
| `evr_recording_consent` field spec | `event_registrants_class.php` | 65 |
| `evr_first_event` field spec | `event_registrants_class.php` | 66 |
| `evr_other_events` field spec | `event_registrants_class.php` | 67 |
| `evr_health_notes` field spec | `event_registrants_class.php` | 68 |
| `evr_extra_info_completed` field spec | `event_registrants_class.php` | 69 |
| `evt_collect_extra_info` hidden input | `admin_event_edit.php` | 247 |
| `evt_collect_extra_info` in editable_fields | `admin_event_edit_logic.php` | 137 |
| "Collect Extra Info" display row | `admin_event.php` | 228-229 |
| Commented-out extra info display block | `admin_event.php` | 514-543 |
| Commented-out extra info reminder | `profile_logic.php` | 98-103 |
| Commented-out extra info reminder | `views/profile/profile.php` | 27-41 |
| Commented-out extra info reminder | `views/profile/event_sessions.php` | 556-571 |
| Commented-out extra info reminder | `plugins/controld/views/profile/profile.php` | 29-43 |
| Entire file | `logic/event_register_finish_logic.php` | all |
| Entire file | `views/profile/event_register_finish.php` | all |

**Note:** `BasicProductRequirementException` should be preserved (or renamed to `ProductRequirementException`) since it's used in the validation flow and caught by `product_logic.php:88`.

---

## Files Modified

| File | Change |
|------|--------|
| `data/products_class.php` | Remove ~580 lines of requirement classes and `$REQUIREMENT_IDS`; remove `pro_requirements` from `$field_specifications`; rewrite `get_product_requirements()`, `validate_form()`, `output_product_form()`, `output_javascript()` to use RequirementRegistry; remove `get_requirement_info()` bitmask path |
| `logic/product_logic.php` | Update exception class name if renamed |
| `logic/cart_charge_logic.php` | Replace scattered field-name checks with single `post_purchase()` loop; remove `more_info_required` email flag logic |
| `data/product_requirements_class.php` | Add `prq_rqt_requirement_type_id` and `prq_config` to `$field_specifications` |
| `data/product_requirement_instances_class.php` | Add `pri_order` and `pri_config` to `$field_specifications` |
| `data/events_class.php` | Remove `evt_collect_extra_info` from `$field_specifications` |
| `data/event_registrants_class.php` | Remove `evr_recording_consent`, `evr_first_event`, `evr_other_events`, `evr_health_notes`, `evr_extra_info_completed` from `$field_specifications` |
| `adm/admin_product_edit.php` | Remove bitmask checkbox section; merge both checkbox sections into unified requirement checkbox list |
| `adm/logic/admin_product_edit_logic.php` | Remove bitmask summation; replace with instance-based saving via `save_requirement_instances()` |
| `adm/admin_product_requirement_edit.php` | Add requirement type dropdown, config editing |
| `adm/admin_product_requirements.php` | Add type column, type filter |
| `adm/admin_event_edit.php` | Remove `evt_collect_extra_info` hidden input |
| `adm/logic/admin_event_edit_logic.php` | Remove `evt_collect_extra_info` from `$editable_fields` |
| `adm/admin_event.php` | Remove "Collect Extra Info" display row; remove commented-out extra info display block |
| `logic/profile_logic.php` | Remove commented-out extra info reminder |
| `views/profile/profile.php` | Remove commented-out extra info reminder |
| `views/profile/event_sessions.php` | Remove commented-out extra info reminder |
| `plugins/controld/views/profile/profile.php` | Remove commented-out extra info reminder |

## Files Created

| File | Purpose |
|------|---------|
| `data/requirement_types_class.php` | RequirementType + MultiRequirementType models |
| `includes/requirements/ProductRequirementInterface.php` | Interface definition |
| `includes/requirements/AbstractProductRequirement.php` | Base class with default implementations |
| `includes/requirements/RequirementRegistry.php` | Factory/registry class |
| `includes/requirements/FullNameRequirement.php` | Extracted from products_class.php |
| `includes/requirements/PhoneNumberRequirement.php` | Extracted from products_class.php |
| `includes/requirements/DOBRequirement.php` | Extracted from products_class.php |
| `includes/requirements/AddressRequirement.php` | Extracted from products_class.php |
| `includes/requirements/GDPRNoticeRequirement.php` | Extracted from products_class.php |
| `includes/requirements/RecordConsentRequirement.php` | Extracted from products_class.php |
| `includes/requirements/EmailRequirement.php` | Extracted from products_class.php |
| `includes/requirements/UserPriceRequirement.php` | Extracted from products_class.php |
| `includes/requirements/NewsletterSignupRequirement.php` | Extracted from products_class.php |
| `includes/requirements/CommentRequirement.php` | Extracted from products_class.php |
| `includes/requirements/QuestionRequirement.php` | Wraps Question model for dynamic questions |
| `migrations/migrate_product_requirements.php` | One-time data migration script |

## Files Deleted

| File | Reason |
|------|--------|
| `logic/event_register_finish_logic.php` | Event extra info form — replaced by product requirements at checkout |
| `views/profile/event_register_finish.php` | Event extra info view — replaced by product requirements at checkout |

---

## Testing

1. **Unit:** Each requirement class produces correct form HTML, validation results, and display data
2. **Integration:** Full checkout flow with products using various requirement combinations — purchase completes, order items created, requirement data stored in `oir_order_item_requirements`
3. **Migration:** Run migration against production data dump; verify every product's requirement set matches what the old bitmask + question systems would have produced
4. **Post-purchase hooks:** Verify recording consent is set on event registrants, comments stored on order items, newsletter subscriptions triggered
5. **Admin:** Create/edit products with requirements via the new unified UI; verify instances created correctly
6. **Plugin types:** Register a custom requirement type, attach to a product, complete checkout — verify the full lifecycle works
7. **Event extra info removal:** Verify event admin pages render without errors after field removal; verify event receipt emails send without errors (no `more_info_required` reference); verify `/profile/event_register_finish` returns 404; verify event registration checkout still works with QuestionRequirement-based alternatives
