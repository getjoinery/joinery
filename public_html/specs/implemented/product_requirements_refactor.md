# Product Requirements Refactor Specification — Option D (Convention-Based)

**Status:** Proposed
**Created:** 2026-03-01
**Revised:** 2026-03-05
**Priority:** Medium

## Overview

Replace the hardcoded bitmask-based product requirements system (10 classes in `products_class.php`) with a convention-based, auto-discovered architecture. Drop a requirement class file into a known directory and it just works — no database registration, no migrations, no admin setup to "register" a type.

The current system has three parallel mechanisms for collecting customer information during checkout and registration:
1. A rigid bitmask system (10 hardcoded classes in `products_class.php`)
2. A flexible database-driven question system (`prq_product_requirements` → `pri_product_requirement_instances`)
3. A half-implemented event extra info system (`evt_collect_extra_info`, post-registration form)

This refactor merges the first two into a single extensible system and removes the third entirely.

No backward compatibility is maintained. The bitmask system, event extra info system, and all related code are removed entirely. A one-time data migration converts existing product configurations to the new system.

## Problem Statement

### Current Architecture: Three Parallel Systems

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

### Why Refactor

- The bitmask system cannot be extended without code changes to `products_class.php`
- Adding a new requirement type requires modifying core files, not just adding a plugin file
- Two parallel validation paths (`validate_form()` for bitmask, separate logic for questions) create maintenance burden
- Event extra info is dead code that should be removed
- The 3-table chain approach (rqt → prq → pri) attempted previously was over-engineered for this use case

---

## Design: Convention-Based Auto-Discovery (Option D)

### Core Principles

1. **Use existing systems first** — the Question system already handles most data collection and confirmations; use it before writing code
2. **Custom classes as last resort** — only for complex UI, pricing logic, or side effects that Questions can't express
3. **`pri` table links to class names directly** — no rqt or prq intermediary tables needed
4. **Auto-discovery** — system scans known directories for requirement classes implementing the interface
5. **Single config level** — `pri_config` JSON on the instance row handles all per-product configuration

### Requirement Tiers

When adding a requirement, use the **simplest tier** that meets the need:

#### Tier 1: QuestionRequirement (zero code)

Use the existing Question system. Create a Question in admin (or via migration), then attach it to products via `QuestionRequirement`. The Question system is extended with one new type (`confirmation`) to handle acknowledgment/agreement patterns alongside standard data collection.

```
pri_class_name = 'QuestionRequirement'
pri_config = '{"question_id": 42}'
```

**Supported question types:**
- `short_text` — single-line text input
- `long_text` — multi-line textarea
- `dropdown` — select from options
- `radio` — radio button group
- `checkbox` — single checkbox
- `checkbox_list` — multiple checkboxes
- `confirmation` — **NEW** — display body text + required checkbox (for waivers, notices, consent)

**Use when:** You need to collect information or require an acknowledgment. No custom rendering, no side effects, no pricing changes.

**New `confirmation` question type:**

A confirmation is a Question with `qst_type = 'confirmation'` and config in `qst_config`:

```
qst_question = 'GDPR Notice'
qst_type = 'confirmation'
qst_config = '{"body_text": "Our privacy policy...", "checkbox_label": "I agree to the privacy policy", "scrollable": true}'
```

`QuestionRequirement` handles it — when it sees `qst_type = 'confirmation'`, it renders the optional body text (with optional scrollable container), then a required checkbox. The answer is stored in `oir_order_item_requirements` like all other questions.

**Confirmation config options (stored in `qst_config`):**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `body_text` | string | `null` | Text/HTML shown above the checkbox (waiver text, privacy notice, etc.) |
| `checkbox_label` | string | `qst_question` | Label next to the checkbox itself |
| `scrollable` | bool | `false` | Wrap `body_text` in a scrollable container (for long legal text) |

**Current bitmask requirements replaced by this tier:**

| Old Class | Question Type | Notes |
|-----------|--------------|-------|
| `CommentRequirement` (512) | `long_text` | Free-text comment, stored in `oir_order_item_requirements` instead of `odi_comment` |
| `GDPRNoticeRequirement` (16) | `confirmation` | `qst_config = {"body_text": "[privacy notice]", "checkbox_label": "I agree to the privacy policy", "scrollable": true}` |
| `RecordConsentRequirement` (32) | `confirmation` | `qst_config = {"checkbox_label": "I consent to being recorded"}` |

**Plugin/developer examples:**
- "Emergency contact name" (short text), "T-shirt size" (dropdown), "Dietary restrictions" (checkbox list)
- "Liability Waiver" (confirmation with scrollable body text)
- "Code of Conduct" (confirmation with checkbox)

#### Tier 2: Custom Class (code required)

