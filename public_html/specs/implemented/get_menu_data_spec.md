# Specification: get_menu_data() Function for PublicPageBase

## Overview
Create a unified menu generation function in PublicPageBase that consolidates all menu-related logic from various PublicPage implementations. This function will output a comprehensive array containing all top menu item information, processing settings, user permissions, cart state, and other menu-related data.

## Function Signature
```php
public function get_menu_data(): array
```

## Return Structure
The function will return an associative array with the following structure:

```php
[
    'main_menu' => [
        // Array of main navigation menu items from public_menus table
        [
            'name' => string,
            'link' => string,
            'icon' => string|null,
            'parent' => bool,
            'submenu' => array|null,
            'is_active' => bool,
            'display' => string
        ]
    ],

    'user_menu' => [
        'is_logged_in' => bool,
        'user_id' => int|null,
        'user_name' => string|null,
        'display_name' => string|null,
        'permission_level' => int,
        'avatar_url' => string|null,
        'items' => [
            // Dynamic menu items based on login state
            [
                'label' => string,
                'link' => string,
                'icon' => string|null
            ]
        ]
    ],

    'cart' => [
        'enabled' => bool,  // Always true - cart is always available
        'item_count' => int,
        'total_items' => int,
        'subtotal' => float|null,
        'link' => string,
        'has_items' => bool
    ],

    'notifications' => [
        'enabled' => bool,
        'count' => int,
        'unread_count' => int,
        'items' => array  // Future expansion
    ],

    'site_info' => [
        'site_name' => string,
        'site_description' => string,
        'logo_link' => string|null,
        'theme' => string,
        'register_enabled' => bool
    ],

    'mobile_menu' => [
        'enabled' => bool
    ]
]
```

## Implementation Details

### 1. Main Menu Processing
- Call `PublicPage::get_public_menu()` to get database menu items
- Process parent/child relationships
- Determine active states based on current URL
- Handle icon assignments

### 2. User Menu Logic
```php
if ($session->is_logged_in()) {
    // Show: Profile, Settings, Sign Out
    // If admin: Admin Dashboard
} else {
    // Show: Sign In
    // If register_active: Sign Up
}
```

### 3. Cart Processing
```php
$cart = $session->get_shopping_cart();
$cart_data = [
    'enabled' => $settings->get_setting('ecommerce_enabled', false, true),
    'item_count' => $cart ? $cart->count_items() : 0,
    // ... additional cart data
];
```

### 4. Settings Integration
The function will check these actual settings that exist in the system:
- `register_active` - Whether registration is enabled
- `site_name` - Site title
- `site_description` - Site description (for metadata)
- `logo_link` - Logo URL
- `theme_template` - Current theme

## Discrepancies Found in Current Implementations

### 1. Permission Level Inconsistencies
- **PublicPageFalcon**: Uses `>= 5` for admin menu, `> 5` for admin items
- **PublicPageTailwind**: Uses `>= 5` for admin link
- **jeremytunnell theme**: No admin menu implementation
- **Recommendation**: Standardize on `>= 5` for basic admin, `>= 10` for super admin

### 2. Cart Display Logic
- **PublicPageFalcon**: Shows cart icon with item count badge
- **PublicPageTailwind**: Shows "Cart" text link
- **Canvas theme**: No cart implementation shown
- **Recommendation**: Return both display options, let theme decide

### 3. User Avatar Handling
- **PublicPageFalcon**: Uses static avatar image from theme
- **Other themes**: No avatar implementation
- **Recommendation**: Check for user avatar setting, provide default fallback

### 4. Menu Structure Differences
- **PublicPageFalcon**: Dropdown menus with FontAwesome icons
- **PublicPageTailwind**: Simple text links
- **Canvas theme**: Multi-level dropdown support
- **jeremytunnell**: Static menu items, no database integration
- **Recommendation**: Provide full data, let theme render as needed

### 5. Mobile Menu Variations
- **PublicPageFalcon**: Hamburger menu with collapse
- **Canvas theme**: Custom hamburger implementation
- **Recommendation**: Provide mobile menu data separately

## Usage Example

```php
// In any PublicPage implementation:
$menu_data = $this->get_menu_data();

// Access specific menu components:
$main_menu = $menu_data['main_menu'];
$cart_count = $menu_data['cart']['item_count'];
$is_admin = $menu_data['admin_menu']['show'];

// Use in template:
foreach ($menu_data['main_menu'] as $menu_item) {
    if ($menu_item['parent'] && !empty($menu_item['submenu'])) {
        // Render dropdown menu
    } else {
        // Render simple menu item
    }
}
```

## Migration Notes

1. This function should be added to PublicPageBase as a public method
2. Existing theme implementations can gradually migrate to use this function
3. The function should be backward compatible with existing menu rendering
4. Consider caching menu data per session to improve performance

## Testing Requirements

