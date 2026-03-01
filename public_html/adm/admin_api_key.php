<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_api_key_logic.php'));

	$page_vars = process_logic(admin_api_key_logic($_GET, $_POST));

	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'urls',
		'page_title' => 'ApiKeys',
		'readable_title' => 'ApiKeys',
		'breadcrumbs' => array(
			'ApiKeys'=>'/admin/admin_api_keys',
			'ApiKey' => '',
		),
		'session' => $session,
	)
	);

	$options['title'] = 'ApiKey';
	$options['altlinks'] = array('Edit'=>'/admin/admin_api_key_edit?apk_api_key_id='.$api_key->key);
	if(!$api_key->get('apk_delete_time')){
		$options['altlinks']['Soft Delete'] = '/admin/admin_api_key?action=soft_delete&apk_api_key_id='.$api_key->key;
	}
	else{
		$options['altlinks']['Undelete'] = '/admin/admin_api_key?action=undelete&apk_api_key_id='.$api_key->key;
	}

	// Add regenerate secret option (not shown when secret is currently displayed)
	if(!$api_key->get('apk_delete_time') && !(isset($_SESSION['new_api_secret']) && isset($_SESSION['new_api_key_id']) && $_SESSION['new_api_key_id'] == $api_key->key)){
		$options['altlinks']['Regenerate Secret'] = '/admin/admin_api_key?action=regenerate_secret&apk_api_key_id='.$api_key->key;
	}

	if($_SESSION['permission'] >= 8) {
		$options['altlinks'] += array('Permanent Delete' => '/admin/admin_api_key?action=permanent_delete&apk_api_key_id='.$api_key->key);
	}

	$page->begin_box($options);

	// Check if there's a newly created secret to display
	$show_secret = false;
	$plain_secret = null;

	if(isset($_SESSION['new_api_secret']) &&
	   isset($_SESSION['new_api_key_id']) &&
	   $_SESSION['new_api_key_id'] == $api_key->key) {

		$show_secret = true;
		$plain_secret = $_SESSION['new_api_secret'];

		// Clear from session immediately
		unset($_SESSION['new_api_secret']);
		unset($_SESSION['new_api_key_id']);
	}

	// Display one-time secret warning box if applicable
	if($show_secret) {
		echo '<div class="alert alert-warning" style="background-color: #fff3cd; border: 2px solid #ffc107; padding: 20px; margin-bottom: 20px;">';
		echo '<h4 style="color: #856404; margin-top: 0;">⚠️ Important: Save Your Secret Key</h4>';
		echo '<p style="color: #856404;"><strong>This is the ONLY time you will see the plain text secret key. Save it now in a secure location.</strong></p>';
		echo '<div style="background-color: #fff; padding: 15px; border: 1px solid #ffc107; margin: 10px 0; font-family: monospace; font-size: 16px; word-break: break-all;">';
		echo '<strong>Secret Key:</strong> ' . htmlspecialchars($plain_secret);
		echo '</div>';
		echo '<p style="color: #856404;">Store securely. If lost, regenerate it. Never expose in client-side code.</p>';
		echo '<button class="btn btn-primary" onclick="this.parentElement.style.display=\'none\'">I have saved the secret key</button>';
		echo '</div>';
	}

	echo '<h3>'.$api_key->get('apk_name').'</h3>';

	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($api_key->get('apk_create_time'), 'UTC', $session->get_timezone()) .'<br />';

	$rowvalues = array();

	echo '<strong>Public key:</strong> '. $api_key->get('apk_public_key').'<br>';

	// Display secret key status
	if($show_secret) {
		echo '<strong>Secret key:</strong> <em style="color: #28a745;">Displayed above - save it now!</em><br>';
	} else {
		echo '<strong>Secret key:</strong> <em style="color: #6c757d;">Hidden for security (cannot be retrieved)</em><br>';
	}

	echo '<strong>Owner:</strong> '. $owner->display_name().'<br>';

	if($api_key->get('apk_start_time')){
		echo '<strong>Starts:</strong> '. LibraryFunctions::convert_time($api_key->get('apk_start_time'), "UTC", $session->get_timezone(), 'M j, Y').'<br>';
	}

	if($api_key->get('apk_expires_time')){
		echo '<strong>Expires:</strong> '. LibraryFunctions::convert_time($api_key->get('apk_expires_time'), "UTC", $session->get_timezone(), 'M j, Y').'<br>';
	}

	if($api_key->get('apk_delete_time')){
		echo '<strong>Status:</strong> <b>Deleted</b>';
	}
	else if(!$api_key->get('apk_is_active')){
		echo '<strong>Status:</strong> <b>Inactive</b>';
	}
	else if($api_key->get('apk_expires_time') && $api_key->get('apk_expires_time') < $now_utc){
		echo '<strong>Status:</strong> <b>Expired</b>';
	}
	else if($api_key->get('apk_start_time') && $api_key->get('apk_start_time') > $now_utc){
		echo '<strong>Status:</strong> <b>Scheduled</b>';
	}
	else{
		echo '<strong>Status:</strong> <b>Active</b>';
	}

	echo '<br />';
	$page->end_box();

?>
<script>
document.addEventListener('DOMContentLoaded', function() {
	const regenerateLink = document.querySelector('a[href*="action=regenerate_secret"]');
	if(regenerateLink) {
		regenerateLink.addEventListener('click', function(e) {
			if(!confirm('Regenerate secret key?\n\nThis will invalidate the current secret key immediately. Any applications using the old secret will stop working.\n\nYou will be shown the new secret key ONE TIME only.\n\nContinue?')) {
				e.preventDefault();
			}
		});
	}
});
</script>
<?php
	$page->admin_footer();
?>

