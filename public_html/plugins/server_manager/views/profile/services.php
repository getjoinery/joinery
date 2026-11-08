<?php
/**
 * Connected sites — /profile/server_manager/services
 *
 * Every site linked to this account for Joinery-run services: host, when it
 * was connected, and per service the state, the paid-through date and the
 * figure against the allowance. Disconnect (a POST) cuts a site off.
 *
 * @version 1.3 - not found where this site does not sell hosting (SmHosting)
 * @version 1.3 - a row paid for that the site has not switched on says so, not 'Not yet entitled'
 * @version 1.2 - the services are bought here, per site: Buy, Keep going, or renewing by subscription
 * @version 1.1 - a site moved off Managed reads as such, with no Disconnect
 * @version 1.0.1 - the page carries its own styles (site block, facts grid, buttons)
 * @version 1.0 - specs/services_phase2_platform.md §4, E6
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_services_logic.php', 'logic', 'system', null, 'server_manager'));

// A buyer's page exists only where this site sells hosting (SmHosting).
SmHosting::not_found_unless_enabled();

$page_vars = process_logic(profile_services_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$service_labels = array('mail' => 'Outbound email', 'shelf' => 'Backup storage');
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

<?php if ($offers): ?>
<p class="sms-lead">getjoinery's email and backup storage for one self-hosted site:
	<strong><?php echo htmlspecialchars($offers[0]['price']); ?></strong>, with <?php echo htmlspecialchars($includes); ?>. Your own accounts work just as well: your site's Email and Backups setup steps take either.</p>
<?php endif; ?>

<?php if (count($sites) === 0): ?>
	<p>No site is connected to this account. A site connects from its own setup wizard's Email or Backups step;
		choose getjoinery there, and the site appears here to buy for.</p>
<?php else: ?>
<section class="sms-sites">
<?php foreach ($sites as $site): ?>
	<article class="sms-site">
		<h3><?php echo htmlspecialchars($site['host']); ?></h3>
		<dl class="sms-facts">
			<?php if ($site['node_linked']): ?>
			<dt>Connected</dt>
			<dd>Moved from Managed — mail and backups through us</dd>
			<?php else: ?>
			<dt>Connected</dt>
			<dd><?php echo $site['connected_time'] !== ''
				? htmlspecialchars(LibraryFunctions::convert_time($site['connected_time'], 'UTC', $session->get_timezone(), 'F j, Y'))
				: '—'; ?><?php if (!$site['active']): ?> <span class="sms-note">(key inactive)</span><?php endif; ?></dd>
			<?php endif; ?>
			<?php foreach ($site['services'] as $service => $s): ?>
			<dt><?php echo htmlspecialchars($service_labels[$service] ?? $service); ?></dt>
			<dd>
				<?php // Paid, and the site has not asked since: not "not entitled".
				echo htmlspecialchars(($s['state'] === 'unpaid' && !empty($s['entitled']))
					? 'Paid, not switched on yet: your site\'s setup step does that'
					: ($state_labels[$s['state']] ?? $s['state'])); ?>
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
		<?php if ($site['card'] === 'renewing'): ?>
		<p class="sms-note">Paid by a subscription that renews on its own. To stop it, cancel it on
			<a href="/profile/subscriptions">your subscriptions</a>; the services run to the end of the period paid for.</p>
		<?php elseif ($site['card'] === 'buy' || $site['card'] === 'keep'): ?>
		<div class="sms-actions">
			<?php foreach ($offers as $offer): ?>
				<?php echo JoineryServicesFulfillment::buy_form($offer, (int)$site['row_id'],
					($site['card'] === 'keep' ? 'Keep going' : 'Buy') . ' — ' . $offer['label'] . ', ' . $offer['price']); ?>
			<?php endforeach; ?>
		</div>
		<p class="sms-note">Once it is paid, go back to your site's setup and press Save on the Email step, or
			Store backups with getjoinery on the Backups step.</p>
		<?php endif; ?>
		<?php if ($site['node_linked']): ?>
		<p class="sms-note">To send email another way, use your site's own setup wizard (its Email step) to switch to
			your own email account, then tell us and we close this one.</p>
		<?php elseif ($site['active']): ?>
		<div class="sms-actions">
			<?php echo PublicPage::action_button('Disconnect', $self_url, array(
				'hidden'  => array('action' => 'disconnect', 'host' => $site['host']),
				'confirm' => 'Disconnect ' . $site['host'] . '? Its key stops working, its outbound email through getjoinery is closed, and its backup storage is kept 90 days and then pruned.',
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
.sms-buy-btn { background: #1f2937; color: #fff; border: 1px solid #1f2937; padding: 8px 18px;
	border-radius: 4px; font-size: .95em; cursor: pointer; }
.sms-secondary-btn { background: #fff; color: #1f2937; border: 1px solid #cbd5e1; padding: 8px 18px;
	border-radius: 4px; font-size: .95em; cursor: pointer; }
</style>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
