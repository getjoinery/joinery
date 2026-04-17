# Admin Page Card Layout Option

## Problem Statement

Currently, the `AdminPage::admin_header()` function automatically wraps all page content in a card with a title header by calling `BeginPage()` and `EndPage()`. This works well for traditional form-based admin pages where all content belongs inside one card.

However, modern admin pages often use a card-based layout with multiple independent cards (e.g., the reorganized admin_user.php). In these cases, the automatic outer card creates an undesirable "card within cards" structure with redundant borders and backgrounds.

### Current Behavior

**File: `/var/www/html/joinerytest/public_html/includes/PublicPageFalcon.php`**

```php
public static function BeginPage($title='', $options=array()) {
    $output = '
        <div class="card">';
        if($title){
            $output .= '
            <div class="card-header bg-body-tertiary">
                <h5 class="mb-0">'.$title.'</h5>
            </div>';
        }
        $output .= '
            <div class="card-body">
        ';
    return $output;
}

public static function EndPage($options=array()) {
    $output = '</div></div>
        ';
    return $output;
}
```

**File: `/var/www/html/joinerytest/public_html/includes/AdminPage.php`**

```php
public function admin_header($options=array()) {
    // ... setup code ...
    $this->public_header($options);
    echo AdminPage::BeginPage($options['readable_title']);
    return true;
}

public function admin_footer($options=array()) {
    // ... cleanup code ...
    echo AdminPage::EndPage();
    $this->public_footer($options);
}
```

## Proposed Solution

Add a new option `'no_page_card' => true` that can be passed to `admin_header()` to skip the automatic card wrapper and instead display a clean page title and breadcrumbs section.

### Implementation Details

#### 1. Modify `AdminPage::admin_header()`

```php
public function admin_header($options=array()) {
    $session = SessionControl::get_instance();
    $_GLOBALS['page_header_loaded'] = true;
    $options['vertical_menu'] = MultiAdminMenu::getadminmenu($session->get_permission(), $options['menu-id']);

    $options['hide_horizontal_menu'] = true;
    $options['full_width'] = true;

    $this->public_header($options);

    // NEW: Check for no_page_card option
    if (isset($options['no_page_card']) && $options['no_page_card'] === true) {
        echo AdminPage::BeginPageNoCard($options);
    } else {
        echo AdminPage::BeginPage($options['readable_title']);
    }

    return true;
}

public function admin_footer($options=array()) {
    $session = SessionControl::get_instance();
    $session->clear_clearable_messages();
    $settings = Globalvars::get_instance();

    // NEW: Check for no_page_card option
    if (isset($this->header_options['no_page_card']) && $this->header_options['no_page_card'] === true) {
        echo AdminPage::EndPageNoCard();
    } else {
        echo AdminPage::EndPage();
    }

    $this->public_footer($options);
}
```

**Note:** We need to store the `$options` array as an instance variable `$this->header_options` in `admin_header()` so that `admin_footer()` knows which closing markup to use.

#### 2. Add New Methods to `PublicPageFalcon`

```php
/**
 * Begin page content without outer card wrapper
 * Displays page title and breadcrumbs in a clean header section
 */
public static function BeginPageNoCard($options=array()) {
    $output = '
    <!-- Page Header -->
    <div class="mb-3">';

    // Only show header if there's a title or breadcrumbs
    if (!empty($options['readable_title']) || !empty($options['breadcrumbs'])) {
        $output .= '
        <div class="d-flex flex-wrap flex-between-center mb-2">
            <div>';

        // Page Title
        if (!empty($options['readable_title'])) {
            $output .= '
                <h2 class="mb-2">' . htmlspecialchars($options['readable_title']) . '</h2>';
        }

        // Breadcrumbs
        if (!empty($options['breadcrumbs']) && is_array($options['breadcrumbs'])) {
            $output .= '
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">';

            $breadcrumb_count = count($options['breadcrumbs']);
            $current_index = 0;

            foreach ($options['breadcrumbs'] as $name => $url) {
                $current_index++;
                $is_last = ($current_index === $breadcrumb_count);

                if ($is_last || empty($url)) {
                    // Last item or no URL - display as active
                    $output .= '
                        <li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars($name) . '</li>';
                } else {
                    // Regular breadcrumb with link
                    $output .= '
                        <li class="breadcrumb-item"><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($name) . '</a></li>';
                }
            }

            $output .= '
                    </ol>
                </nav>';
        }

        $output .= '
            </div>
        </div>';
    }

    $output .= '
    </div>
    ';

    return $output;
}

/**
 * End page content without outer card wrapper
 */
public static function EndPageNoCard($options=array()) {
    // No closing markup needed since we don't open any wrapping containers
    return '';
}
```

