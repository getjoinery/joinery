<?php
/**
 * Connected sites — /profile/server_manager/services
 *
 * Every site linked to this account for Joinery-run services: host, when it
 * was connected, and per service the state, the paid-through date and the
 * figure against the allowance. Disconnect (a POST) cuts a site off.
 *
 * @version 1.0.1 - the page carries its own styles (site block, facts grid, buttons)
 * @version 1.0 - specs/services_phase2_platform.md §4, E6
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_services_logic.php', 'logic', 'system', null, 'server_manager'));

$page_vars = process_logic(profile_services_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$service_labels = array('mail' => 'Outbound email', 'shelf' => 'Backup shelf');
$state_labels = array(
	'unpaid'       => 'Not yet entitled',
	'provisioning' => 'Setting up',
	'active'       => 'Active',
	'suspended'    => 'Stopped — paid-through date passed',
	'released'     => 'Released',
);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Connected sites',
	'breadcrumbs' => array('Your Sites' => '/profile/server_manager', 'Connected sites' => ''),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Connected sites', $hoptions);
?>

<p class="sms-lead">Sites you have linked to this account for Joinery-run services. A linked site holds one
	credential of its own; disconnecting it stops that credential working and releases every service it holds.</p>

<?php if (count($sites) === 0): ?>
	<p>No site is connected to this account. A site connects from its own setup wizard's Email or Backups step.</p>
<?php else: ?>
<section class="sms-sites">
<?php foreach ($sites as $site): ?>
	<article class="sms-site">
		<h3><?php echo htmlspecialchars($site['host']); ?></h3>
		<dl class="sms-facts">
			<dt>Connected</dt>
			<dd><?php echo $site['connected_time'] !== ''
				? htmlspecialchars(LibraryFunctions::convert_time($site['connected_time'], 'UTC', $session->get_timezone(), 'F j, Y'))
				: '—'; ?><?php if (!$site['active']): ?> <span class="sms-note">(key inactive)</span><?php endif; ?></dd>
			<?php foreach ($site['services'] as $service => $s): ?>
			<dt><?php echo htmlspecialchars($service_labels[$service] ?? $service); ?></dt>
			<dd>
				<?php echo htmlspecialchars($state_labels[$s['state']] ?? $s['state']); ?>
				<?php if ($s['paid_until']): ?>
					&mdash; paid through <?php echo htmlspecialchars(LibraryFunctions::convert_time($s['paid_until'], 'UTC', $session->get_timezone(), 'F j, Y')); ?>
				<?php endif; ?>
				<?php if ($s['state'] === 'active' || $s['state'] === 'suspended'): ?>
					<br><span class="sms-note"><?php echo htmlspecialchars($s['label'] . ': ' . $s['used_label'] . ' of ' . $s['allowance_label']); ?></span>
				<?php endif; ?>
				<?php if ($s['notice'] !== ''): ?>
					<br><span class="sms-note"><?php echo htmlspecialchars($s['notice']); ?></span>
				<?php endif; ?>
			</dd>
			<?php endforeach; ?>
		</dl>
		<?php if ($site['active']): ?>
		<div class="sms-actions">
			<?php echo PublicPage::action_button('Disconnect', $self_url, array(
				'hidden'  => array('action' => 'disconnect', 'host' => $site['host']),
				'confirm' => 'Disconnect ' . $site['host'] . '? Its key stops working, its outbound email through getjoinery is closed, and its backup shelf is kept 90 days and then pruned.',
				'class'   => 'sms-secondary-btn',
			)); ?>
		</div>
		<?php endif; ?>
	</article>
<?php endforeach; ?>
</section>
<?php endif; ?>

<style>
.sms-lead { color: #374151; }
.sms-site { padding: 16px 0; border-bottom: 1px solid #e2e2e2; }
.sms-site h3 { margin: 0 0 .25em; }
.sms-facts { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: .5em 0 1em; }
.sms-facts dt { font-weight: 600; }
.sms-facts dd { margin: 0; }
.sms-note { color: #555; font-size: .9em; }
.sms-actions form { display: inline-block; margin-right: .5em; }
.sms-secondary-btn { background: #fff; color: #1f2937; border: 1px solid #cbd5e1; padding: 8px 18px;
	border-radius: 4px; font-size: .95em; cursor: pointer; }
</style>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