Write a class implementing `ProductRequirementInterface` only when Tier 1 can't handle the requirement. Reasons to use Tier 2:
- **Complex multi-field UI** (address picker, date-of-birth dropdowns)
- **Pricing modification** (user-chosen price)
- **Custom model interaction** (PhoneNumber model, Address model)
- **Post-purchase side effects** (newsletter subscription, external API calls)
- **User lookup/creation logic** (email, name fields used by checkout)

**Current bitmask requirements that remain as custom classes:**

| Class | Reason |
|-------|--------|
| `FullNameRequirement` | Two coordinated fields (first + last) + used by cart_charge_logic for user creation |
| `EmailRequirement` | Used by cart_charge_logic for user lookup/creation |
| `PhoneNumberRequirement` | Uses PhoneNumber model for formatting/validation |
| `DOBRequirement` | Custom 3-dropdown UI (month/day/year) with JavaScript |
| `AddressRequirement` | Multi-field form + existing address picker + Address model |
| `UserPriceRequirement` | `affects_pricing() = true` — modifies the item price |
| `NewsletterSignupRequirement` | post_purchase side effect (newsletter subscription API call) |

### Summary: Migration of the 10 Bitmask Requirements

| # | Old Class | New Tier | New Implementation |
|---|-----------|----------|--------------------|
| 1 | FullNameRequirement | Tier 2 | Custom class in `includes/requirements/` |
| 2 | PhoneNumberRequirement | Tier 2 | Custom class in `includes/requirements/` |
| 4 | DOBRequirement | Tier 2 | Custom class in `includes/requirements/` |
| 8 | AddressRequirement | Tier 2 | Custom class in `includes/requirements/` |
| 16 | GDPRNoticeRequirement | Tier 1 | Question with `qst_type = 'confirmation'` |
| 32 | RecordConsentRequirement | Tier 1 | Question with `qst_type = 'confirmation'` |
| 64 | EmailRequirement | Tier 2 | Custom class in `includes/requirements/` |
| 128 | UserPriceRequirement | Tier 2 | Custom class in `includes/requirements/` |
| 256 | NewsletterSignupRequirement | Tier 2 | Custom class in `includes/requirements/` |
| 512 | CommentRequirement | Tier 1 | Question with `qst_type = 'long_text'` |

### Schema Changes

#### Modified: `qst_questions`

| Column | Type | Description |
|--------|------|-------------|
| `qst_config` | jsonb | **NEW** — Type-specific configuration (e.g., `{"body_text": "...", "scrollable": true}` for confirmations) |

New `qst_type` value: `'confirmation'`

### Schema Changes

#### Modified: `pri_product_requirement_instances`

| Column | Type | Description |
|--------|------|-------------|
| `pri_id` | serial PK | (existing) |
| `pri_pro_product_id` | int FK → products | (existing) Product this requirement belongs to |
| `pri_class_name` | varchar(255) | **NEW** — PHP class name, e.g. `"FullNameRequirement"`. Replaces `pri_prq_product_requirement_id` |
| `pri_config` | jsonb | **NEW** — Instance-level config. e.g. `{"question_id": 123}` for QuestionRequirement |
| `pri_order` | int | (existing) Display/validation order |
| `pri_delete_time` | timestamp | (existing) Soft delete |
| `pri_created_time` | timestamp | (existing) |

**Dropped columns:**
- `pri_prq_product_requirement_id` — replaced by `pri_class_name`

#### Tables No Longer Needed

- `rqt_requirement_types` — class metadata lives in the class itself (constants/methods)
- `prq_product_requirements` — no intermediary between products and requirement classes

These tables can remain in the database but will not be used. No migration to drop them is needed.

#### Removed: `pro_requirements` Column

The bitmask column `pro_requirements` on `pro_products` is no longer used. Existing bitmask values are migrated to `pri` rows during the one-time migration.

#### Unchanged: `oir_order_item_requirements` Table

No changes needed — already stores label/answer pairs generically.

---

## Auto-Discovery: RequirementRegistry

A new `RequirementRegistry` class scans known directories for requirement classes.

**Scan locations (in order):**
1. `includes/requirements/*.php` — core/system requirement classes
2. `plugins/*/requirements/*.php` — plugin-provided requirement classes

**Discovery rules:**
- File must contain a class implementing `ProductRequirementInterface`
- Class self-describes via constants (`LABEL`) and interface methods
- No database registration needed — if the file exists and implements the interface, it's available

```php
// RequirementRegistry usage
$registry = RequirementRegistry::getInstance();
$all_types = $registry->getAll();           // All discovered requirement classes
$class = $registry->get('FullNameRequirement'); // Get specific class info
$instance = $registry->createInstance('FullNameRequirement', $config); // Instantiate with config
```

### `getProductRequirements()` Implementation

1. Load all `pri_product_requirement_instances` for the product (non-deleted), ordered by `pri_order`
2. For each instance, look up the class in the registry by `pri_class_name`
3. Instantiate the class with `pri_config` decoded from JSON
4. Return ordered array of `ProductRequirementInterface` instances

