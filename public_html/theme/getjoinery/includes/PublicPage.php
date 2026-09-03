<?php
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

class PublicPage extends PublicPageBase {

    protected function getTableClasses() {
        return [
            'wrapper' => 'table-wrapper',
            'table' => 'table',
            'header' => 'table-header'
        ];
    }

    public static function OutputGenericPublicPage($title, $header, $body, $options=array()) {
        $page = new PublicPage();
        $page->public_header(
            array_merge(
                array('title' => $title, 'showheader' => TRUE),
                $options
            )
        );
        echo PublicPage::BeginPage($header);
        ?>
        <p><?php echo $body; ?></p>
        <?php
        echo PublicPage::EndPage();
        $page->public_footer();
        exit;
    }

    public static function BeginPage($title='', $options=array()) {
        $output = '<div class="content-wrapper">';
        if ($title) {
            $output .= '<h1>' . htmlspecialchars($title) . '</h1>';
        }
        return $output;
    }

    public static function EndPage($options=array()) {
        return '</div>';
    }

    /**
     * Member section nav in this theme's own markup — it sits inside the site
     * <nav> and uses the theme's .member-subnav styling rather than the shared
     * kit classes. The item list and its gates stay with PublicPageBase.
     */
    public function render_member_subnav($menu_data = NULL) {
        $items = $this->member_subnav_items($menu_data);
        if (empty($items)) {
            return;
        }
        $request_path = $this->request_path();
        ?>
    <div class="member-subnav">
        <div class="container">
            <nav class="member-subnav-links" aria-label="Profile sections">
                <?php foreach ($items as $it):
                    $active = ($it['link'] === $request_path) ? ' active' : '';
                    echo '<a href="' . htmlspecialchars($it['link'], ENT_QUOTES, 'UTF-8') . '" class="member-subnav-link' . $active . '">' . htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') . '</a>';
                endforeach; ?>
            </nav>
        </div>
    </div>
        <?php
    }

