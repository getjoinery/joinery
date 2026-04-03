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
				array(
					'title' => $title,
					'showheader' => TRUE
				),
				$options));
		echo PublicPage::BeginPage($header);
		?>
		<p><?php echo $body; ?></p>
		<?php
		echo PublicPage::EndPage();
		$page->public_footer();
		exit;
	}

	public static function BeginPage($title='', $options=array()) {
		$output = '<div class="section-content">
		<article style="position: relative;">';

		if($title) {
			$first_letter = mb_substr($title, 0, 1);
			$output .= '<div class="post-dropcap">' . htmlspecialchars($first_letter) . '</div>
			<div class="post-entry-inner">
				<div class="page-header">
					<h1 class="page-title">' . htmlspecialchars($title) . '</h1>
				</div>';
		} else {
			$output .= '<div class="post-entry-inner">';
		}

		$output .= '<div class="entry-content">';

		return $output;
	}

	public static function EndPage($options=array()) {
		$output = '</div><!-- .entry-content -->
			</div><!-- .post-entry-inner -->
		</article>
	</div><!-- .section-content -->';
		return $output;
	}

	public function public_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		ob_start();
		$options = parent::public_header_common($options);
		$_head_inject = ob_get_clean();

		$menu_data = $this->get_menu_data();
		$site_name = $settings->get_setting('site_name', true, true) ?: 'Jeremy Tunnell';

		$title = isset($options['title']) ? $options['title'] : $site_name;
		$description = isset($options['description']) ? $options['description'] : ($settings->get_setting('site_description', true, true) ?: '');

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

    <link rel="stylesheet" href="/theme/jeremytunnell-html5/assets/css/style.css">

    <?php
    if($settings->get_setting('custom_css')){
        echo '<style>' . $settings->get_setting('custom_css') . '</style>';
    }
    ?>