No 3-table join. No intermediary lookups. Just `pri → class`.

---

## Requirement Interface

```php
interface ProductRequirementInterface {
    const LABEL = 'Human-readable label';

    // Render form fields for this requirement
    public function render_fields(FormWriterHTML5 $formwriter, $product, $existing_data = []);

    // Validate submitted data; return array of errors (empty = valid)
    public function validate($post_data, $product);

    // Process/save submitted data after successful validation
    public function process($post_data, $product, $order_detail, $user);

    // Return data for admin/report display
    public function get_display_data($order_detail, $user);

    // Does this requirement affect pricing?
    public function affects_pricing(): bool;

    // Get the modified price (only called if affects_pricing() returns true)
    public function get_modified_price($post_data, $product, $base_price);

    // Return client-side validation rules for JoineryValidation
    public function get_validation_info();

    // Return any custom JavaScript needed by the form fields
    public function get_javascript(): string;

    // Post-purchase hook — called after successful payment
    public function post_purchase($data, $order_item, $user, $order);
}
```

### Abstract Base Class

```php
abstract class AbstractProductRequirement implements ProductRequirementInterface {
    protected $config;  // From pri_config JSON

    public function __construct(array $config = []) {
        $this->config = $config;
    }

    // Default implementations
    public function affects_pricing(): bool { return false; }
    public function get_modified_price($post_data, $product, $base_price) { return $base_price; }
    public function get_display_data($order_detail, $user) { return []; }
    public function get_validation_info() { return null; }
    public function get_javascript(): string { return ''; }
    public function post_purchase($data, $order_item, $user, $order) {}
}
```

---

## Built-In Requirement Classes

Located in `includes/requirements/`:

### Config-Driven (Tier 1 — no additional code needed)

| Class | Config | Notes |
|-------|--------|-------|
| `QuestionRequirement` | `{"question_id": N}` | Wraps existing Question system — supports all question types including the new `confirmation` type |

### Custom Classes (Tier 2 — code required for complex behavior)

| Class | Label | Notes |
|-------|-------|-------|
| `FullNameRequirement` | Name | Two coordinated fields (first + last); used by checkout for user creation |
| `EmailRequirement` | Email | Used by checkout for user lookup/creation |
| `PhoneNumberRequirement` | Phone Number | Uses PhoneNumber model for formatting/validation |
| `DOBRequirement` | Date of Birth | Custom 3-dropdown UI (month/day/year) with JavaScript |
| `AddressRequirement` | Address | Multi-field form + existing address picker + Address model |
| `UserPriceRequirement` | User Chooses Price | `affects_pricing() = true` — modifies the item price |
| `NewsletterSignupRequirement` | Newsletter Signup | post_purchase side effect: newsletter subscription |

### QuestionRequirement: Details

`QuestionRequirement` bridges the existing Question system (`qst_questions` table) into the new requirement architecture. Each question becomes a separate `pri` row:

```
pri_class_name = 'QuestionRequirement'
pri_config = '{"question_id": 42}'
```

In the admin UI, questions are shown individually (not as a single "QuestionRequirement" checkbox). Each question's checkbox creates/removes a `pri` row with the appropriate `question_id` in config.

`QuestionRequirement` delegates rendering and validation to the Question model. For standard types (short_text, long_text, dropdown, etc.), this works exactly as the existing question system. For the new `confirmation` type, `QuestionRequirement` renders the body text from `qst_config` followed by a required checkbox — all handled within the Question model's `output_question()` method.

The Question model gains:
- A new `qst_type` value: `'confirmation'`
- A new `qst_config` jsonb column for type-specific configuration
- A rendering branch in `output_question()` for the confirmation type

---

## File Structure

```
includes/
  requirements/
    ProductRequirementInterface.php     -- Interface definition
    AbstractProductRequirement.php      -- Base class with default implementations
    RequirementRegistry.php             -- Auto-discovery registry/factory
    QuestionRequirement.php             -- Tier 1: wraps Question model (config-driven)
    FullNameRequirement.php             -- Tier 2: custom class
    EmailRequirement.php                -- Tier 2: custom class
    PhoneNumberRequirement.php          -- Tier 2: custom class
    DOBRequirement.php                  -- Tier 2: custom class
    AddressRequirement.php              -- Tier 2: custom class
    UserPriceRequirement.php            -- Tier 2: custom class
    NewsletterSignupRequirement.php     -- Tier 2: custom class

plugins/
  {plugin-name}/
    requirements/
      CustomRequirement.php             -- Plugin-provided requirements (Tier 2)
```

Note: `GDPRNoticeRequirement`, `RecordConsentRequirement`, and `CommentRequirement` do **not** have class files. They are Questions — the first two with `qst_type = 'confirmation'`, the third with `qst_type = 'long_text'`. All managed via the Question admin UI and attached to products as `QuestionRequirement` instances.

---