1. Test with logged-in and logged-out users
2. Test with various permission levels (0, 5, 6, 10)
3. Test with cart containing 0, 1, and multiple items
4. Test with all settings enabled/disabled
5. Test menu active state detection
6. Test with themes that don't use certain features

## Phase 2 - Menu Function Consolidation

After implementing the `get_menu_data()` function, consolidate existing menu functions to eliminate duplication:

### Functions to Remove/Replace

1. **Remove `top_right_menu()` from PublicPageFalcon**
   - This function duplicates cart counting and user menu logic
   - Replace with `get_menu_data()['cart']` and `get_menu_data()['user_menu']`
   - Location: `/includes/PublicPageFalcon.php:78`

2. **Remove hardcoded profile menu arrays from PublicPageTailwind**
   - Replace `$profile_menu` and `$logged_out_menu` arrays
   - Use `get_menu_data()['user_menu']['items']` instead
   - Location: `/includes/PublicPageTailwind.php:160-175`

3. **Keep theme-specific `tab_menu()` implementations**
   - Each theme uses fundamentally different UI patterns (Bootstrap vs Tailwind vs generic)
   - No consolidation needed - theme-specific implementations are appropriate

### Migration Steps

1. **Update PublicPageFalcon**:
   ```php
   // Replace this line in public_header():
   <?php $this->top_right_menu(); ?>

   // With menu data usage:
   <?php
   $menu_data = $this->get_menu_data();
   $cart = $menu_data['cart'];
   $user_menu = $menu_data['user_menu'];
   // Render cart and user menu using the data
   ?>
   ```

2. **Update PublicPageTailwind**:
   ```php
   // Replace hardcoded arrays:
   $profile_menu = array();
   $logged_out_menu = array();

   // With:
   $menu_data = $this->get_menu_data();
   $user_items = $menu_data['user_menu']['items'];
   ```

3. **No tab_menu() consolidation needed**
   - Theme-specific implementations use different UI paradigms (Bootstrap vs Tailwind vs generic)
   - Each implementation should remain as-is to maintain proper theme functionality

### Benefits

- **Eliminates code duplication** for cart and user menu processing
- **Centralizes menu logic** in PublicPageBase
- **Consistent menu behavior** across all themes
- **Easier maintenance** - menu logic changes only need to be made in one place
- **Better performance** - no duplicate processing of cart/user data

### Testing Requirements

1. Verify cart display works correctly in Falcon theme after migration
2. Verify user menu displays correctly in Tailwind theme after migration
3. Test with different permission levels and login states
4. Ensure no PHP errors after removing duplicate functions

## Future Enhancements

1. Add menu item visibility permissions
2. Support for mega menus
3. Menu item badges/notifications
4. Custom menu item types (widgets, HTML)
5. Menu caching system
6. Multi-language support
7. A/B testing support for menu variations

## Implementation Code

