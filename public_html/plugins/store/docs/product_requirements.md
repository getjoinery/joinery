# Product Requirements

Product Requirements are fields collected from a buyer on the product page, before or during checkout. They are the correct tool when a product needs information from the purchaser — never write custom form fields or cart scripts for this.

See also: [Questions & Surveys](questions_surveys.md) — the underlying question system that `QuestionRequirement` and `SurveyRequirement` wrap.

## How it works

1. An admin attaches one or more requirement instances to a product via the product edit page.
2. On the public product page, `$product->get_product_requirements()` returns the ordered list of requirements. Each requirement renders its fields via `render_fields($formwriter, $product)`.
3. On form submission, `$product->validate_form($form_data, $session)` calls `validate()` then `process()` on each requirement in order.
4. After successful payment, `cart_charge_logic.php` calls `post_purchase()` on each requirement (e.g. to save survey answers, subscribe to a newsletter).

## Built-in requirement types

| Class name | Label | What it collects |
|---|---|---|
| `FullNameRequirement` | Name | First name + last name |
| `EmailRequirement` | Email | Email address |
| `PhoneNumberRequirement` | Phone | Phone number |
| `DOBRequirement` | Date of Birth | Date of birth |
| `AddressRequirement` | Address | Street / city / state / zip / country |
| `UserPriceRequirement` | User Chooses Price | Optional donation amount (affects pricing) |
| `NewsletterSignupRequirement` | Newsletter Signup | Optional email opt-in checkbox |
| `QuestionRequirement` | Question | Any single `Question` (all types supported) |
| `SurveyRequirement` | Survey | All questions in a `Survey`, saves `SurveyAnswer` records on purchase |

## Admin UI

Requirements are managed on the product edit page at `/admin/admin_product_edit?pro_product_id={id}`. The requirements section lets you add, order, and remove requirement instances for that product.

## Attaching requirements programmatically

Use `$product->save_requirement_instances($requirements)` — it diffs against existing instances and soft-deletes anything removed.

```php
$product->save_requirement_instances([
    ['class_name' => 'FullNameRequirement', 'config' => []],
    ['class_name' => 'QuestionRequirement',  'config' => ['question_id' => 42]],
    ['class_name' => 'SurveyRequirement',    'config' => ['survey_id' => 7]],
]);
```

For `QuestionRequirement`, `config` must contain `question_id`.
For `SurveyRequirement`, `config` must contain `survey_id` (and optionally `event_id` to mark the event registrant's survey as completed on purchase).

## Reading existing requirements

```php
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));

// Returns ordered AbstractProductRequirement[] for a product
$requirements = AbstractProductRequirement::getProductRequirements($product_id);

// Or via the Product model (also auto-adds event pre-purchase surveys):
$requirements = $product->get_product_requirements();
```

## Auto-injected survey requirements

If a product is linked to an event (`pro_evt_event_id`) and that event has `evt_survey_display = 'required_before_purchase'`, a `SurveyRequirement` is automatically appended to the requirement list — no `ProductRequirementInstance` record needed. This is handled inside `Product::get_product_requirements()`.

## Writing a custom requirement (plugin use)

Subclass `AbstractProductRequirement`, implement the methods you need, and register at the bottom of your file:

```php
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));

class MyCustomRequirement extends AbstractProductRequirement {

    const LABEL = 'My Custom Field';

    public function render_fields($formwriter, $product, $existing_data = []) {
        $formwriter->textinput('my_field', 'My Label', ['validation' => ['required' => true]]);
    }

    public function validate($post_data, $product) {
        if (empty($post_data['my_field'])) {
            return ['My field is required.'];
        }
        return [];
    }

    public function process($post_data, $product, $order_detail, $user) {
        $data_array    = ['my_field' => $post_data['my_field']];
        $display_array = ['My Label' => $post_data['my_field']];
        return [$data_array, $display_array];
    }

    public function post_purchase($data, $order_item, $user, $order) {
        // Called after successful payment — $data contains the merged output of all process() calls
    }
}

AbstractProductRequirement::register('MyCustomRequirement', __FILE__);
```

Override only the methods you need — all have no-op defaults in `AbstractProductRequirement`.

### AbstractProductRequirement interface summary

| Method | Purpose |
|---|---|
| `render_fields($formwriter, $product, $existing_data)` | Output form fields |
| `validate($post_data, $product)` | Return array of error strings (empty = valid) |
| `process($post_data, $product, $order_detail, $user)` | Return `[$data_array, $display_array]` |
| `post_purchase($data, $order_item, $user, $order)` | Side effects after successful payment |
| `affects_pricing()` | Return `true` if this requirement modifies the price |
| `get_modified_price($post_data, $product, $base_price)` | Return adjusted price (only called when `affects_pricing()` is true) |
| `get_validation_info()` | Return client-side validation rules for JoineryValidation |
| `get_javascript()` | Return custom JS string (without `<script>` tags) |
| `getFormGroup()` | Return `'info'`, `'address'`, or `'questions'` for card grouping |

## Data model

```
ProductRequirementInstance  (pri_product_requirement_instances)
  pri_pro_product_id    — which product
  pri_class_name        — e.g. 'QuestionRequirement'
  pri_config            — JSON config, e.g. {"question_id": 42}
  pri_order             — display order
  pri_delete_time       — soft delete
```

The registry and instantiation are managed entirely through `AbstractProductRequirement` — never instantiate requirement classes directly from `pri_class_name` without going through `AbstractProductRequirement::createInstance()`.