## Admin UI: Product Edit Page

The requirements section becomes a single unified checkbox list, organized by tier:

```
Requirements:
  ── System ──
  [x] Name
  [x] Email
  [ ] Phone Number
  [ ] Date of Birth
  [ ] Address
  [ ] User Chooses Price
  [ ] Newsletter Signup
  ── Questions & Confirmations ──
  [x] GDPR Notice               ← confirmation question
  [ ] Consent to Record          ← confirmation question
  [x] What is your t-shirt size?
  [x] Do you have dietary restrictions?
  [ ] Emergency contact name
  [ ] Comment                    ← migrated from old CommentRequirement
```

**Behavior:**
- Checking a box creates a `pri` row with `pri_class_name` and the appropriate `pri_config`
- Unchecking soft-deletes the `pri` row
- Drag handles allow reordering (updates `pri_order`)
- Grouped by type: system classes (Tier 2) first, then questions/confirmations (Tier 1)
- All questions (including confirmation-type questions) are managed through the existing Question admin; they appear here automatically
- Confirmation questions are visually indistinct from regular questions in this list — they're all just questions with different types

### Admin Pages No Longer Needed

- `admin_product_requirements.php` — managed the `prq` table, no longer needed
- `admin_product_requirement_edit.php` — managed individual `prq` records, no longer needed

These can be removed or repurposed.

---

## Checkout Flow Integration

The checkout/registration flow calls requirements in `pri_order`:

```php
// In product view / cart form
$registry = RequirementRegistry::getInstance();
$instances = new MultiProductRequirementInstance(
    ['product_id' => $product_id, 'deleted' => false],
    ['pri_order' => 'ASC']
);
$instances->load();

foreach ($instances as $pri) {
    $requirement = $registry->createInstance(
        $pri->get('pri_class_name'),
        json_decode($pri->get('pri_config'), true) ?: []
    );
    $requirement->render_fields($formwriter, $product, $existing_data);
}

// In validation
$errors = [];
foreach ($instances as $pri) {
    $requirement = $registry->createInstance($pri->get('pri_class_name'), ...);
    $errors = array_merge($errors, $requirement->validate($_POST, $product));
}

// In processing (after payment success)
foreach ($instances as $pri) {
    $requirement = $registry->createInstance($pri->get('pri_class_name'), ...);
    $requirement->process($_POST, $product, $order_detail, $user);
}
```

---

## post_purchase() Hook — Side Effect Migration

The current `cart_charge_logic.php` contains scattered field-name checks that trigger side effects. Each moves into the relevant requirement class's `post_purchase()` method.

| Current Code (cart_charge_logic.php) | Moves To |
|------|----------|
| `if(isset($data['record_terms'])){ ... }` (lines 474-477, 507-509) | Removed — consent stored in `oir_order_item_requirements` via `save_cart_data()` (now a confirmation Question) |
| `if(isset($data['comment'])){ ... }` (lines 331-333) | Removed — comment stored in `oir_order_item_requirements` via `save_cart_data()` (now a long_text Question) |
| User creation from email/name (lines 296-305) | Keep in cart_charge_logic — user creation is a checkout concern |
| Receipt name assembly (line 551) | Keep in cart_charge_logic — receipt assembly is a checkout concern |

### post_purchase() Calling Convention

In `cart_charge_logic.php`, after creating the order item and saving cart data:

```php
foreach (RequirementRegistry::getProductRequirements($product->key) as $requirement) {
    $requirement->post_purchase($data, $order_item, $user, $order);
}
```

---

## Data Migration

A one-time migration converts existing data:

### Step 1: Create Questions for Tier 1 requirements

Before processing products, create the Question records that will replace bitmask requirements. These are created once globally:

```php
// Comment → long_text Question
$comment_q = new Question(NULL);
$comment_q->set('qst_question', 'Comment');
$comment_q->set('qst_type', 'long_text');
$comment_q->set('qst_is_required', false);
$comment_q->save();

// GDPR Notice → confirmation Question
$gdpr_q = new Question(NULL);
$gdpr_q->set('qst_question', 'GDPR Notice');
$gdpr_q->set('qst_type', 'confirmation');
$gdpr_q->set('qst_config', json_encode([
    'body_text' => '[site privacy notice text]',
    'checkbox_label' => 'I agree to the privacy policy',
    'scrollable' => true
]));
$gdpr_q->set('qst_is_required', true);
$gdpr_q->save();

// Recording Consent → confirmation Question
$consent_q = new Question(NULL);
$consent_q->set('qst_question', 'Consent to Record');
$consent_q->set('qst_type', 'confirmation');
$consent_q->set('qst_config', json_encode([
    'checkbox_label' => 'I consent to being recorded'
]));
$consent_q->set('qst_is_required', true);
$consent_q->save();
```

### Step 2: Bitmask → pri rows

For each product where `pro_requirements > 0`, decode the bitmask and create `pri` rows:

