<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('test_logic.php', 'logic', 'system', null, 'dns_filtering'));

$page_vars     = process_logic(test_logic(array_merge($_GET, $_POST, $params ?? [])));
$device        = $page_vars['device'];
$device_id     = (int)$device->key;
$device_name   = htmlspecialchars($device->get_readable_name());
$is_active     = $device->get('sdd_is_active');
$can_add_rules = $page_vars['can_add_rules'];

$page    = new PublicPage();
$hoptions = array(
	'is_valid_page' => $is_valid_page,
	'title'         => 'Test a Domain/Page',
	'breadcrumbs'   => array(
		'Devices'            => '/profile/dns_filtering/devices',
		'Test a Domain/Page' => '',
	),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Test a Domain/Page', $hoptions);

if (!$is_active) {
	echo '
	<section class="space">
		<div class="container">
			<div class="error-content">
				<h2 class="error-title">Device Not Activated</h2>
				<p class="error-text">The domain test feature requires an activated device. Please activate <strong>' . $device_name . '</strong> first.</p>
				<a href="/profile/dns_filtering/activation?device_id=' . $device_id . '" class="th-btn">Activate Device</a>
			</div>
		</div>
	</section>';
} else {
	echo '
	<section class="space">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-8">

					<div class="job-post style2 dnsf-mb20">
						<div class="job-content">
							<h3 class="box-title">' . $device_name . '</h3>
							<p class="dnsf-test-sub">Enter a domain to check how your filter handles it, or paste a full page URL to scan all domains that page loads.</p>
						</div>
						<div class="job-post_author dnsf-test-inputrow">
							<div class="job-wrapp dnsf-test-inputwrap">
								<input type="text" id="scd-test-input" placeholder="e.g. facebook.com or https://example.com/page" class="dnsf-test-input">
							</div>
							<button type="button" id="scd-test-btn" class="th-btn">Test</button>
						</div>
					</div>

					<div id="scd-result" hidden></div>

				</div>
			</div>
		</div>
	</section>';
}

echo PublicPage::EndPage();
?>
<?php echo PageScript::json('domain-test-config', array('device_id' => (int)$device_id, 'can_add_rules' => !empty($can_add_rules))), PageScript::src('plugins/dns_filtering/assets/js/domain-test.js'); ?>
<?php
	$page->public_footer($foptions = array('track' => TRUE, 'show_survey' => TRUE));
?>