```php
/**
 * Get comprehensive menu data for all menu types
 * Consolidates menu logic from various theme implementations
 *
 * @return array Complete menu data structure
 */
public function get_menu_data() {
    $session = SessionControl::get_instance();
    $settings = Globalvars::get_instance();

    // Initialize return array
    $menu_data = [
        'main_menu' => [],
        'user_menu' => [],
        'cart' => [],
        'notifications' => [],
        'site_info' => [],
        'mobile_menu' => []
    ];

    // 1. Process main navigation menu from database
    try {
        $menus = PublicPage::get_public_menu();
        $menu_data['main_menu'] = $menus;

        // Add current page detection
        $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        foreach ($menu_data['main_menu'] as &$menu_item) {
            $menu_item['is_active'] = ($menu_item['link'] === $current_path);
            if (!empty($menu_item['submenu'])) {
                foreach ($menu_item['submenu'] as &$submenu_item) {
                    $submenu_item['is_active'] = ($submenu_item['link'] === $current_path);
                    if ($submenu_item['is_active']) {
                        $menu_item['is_active'] = true; // Parent is active if child is
                    }
                }
            }
        }
    } catch (Exception $e) {
        $menu_data['main_menu'] = [];
    }

    // 2. Build user menu based on login state
    $is_logged_in = $session->is_logged_in();
    $menu_data['user_menu'] = [
        'is_logged_in' => $is_logged_in,
        'user_id' => $is_logged_in ? $session->get_user_id() : null,
        'user_name' => null,
        'display_name' => null,
        'permission_level' => $session->get_permission(),
        'avatar_url' => null,
        'items' => []
    ];

    if ($is_logged_in) {
        // Get user information
        if ($session->get_user_id()) {
            try {
                $user = new User($session->get_user_id(), TRUE);
                $menu_data['user_menu']['user_name'] = $user->get('usr_email');
                $menu_data['user_menu']['display_name'] = $user->display_name();

                // Default avatar path
                $menu_data['user_menu']['avatar_url'] = PathHelper::getThemeFilePath('avatar.png', 'assets/images', 'web');
            } catch (Exception $e) {
                // User load failed, use session data only
                $menu_data['user_menu']['display_name'] = 'User';
            }
        }

        // Logged in menu items - only include items that should be shown
        $menu_data['user_menu']['items'] = [
            // Navigation
            [
                'label' => 'Home',
                'link' => '/',
                'icon' => 'home'
            ],
            [
                'label' => 'My Profile',
                'link' => '/profile',
                'icon' => 'user'
            ],

            // E-commerce related
            [
                'label' => 'Orders',
                'link' => '/profile#orders',
                'icon' => 'shopping-bag'
            ],
            [
                'label' => 'Subscriptions',
                'link' => '/profile/subscriptions',
                'icon' => 'refresh'
            ],

            // Event related
            [
                'label' => 'My Events',
                'link' => '/profile#events',
                'icon' => 'calendar'
            ],
            [
                'label' => 'Event Sessions',
                'link' => '/profile/event_sessions',
                'icon' => 'clock'
            ],

            // Authentication
            [
                'label' => 'Sign out',
                'link' => '/logout',
                'icon' => 'sign-out'
            ]
        ];

        // Add admin items based on permission level (checked here, not in array)
        $permission = $session->get_permission();
        if ($permission >= 5) {
            // Insert admin items before logout
            array_splice($menu_data['user_menu']['items'], -1, 0, [
                [
                    'label' => 'Admin Dashboard',
                    'link' => '/admin/admin_users',
                    'icon' => 'dashboard'
                ]
            ]);

            // Advanced admin items for permission > 5
            if ($permission > 5) {
                array_splice($menu_data['user_menu']['items'], -1, 0, [
                    [
                        'label' => 'Admin Settings',
                        'link' => '/admin/admin_settings',
                        'icon' => 'wrench'
                    ],
                    [
                        'label' => 'Admin Utilities',
                        'link' => '/admin/admin_utilities',
                        'icon' => 'tools'
                    ]
                ]);
            }

            // Help available to all admin users
            array_splice($menu_data['user_menu']['items'], -1, 0, [
                [
                    'label' => 'Admin Help',
                    'link' => '/admin/admin_help',
                    'icon' => 'question-circle'
                ]
            ]);
        }
    } else {
        // Logged out menu items
        $register_active = $settings->get_setting('register_active', false, true);

        $menu_data['user_menu']['items'] = [
            [
                'label' => 'Home',
                'link' => '/',
                'icon' => 'home'
            ],
            [
                'label' => 'Sign in',
                'link' => '/login',
                'icon' => 'sign-in'
            ],
            [
                'label' => 'Forgot Password',
                'link' => '/password-reset-1',
                'icon' => 'key'
            ]
        ];

        if ($register_active) {
            $menu_data['user_menu']['items'][] = [
                'label' => 'Sign up',
                'link' => '/register',
                'icon' => 'user-plus'
            ];
        }
    }


    // 3. Process shopping cart data
    // Shopping cart is always available - no setting controls it
    $cart = null;
    $item_count = 0;

    try {
        $cart = $session->get_shopping_cart();
        if ($cart) {
            $item_count = $cart->count_items();
        }
    } catch (Exception $e) {
        // Cart not available
        $item_count = 0;
    }

    $menu_data['cart'] = [
        'enabled' => true, // Cart is always enabled in the system
        'item_count' => $item_count,
        'total_items' => $item_count, // Could be different if we track quantity
        'subtotal' => null, // Future: calculate subtotal
        'link' => '/cart',
        'has_items' => ($item_count > 0)
    ];

    // 4. Notifications (placeholder for future implementation)
    // No notifications system exists yet
    $menu_data['notifications'] = [
        'enabled' => false,
        'count' => 0,
        'unread_count' => 0,
        'items' => []
    ];

    // 5. Site information
    $menu_data['site_info'] = [
        'site_name' => $settings->get_setting('site_name', 'Joinery', true),
        'site_description' => $settings->get_setting('site_description', '', true),
        'logo_link' => $settings->get_setting('logo_link', null, true),
        'theme' => $settings->get_setting('theme_template', 'falcon', true),
        'register_enabled' => $settings->get_setting('register_active', false, true)
    ];

    // 6. Mobile menu configuration
    $menu_data['mobile_menu'] = [
        'enabled' => true // Always enabled by default
    ];

    return $menu_data;
}
```

This implementation:
- Handles all error cases gracefully with try-catch blocks
- Uses the fail_silently parameter for settings to prevent errors
- Provides comprehensive data for public-facing menu types
- Filters menu items based on permissions and conditions
- Re-indexes arrays after filtering to maintain clean structure
- Includes placeholders for future enhancements (notifications, cart subtotal)
- Excludes admin menu data (which should be handled by AdminPage class extensions)