```php
$order = 0;
$bitmask = $product->get('pro_requirements');

// Tier 2: custom classes (direct mapping)
$custom_map = [
    1 => 'FullNameRequirement',
    2 => 'PhoneNumberRequirement',
    4 => 'DOBRequirement',
    8 => 'AddressRequirement',
    64 => 'EmailRequirement',
    128 => 'UserPriceRequirement',
    256 => 'NewsletterSignupRequirement',
];

// Tier 1: Questions (created in Step 1)
$question_map = [
    16 => $gdpr_q->key,
    32 => $consent_q->key,
    512 => $comment_q->key,
];

foreach ($custom_map as $bit => $class_name) {
    if ($bitmask & $bit) {
        $instance = new ProductRequirementInstance(NULL);
        $instance->set('pri_pro_product_id', $product->key);
        $instance->set('pri_class_name', $class_name);
        $instance->set('pri_order', $order++);
        $instance->save();
    }
}

foreach ($question_map as $bit => $question_id) {
    if ($bitmask & $bit) {
        $instance = new ProductRequirementInstance(NULL);
        $instance->set('pri_pro_product_id', $product->key);
        $instance->set('pri_class_name', 'QuestionRequirement');
        $instance->set('pri_config', json_encode(['question_id' => $question_id]));
        $instance->set('pri_order', $order++);
        $instance->save();
    }
}
```

### Step 2: Existing prq/pri → new pri rows

For each existing `pri` row that references a `prq` row linked to a question, create a new `pri` row:

```php
$instance->set('pri_class_name', 'QuestionRequirement');
$instance->set('pri_config', json_encode(['question_id' => $prq->get('prq_qst_question_id')]));
```

### Step 3: Clean up

Remove `pro_requirements` from `$field_specifications` in `products_class.php` (column can remain in DB).

---

## Plugin / Developer Workflow

When adding a new requirement, start at Tier 1 and only move down if Questions can't do what you need.

### Tier 1: Use a Question (zero code)

Handles the vast majority of use cases. Create a Question via admin UI or migration.

**Collecting information:**
```php
$question = new Question(NULL);
$question->set('qst_question', 'Emergency contact phone number');
$question->set('qst_type', 'short_text');
$question->set('qst_is_required', true);
$question->save();
```

**Requiring an acknowledgment/agreement:**
```php
$question = new Question(NULL);
$question->set('qst_question', 'Liability Waiver');
$question->set('qst_type', 'confirmation');
$question->set('qst_config', json_encode([
    'body_text' => 'By participating you agree to assume all risk...',
    'checkbox_label' => 'I agree to the liability waiver',
    'scrollable' => false
]));
$question->set('qst_is_required', true);
$question->save();
```

Then an admin checks the box on a product. Done.

**Handles:** text fields, text areas, dropdowns, radio buttons, checkboxes, checkbox lists, waivers, terms acceptance, privacy notices, consent checkboxes — anything that collects data or requires acknowledgment.

### Tier 2: Write a custom class (last resort)

Only needed for: complex multi-field UI, pricing modification, custom model interaction, post-purchase side effects beyond data storage.

```php
// plugins/my-plugin/requirements/SkillLevelRequirement.php

require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));

class SkillLevelRequirement extends AbstractProductRequirement {
    const LABEL = 'Skill Level Assessment';

    public function render_fields(FormWriterHTML5 $formwriter, $product, $existing_data = []) {
        // Custom multi-step UI: experience slider + video upload + instructor approval
        // This kind of complex interaction can't be expressed as a Question
    }

    public function validate($post_data, $product) {
        if (empty($post_data['skill_level'])) {
            return ['Please complete the skill level assessment.'];
        }
        return [];
    }

    public function process($post_data, $product, $order_detail, $user) {
        // Store skill level, notify instructor, etc.
    }

    public function post_purchase($data, $order_item, $user, $order) {
        // Add user to appropriate skill-level group after purchase
    }
}
```

Drop the file in `plugins/my-plugin/requirements/` — auto-discovered, appears in admin UI automatically.

---

## Event Extra Info Removal

The event extra info system is removed entirely. Any information previously collected through it should instead be collected as product requirements using `QuestionRequirement` instances.

### What's Removed

**Database fields removed from `evt_events`:**
- `evt_collect_extra_info` — the toggle flag

**Database fields removed from `evr_event_registrants`:**
- `evr_recording_consent`
- `evr_first_event`
- `evr_other_events`
- `evr_health_notes`
- `evr_extra_info_completed`

**Note:** `evr_recording_consent` is also **removed** — see Recording Consent below.

### Files Affected

