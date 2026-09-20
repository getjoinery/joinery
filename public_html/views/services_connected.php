<?php
/**
 * services_connected — the landing for the operator's Connect redirect
 * (/services_connected). Reached by auto-discovery.
 *
 * All work happens in services_connected_logic, which redirects to the
 * wizard step on every completed landing; this body renders only when the
 * logic refused the landing outright (wrong origin, not the owner, not a
 * navigation).
 *
 * @version 1.0 - specs/services_phase2_platform.md §4
 */
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('services_connected_logic.php', 'logic'));

	$page_vars = process_logic(services_connected_logic(array_merge($_GET, $params ?? [])));

	$page = new PublicPage();
	$page->public_header([
		'is_valid_page' => true,
		'title'         => 'Connection problem',
		'header_only'   => true,
	]);
?>

<div class="jy-ui">
<div class="auth-page">
	<div class="auth-card">
		<div class="auth-logo">
			<a href="/"><?php $page->get_logo(); ?></a>
		</div>
		<h3>We couldn&rsquo;t complete that connection</h3>
		<p><?php echo htmlspecialchars((string)($page_vars['error'] ?? 'Start again from the setup wizard\'s Email or Backups step.')); ?></p>
		<p><a href="/setup?step=mail_send" class="btn btn-primary">Back to setup</a></p>
	</div>
</div>
</div>

<?php $page->public_footer(array('header_only' => true)); ?>
