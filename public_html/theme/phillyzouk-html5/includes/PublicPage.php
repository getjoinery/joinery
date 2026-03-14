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
		$output = '<section class="post-detail-area"><div class="container"><div class="post-content">';
		if($title) {
			$output .= '<div class="post-header"><h1>' . htmlspecialchars($title) . '</h1></div>';
		}
		$output .= '<div class="post-body">';
		return $output;
	}

	public static function EndPage($options=array()) {
		$output = '</div><!-- .post-body -->
		</div><!-- .post-content -->
		</div><!-- .container -->
		</section><!-- .post-detail-area -->';
		return $output;
	}

	public function public_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$options = parent::public_header_common($options);

		$menu_data = $this->get_menu_data();
		$site_name = $settings->get_setting('site_name', true, true) ?: 'Phillyzouk';

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

    <?php $this->global_includes_top($options); ?>

    <link rel="stylesheet" href="/theme/phillyzouk-html5/assets/css/style.css">
    <link rel="icon" type="image/png" href="/theme/phillyzouk-html5/assets/images/favicon.png">

    <?php
    if($settings->get_setting('custom_css')){
        echo '<style>' . $settings->get_setting('custom_css') . '</style>';
    }
    ?>
</head>
<body>

    <!-- Navbar -->
    <div class="nav-area">
        <div class="navbar-area">
            <!-- Mobile -->
            <div class="mobile-nav">
                <a href="/">
                    <?php if ($logo_url = $settings->get_setting('logo_link', true, true)): ?>
                        <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($site_name); ?>">
                    <?php else: ?>
                        <span class="site-name-logo"><?php echo htmlspecialchars($site_name); ?></span>
                    <?php endif; ?>
                </a>
                <button class="menu-toggle">&#9776;</button>
            </div>
            <!-- Desktop -->
            <div class="main-nav">
                <div class="container-fluid">
                    <div class="navbar-inner">
                        <a class="navbar-brand" href="/">
                            <?php if ($logo_url = $settings->get_setting('logo_link', true, true)): ?>
                                <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($site_name); ?>">
                            <?php else: ?>
                                <span class="site-name-logo"><?php echo htmlspecialchars($site_name); ?></span>
                            <?php endif; ?>
                        </a>
                        <ul class="navbar-nav">
<?php
	foreach($menu_data['main_menu'] as $menu_item) {
		$has_submenu = !empty($menu_item['submenu']);
		echo '<li class="nav-item">';
		echo '<a href="' . htmlspecialchars($menu_item['link']) . '">' . htmlspecialchars($menu_item['name']);
		if ($has_submenu) {
			echo ' <i>&#9660;</i>';
		}
		echo '</a>';
		if ($has_submenu) {
			echo '<ul class="dropdown-menu">';
			foreach($menu_item['submenu'] as $submenu_item) {
				echo '<li><a href="' . htmlspecialchars($submenu_item['link']) . '">' . htmlspecialchars($submenu_item['name']) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</li>';
	}
?>
                        </ul>
                        <div class="others-option">
<?php if ($session->is_logged_in()): ?>
                            <div class="follow">
                                <?php echo htmlspecialchars($menu_data['user_menu']['display_name'] ?? 'Account'); ?> &#9660;
                                <ul>
                                    <li><a href="/profile">Profile</a></li>
<?php if ($menu_data['user_menu']['permission_level'] >= 5): ?>
                                    <li><a href="/admin/admin_users">Admin</a></li>
<?php endif; ?>
                                    <li><a href="/logout">Sign out</a></li>
                                </ul>
                            </div>
<?php else: ?>
                            <div class="follow">
                                Account &#9660;
                                <ul>
                                    <li><a href="/login">Login</a></li>
                                </ul>
                            </div>
<?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

	<?php
	}

	public function public_footer($options=array()) {
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();

		$site_name = $settings->get_setting('site_name', true, true) ?: 'Phillyzouk';
	?>

    <!-- Footer Top -->
    <footer class="footer-top-area">
        <div class="container">
            <div class="row">
                <div class="col-lg-6 col-md-6">
                    <div class="single-widget">
                        <?php if ($logo_url = $settings->get_setting('logo_link', true, true)): ?>
                            <a href="/"><img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($site_name); ?>"></a>
                        <?php else: ?>
                            <h3><?php echo htmlspecialchars($site_name); ?></h3>
                        <?php endif; ?>
                        <p><?php echo htmlspecialchars($settings->get_setting('site_description', true, true)); ?></p>
                        <div class="social-area">
                            <ul>
                                <?php if ($settings->get_setting('social_facebook_link')): ?>
                                <li><a href="<?php echo htmlspecialchars($settings->get_setting('social_facebook_link')); ?>" target="_blank">f</a></li>
                                <?php endif; ?>
                                <?php if ($settings->get_setting('social_twitter_link')): ?>
                                <li><a href="<?php echo htmlspecialchars($settings->get_setting('social_twitter_link')); ?>" target="_blank">t</a></li>
                                <?php endif; ?>
                                <?php if ($settings->get_setting('social_linkedin_link')): ?>
                                <li><a href="<?php echo htmlspecialchars($settings->get_setting('social_linkedin_link')); ?>" target="_blank">in</a></li>
                                <?php endif; ?>
                                <?php if ($settings->get_setting('social_youtube_link')): ?>
                                <li><a href="<?php echo htmlspecialchars($settings->get_setting('social_youtube_link')); ?>" target="_blank">&#9654;</a></li>
                                <?php endif; ?>
                                <?php if ($settings->get_setting('social_instagram_link')): ?>
                                <li><a href="<?php echo htmlspecialchars($settings->get_setting('social_instagram_link')); ?>" target="_blank">ig</a></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 col-md-6">
                    <div class="single-widget contact">
                        <h3>Get In Touch</h3>
                        <ul>
                            <?php if ($phone = $settings->get_setting('contact_phone', true, true)): ?>
                            <li>
                                <span class="ci">&#128222;</span>
                                <span>Phone:</span>
                                <?php echo htmlspecialchars($phone); ?>
                            </li>
                            <?php endif; ?>

                            <?php if ($email = $settings->get_setting('contact_email', true, true) ?: $settings->get_setting('defaultemail', true, true)): ?>
                            <li>
                                <span class="ci">&#9993;</span>
                                <span>Email:</span>
                                <a href="mailto:<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></a>
                            </li>
                            <?php endif; ?>

                            <?php if ($address = $settings->get_setting('contact_address', true, true)): ?>
                            <li>
                                <span class="ci">&#128205;</span>
                                <span>Address:</span>
                                <?php echo htmlspecialchars($address); ?>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Footer Bottom -->
    <footer class="footer-bottom-area">
        <div class="container">
            <div class="copy-right">
                <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_name); ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <div class="go-top">&#8679;</div>

    <script src="/theme/phillyzouk-html5/assets/js/script.js"></script>
    <script>
        // Mobile menu toggle
        const menuToggle = document.querySelector('.menu-toggle');
        const mainNav = document.querySelector('.main-nav');
        if (menuToggle && mainNav) {
            menuToggle.addEventListener('click', function() {
                mainNav.classList.toggle('open');
            });
        }
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