| File | Change |
|------|--------|
| `data/events_class.php` | Remove `evt_collect_extra_info` from `$field_specifications` |
| `data/event_registrants_class.php` | Remove `evr_recording_consent`, `evr_first_event`, `evr_other_events`, `evr_health_notes`, `evr_extra_info_completed` from `$field_specifications` |
| `logic/cart_charge_logic.php` | Remove `more_info_required` flag logic (lines 480-483) |
| `adm/admin_event_edit.php` | Remove `evt_collect_extra_info` hidden input (line 252) |
| `adm/logic/admin_event_edit_logic.php` | Remove `evt_collect_extra_info` from `$editable_fields` (line 192) |
| `adm/admin_event.php` | Remove "Collect Extra Info" display row (lines 228-229); remove commented-out extra info display block (lines 510-539) |
| `logic/profile_logic.php` | Remove commented-out extra info reminder (lines 98-103) |
| `views/profile/profile.php` | Remove commented-out extra info reminder (lines 27-41) |
| `views/profile/event_sessions.php` | Remove commented-out extra info reminder (lines 559-571) |
| `plugins/controld/views/profile/profile.php` | Remove commented-out extra info reminder (lines 29-43) |

### Files Deleted

| File | Reason |
|------|--------|
| `logic/event_register_finish_logic.php` | Entire file exists only for event extra info form submission |
| `views/profile/event_register_finish.php` | Entire file exists only for event extra info form |

### Recording Consent

`evr_recording_consent` is removed from EventRegistrant. The recording consent confirmation Question continues to collect consent at checkout, but the answer is stored in `oir_order_item_requirements` via `save_cart_data()`. To query consent:

```sql
SELECT oir.oir_answer
FROM oir_order_item_requirements oir
JOIN odi_order_items odi ON odi.odi_order_item_id = oir.oir_odi_order_item_id
WHERE odi.odi_evr_event_registrant_id = [registrant_id]
AND oir.oir_label = 'Consent to Record'
```

### Impact on Email Templates

The `event_reciept_content` email template may reference `more_info_required`. After removal, this variable will no longer be set. The template should be updated to remove any conditional block that displays "additional information needed" messaging.

---

## Removed Code

All of the following are deleted with no replacement:

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
| `BasicProductRequirementException` class | `products_class.php` | 23 |
| `$REQUIREMENT_IDS` static array | `products_class.php` | 28-39 |
| `pro_requirements` field spec | `products_class.php` | 654 |
| `pro_requirements` column | `pro_products` table | — |
| Bitmask checkbox UI | `admin_product_edit.php` | 134-159 |
| Bitmask summation logic | `admin_product_edit_logic.php` | 46-52 |
| Scattered field-name checks in checkout | `cart_charge_logic.php` | 331-333, 474-477, 507-509 |
| `more_info_required` email flag logic | `cart_charge_logic.php` | 480-483 |
| `evt_collect_extra_info` field spec | `events_class.php` | 105 |
| `evr_recording_consent` field spec | `event_registrants_class.php` | 65 |
| `evr_first_event` field spec | `event_registrants_class.php` | 66 |
| `evr_other_events` field spec | `event_registrants_class.php` | 68 |
| `evr_health_notes` field spec | `event_registrants_class.php` | 69 |
| `evr_extra_info_completed` field spec | `event_registrants_class.php` | 70 |
| `evt_collect_extra_info` hidden input | `admin_event_edit.php` | 252 |
| `evt_collect_extra_info` in editable_fields | `admin_event_edit_logic.php` | 192 |
| "Collect Extra Info" display row | `admin_event.php` | 228-229 |
| Commented-out extra info display block | `admin_event.php` | 510-539 |
| Commented-out extra info reminder | `profile_logic.php` | 98-103 |
| Commented-out extra info reminder | `views/profile/profile.php` | 27-41 |
| Commented-out extra info reminder | `views/profile/event_sessions.php` | 559-571 |
| Commented-out extra info reminder | `plugins/controld/views/profile/profile.php` | 29-43 |
| Entire file | `logic/event_register_finish_logic.php` | all |
| Entire file | `views/profile/event_register_finish.php` | all |

**Note:** `BasicProductRequirementException` should be preserved (or renamed to `ProductRequirementException`) since it's used in the validation flow and caught by `product_logic.php:87`.

---

## Implementation Order

### Phase 1: Core Infrastructure
1. Create `ProductRequirementInterface` and `AbstractProductRequirement` in `includes/requirements/`
2. Create `RequirementRegistry` with auto-discovery logic
3. Modify `pri_product_requirement_instances` schema (add `pri_class_name`, `pri_config`; drop `pri_prq_product_requirement_id`)

### Phase 2: Question System Extension
4. Add `qst_config` jsonb column to `qst_questions` (via `$field_specifications`)
5. Add `confirmation` type support to Question model's `output_question()` method
6. Create `QuestionRequirement` — wraps Question rendering/validation for the requirement system

### Phase 3: Custom Classes (Tier 2)
7. Extract 7 bitmask requirement classes from `products_class.php` into `includes/requirements/` as individual files (FullName, Email, Phone, DOB, Address, UserPrice, NewsletterSignup)
8. Each class must produce identical HTML output to the current bitmask rendering

