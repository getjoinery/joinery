<?php
/**
 * Inbound Email - Accounts
 *
 * One consolidated tree of everything mail routing touches: each domain, the
 * mailboxes (aliases) under it, the forwarding destinations per mailbox, and
 * the IMAP feed bound to a mailbox (superadmin only). A domain is either
 * MX-hosted (mail pushed in) or an IMAP source (mail pulled in per mailbox) —
 * same nesting either way, so a polled Gmail is just the domain "gmail.com"
 * flagged as an IMAP source with its accounts as mailboxes beneath it.
 *
 * The page is an overview + entry point: + Domain / + Mailbox / + IMAP feed and
 * every Edit jump to the existing per-object editors with context pre-filled.
 * DNS/host diagnostics live on the Setup tab.
 *
 * @version 1.5
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_accounts_logic.php'));

$page_vars = process_logic(admin_mailbox_accounts_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox_accounts',
		'Accounts' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Accounts');

// Flash messages render in the AdminPage header (admin pages must not
// fetch or render session messages themselves).

// How mail reaches this server is a deployment fact, not a question every page
// has to have answered first: an undecided deployment receives directly and
// works. The choice lives in the Setup tab's Advanced section
// (specs/mailbox_relay_surface_simplification.md).
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));

$domain_base  = '/plugins/mailbox/admin/admin_mailbox_domains';
$alias_base   = '/plugins/mailbox/admin/admin_mailbox_alias';
$alias_action = '/plugins/mailbox/admin/admin_mailbox';
$imap_edit    = '/plugins/mailbox/admin/admin_mailbox_imap_edit';
$imap_action  = '/plugins/mailbox/admin/admin_mailbox_imap';

// Delivery-mode label for a mailbox row.
$mode_label = function ($alias) {
	$mode = $alias->get('iea_delivery_mode') ?: InboundEmailAlias::MODE_FORWARD;
	$dests = $alias->get_destinations_array();
	$dest_str = $dests ? htmlspecialchars(implode(', ', $dests)) : '<em>no destinations</em>';
	if ($mode === InboundEmailAlias::MODE_STORE) {
		return '<span class="iea-tag iea-tag-store">Mailbox</span>';
	}
	if ($mode === InboundEmailAlias::MODE_FORWARD_AND_STORE) {
		return '<span class="iea-tag iea-tag-store">Mailbox</span> '
			. '<span class="iea-tag iea-tag-fwd">Forward</span> &rarr; ' . $dest_str;
	}
	return '<span class="iea-tag iea-tag-fwd">Forward</span> &rarr; ' . $dest_str;
};

// Connect/Reconnect affordance for an OAuth feed: "Connect" when never connected,
// "Reconnect" only when the stored token is known-broken, and nothing at all when
// the connection is healthy.
$connect_button = function ($imap) use ($imap_action) {
	if (!$imap || !$imap->isOAuth()) { return; }
	if (!$imap->hasOAuthToken()) {
		$label = 'Connect';
	} elseif ($imap->needsReauth()) {
		$label = 'Reconnect';
	} else {
		return;
	}
	echo PublicPageBase::action_button($label, $imap_action, array(
		'hidden' => array('action' => 'connect', 'iia_inbound_imap_account_id' => $imap->key),
		'class' => 'btn btn-sm btn-warning',
	));
};
?>

<div class="iea-acct">
<?php if (empty($tree)): ?>
	<div class="alert alert-info">No domains yet. Add one to start receiving mail &mdash; a domain you host (MX),
		or an IMAP source like <code>gmail.com</code> to pull mail from an existing mailbox.</div>
<?php else: ?>
	<?php foreach ($tree as $node):
		$domain = $node['domain'];
		$is_imap_src = (bool)$domain->get('ied_is_imap_source');
		$enabled = (bool)$domain->get('ied_is_enabled');
	?>
	<div class="iea-domain">
		<div class="iea-domain-head">
			<span class="iea-domain-name"><?php echo htmlspecialchars($domain->get('ied_domain')); ?></span>
			<?php if ($is_imap_src): ?>
				<span class="iea-badge iea-badge-imapsrc" title="Mail is pulled in by IMAP poll; no MX needed">IMAP source</span>
			<?php else: ?>
				<span class="iea-badge <?php echo $enabled ? 'iea-badge-on' : 'iea-badge-off'; ?>">
					<?php echo $enabled ? 'Enabled' : 'Disabled'; ?></span>
			<?php endif; ?>
			<?php
			// Protection level (specs/mailbox_protection_ceremony.md): every domain
			// shows its level, and the badge IS the path to raising it — click
			// through to the domain editor's ceremony.
			$level = $domain->security_level();
			echo ' <a class="iea-badge iea-badge-level iea-badge-level-' . htmlspecialchars($level)
				. '" href="' . $domain_base . '?ied_inbound_email_domain_id=' . (int)$domain->key . '"'
				. ' title="Mail protection level — click to change">' . htmlspecialchars(ucfirst($level)) . '</a>';
			?>
			<span class="iea-spacer"></span>
			<a class="btn btn-sm btn-outline-secondary"
			   href="<?php echo $domain_base . '?ied_inbound_email_domain_id=' . $domain->key; ?>">Edit domain</a>
			<?php
			// Under an IMAP-source domain, adding a mailbox sets up the mailbox AND
			// its IMAP feed in one form; a hosted domain just needs the alias.
			$add_mailbox_url = $is_imap_src
				? ($imap_edit . '?domain_id=' . $domain->key)
				: ($alias_base . '?domain_id=' . $domain->key);
			?>
			<a class="btn btn-sm btn-outline-primary"
			   href="<?php echo $add_mailbox_url; ?>">+ Mailbox</a>
			<?php
			echo PublicPageBase::action_button('Delete', $domain_base, array(
				'hidden' => array('action' => 'delete', 'ied_inbound_email_domain_id' => $domain->key),
				'confirm' => 'Delete this domain and all its mailboxes?',
				'class' => 'btn btn-sm btn-outline-danger',
			));
			?>
		</div>

		<?php if (empty($node['mailboxes'])): ?>
			<div class="iea-empty-mb">No mailboxes under this domain yet.</div>
		<?php else: ?>
		<ul class="iea-mailboxes">
			<?php foreach ($node['mailboxes'] as $mb):
				$alias = $mb['alias'];
				$imap = $mb['imap'];
			?>
			<li class="iea-mailbox">
				<div class="iea-mb-main">
					<div class="iea-mb-addr"><?php echo htmlspecialchars($alias->get_full_address()); ?>
						<?php if (!$alias->get('iea_is_enabled')): ?>
							<span class="iea-badge iea-badge-off">disabled</span>
						<?php endif; ?>
						<?php // A hint, not a verdict: it says go and look, and the Setup
						      // tab is what actually checks. See specs/mailbox_setup_verdicts.md.
						      $hint = $setup_hints[(int)$alias->key] ?? null;
						      if ($hint): ?>
							<a class="iea-badge iea-badge-warn"
							   href="<?php echo htmlspecialchars($hint['url']); ?>"
							   title="<?php echo htmlspecialchars($hint['title']); ?>"><?php echo htmlspecialchars($hint['text']); ?></a>
						<?php endif; ?>
						<?php $mb_level = $domain->security_level();
						if ($mb_level !== InboundEmailDomain::LEVEL_STANDARD): ?>
							<a class="iea-badge iea-badge-level iea-badge-level-<?php echo htmlspecialchars($mb_level); ?>"
							   href="<?php echo $domain_base . '?ied_inbound_email_domain_id=' . (int)$domain->key; ?>"
							   title="Mail protection level — set on the domain"><?php echo htmlspecialchars(ucfirst($mb_level)); ?></a>
						<?php endif; ?>
					</div>
					<div class="iea-mb-route"><?php echo $mode_label($alias); ?></div>
					<?php if ($can_imap && $imap):
						$plabel = $presets[$imap->get('iia_provider_key')]['label'] ?? $imap->get('iia_provider_key');
						$conn_badge = '';
						if ($imap->isOAuth()) {
							$conn_badge = $imap->hasOAuthToken()
								? ' <span class="iea-badge iea-badge-conn">Connected</span>'
								: ' <span class="iea-badge iea-badge-noconn">Not connected</span>';
						}
					?>
						<div class="iea-mb-imap">
							&#8623; IMAP feed: <strong><?php echo htmlspecialchars($plabel); ?></strong><?php echo $conn_badge; ?>
							<?php if (!empty($fetch_task_warning)): ?>
								<a class="iea-badge iea-badge-warn" href="/admin/admin_scheduled_tasks"
								   title="<?php echo htmlspecialchars($fetch_task_warning); ?>">&#9888; Auto-fetch</a>
							<?php endif; ?>
							<?php if ($imap->get('iia_last_status')): ?>
								<span class="iem-imap-status">&middot; <?php echo htmlspecialchars($imap->get('iia_last_status')); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

				<div class="iea-mb-actions">
					<?php if ($is_imap_src): ?>
						<?php // IMAP-source mailbox: one Edit manages the mailbox AND its feed
						      // (and creates the feed if this mailbox doesn't have one yet).
						if ($can_imap): ?>
							<a class="btn btn-sm btn-outline-secondary"
							   href="<?php echo $imap_edit . '?domain_id=' . $domain->key . '&alias_id=' . $alias->key; ?>">Edit</a>
							<?php if ($imap):
								$connect_button($imap);
								echo PublicPageBase::action_button('Test', $imap_action, array(
									'hidden' => array('action' => 'test', 'iia_inbound_imap_account_id' => $imap->key),
									'class' => 'btn btn-sm btn-outline-secondary',
								));
								echo PublicPageBase::action_button('Fetch now', $imap_action, array(
									'hidden' => array('action' => 'poll_now', 'iia_inbound_imap_account_id' => $imap->key),
									'class' => 'btn btn-sm btn-outline-secondary',
								));
							endif; ?>
						<?php endif; ?>
					<?php else: ?>
						<a class="btn btn-sm btn-outline-secondary"
						   href="<?php echo $alias_base . '?iea_inbound_email_alias_id=' . $alias->key; ?>">Edit</a>

						<?php if ($can_imap): ?>
							<?php if ($imap): ?>
								<a class="btn btn-sm btn-outline-secondary"
								   href="<?php echo $imap_edit . '?iia_inbound_imap_account_id=' . $imap->key; ?>">Edit feed</a>
								<?php
								$connect_button($imap);
								echo PublicPageBase::action_button('Test', $imap_action, array(
									'hidden' => array('action' => 'test', 'iia_inbound_imap_account_id' => $imap->key),
									'class' => 'btn btn-sm btn-outline-secondary',
								));
								echo PublicPageBase::action_button('Fetch now', $imap_action, array(
									'hidden' => array('action' => 'poll_now', 'iia_inbound_imap_account_id' => $imap->key),
									'class' => 'btn btn-sm btn-outline-secondary',
								));
								echo PublicPageBase::action_button('Remove feed', $imap_action, array(
									'hidden' => array('action' => 'delete', 'iia_inbound_imap_account_id' => $imap->key),
									'confirm' => 'Remove this IMAP feed? Already-ingested mail is kept.',
									'class' => 'btn btn-sm btn-outline-danger',
								));
								?>
							<?php else: ?>
								<a class="btn btn-sm btn-outline-primary"
								   href="<?php echo $imap_edit . '?alias_id=' . $alias->key; ?>">+ IMAP feed</a>
							<?php endif; ?>
						<?php endif; ?>
					<?php endif; ?>

					<?php // The other way in: a feed pulls from a live account, an import reads a dead export. ?>
					<a class="btn btn-sm btn-outline-secondary"
					   href="/plugins/mailbox/admin/admin_mailbox_import?alias_id=<?php echo intval($alias->key); ?>">Import archive</a>

					<?php
					echo PublicPageBase::action_button('Delete', $alias_action, array(
						'hidden' => array('action' => 'delete', 'iea_inbound_email_alias_id' => $alias->key),
						'confirm' => 'Delete this mailbox? Stored mail for it is removed from the reader.',
						'class' => 'btn btn-sm btn-outline-danger',
					));
					?>
				</div>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>

		<?php if (!empty($node['deleted_mailboxes'])): ?>
		<details class="iea-trash">
			<summary>Deleted mailboxes (<?php echo count($node['deleted_mailboxes']); ?>)</summary>
			<ul class="iea-mailboxes">
				<?php foreach ($node['deleted_mailboxes'] as $dmb): ?>
				<li class="iea-mailbox">
					<div class="iea-mb-main">
						<div class="iea-mb-addr"><?php echo htmlspecialchars($dmb->get_full_address()); ?>
							<span class="iea-badge iea-badge-off">deleted</span></div>
					</div>
					<div class="iea-mb-actions">
						<?php
						echo PublicPageBase::action_button('Restore', $alias_action, array(
							'hidden' => array('action' => 'undelete', 'iea_inbound_email_alias_id' => $dmb->key),
							'confirm' => 'Restore this mailbox? Its stored mail comes back in the reader.',
							'class' => 'btn btn-sm btn-outline-success',
						));
						echo PublicPageBase::action_button('Permanent delete', $alias_action, array(
							'hidden' => array('action' => 'permanent_delete', 'iea_inbound_email_alias_id' => $dmb->key),
							'confirm' => 'PERMANENTLY delete this mailbox and its stored mail? This cannot be undone.',
							'confirm_typed' => 'delete ' . $dmb->get_full_address(),
							'class' => 'btn btn-sm btn-outline-danger',
						));
						?>
					</div>
				</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>
<?php endif; ?>

	<div class="iea-page-actions">
		<a class="btn btn-primary" href="<?php echo $domain_base . '?action=add'; ?>">+ Add Domain</a>
	</div>

<?php if (!empty($deleted_domains)): ?>
	<details class="iea-trash">
		<summary>Deleted domains (<?php echo count($deleted_domains); ?>)</summary>
		<ul class="iea-mailboxes">
			<?php foreach ($deleted_domains as $d): ?>
			<li class="iea-mailbox">
				<div class="iea-mb-main">
					<div class="iea-mb-addr"><?php echo htmlspecialchars($d->get('ied_domain')); ?>
						<span class="iea-badge iea-badge-off">deleted</span></div>
				</div>
				<div class="iea-mb-actions">
					<?php
					echo PublicPageBase::action_button('Restore', $domain_base, array(
						'hidden' => array('action' => 'undelete', 'ied_inbound_email_domain_id' => $d->key),
						'confirm' => 'Restore this domain and its aliases?',
						'class' => 'btn btn-sm btn-outline-success',
					));
					echo PublicPageBase::action_button('Permanent delete', $domain_base, array(
						'hidden' => array('action' => 'permanent_delete', 'ied_inbound_email_domain_id' => $d->key),
						'confirm' => 'PERMANENTLY delete this domain and all its mailboxes? This cannot be undone.',
						'confirm_typed' => 'delete ' . $d->get('ied_domain'),
						'class' => 'btn btn-sm btn-outline-danger',
					));
					?>
				</div>
			</li>
			<?php endforeach; ?>
		</ul>
	</details>
<?php endif; ?>
</div>
<?php
$page->admin_footer();
?>
