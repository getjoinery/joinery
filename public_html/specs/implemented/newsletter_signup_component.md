# Newsletter Signup Component

## Overview

A reusable page component that renders a mailing list signup form. It supports three list modes: default mailing list, a specific list passed as a config argument, or all public lists displayed as a checkbox list. The component reuses existing list/lists logic and integrates with the captcha/antispam system when enabled.

## Goals

- Provide a drop-in newsletter signup form usable on any page via the component system
- Reuse existing `list_logic.php` and `lists_logic.php` POST handling so subscription behavior stays consistent
- Support the captcha, antispam, and honeypot systems when their settings are enabled
- Work with any CSS framework (plain HTML5 with Bootstrap-compatible class names)
- Be configurable via the admin component editor (heading, description, button text, colors, list mode)

## Component Files

### 1. Component Type Definition: `views/components/newsletter_signup.json`

```json
{
    "title": "Newsletter Signup",
    "description": "Mailing list signup form with captcha support",
    "category": "conversion",
    "css_framework": "bootstrap",
    "config_schema": {
        "fields": [
            {
                "name": "heading",
                "label": "Heading",
                "type": "textinput",
                "default": "Stay in Touch"
            },
            {
                "name": "subheading",
                "label": "Supporting Text",
                "type": "textarea",
                "default": "Sign up for our newsletter to get the latest updates."
            },
            {
                "name": "list_mode",
                "label": "List Mode",
                "type": "dropinput",
                "default": "default",
                "options": {
                    "default": "Default Mailing List",
                    "specific": "Specific List (by ID)",
                    "all": "Show All Public Lists"
                },
                "help": "'Default' uses the system default_mailing_list setting. 'Specific' uses the List ID below. 'All' shows checkboxes for every public list."
            },
            {
                "name": "mailing_list_id",
                "label": "Mailing List ID",
                "type": "textinput",
                "default": "",
                "help": "Only used when List Mode is 'Specific'. Enter the mailing list ID number."
            },
            {
                "name": "button_text",
                "label": "Button Text",
                "type": "textinput",
                "default": "Subscribe"
            },
            {
                "name": "success_message",
                "label": "Success Message",
                "type": "textinput",
                "default": "Thank you for subscribing!",
                "help": "Message shown after successful signup."
            },
            {
                "name": "show_name_fields",
                "label": "Show Name Fields",
                "type": "checkboxinput",
                "default": true,
                "help": "Show first/last name fields for non-logged-in users. If unchecked, only email is shown."
            },
            {
                "name": "compact_mode",
                "label": "Compact Layout",
                "type": "checkboxinput",
                "default": false,
                "help": "Renders a single-row inline form (email + button only). Overrides Show Name Fields when enabled."
            },
            {
                "name": "background_type",
                "label": "Background",
                "type": "dropinput",
                "default": "none",
                "options": {
                    "none": "None (transparent)",
                    "color": "Solid Color",
                    "gradient": "Gradient"
                }
            },
            {
                "name": "background_color",
                "label": "Background Color",
                "type": "textinput",
                "help": "Hex color for solid background",
                "default": "#f8f9fa"
            },
            {
                "name": "gradient_start",
                "label": "Gradient Start",
                "type": "textinput",
                "default": "#667eea"
            },
            {
                "name": "gradient_end",
                "label": "Gradient End",
                "type": "textinput",
                "default": "#764ba2"
            },
            {
                "name": "text_color",
                "label": "Text Color",
                "type": "textinput",
                "help": "Hex color for heading/body text",
                "default": ""
            },
            {
                "name": "text_align",
                "label": "Text Alignment",
                "type": "dropinput",
                "default": "center",
                "options": {
                    "left": "Left",
                    "center": "Center",
                    "right": "Right"
                }
            }
        ]
    },
    "layout_defaults": {
        "container_width": "600px",
        "max_height": "default"
    }
}
```

### 2. Component Logic Function: `logic/components/newsletter_signup_logic.php`

This is the first component logic function in the system. It will be referenced by the `com_logic_function` column on the component type record.

```php
function newsletter_signup_logic($config) { ... }
```

**Responsibilities:**
- Determine the effective list mode and resolve which mailing list(s) to use
- Check that `mailing_lists_active` setting is enabled; return empty data if not
- Load the session to determine if user is logged in
- For `default` mode: load the mailing list specified by the `default_mailing_list` setting
- For `specific` mode: load the mailing list with the ID from `$config['mailing_list_id']`
- For `all` mode: load all public, active, non-deleted mailing lists via `MultiMailingList`
- If the user is logged in, check which lists they are already subscribed to
- Handle POST submissions by delegating to the same code path used by `list_logic.php` / `lists_logic.php`:
  - Honeypot check (non-logged-in users)
  - Antispam question check (non-logged-in users)
  - Captcha check (non-logged-in users)
  - Find or create user
  - Call `$user->add_user_to_mailing_lists()` with the selected list IDs
- Return data array with: `mailing_lists`, `session`, `messages`, `user_subscribed_list`, `list_mode`

