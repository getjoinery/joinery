<?php
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));

class PublicPage extends PublicPageBase {

	// Implement abstract method from PublicPageBase
	protected function getTableClasses() {
		return [
			'wrapper' => 'uk-overflow-auto',
			'table' => 'uk-table uk-table-striped',
			'header' => 'uk-table-header'
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
		$output = '	<div class="uk-section">
		<div class="uk-container">';

		if($title){
			$output .= '';
		}

		$output .= '';

		return $output;
	}

	public static function EndPage($options=array()) {
		$output = '</div></div>';
		return $output;
	}


	public function public_header($options=array()) {
		$GLOBALS['page_header_loaded'] = true;
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$menu_data = $this->get_menu_data();
		ob_start();
		$options = parent::public_header_common($options);
		$_head_inject = ob_get_clean();

		$site_name = $settings->get_setting('site_name', true, true) ?: 'The Zouk Room';
		$title = isset($options['title']) ? $options['title'] : $site_name;
		?>

<!DOCTYPE html>
<html lang="en-gb" dir="ltr" xmlns="http://www.w3.org/1999/xhtml" xmlns:fb="http://ogp.me/ns/fb#">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?php echo htmlspecialchars(isset($options['description']) ? $options['description'] : ''); ?>">
  <meta name="keywords" content="">

  <title><?php echo htmlspecialchars($title); ?></title>

  <?php echo $_head_inject; ?>
  <?php $this->global_includes_top($options); ?>
  <link href="https://fonts.googleapis.com/css?family=Nunito+Sans:400,600,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/theme/zoukroom-html5/assets/css/main.css" />
  <script src="/theme/zoukroom-html5/assets/js/uikit.js"></script>
  <script src="/assets/js/joinery-validate.js"></script>

  <?php
  if ($settings->get_setting('custom_css')) {
      echo '<style>' . $settings->get_setting('custom_css') . '</style>';
  }
  ?>
</head>

<body class="uk-background-body">

	<?php
	if (empty($options['noheader'])) {
		?>

<header id="header">
	<div data-uk-sticky="animation: uk-animation-slide-top; sel-target: .uk-navbar-container; cls-active: uk-navbar-sticky; cls-inactive: uk-navbar-transparent; top: #header">
	  <nav class="uk-navbar-container uk-letter-spacing-small uk-text-bold">
	    <div class="uk-container">
	      <div class="uk-position-z-index" data-uk-navbar>
	        <div class="uk-navbar-left">
	          <a class="uk-navbar-item uk-logo" href="/"><?php echo htmlspecialchars($site_name); ?></a>
	        </div>
	        <div class="uk-navbar-right">
	          <ul class="uk-navbar-nav uk-visible@m" data-uk-scrollspy-nav="closest: li; scroll: true; offset: 80">
	            <li class="uk-active"><a href="/events">Courses</a></li>
	            <?php
	            if ($session->get_user_id()) {
	                echo '<li><a href="/profile/profile">Profile</a></li>';
	                if ($menu_data['user_menu']['permission_level'] >= 5) {
	                    echo '<li><a href="/admin/admin_users">Admin</a></li>';
	                }
	                $cart = $session->get_shopping_cart();
	                if ($numitems = $cart->count_items()) {
	                    echo '<li><a href="/cart">Cart (' . intval($numitems) . ')</a></li>';
	                }
	                echo '<li><a href="/logout">Log out</a></li>';
	            } else {
	                echo '<li><a href="/login">Log in</a></li>';
	            }
	            ?>
	          </ul>
	          <a class="uk-navbar-toggle uk-hidden@m" href="#offcanvas" data-uk-toggle><span
	            data-uk-navbar-toggle-icon></span></a>
	        </div>
	      </div>
	    </div>
	  </nav>
	</div>
</header>

		<?php
	}
	}

	public function public_footer($options=array()) {
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();

		$site_name = $settings->get_setting('site_name', true, true) ?: 'The Zouk Room';
		$contact_email = $settings->get_setting('contact_email', true, true) ?: $settings->get_setting('defaultemail', true, true);
		$menu_data = $this->get_menu_data();
	?>

<footer class="uk-section uk-section-secondary uk-section-large">
	<div class="uk-container uk-text-muted">
		<div class="uk-child-width-1-2@s uk-child-width-1-5@m" data-uk-grid>
			<div>
				<h5>Dance Links</h5>
				<ul class="uk-list uk-text-small">
					<li><a class="uk-link-muted" href="https://www.danceplace.com/">Danceplace</a></li>
					<li><a class="uk-link-muted" href="http://zoukology.com/">Zoukology</a></li>
				</ul>
			</div>
			<div>
				<h5>To list your course</h5>
				<ul class="uk-list uk-text-small">
					<?php if ($contact_email): ?>
					<li><p>Send an email to <a href="mailto:<?php echo htmlspecialchars($contact_email); ?>"><?php echo htmlspecialchars($contact_email); ?></a> with the course dates, description, link to register, a large picture for the event, and a large picture of your instructor.</p></li>
					<?php endif; ?>
				</ul>
			</div>
			<div>
				<h5>&nbsp;</h5>
				<ul class="uk-list uk-text-small">
					<li><a class="uk-link-muted" href="#">&nbsp;</a></li>
				</ul>
			</div>
			<div>
				<h5>&nbsp;</h5>
				<ul class="uk-list uk-text-small">
					<li><a class="uk-link-muted" href="#">&nbsp;</a></li>
				</ul>
			</div>
			<div>
				<div class="uk-margin">
					<a href="/" class="uk-logo"><?php echo htmlspecialchars($site_name); ?></a>
				</div>
			</div>
		</div>
	</div>
</footer>

<div id="offcanvas" data-uk-offcanvas="flip: true; overlay: true">
  <div class="uk-offcanvas-bar">
    <a class="uk-logo" href="/"><?php echo htmlspecialchars($site_name); ?></a>
    <button class="uk-offcanvas-close" type="button" data-uk-close="ratio: 1.2"></button>
    <ul class="uk-nav uk-nav-primary uk-nav-offcanvas uk-margin-medium-top uk-text-center">
      <?php foreach ($menu_data['main_menu'] as $menu_item): ?>
        <li><a href="<?php echo htmlspecialchars($menu_item['link']); ?>"><?php echo htmlspecialchars($menu_item['name']); ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

</body>

</html>

<?php

	}
}

?>
