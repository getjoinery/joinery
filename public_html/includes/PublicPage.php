<?php
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));

if (!class_exists('PublicPage')) {
class PublicPage extends PublicPageBase {

    protected function getTableClasses() {
        return [
            'wrapper' => 'table-wrapper',
            'table'   => 'styled-table',
            'header'  => '',
        ];
    }

    public function get_logo() {
        $settings = Globalvars::get_instance();
        if ($settings->get_setting('logo_link')) {
            echo '<img src="' . htmlspecialchars($settings->get_setting('logo_link'), ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($settings->get_setting('site_name'), ENT_QUOTES, 'UTF-8') . '" class="logo-img">';
        }
        echo '<span class="logo-text">' . htmlspecialchars($settings->get_setting('site_name'), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public function top_right_menu() {
        $menu_data  = $this->get_menu_data();
        $cart       = $menu_data['cart'];
        $user_menu  = $menu_data['user_menu'];

        // Cart
        if ($cart['has_items']) {
            echo '<a href="' . htmlspecialchars($cart['link'], ENT_QUOTES, 'UTF-8') . '" class="header-cart-link" title="Cart (' . (int)$cart['item_count'] . ' items)">';
            echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
            echo '<span class="cart-count">' . (int)$cart['item_count'] . '</span>';
            echo '</a>';
        }

        // Notifications
        $this->render_notification_icon($menu_data);

        // Admin link
        if ($user_menu['permission_level'] >= 5) {
            echo ' <a href="/admin" class="btn btn-sm btn-outline">Admin</a>';
        }

        // Login / Register or user dropdown
        if (!$user_menu['is_logged_in']) {
            echo ' <a href="' . htmlspecialchars($user_menu['login_link'], ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-outline">Login</a>';
            if ($user_menu['register_link']) {
                echo ' <a href="' . htmlspecialchars($user_menu['register_link'], ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-primary">Register</a>';
            }
        } else {
            echo '<details class="user-dropdown">';
            echo '<summary class="user-dropdown-toggle" aria-label="User menu">';
            echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>';
            if ($user_menu['display_name']) {
                echo ' <span class="user-name">' . htmlspecialchars($user_menu['display_name'], ENT_QUOTES, 'UTF-8') . '</span>';
            }
            echo '</summary>';
            echo '<div class="user-dropdown-menu">';
            foreach ($user_menu['items'] as $item) {
                if (!in_array($item['label'], ['Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help'])) {
                    echo '<a href="' . htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</a>';
                }
            }
            echo '</div>';
            echo '</details>';
        }
    }

    public function public_header($options = array()) {
        $settings = Globalvars::get_instance();
        $options  = parent::public_header_common($options);

        if (empty($options['title'])) {
            $options['title'] = $settings->get_setting('site_name');
        }
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <?php if (!empty($options['meta_description'])): ?>
    <meta name="description" content="<?php echo htmlspecialchars($options['meta_description'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <?php if (!empty($options['noindex'])): ?>
    <meta name="robots" content="noindex<?php echo !empty($options['nofollow']) ? ',nofollow' : ''; ?>">
    <?php endif; ?>
    <title><?php echo htmlspecialchars($options['title'], ENT_QUOTES, 'UTF-8'); ?></title>

    <?php $this->global_includes_top($options); ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,700;1,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/custom.css">
</head>
<body>
<?php
        if (isset($options['header_only']) && $options['header_only']) {
            return;
        }

        // Store noheader option for use in public_footer
        $this->_noheader = !empty($options['noheader']);

        if ($this->_noheader) {
?>
<header class="site-header header-light" style="border-bottom: 1px solid var(--color-border, #eee);">
    <div class="header-inner" style="justify-content: center; position: relative;">
        <a href="/" class="logo" style="position: absolute; left: 1.5rem;" onclick="return confirm('Leave checkout? Your cart will be saved.');">
            <?php $this->get_logo(); ?>
        </a>
        <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; font-size: 1.0625rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Secure Checkout
        </div>
    </div>
</header>

<main class="main-content">
<?php
        } else {
?>
<header class="site-header header-light">
    <div class="header-inner">
        <a href="/" class="logo">
            <?php $this->get_logo(); ?>
        </a>
        <div class="header-right">
            <nav class="nav-links" aria-label="Main navigation">
                <?php
                $menu_data = $this->get_menu_data();
                foreach ($menu_data['main_menu'] as $menu_item) {
                    $active_class = !empty($menu_item['is_active']) ? ' class="active"' : '';
                    if (!empty($menu_item['submenu'])) {
                        echo '<div class="nav-dropdown">';
                        echo '<a href="' . htmlspecialchars($menu_item['link'], ENT_QUOTES, 'UTF-8') . '"' . $active_class . '>' . htmlspecialchars($menu_item['name'], ENT_QUOTES, 'UTF-8') . ' <span aria-hidden="true">&#9660;</span></a>';
                        echo '<div class="nav-dropdown-menu">';
                        foreach ($menu_item['submenu'] as $sub) {
                            $sub_active = !empty($sub['is_active']) ? ' class="active"' : '';
                            echo '<a href="' . htmlspecialchars($sub['link'], ENT_QUOTES, 'UTF-8') . '"' . $sub_active . '>' . htmlspecialchars($sub['name'], ENT_QUOTES, 'UTF-8') . '</a>';
                        }
                        echo '</div>';
                        echo '</div>';
                    } else {
                        echo '<a href="' . htmlspecialchars($menu_item['link'], ENT_QUOTES, 'UTF-8') . '"' . $active_class . '>' . htmlspecialchars($menu_item['name'], ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                }
                ?>
            </nav>
            <div class="header-icons">
                <?php $this->top_right_menu(); ?>
            </div>
            <button class="menu-toggle" aria-label="Toggle navigation menu" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</header>

<main class="main-content">
<?php
        }
    }

    public function public_footer($options = array()) {
        parent::public_footer($options);

        $settings = Globalvars::get_instance();

        if (!isset($options['header_only']) || !$options['header_only']) {
            if (!empty($this->_noheader)) {
                // Minimal footer for checkout mode
?>
</main>
<?php
            } else {
?>
</main>

<footer class="site-footer">
    <div class="container">
        <div class="footer-bottom">
            <p class="copyright">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($settings->get_setting('site_name'), ENT_QUOTES, 'UTF-8'); ?>. All rights reserved.</p>
        </div>
    </div>
</footer>
<?php
            }
        }
?>
    <script src="/assets/js/joinery-validate.js"></script>
    <script src="/assets/js/script.js"></script>
</body>
</html>
<?php
    }

    public static function BeginPage($title = '', $options = array()) {
        $output = '<div class="page-content">';
        if ($title) {
            $output .= '<div class="page-header">';
            $output .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
            if (!empty($options['subtitle'])) {
                $output .= '<p class="page-subtitle">' . $options['subtitle'] . '</p>';
            }
            $output .= '</div>';
        }
        return $output;
    }

    public static function EndPage($options = array()) {
        return '</div><!-- /.page-content -->';
    }

    public static function BeginPanel($options = array()) {
        return '<div class="panel">';
    }

    public static function EndPanel($options = array()) {
        return '</div><!-- /.panel -->';
    }

    public static function BeginPageNoCard($options = array()) {
        $output = '<div class="page-header-bar">';
        if (!empty($options['readable_title'])) {
            $output .= '<h2>' . htmlspecialchars($options['readable_title'], ENT_QUOTES, 'UTF-8') . '</h2>';
        }
        if (!empty($options['breadcrumbs']) && is_array($options['breadcrumbs'])) {
            $output .= '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol>';
            $count = count($options['breadcrumbs']);
            $i     = 0;
            foreach ($options['breadcrumbs'] as $name => $url) {
                $i++;
                if ($i === $count || empty($url)) {
                    $output .= '<li class="active" aria-current="page">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</li>';
                } else {
                    $output .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a></li>';
                }
            }
            $output .= '</ol></nav>';
        }
        if (!empty($options['header_action'])) {
            $output .= '<div class="page-header-action">' . $options['header_action'] . '</div>';
        }
        $output .= '</div>';
        return $output;
    }

    public static function EndPageNoCard($options = array()) {
        return '';
    }

    public static function OutputGenericPublicPage($title, $header, $body, $options = array()) {
        $page = new PublicPage();
        $page->public_header(array_merge(['title' => $title, 'showheader' => true], $options));
        echo PublicPage::BeginPage($header);
        echo '<div class="container"><p>' . $body . '</p></div>';
        echo PublicPage::EndPage();
        $page->public_footer();
        exit;
    }
} // end class PublicPage
} // end class_exists guard