    public function public_header($options=array()) {
        $_GLOBALS['page_header_loaded'] = true;
        $settings = Globalvars::get_instance();
        $session = SessionControl::get_instance();
        ob_start();
        $options = parent::public_header_common($options);
        $_head_inject = ob_get_clean();

        $menu_data = $this->get_menu_data();

        // Determine active page for nav highlighting
        $request_path = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';

        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php echo $_head_inject; ?>
    <?php $this->global_includes_top($options); ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/theme/getjoinery/assets/css/style.css?v=<?php echo $this->asset_mtime('theme/getjoinery/assets/css/style.css'); ?>">

    <?php
    if ($settings->get_setting('custom_css')) {
        echo '<style>' . $settings->get_setting('custom_css') . '</style>';
    }
    ?>
</head>
<body>

<?php if ($this->show_site_chrome()): ?>
<?php
    // Member/account chrome, all sourced from get_menu_data() so the member
    // apps (Email, Calendar, Drive, AI, ...) and their permission/setting gates
    // stay in one place — the seeded profile menu.
    $is_logged_in  = $session->is_logged_in();
    $user_items    = $menu_data['user_menu']['items'] ?? [];
    $display_name  = $menu_data['user_menu']['display_name'] ?: 'Account';
    $register_link = $menu_data['user_menu']['register_link'] ?? null;
    $cart_count    = (int)($menu_data['cart']['count'] ?? 0);
    $cart_link     = $menu_data['cart']['link'] ?? '/cart';

    // Avatar initials from the display name.
    $initials = '';
    foreach (preg_split('/\s+/', trim((string)$display_name)) as $word) {
        if ($word === '') continue;
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        if (mb_strlen($initials) >= 2) break;
    }
    if ($initials === '') $initials = 'U';
?>
<nav class="site-nav">
    <div class="container">
        <a href="/" class="nav-logo">Joinery</a>

        <div class="nav-cluster">
            <div class="nav-links" id="nav-links">
                <?php
                // Header navigation comes from the site's public menu, so the
                // items and their order are the site's data rather than this
                // theme's opinion. A submenu renders as a dropdown.
                foreach (($menu_data['main_menu'] ?? []) as $nav_item):
                    $nav_link = (string)($nav_item['link'] ?? '');
                    $nav_name = (string)($nav_item['name'] ?? '');
                    $nav_children = $nav_item['submenu'] ?? [];
                    $nav_active = !empty($nav_item['is_active']);
                    if ($nav_name === '') { continue; }

                    if (empty($nav_children)):
                ?>
                <a href="<?php echo htmlspecialchars($nav_link, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $nav_active ? ' class="active"' : ''; ?>><?php echo htmlspecialchars($nav_name, ENT_QUOTES, 'UTF-8'); ?></a>
                <?php else:
                    $child_active = $nav_active;
                    foreach ($nav_children as $child) { if (!empty($child['is_active'])) { $child_active = TRUE; } }
                ?>
                <div class="nav-dropdown">
                    <button class="nav-dropdown-toggle<?php echo $child_active ? ' active' : ''; ?>" aria-haspopup="true" aria-expanded="false">
                        <?php echo htmlspecialchars($nav_name, ENT_QUOTES, 'UTF-8'); ?>
                        <svg class="nav-dropdown-chevron" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1,1 5,5 9,1"/></svg>
                    </button>
                    <div class="nav-dropdown-menu">
                        <?php foreach ($nav_children as $child): ?>
                        <a href="<?php echo htmlspecialchars((string)($child['link'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"<?php echo !empty($child['is_active']) ? ' class="active"' : ''; ?>><?php echo htmlspecialchars((string)($child['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; endforeach; ?>
            </div>

            <div class="nav-user">
                <?php if ($is_logged_in): ?>
                    <div class="nav-user-icons">
                        <?php $this->render_notification_icon($menu_data); ?>
                        <?php $this->render_message_icon($menu_data); ?>
                        <?php if ($cart_count > 0): ?>
                        <a href="<?php echo htmlspecialchars($cart_link, ENT_QUOTES, 'UTF-8'); ?>" class="header-cart-link" title="Cart" aria-label="Cart">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            <span class="cart-count"><?php echo $cart_count; ?></span>
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="nav-dropdown nav-dropdown--end nav-avatar">
                        <button class="nav-dropdown-toggle nav-avatar-toggle" aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
                            <span class="nav-avatar-circle"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span>
                            <svg class="nav-dropdown-chevron" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1,1 5,5 9,1"/></svg>
                        </button>
                        <div class="nav-dropdown-menu nav-dropdown-menu--wide">
                            <div class="nav-dropdown-name"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php
                            $prev_admin = false;
                            foreach ($user_items as $it):
                                if (($it['slug'] ?? '') === 'core-signout') continue; // rendered last
                                $is_admin = self::isAdminMenuItem($it);
                                if ($is_admin && !$prev_admin) echo '<div class="nav-dropdown-divider"></div>';
                                $prev_admin = $is_admin;
                                $active = ($it['link'] === $request_path) ? ' class="active"' : '';
                                echo '<a href="' . htmlspecialchars($it['link'], ENT_QUOTES, 'UTF-8') . '"' . $active . '>' . htmlspecialchars($it['label']) . '</a>';
                            endforeach;
                            ?>
                            <div class="nav-dropdown-divider"></div>
                            <a href="/logout">Sign out</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="/login" class="nav-signin<?php echo $request_path === '/login' ? ' active' : ''; ?>">Log in</a>
                    <?php if ($register_link): ?>
                    <a href="<?php echo htmlspecialchars($register_link, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary btn-sm">Sign up</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <button class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
        </div>
    </div>

    <?php $this->render_member_subnav($menu_data); ?>
</nav>
<?php endif; ?>

    <?php
    }

    public function public_footer($options=array()) {
        $settings = Globalvars::get_instance();
        $session = SessionControl::get_instance();
        $session->clear_clearable_messages();

        // The footer carries the wider link set — the audience landing pages the
        // header deliberately leaves out, so the top of the site tells one
        // story. It is this theme's markup rather than site data: the header
        // nav is editable in the admin, the footer is a design decision.
        $footer_links = [
            '/page/apps'                  => 'Apps',
            '/page/install'               => 'Install',
            '/page/pricing'               => 'Pricing',
            '/page/leave-gmail'           => 'Leave Gmail',
            '/page/nextcloud-alternative' => 'Nextcloud Alternative',
            '/page/families'              => 'For Families',
            '/page/small-business'        => 'For Small Businesses',
            '/page/why'                   => 'Why Joinery',
            '/page/about'                 => 'About',
        ];
    ?>

<?php if ($this->show_site_chrome()): ?>
<footer class="site-footer">
    <div class="container">
        <div class="footer-links">
<?php foreach ($footer_links as $footer_url => $footer_label): ?>
            <a href="<?php echo htmlspecialchars($footer_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($footer_label, ENT_QUOTES, 'UTF-8'); ?></a>
<?php endforeach; ?>
<?php if ($settings->get_setting('social_github_link')): ?>
            <a href="<?php echo htmlspecialchars($settings->get_setting('social_github_link')); ?>" target="_blank" rel="noopener">GitHub</a>
<?php endif; ?>
        </div>
        <div class="footer-bottom">
            &copy; <?php echo date('Y'); ?> Joinery. All rights reserved.
            <?php $privacy_url = trim((string)$settings->get_setting('privacy_url')); if ($privacy_url !== ''): ?>
            &middot; <a href="<?php echo htmlspecialchars($privacy_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Privacy</a>
            <?php endif; ?>
            <?php $terms_url = trim((string)$settings->get_setting('terms_url')); if ($terms_url !== ''): ?>
            &middot; <a href="<?php echo htmlspecialchars($terms_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Terms</a>
            <?php endif; ?>
        </div>
    </div>
</footer>
<?php endif; ?>

<script src="/assets/js/joinery-validate.js"></script>
<script src="/theme/getjoinery/assets/js/script.js?v=<?php echo $this->asset_mtime('theme/getjoinery/assets/js/script.js'); ?>"></script>

</body>
</html>

<?php
    }

    static function pagination_list($tmpnumtotal, $numperpage, $currentpage, $qstring=NULL) {
        parse_str($qstring, $current_query);
        unset($current_query['location']);
        unset($current_query['addr_id']);

        $links = array();
        $numpagestotal = ceil($tmpnumtotal/$numperpage);

        if ($tmpnumtotal > $numperpage) {
            $x = $currentpage - 2;

            if ($currentpage > 1) {
                $current_query['pagenum'] = $currentpage - 1;
                $links['Previous']['link'] = '?' . http_build_query($current_query);
                $links['Previous']['current'] = FALSE;
            }

            if ($currentpage > 10) {
                $current_query['pagenum'] = $currentpage - 10;
                $links[$current_query['pagenum']]['link'] = '?' . http_build_query($current_query);
                $links[$current_query['pagenum']]['current'] = FALSE;
                $links['elipse1']['link'] = NULL;
                $links['elipse1']['current'] = FALSE;
            } else if ($currentpage <= 10 && $x > 1) {
                $current_query['pagenum'] = 1;
                $links[$current_query['pagenum']]['link'] = '?' . http_build_query($current_query);
                $links[$current_query['pagenum']]['current'] = FALSE;
                $links['elipse1']['link'] = NULL;
                $links['elipse1']['current'] = FALSE;
            }

            $numprinted = 0;
            while ($numprinted < 5 && $x <= $numpagestotal) {
                if ($x > 0 && $x <= $numpagestotal) {
                    $current_query['pagenum'] = $x;
                    $links[$x]['link'] = '?' . http_build_query($current_query);
                    $links[$x]['current'] = ($x == $currentpage);
                    $numprinted++;
                }
                $x++;
            }

            if ($currentpage + 10 < $numpagestotal) {
                $links['elipse2']['link'] = NULL;
                $links['elipse2']['current'] = FALSE;
                $current_query['pagenum'] = $currentpage + 10;
                $links[$current_query['pagenum']]['link'] = '?' . http_build_query($current_query);
                $links[$current_query['pagenum']]['current'] = FALSE;
            }

            if ($currentpage < $numpagestotal) {
                $current_query['pagenum'] = $currentpage + 1;
                $links['Next']['link'] = '?' . http_build_query($current_query);
                $links['Next']['current'] = FALSE;
            }
        }

        return $links;
    }

    static function write_pagination($page_links) {
        $out = '';
        foreach ($page_links as $pagelabel => $pageinfo) {
            if ($pagelabel && $pageinfo['link']) {
                if ($page_links[$pagelabel]['current']) {
                    $out .= '<span class="currentPage">' . $pagelabel . '</span>';
                } else {
                    $out .= '<a href="' . $pageinfo['link'] . '">' . $pagelabel . '</a>';
                }
            } else if ($pagelabel == 'elipse1' || $pagelabel == 'elipse2') {
                $out .= '<span class="ellipsis">...</span>';
            }
        }
        return $out;
    }
}

?>
