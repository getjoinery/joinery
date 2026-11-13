<?php
/**
 * Link this site — /services/authorize
 *
 * One card: the site asking, the account it will be linked to, one Approve
 * button (a POST) and a Cancel that sends the visitor back to the site with
 * nothing minted. The key never appears on this page.
 *
 * @version 1.3 - Cancel is a submit button posting decision=decline (no inline handler)
 * @version 1.2 - the linking site's origin is allowed in form-action for this render, so Approve's
 *                redirect to it is not blocked by the content security policy
 * @version 1.1 - not found where this site does not sell hosting (SmHosting)
 * @version 1.0.1 - the two buttons stack full-width; the lead paragraph is styled here, not by the start page
 * @version 1.0 - specs/services_phase2_platform.md §4 (the Connect flow)
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('services_authorize_logic.php', 'logic', 'system', null, 'server_manager'));

// A buyer's page exists only where this site sells hosting (SmHosting).
SmHosting::not_found_unless_enabled();

$page_vars = process_logic(services_authorize_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
// Approve answers with a redirect to the site's return address, carrying its
// key; form-action governs that hop, so the site's origin is allowed for this
// render. Only once the logic has accepted the address (same host, https).
if ($problem === '') {
	$return_parts = parse_url((string)$return);
	if (is_array($return_parts) && !empty($return_parts['scheme']) && !empty($return_parts['host'])) {
		$page->allow_form_action_origin($return_parts['scheme'] . '://' . $return_parts['host']
			. (isset($return_parts['port']) ? ':' . (int)$return_parts['port'] : ''));
	}
}
$page->public_header(array(
	'is_valid_page' => true,
	'title'         => 'Link your site',
	'header_only'   => true,
));
?>

<div class="jy-ui">
<div class="auth-page sms-start-page">
	<div class="auth-card sms-start-card">

		<div class="auth-logo">
			<a href="/"><?php $page->get_logo(); ?></a>
		</div>

		<h3>Link your site</h3>

		<?php if ($problem !== ''): ?>
			<?php echo PublicPage::alert('Cannot link this site', $problem, 'warn'); ?>
		<?php else: ?>
			<p class="sms-start-lead">
				<strong><?php echo htmlspecialchars($host); ?></strong> is asking to use your account's services
				&mdash; outbound email and backup storage &mdash; as
				<strong><?php echo htmlspecialchars($account); ?></strong>.
				<?php if ($already): ?>
					This site is already linked to this account; approving again replaces its key.
				<?php endif; ?>
				Approving sends the site one credential of its own, which you can cut off at any time from
				<a href="/profile/server_manager/services">your connected sites</a>.
			</p>
			<?php
			$fw = $page->getFormWriter('services_authorize', array('action' => '/services/authorize', 'method' => 'POST'));
			$fw->begin_form();
			$fw->hiddeninput('site', '', array('value' => $host));
			$fw->hiddeninput('return', '', array('value' => $return));
			$fw->hiddeninput('state', '', array('value' => $state));
			$fw->hiddeninput('decision', '', array('value' => 'approve'));
			?>
			<div class="sms-actions">
				<?php $fw->submitbutton('btn_approve', 'Link this site', array('class' => 'btn btn-primary')); ?>
				<?php // Cancel posts decision=decline after the hidden approve: the later value wins. ?>
				<button type="submit" class="btn btn-soft-default jy-w-full sms-cancel" name="decision" value="decline" formnovalidate>Cancel</button>
			</div>
			<?php $fw->end_form(); ?>
		<?php endif; ?>
	</div>
</div>
</div>

<style>
.sms-start-lead { color: #374151; margin: 0 0 1.25rem; }
.jy-ui .sms-actions .sms-cancel { margin-top: .5rem; }
</style>

<?php $page->public_footer(array('header_only' => true)); ?>