#### 3. Store Options in AdminPage Instance

Add an instance variable to track header options:

```php
class AdminPage extends PublicPageFalcon {
    protected $header_options = array();

    public function admin_header($options=array()) {
        // ... existing code ...

        // Store options for use in footer
        $this->header_options = $options;

        // ... rest of method ...
    }
}
```

### Usage Example

#### Old Style (Default - Single Card Page)

```php
$page = new AdminPage();
$page->admin_header(array(
    'menu-id'=> 'users-list',
    'page_title' => 'User Edit',
    'readable_title' => 'User Edit',
    'breadcrumbs' => array(
        'Users'=>'/admin/admin_users',
        'User Edit'=>'',
    ),
    'session' => $session,
));

// Content goes inside one card automatically
echo '<form>...</form>';

$page->admin_footer();
```

**Output:**
```html
<div class="card">
    <div class="card-header bg-body-tertiary">
        <h5 class="mb-0">User Edit</h5>
    </div>
    <div class="card-body">
        <form>...</form>
    </div>
</div>
```

#### New Style (Multi-Card Layout)

```php
$page = new AdminPage();
$page->admin_header(array(
    'menu-id'=> 'users-list',
    'page_title' => 'User Detail',
    'readable_title' => 'John Doe',
    'breadcrumbs' => array(
        'Users'=>'/admin/admin_users',
        'John Doe'=>'',
    ),
    'session' => $session,
    'no_page_card' => true,  // NEW OPTION
));

// Content is composed of multiple independent cards
?>
<div class="card mb-3">
    <div class="card-header">Account Info</div>
    <div class="card-body">...</div>
</div>

<div class="card mb-3">
    <div class="card-header">Orders</div>
    <div class="card-body">...</div>
</div>
<?php

$page->admin_footer();
```

**Output:**
```html
<!-- Page Header -->
<div class="mb-3">
    <div class="d-flex flex-wrap flex-between-center mb-2">
        <div>
            <h2 class="mb-2">John Doe</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/admin/admin_users">Users</a></li>
                    <li class="breadcrumb-item active" aria-current="page">John Doe</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">Account Info</div>
    <div class="card-body">...</div>
</div>

<div class="card mb-3">
    <div class="card-header">Orders</div>
    <div class="card-body">...</div>
</div>
```

## Benefits

1. **Flexibility**: Supports both traditional single-card pages and modern multi-card layouts
2. **Backward Compatible**: Existing pages continue to work without changes
3. **Clean Markup**: Eliminates redundant card nesting
4. **Proper Breadcrumbs**: Breadcrumbs are now properly rendered in the header section
5. **Consistent Styling**: Uses Bootstrap/Falcon conventions for breadcrumbs and page headers

## Migration Path

Pages can be migrated incrementally:

1. **Immediate**: New multi-card pages use `'no_page_card' => true`
2. **Future**: Existing single-card pages can continue using default behavior
3. **Optional**: Single-card pages can be refactored to use new option if desired

## Testing Checklist

- [ ] Verify old-style pages (without `no_page_card`) still work correctly
- [ ] Verify new-style pages (with `no_page_card => true`) render proper header
- [ ] Test breadcrumbs display correctly with various link configurations
- [ ] Test pages with no title
- [ ] Test pages with no breadcrumbs
- [ ] Test pages with both title and breadcrumbs
- [ ] Verify responsive behavior on mobile devices
- [ ] Check that admin_footer() properly closes markup in both modes

## Files to Modify

1. `/var/www/html/joinerytest/public_html/includes/AdminPage.php` - Add option handling and store header_options
2. `/var/www/html/joinerytest/public_html/includes/PublicPageFalcon.php` - Add BeginPageNoCard() and EndPageNoCard() methods
3. `/var/www/html/joinerytest/public_html/adm/admin_user.php` - Example usage (add `'no_page_card' => true` and remove current User Header Card)

## Future Enhancements

- Add support for action buttons in the header (similar to current User Header Card design)
- Add option to customize breadcrumb styling
- Consider adding support for page subtitles or descriptions
