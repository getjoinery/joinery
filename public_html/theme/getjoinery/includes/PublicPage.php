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

    public function public_header($options=array()) {
        $_GLOBALS['page_header_loaded'] = true;
        $settings = Globalvars::get_instance();
        $session = SessionControl::get_instance();
        ob_start();
        $options = parent::public_header_common($options);
        $_head_inject = ob_get_clean();

        $menu_data = $this->get_menu_data();
        $site_name = $settings->get_setting('site_name', true, true) ?: 'Joinery';

        $title = isset($options['title']) ? $options['title'] : $site_name;
        $description = isset($options['description']) ? $options['description'] : ($settings->get_setting('site_description', true, true) ?: 'Membership software you can trust with your data.');

        // Determine active page for nav highlighting
        $request_path = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';

        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($description); ?>">
    <title><?php echo htmlspecialchars($title); ?></title>

    <?php echo $_head_inject; ?>
    <?php $this->global_includes_top($options); ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/theme/getjoinery/assets/css/style.css?v=3">

    <?php
    if ($settings->get_setting('custom_css')) {
        echo '<style>' . $settings->get_setting('custom_css') . '</style>';
    }
    ?>
</head>
<body>

<nav class="site-nav">
    <div class="container">
        <a href="/" class="nav-logo">Joinery</a>

        <div class="nav-links" id="nav-links">
            <a href="/features"<?php echo $request_path === '/features' ? ' class="active"' : ''; ?>>Features</a>
            <a href="/pricing"<?php echo $request_path === '/pricing' ? ' class="active"' : ''; ?>>Pricing</a>
            <a href="/developers"<?php echo $request_path === '/developers' ? ' class="active"' : ''; ?>>Developers</a>
            <a href="/showcase"<?php echo $request_path === '/showcase' ? ' class="active"' : ''; ?>>Showcase</a>
            <a href="/philosophy"<?php echo $request_path === '/philosophy' ? ' class="active"' : ''; ?>>Philosophy</a>
            <a href="/about"<?php echo $request_path === '/about' ? ' class="active"' : ''; ?>>About</a>
            <?php // TODO: Re-enable demo/signup button when ready ?>
            <?php // <a href="/login" class="btn btn-primary btn-sm">Demo</a> ?>
        </div>

        <button class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
    </div>
</nav>

    <?php
    }

    public function public_footer($options=array()) {
        $settings = Globalvars::get_instance();
        $session = SessionControl::get_instance();
        $session->clear_clearable_messages();
    ?>

<footer class="site-footer">
    <div class="container">
        <div class="footer-links">
            <a href="/features">Features</a>
            <a href="/pricing">Pricing</a>
            <a href="/developers">Developers</a>
            <a href="/showcase">Showcase</a>
            <a href="/philosophy">Philosophy</a>
            <a href="/about">About</a>
<?php if ($settings->get_setting('social_github_link')): ?>
            <a href="<?php echo htmlspecialchars($settings->get_setting('social_github_link')); ?>" target="_blank">GitHub</a>
<?php else: ?>
            <a href="https://github.com/getjoinery/joinery" target="_blank">GitHub</a>
<?php endif; ?>
        </div>
        <div class="footer-bottom">
            &copy; <?php echo date('Y'); ?> Joinery. All rights reserved.
            &middot; <a href="/privacy">Privacy</a>
            &middot; <a href="/terms">Terms</a>
        </div>
    </div>
</footer>

<script src="/assets/js/joinery-validate.js"></script>
<script src="/theme/getjoinery/assets/js/script.js"></script>

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