**Key design decision — reuse via delegation:**
Rather than duplicating the POST-handling logic from `list_logic.php` and `lists_logic.php`, the component logic function should extract the shared validation and subscription steps into a helper or call them directly. The simplest approach: the logic function contains its own POST handler that follows the same pattern (honeypot → antispam → captcha → find/create user → subscribe). This keeps the logic function self-contained while matching the existing behavior.

### 3. Component Template: `views/components/newsletter_signup.php`

**Available variables (standard component vars):**
- `$component_config` — merged configuration
- `$component_data` — data returned by `newsletter_signup_logic()`
- `$component` — PageContent instance (null in type_key mode)
- `$component_type_record` — Component type record
- `$component_slug` — slug string

**Template behavior:**

1. Extract config values with defaults
2. If `$component_data` is empty or mailing lists feature is off, render nothing
3. If there are messages (from POST), render them as alerts
4. If user is already subscribed to the list (single-list modes) and is logged in, show "already subscribed" message instead of form
5. Render the form:
   - Form action: `/list/{slug}` for single-list modes, `/lists` for all-lists mode
   - Form method: POST
   - Form uses FormWriter for proper field rendering
   - For non-logged-in users: name fields (if `show_name_fields`), email, timezone, privacy consent
   - For `all` mode: checkbox list of available mailing lists
   - For `default`/`specific` mode: hidden input with mailing list ID + subscribe checkbox
   - Antispam question, honeypot, and captcha fields (non-logged-in users only)
   - Submit button with configurable text
6. Compact mode: renders only email + submit button in a flex/inline layout

**Form action and POST handling approach:**

The form posts to the existing `/list/{slug}` or `/lists` endpoints. This means:
- The existing `list_logic.php` / `lists_logic.php` handles the actual subscription
- After POST, the user is on the `/list` or `/lists` page with the success/error message
- This maximizes code reuse — the component only needs to render the form, not handle submissions

This is the preferred approach because:
- Zero duplicated POST-handling logic
- Captcha, antispam, honeypot validation stays in one place
- Welcome emails, Mailchimp sync, user creation all work automatically
- Success/error messages are displayed by the existing pages

**Consequence:** The logic function does NOT need POST handling at all. It only needs to:
- Resolve which list(s) to display
- Check if the user is already subscribed (for logged-in users)
- Return the data for the template to render the form

### Revised Logic Function (simplified):

```php
function newsletter_signup_logic($config) {
    require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

    $settings = Globalvars::get_instance();
    $data = ['mailing_lists' => [], 'list_mode' => 'default', 'is_active' => false,
             'session' => null, 'user_subscribed_list' => [], 'form_action' => '/lists'];

    // Check if feature is enabled
    if (!$settings->get_setting('mailing_lists_active')) {
        return $data;
    }
    $data['is_active'] = true;

    $session = SessionControl::get_instance();
    $data['session'] = $session;

    $list_mode = $config['list_mode'] ?? 'default';
    $data['list_mode'] = $list_mode;

    if ($list_mode === 'all') {
        // Load all public active lists
        $lists = new MultiMailingList(
            ['deleted' => false, 'visibility' => MailingList::VISIBILITY_PUBLIC],
            ['name' => 'ASC']
        );
        $lists->load();
        $data['mailing_lists'] = $lists;
        $data['form_action'] = '/lists';
    } else {
        // Resolve single list ID
        $list_id = null;
        if ($list_mode === 'specific' && !empty($config['mailing_list_id'])) {
            $list_id = (int) $config['mailing_list_id'];
        } else {
            $list_id = $settings->get_setting('default_mailing_list');
        }

        if ($list_id) {
            $list = new MailingList($list_id, TRUE);
            if ($list->get('mlt_is_active') && !$list->get('mlt_delete_time')) {
                $data['mailing_lists'] = $list;
                $data['form_action'] = $list->get_url();
            }
        }
    }

    // Check user subscriptions for logged-in users
    if ($session->get_user_id()) {
        // ... load user's subscribed list IDs
    }

    return $data;
}
```

## Template HTML Structure

### Standard Layout (non-compact)

```html
<section class="newsletter-signup py-4" style="{background + text color styles}">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-8 {text-align class}">
        <h2>{heading}</h2>
        <p>{subheading}</p>

        <!-- Messages area (empty by default, populated on redirect back) -->

        <form method="POST" action="{/list/slug or /lists}">
          <!-- Non-logged-in user fields -->
          <div class="mb-3">
            <label>First Name</label>
            <input type="text" name="usr_first_name" required maxlength="32">
          </div>
          <div class="mb-3">
            <label>Last Name</label>
            <input type="text" name="usr_last_name" required maxlength="32">
          </div>
          <div class="mb-3">
            <label>Email</label>
            <input type="email" name="usr_email" required maxlength="64">
          </div>
          <!-- Timezone dropdown -->
          <!-- Privacy consent checkbox -->

          <!-- List selection (hidden input for single, checkboxes for all) -->
          <input type="hidden" name="mlt_mailing_list_id" value="{id}">
          <input type="hidden" name="mlt_mailing_list_id_subscribe" value="1">

          <!-- Antispam, honeypot, captcha (non-logged-in only) -->

          <button type="submit" class="btn btn-primary">{button_text}</button>
        </form>
      </div>
    </div>
  </div>
</section>
```