</head>
<body>

    <!-- Header -->
    <header class="site-header">
        <div class="header-inner">
            <div class="site-branding">
                <a href="/" class="site-logo-icon">J</a>
                <div class="site-title">
                    <a href="/"><?php echo htmlspecialchars($site_name); ?></a>
                </div>
            </div>
            <div class="header-actions">
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </header>

    <!-- Cover -->
    <div class="site-cover"></div>

    <!-- Main content -->
    <div class="typology-wrap">
        <div class="typology-fake-bg">
            <div class="typology-section">

	<?php
	}

	public function public_footer($options=array()) {
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();

		$menu_data = $this->get_menu_data();
		$site_name = $settings->get_setting('site_name', true, true) ?: 'Jeremy Tunnell';
	?>

            </div><!-- .typology-section -->
        </div><!-- .typology-fake-bg -->
    </div><!-- .typology-wrap -->

    <!-- Footer -->
    <footer class="site-footer">
        <div class="footer-inner">
            <div class="footer-bottom">
                <p>Copyright <?php echo htmlspecialchars($site_name); ?></p>
                <p>All rights reserved</p>
                <p><a href="/sitemap">Sitemap</a></p>
            </div>
        </div>
    </footer>

    <!-- Sidebar overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="sidebar-panel" id="sidebarPanel">
        <button class="sidebar-close" id="sidebarClose">&times;</button>
        <div class="sidebar-branding">
            <a href="/" class="site-logo-icon">J</a>
            <div class="site-title"><a href="/"><?php echo htmlspecialchars($site_name); ?></a></div>
        </div>
        <nav class="sidebar-nav">
            <h3>Menu</h3>
            <ul>
<?php
	foreach($menu_data['main_menu'] as $menu_item) {
		echo '<li><a href="' . htmlspecialchars($menu_item['link']) . '">' . htmlspecialchars($menu_item['name']) . '</a>';
		if (!empty($menu_item['submenu'])) {
			echo '<ul>';
			foreach($menu_item['submenu'] as $submenu_item) {
				echo '<li><a href="' . htmlspecialchars($submenu_item['link']) . '">' . htmlspecialchars($submenu_item['name']) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</li>';
	}
?>
            </ul>
        </nav>

        <!-- User menu -->
        <div class="sidebar-contact">
<?php if ($session->is_logged_in()): ?>
            <h4><?php echo htmlspecialchars($menu_data['user_menu']['display_name'] ?? 'Account'); ?></h4>
            <ul style="list-style: none; padding: 0;">
                <li><a href="/profile">Profile</a></li>
<?php if ($menu_data['user_menu']['permission_level'] >= 5): ?>
                <li><a href="/admin/admin_users">Admin</a></li>
<?php endif; ?>
                <li><a href="/logout">Sign out</a></li>
            </ul>
<?php else: ?>
            <h4>Account</h4>
            <p><a href="/login">Login</a></p>
<?php endif; ?>
        </div>

        <div class="sidebar-contact">
            <h4>Get in touch</h4>
            <p>You can reach Jeremy at <?php echo htmlspecialchars($settings->get_setting('defaultemail', true, true) ?: 'jeremy.tunnell@gmail.com'); ?></p>
        </div>
    </div>

    <script>
        const toggle = document.getElementById('sidebarToggle');
        const overlay = document.getElementById('sidebarOverlay');
        const panel = document.getElementById('sidebarPanel');
        const close = document.getElementById('sidebarClose');
        function openSidebar() { overlay.classList.add('active'); panel.classList.add('active'); }
        function closeSidebar() { overlay.classList.remove('active'); panel.classList.remove('active'); }
        if (toggle) toggle.addEventListener('click', openSidebar);
        if (overlay) overlay.addEventListener('click', closeSidebar);
        if (close) close.addEventListener('click', closeSidebar);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
    </script>

</body>
</html>

<?php
	}

	static function pagination_list($tmpnumtotal, $numperpage, $currentpage, $qstring=NULL){
		parse_str($qstring, $current_query);
		unset($current_query['location']);
		unset($current_query['addr_id']);

		$links = array();
		$numpagestotal = ceil($tmpnumtotal/$numperpage);
		$tmpoffset = $currentpage * $numperpage;

		if($tmpnumtotal > $numperpage){
			$x = $currentpage - 2;

			if ($currentpage > 1) {
				$current_query['pagenum'] = $currentpage - 1;
				$links['Previous']['link'] = '?' . http_build_query($current_query);
				$links['Previous']['current'] = FALSE;
			}

			if($currentpage > 10){
				$current_query['pagenum'] = $currentpage - 10;
				$links[$current_query['pagenum']]['link'] = '?' . http_build_query($current_query);
				$links[$current_query['pagenum']]['current'] = FALSE;
				$links['elipse1']['link'] = NULL;
				$links['elipse1']['current'] = FALSE;
			}
			else if($currentpage <= 10 && $x > 1) {
				$current_query['pagenum'] = 1;
				$links[$current_query['pagenum']]['link'] = '?' . http_build_query($current_query);
				$links[$current_query['pagenum']]['current'] = FALSE;
				$links['elipse1']['link'] = NULL;
				$links['elipse1']['current'] = FALSE;
			}

			$numprinted=0;
			while($numprinted < 5 && $x <= $numpagestotal){
				if($x > 0 && $x <= $numpagestotal){
					$current_query['pagenum'] = $x;
					$links[$x]['link'] = '?' . http_build_query($current_query);
					if($x == $currentpage) {
						$links[$x]['current'] = TRUE;
					} else {
						$links[$x]['current'] = FALSE;
					}
					$numprinted++;
				}
				$x++;
			}

			if($currentpage+10 < $numpagestotal){
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
		foreach ($page_links as $pagelabel=>$pageinfo) {
			if($pagelabel && $pageinfo['link']) {
				if($page_links[$pagelabel]['current']) {
					$out .= '<span class="currentPage">' . $pagelabel . '</span>';
				} else {
					$out .= '<a href="' . $pageinfo['link'] . '">' . $pagelabel . '</a>';
				}
			}
			else if($pagelabel == 'elipse1' || $pagelabel == 'elipse2') {
				$out .= '<span class="ellipsis">...</span>';
			}
		}
		return $out;
	}
}

?>