### Phase 4: Checkout Integration
9. Replace `Product::validate_form()` bitmask logic with registry-based loop
10. Replace requirement rendering in product views (`theme/canvas/views/product.php`, `theme/tailwind/views/product.php`)
11. Replace requirement processing in `cart_charge_logic.php`
12. Replace requirement processing in `profile_logic.php`

### Phase 5: Admin UI
13. Replace bitmask checkbox UI in `admin_product_edit.php` with unified checkbox list (grouped: System / Questions)
14. Handle ordering via drag or simple up/down

### Phase 6: Migration & Cleanup
15. Write data migration (create Questions for Tier 1, bitmask → pri rows, old prq/pri → new pri rows)
16. Remove bitmask code from `products_class.php`
17. Remove event extra info code and views
18. Remove `admin_product_requirements.php` and `admin_product_requirement_edit.php` (no longer needed)

---

## File Changes Summary

### New Files
- `includes/requirements/ProductRequirementInterface.php` — interface definition
- `includes/requirements/AbstractProductRequirement.php` — base class
- `includes/requirements/RequirementRegistry.php` — auto-discovery registry/factory
- `includes/requirements/QuestionRequirement.php` — Tier 1: wraps Question model
- `includes/requirements/FullNameRequirement.php` — Tier 2: custom class
- `includes/requirements/EmailRequirement.php` — Tier 2: custom class
- `includes/requirements/PhoneNumberRequirement.php` — Tier 2: custom class
- `includes/requirements/DOBRequirement.php` — Tier 2: custom class
- `includes/requirements/AddressRequirement.php` — Tier 2: custom class
- `includes/requirements/UserPriceRequirement.php` — Tier 2: custom class
- `includes/requirements/NewsletterSignupRequirement.php` — Tier 2: custom class
- `migrations/migrate_product_requirements.php` — one-time data migration

**Not created (handled as Questions):**
- ~~`GDPRNoticeRequirement.php`~~ → Question with `qst_type = 'confirmation'`
- ~~`RecordConsentRequirement.php`~~ → Question with `qst_type = 'confirmation'`
- ~~`CommentRequirement.php`~~ → Question with `qst_type = 'long_text'`

### Modified Files
- `data/questions_class.php` — add `qst_config` to `$field_specifications`; add `confirmation` type to `output_question()`
- `data/product_requirement_instances_class.php` — add `pri_class_name`, `pri_config`; drop FK to prq
- `data/products_class.php` — remove ~580 lines of bitmask requirement classes; rewrite `validate_form()`, `output_product_form()`, `output_javascript()` to use RequirementRegistry
- `logic/cart_charge_logic.php` — replace scattered field-name checks with `post_purchase()` loop
- `logic/profile_logic.php` — use registry-based requirement processing
- `theme/canvas/views/product.php` — use registry-based requirement rendering
- `theme/tailwind/views/product.php` — use registry-based requirement rendering
- `adm/admin_product_edit.php` — unified checkbox list UI
- `adm/logic/admin_product_edit_logic.php` — save/load pri rows instead of bitmask
- `migrations/migrations.php` — add migration entry

### Removed Files
- `logic/event_register_finish_logic.php`
- `views/profile/event_register_finish.php`
- `adm/admin_product_requirements.php` (no longer needed)
- `adm/admin_product_requirement_edit.php` (no longer needed)
- `adm/logic/admin_product_requirement_edit_logic.php` (no longer needed)

### Unchanged
- `data/product_requirements_class.php` — can remain but becomes unused
- Survey system — completely unaffected

---

## Testing

1. **Tier 1 — Questions:** Verify QuestionRequirement renders, validates, and stores data identically to the existing question system; verify migrated Comment works as a long-text Question
2. **Tier 1 — Confirmations:** Verify `confirmation` question type renders body text + checkbox correctly for various configs (scrollable, non-scrollable, with/without body text); verify migrated GDPR and RecordConsent work
3. **Tier 2 — Custom classes:** Each custom class produces correct form HTML, validation results, and display data matching the old bitmask rendering
4. **Integration:** Full checkout flow with products using requirements from both tiers — purchase completes, data stored in `oir_order_item_requirements`
5. **Migration:** Run migration against production data; verify every product's requirements match what the old bitmask + question systems would have produced
6. **Post-purchase hooks:** Verify newsletter subscriptions and other side effects trigger correctly
7. **Admin:** Create/edit products with the unified checkbox UI; verify ordering works; create confirmation questions via Question admin
8. **Plugin types:** Drop a custom requirement file in a plugin directory, verify it auto-discovers and works end-to-end
9. **Event extra info removal:** Verify admin pages render, receipt emails send, `/profile/event_register_finish` returns 404

---

## Implementation Notes

Notes from pre-implementation code review (2026-03-03):