### Compact Layout

```html
<section class="newsletter-signup newsletter-signup--compact py-3" style="{styles}">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-8 {text-align class}">
        <h2>{heading}</h2>
        <p>{subheading}</p>
        <form method="POST" action="{action}" class="d-flex gap-2 justify-content-center">
          <input type="email" name="usr_email" placeholder="Your email" required class="form-control" style="max-width: 300px;">
          <input type="hidden" name="mlt_mailing_list_id" value="{id}">
          <input type="hidden" name="mlt_mailing_list_id_subscribe" value="1">
          <!-- Antispam/honeypot/captcha still included -->
          <button type="submit" class="btn btn-primary">{button_text}</button>
        </form>
      </div>
    </div>
  </div>
</section>
```

Note: Compact mode omits name fields and timezone. The `/list` logic will still accept the POST — first/last name will be empty, which `User::CreateNew` handles. If captcha is enabled, it renders below the inline row.

## FormWriter Usage in Template

The template must use FormWriter for proper field rendering and spam protection:

```php
require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
$formwriter = new FormWriter('newsletter_signup_' . $component_slug);

$formwriter->begin_form([
    'method' => 'POST',
    'action' => $form_action,
]);

// ... fields via $formwriter->textinput(), etc.

if (!$session->get_user_id()) {
    $formwriter->antispam_question_input();
    $formwriter->honeypot_hidden_input();
    $formwriter->captcha_hidden_input();
}

$formwriter->submitbutton('submit', $button_text, ['class' => 'btn btn-primary']);
$formwriter->end_form();
```

## Component Registration

A database migration inserts the component type record into `com_components`:

```sql
INSERT INTO com_components (
    com_type_key, com_title, com_description, com_category,
    com_template_file, com_config_schema, com_logic_function,
    com_is_active, com_css_framework, com_create_time
) VALUES (
    'newsletter_signup',
    'Newsletter Signup',
    'Mailing list signup form with captcha support',
    'conversion',
    'newsletter_signup.php',
    '{...json schema...}',
    'newsletter_signup_logic',
    TRUE,
    'bootstrap',
    now()
);
```

The JSON schema content matches the `config_schema` object from the JSON definition file.

The `com_layout_defaults` should be set to: `{"container_width": "600px", "max_height": "default"}`

## Usage Examples

### Render by slug (database instance configured in admin)
```php
echo ComponentRenderer::render('footer-newsletter');
```

### Render by type key with default list
```php
echo ComponentRenderer::render(null, 'newsletter_signup', [
    'heading' => 'Join Our Newsletter',
    'list_mode' => 'default',
]);
```

### Render with a specific list
```php
echo ComponentRenderer::render(null, 'newsletter_signup', [
    'list_mode' => 'specific',
    'mailing_list_id' => 3,
    'heading' => 'Subscribe to Updates',
]);
```

### Render with all public lists
```php
echo ComponentRenderer::render(null, 'newsletter_signup', [
    'list_mode' => 'all',
    'heading' => 'Choose Your Lists',
]);
```

### Compact inline form
```php
echo ComponentRenderer::render(null, 'newsletter_signup', [
    'compact_mode' => true,
    'heading' => '',
    'subheading' => 'Get updates delivered to your inbox.',
]);
```

## Implementation Steps

1. **Create `views/components/newsletter_signup.json`** — Component type definition with config schema
2. **Create `logic/components/newsletter_signup_logic.php`** — Logic function to resolve lists and check subscriptions
3. **Create `views/components/newsletter_signup.php`** — Template rendering the signup form
4. **Add migration** to register the component type in `com_components`
5. **Validate** — Run `php -l` and `validate_php_file.php` on all new PHP files
6. **Test** — Verify rendering via browser on test site

## Settings Dependencies

The component respects these existing settings (no new settings needed):

| Setting | Effect |
|---------|--------|
| `mailing_lists_active` | Component renders nothing if disabled |
| `default_mailing_list` | Used when `list_mode` is `default` |
| `use_captcha` | Enables captcha widget in form |
| `hcaptcha_public` / `hcaptcha_private` | hCaptcha keys (preferred) |
| `captcha_public` / `captcha_private` | reCAPTCHA keys (fallback) |
| `use_honeypot` | Enables honeypot field |
| `antispam_question_setting` | The antispam question text |
| `nickname_display_as` | Whether to show nickname field |
| `default_timezone` | Pre-selected timezone value |

## Edge Cases

- **Mailing lists feature disabled**: Component renders empty string (invisible)
- **No default mailing list configured**: Component renders empty in default mode
- **Invalid specific list ID**: Component renders empty
- **List is inactive or deleted**: Component renders empty
- **User already subscribed (logged in, single-list mode)**: Shows "already subscribed" message instead of form
- **Multiple components on same page**: Each gets a unique FormWriter instance via `$component_slug`
- **Compact mode + captcha enabled**: Captcha renders below the inline row as a full-width element
- **All-lists mode with zero public lists**: Shows "no lists available" message
