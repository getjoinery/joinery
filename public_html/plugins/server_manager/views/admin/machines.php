<?php
/** @joinery-admin class=config */
/**
 * Server Manager - Machines
 * URL: /admin/server_manager/machines
 *
 * Every machine we pay for, on one list (specs/fleet_security_patching.md
 * WP8, MachineInventory): the machines this management node manages, every
 * server in our own cloud accounts (a server no node runs on is listed as
 * not managed), and the machines written down by hand for accounts no key
 * here can read. Red rows first: a release past standard support, or a
 * retire-by date passed.
 *
 * Reading the providers takes a second or two per account.
 *
 * @version 1.1 - a node with no agent offers Write down: a note is the only thing that can say what it runs
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();

$now = time();
$snap = MachineInventory::snapshot();
$rows = MachineInventory::rows($snap, $now);
$counts = array('red' => 0, 'amber' => 0, 'grey' => 0, 'green' => 0);
foreach ($rows as $r) { $counts[$r['verdict']]++; }
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$tz = $session->get_timezone();

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'server-manager',
	'page_title'     => 'Machines',
	'readable_title' => 'Machines',
	'breadcrumbs'    => array('Server Manager' => '/admin/server_manager', 'Machines' => ''),
	'session'        => $session,
));
$display_messages = $session->get_messages('/admin/server_manager');
foreach ($display_messages as $msg) {
	$cls = ($msg->display_type == DisplayMessage::MESSAGE_ERROR) ? 'alert-danger' : 'alert-success';
	echo '<div class="alert ' . $cls . '" role="alert">' . $h($msg->message)
		. '<button type="button" class="alert-close" aria-label="Close">&times;</button></div>';
}
$session->mark_shown($display_messages);
$page->begin_box(array());
?>
<p>Every machine we pay for: those this management node manages, every server in our own cloud accounts, and those
written down by hand for accounts no key here can read. A server no node manages is listed so it is converted, retired,
or written down. <strong>Red</strong>: an Ubuntu release past standard support, or a retire-by date passed.
<strong>Amber</strong>: a server nothing explains, a node with no agent and nothing written saying what it runs,
or a written-down machine not confirmed for
<?php echo (int)FleetHandMachine::CONFIRM_DAYS; ?> days. The test account's servers are not listed: they are thrown away nightly.</p>

<p>
	<span class="badge bg-danger"><?php echo (int)$counts['red']; ?> red</span>
	<span class="badge bg-warning"><?php echo (int)$counts['amber']; ?> amber</span>
	<span class="badge bg-success"><?php echo (int)$counts['green']; ?> green</span>
	<?php if ($counts['grey']): ?><span class="badge bg-secondary"><?php echo (int)$counts['grey']; ?> not reported</span><?php endif; ?>
	&nbsp;<a class="btn btn-sm btn-outline-secondary" href="/admin/server_manager/machine_edit">Write down a machine</a>
</p>

<?php foreach ($snap['accounts'] as $a): if ($a['error'] === '') { continue; } ?>
	<div class="jy-callout jy-callout-warning"><?php echo $h($a['name']); ?> could not be read: <?php echo $h($a['error']); ?>.
		Its servers are missing from the list until it can.</div>
<?php endforeach; ?>
<?php if (!$snap['accounts']): ?>
	<div class="jy-callout jy-callout-info">No cloud account of ours is held here, so only managed machines and those written down are listed.</div>
<?php endif; ?>

<div class="table-responsive">
<table class="table table-sm align-middle">
	<thead><tr><th></th><th>Machine</th><th>Where</th><th>Operating system</th><th>Made</th><th>What it is</th><th></th></tr></thead>
	<tbody>
	<?php foreach ($rows as $r):
		$badge = array('red' => 'danger', 'amber' => 'warning', 'grey' => 'secondary', 'green' => 'success')[$r['verdict']];
		$hm = $r['hand']; ?>
		<tr>
			<td><span class="badge bg-<?php echo $badge; ?>" title="<?php echo $h(implode('; ', $r['reasons'])); ?>">&bull;</span></td>
			<td><?php if ($r['node_id']): ?><a href="/admin/server_manager/node_detail?mgn_managed_node_id=<?php echo (int)$r['node_id']; ?>"><?php echo $h($r['name']); ?></a>
				<?php else: ?><?php echo $h($r['name']); ?><?php endif; ?>
				<br><small class="text-muted"><?php echo $h(array('managed' => 'managed', 'unmanaged' => 'not managed', 'hand' => 'written down')[$r['kind']]);
					echo $r['state'] !== '' ? ' &middot; ' . $h(ManagedNode::INSTALL_STATES[$r['state']] ?? $r['state']) : '';
					echo $r['address'] !== '' ? ' &middot; ' . $h($r['address']) : '';
					echo $r['instance'] !== '' ? ' &middot; server ' . $h($r['instance']) : ''; ?></small>
				<?php if ($r['reasons']): ?><br><small class="<?php echo $r['verdict'] === 'red' ? 'text-danger' : 'text-muted'; ?>"><?php echo $h(implode('; ', $r['reasons'])); ?></small><?php endif; ?></td>
			<td class="small"><?php echo $h($r['where']); ?></td>
			<td class="small"><?php echo $h($r['os']); ?></td>
			<td class="small"><?php echo $r['created'] !== '' ? $h(LibraryFunctions::time_ago($r['created'], $tz)) : ''; ?></td>
			<td class="small"><?php if ($hm): ?><?php echo $h($hm['purpose']); ?>
				<?php echo $hm['owner'] !== '' ? '<br><span class="text-muted">' . $h($hm['owner']) . '</span>' : ''; ?>
				<?php echo $hm['serves'] !== '' ? '<br><span class="text-muted">serves ' . $h($hm['serves']) . '</span>' : ''; ?>
				<?php echo $hm['retire_by'] !== '' ? '<br><span class="text-muted">retire by ' . $h($hm['retire_by']) . '</span>' : ''; ?><?php endif; ?></td>
			<td class="small"><?php if ($hm): ?><a href="/admin/server_manager/machine_edit?fhm_fleet_hand_machine_id=<?php echo (int)$hm['id']; ?>">Edit</a>
				<?php elseif ($r['kind'] === 'unmanaged' || ($r['kind'] === 'managed' && empty($r['agent']))): ?><a href="/admin/server_manager/machine_edit?ref=<?php echo urlencode($r['ref']); ?>&amp;name=<?php echo urlencode($r['name']); ?>&amp;address=<?php echo urlencode($r['address']); ?>">Write down</a><?php endif; ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<?php
$page->end_box();
$page->admin_footer();