### `Product::save_requirement_instances()` Rewrite

The existing `save_requirement_instances()` method at `products_class.php:732` currently only manages question-based instances. After the refactor, this method must handle ALL requirement types. The method's current diff-based pattern (delete removed, add new) is sound and can be adapted.

### `prq_is_default_checked` Ghost Field Cleanup

`admin_product_edit.php:178` references `$product_requirement->get('prq_is_default_checked')`, but this field does not exist in `product_requirements_class.php`'s `$field_specifications`. This is a pre-existing bug to clean up as part of the refactor.

### GDPR Requirement (ID=16) Visibility

The current admin checkbox list shows only 9 of the 10 bitmask types — `GDPRNoticeRequirement` (ID=16) is absent. After migration, GDPR will appear as an available requirement in the unified UI.

---

## Documentation Updates

### New Documentation

#### `docs/questions_and_surveys.md` (NEW)

No documentation currently exists for the Questions or Survey systems. Create a comprehensive guide covering:

**Questions System:**
- Overview: reusable data collection fields attached to products via the requirement system
- Question types: `short_text`, `long_text`, `dropdown`, `radio`, `checkbox`, `checkbox_list`, `confirmation` (new)
- The `confirmation` type: config via `qst_config`, rendering (body text + checkbox), use cases (waivers, consent, GDPR)
- `qst_questions` table schema and Question model usage
- How questions connect to products: `QuestionRequirement` → `pri_product_requirement_instances` → product
- How answers are stored: `oir_order_item_requirements` (label + answer pairs)
- Admin UI: creating/editing questions, attaching to products
- Developer guide: creating questions programmatically via migration

**Survey System:**
- Overview: grouped collections of questions for event feedback/assessment
- Current status: infrastructure complete (~80%), disabled on events
- Schema: `svy_surveys` → `srq_survey_questions` → `qst_questions` → `sva_survey_answers`
- Admin pages: survey list, detail, editor, answers, user answers
- User-facing: `survey_logic.php`, `survey_finish.php`
- Relationship to product requirements: surveys are independent — they use the same Question model but are not part of the checkout flow
- Note: survey connection to events is commented out in `admin_event_edit.php`; future work to enable

#### `docs/product_requirements.md` (NEW)

Developer guide for the product requirements system after the refactor:

- Overview: how requirements work (collect data at checkout, validate, store, process)
- Two tiers: Questions (Tier 1) vs Custom Classes (Tier 2)
- How to add a requirement via Question (zero code — admin or migration)
- How to add a confirmation/waiver via `confirmation` question type
- How to write a custom requirement class (interface, abstract base, methods)
- Plugin requirements: auto-discovery from `plugins/*/requirements/`
- `RequirementRegistry`: how discovery works, how to get/create instances
- `pri_product_requirement_instances` schema and how products link to requirements
- Data flow: rendering → validation → processing → post_purchase hooks
- Storage: `oir_order_item_requirements` for answers
- Admin UI: unified checkbox list on product edit page
- Reference: list of all built-in requirement classes and their purposes

### Existing Documentation to Update

#### `docs/product_purchase_hooks.md`

Add a section explaining the relationship between product purchase hooks (plugin-level, in `/plugins/{plugin}/hooks/product_purchase.php`) and the new requirement-level `post_purchase()` method. Key points:
- Product purchase hooks run once per product purchase — for plugin-level side effects
- Requirement `post_purchase()` runs per-requirement — for requirement-specific side effects (newsletter, etc.)
- Both are called from `cart_charge_logic.php` but at different scopes
- Migration table showing what moved from scattered cart_charge_logic checks to requirement `post_purchase()` methods

#### `docs/plugin_developer_guide.md`

Add a section on plugin requirements:
- How plugins can provide custom requirement classes via `plugins/{plugin}/requirements/`
- Auto-discovery: just drop a file implementing `ProductRequirementInterface`
- Example: creating a plugin requirement class
- When to use a custom class vs a Question

#### `CLAUDE.md`

No changes needed — CLAUDE.md does not reference the product requirements system, bitmask, or question system directly.

### Specs to Update

#### `specs/system_features.md`

Update section 4.4 "Product Requirements" to reflect the new architecture:
- Replace bitmask description with tiered system (Questions + Custom Classes)
- Mention `RequirementRegistry` auto-discovery
- Mention `confirmation` question type

#### `specs/event_extra_info_and_surveys.md`

This spec is largely superseded by the refactor. Update to reflect:
- **Extra Info system:** Removed entirely. Reference product_requirements_refactor.md for the replacement strategy (Questions/QuestionRequirement at checkout time instead of post-registration form)
- **Survey system:** Unchanged by this refactor. Move survey documentation to the new `docs/questions_and_surveys.md`. Keep this spec as a brief pointer or move to `specs/implemented/`

#### ~~`specs/implemented/model_form_helpers.md`~~

No changes — implemented specs are not modified.
