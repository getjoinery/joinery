<?php
/**
 * Email Forwarding - Domain Management
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/logic/admin_email_forwarding_domains_logic.php'));

$page_vars = process_logic(admin_email_forwarding_domains_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Email Forwarding' => '/plugins/email_forwarding/admin/admin_email_forwarding',
			'Domains' => '',
		),
		'session' => $session,
	)
);

// Tab navigation
echo '<ul class="nav nav-tabs mb-3">';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/email_forwarding/admin/admin_email_forwarding">Forwarding Aliases</a></li>';
echo '<li class="nav-item"><a class="nav-link active" href="/plugins/email_forwarding/admin/admin_email_forwarding_domains">Domains</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/email_forwarding/admin/admin_email_forwarding_logs">Logs</a></li>';
echo '</ul>';

// Display session messages
$display_messages = $session->get_messages('/plugins\/email_forwarding\/admin\//');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		echo '<div class="alert alert-success">' . htmlspecialchars($msg->message) . '</div>';
	}
	$session->clear_clearable_messages();
}

if (isset($error)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

// Path to the base mail installer — the single fix for missing host components.
$installer_path = PathHelper::getIncludePath('plugins/email_forwarding/provisioning/install_email.sh');

// --- Server Status Panel ---
$pageoptions_status = array('title' => 'Server Status');
$page->begin_box($pageoptions_status);

// Check Postfix
exec('which postfix 2>/dev/null', $pf_output, $pf_exit);
$postfix_installed = ($pf_exit === 0);
exec('pgrep -x master 2>/dev/null', $pf_running_output, $pf_running_exit);
$postfix_running = ($pf_running_exit === 0);

if ($postfix_installed && $postfix_running) {
	echo '<span class="badge bg-success">Postfix</span> Installed and running<br>';
} elseif ($postfix_installed) {
	echo '<span class="badge bg-warning text-dark">Postfix</span> Installed but not running<br>';
} else {
	echo '<span class="badge bg-danger">Postfix</span> Not installed<br>';
}

// Check joinery transport
$transport_output = array();
exec('postconf -M joinery/unix 2>/dev/null', $transport_output);
$transport_configured = !empty($transport_output);
if ($transport_configured) {
	echo '<span class="badge bg-success">Joinery Transport</span> Configured<br>';
} else {
	echo '<span class="badge bg-warning text-dark">Joinery Transport</span> Not found in Postfix config<br>';
}

// Check virtual_mailbox_domains is wired to the live pgsql database map.
// Under Option C the database IS Postfix's domain source — what can be wrong
// is the plumbing, not an individual domain. (No credentials: postconf -h
// prints only the setting value, and the map path is world-readable.)
$vmd_output = array();
exec('postconf -h virtual_mailbox_domains 2>/dev/null', $vmd_output);
$vmd_line = trim(implode('', $vmd_output));
$vmd_ok = (strpos($vmd_line, 'pgsql:') !== false && strpos($vmd_line, 'joinery-domains.cf') !== false);
if ($vmd_ok) {
	echo '<span class="badge bg-success">Domain Map</span> Postfix reads forwarding domains live from the database<br>';
} else {
	echo '<span class="badge bg-warning text-dark">Domain Map</span> <code>virtual_mailbox_domains</code> is not wired to the database map<br>';
}

// Check opendkim
exec('which opendkim 2>/dev/null', $dk_output, $dk_exit);
$opendkim_installed = ($dk_exit === 0);
exec('pgrep -x opendkim 2>/dev/null', $dk_running_output, $dk_running_exit);
$opendkim_running = ($dk_running_exit === 0);

if ($opendkim_installed && $opendkim_running) {
	echo '<span class="badge bg-success">opendkim</span> Installed and running<br>';
} elseif ($opendkim_installed) {
	echo '<span class="badge bg-warning text-dark">opendkim</span> Installed but not running<br>';
} else {
	echo '<span class="badge bg-secondary">opendkim</span> Not installed &mdash; outbound DKIM signing disabled<br>';
}

// Capture mydestination for the per-domain conflict check below.
$mydest_output = array();
exec('postconf -h mydestination 2>/dev/null', $mydest_output);
$mydest_line = implode('', $mydest_output);
if ($mydest_line) {
	$mydest_conflict_check = $mydest_line;
}

if (!$postfix_installed || !$transport_configured || !$vmd_ok || !$opendkim_installed) {
	echo '<p class="mt-2">Run the base mail installer to fix missing components:</p>';
	echo '<pre class="bg-light p-2"><code>sudo bash ' . htmlspecialchars($installer_path) . '</code></pre>';
}

$page->end_box();

// --- Add/Edit Domain Form (only shown when editing or adding) ---
$show_form = $edit_domain || (isset($_GET['action']) && $_GET['action'] === 'add');

if ($show_form) {
	$form_domain = $edit_domain ?: new EmailForwardingDomain(NULL);
	$form_title = $edit_domain ? 'Edit Domain' : 'Add Domain';

	$pageoptions_form = array('title' => $form_title);
	$page->begin_box($pageoptions_form);

	$formwriter = $page->getFormWriter('domain_form', [
		'model' => $form_domain,
		'edit_primary_key_value' => $form_domain->key,
	]);

	echo $formwriter->begin_form();

	$formwriter->textinput('efd_domain', 'Domain Name', [
		'validation' => ['required' => true],
		'help_text' => 'e.g., example.com',
	]);

	$formwriter->checkboxinput('efd_is_enabled', 'Enabled', []);

	$formwriter->textinput('efd_catch_all_address', 'Catch-All Address', [
		'help_text' => 'Optional: receive all unmatched mail for this domain at this address',
	]);

	$formwriter->checkboxinput('efd_reject_unmatched', 'Reject Unmatched', [
		'help_text' => 'Reject mail to non-existent aliases (when no catch-all). If unchecked, unmatched mail is silently discarded.',
	]);

	$formwriter->submitbutton('btn_submit', $edit_domain ? 'Update Domain' : 'Add Domain');

	echo $formwriter->end_form();

// Show per-domain DNS status and instructions when editing an existing domain
if ($edit_domain) {
	$ed_domain_name = $edit_domain->get('efd_domain');
	$ed_hostname = gethostname();
	$ed_server_ip = @file_get_contents('https://api.ipify.org') ?: 'YOUR_SERVER_IP';

	// DNS checks
	$ed_mx_records = @dns_get_record($ed_domain_name, DNS_MX);
	$ed_mx_ok = false;
	$ed_mx_target = '';
	if ($ed_mx_records) {
		foreach ($ed_mx_records as $mx) {
			$ed_mx_target = $mx['target'] ?? '';
			$ed_mx_ok = true;
			break;
		}
	}

	$ed_txt_records = @dns_get_record($ed_domain_name, DNS_TXT);
	$ed_spf_ok = false;
	if ($ed_txt_records) {
		foreach ($ed_txt_records as $txt) {
			if (strpos($txt['txt'] ?? '', 'v=spf1') !== false && strpos($txt['txt'] ?? '', $ed_server_ip) !== false) {
				$ed_spf_ok = true;
				break;
			}
		}
	}

	$ed_dkim_records = @dns_get_record('mail._domainkey.' . $ed_domain_name, DNS_TXT);
	$ed_dkim_ok = false;
	if ($ed_dkim_records) {
		foreach ($ed_dkim_records as $txt) {
			if (strpos($txt['txt'] ?? '', 'v=DKIM1') !== false) {
				$ed_dkim_ok = true;
				break;
			}
		}
	}

	echo '<h6 class="mt-3">DNS &amp; Server Status for ' . htmlspecialchars($ed_domain_name) . '</h6>';
	echo '<table class="table table-sm" style="max-width:500px">';
	echo '<tr><td><strong>MX Record</strong></td><td>';
	if ($ed_mx_ok) {
		echo '<span class="badge bg-success">OK</span> ' . htmlspecialchars($ed_mx_target);
	} else {
		echo '<span class="badge bg-warning text-dark">Missing</span>';
	}
	echo '</td></tr>';
	echo '<tr><td><strong>SPF Record</strong></td><td>';
	echo $ed_spf_ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-warning text-dark">Missing or incomplete</span>';
	echo '</td></tr>';
	echo '<tr><td><strong>DKIM Record</strong></td><td>';
	echo $ed_dkim_ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-secondary">Not found</span>';
	echo '</td></tr>';

	// Check mydestination conflict — a forwarding domain in mydestination
	// outranks virtual_mailbox_domains and breaks virtual forwarding.
	$ed_mydest_conflict = isset($mydest_conflict_check) && strpos($mydest_conflict_check, $ed_domain_name) !== false;
	if ($ed_mydest_conflict) {
		echo '<tr><td><strong>mydestination</strong></td><td>';
		echo '<span class="badge bg-danger">Conflict</span> Domain is in Postfix <code>mydestination</code> — virtual forwarding will not work.';
		echo '<br><pre class="bg-light p-2 mt-1"><code>sudo bash ' . htmlspecialchars($installer_path) . '</code></pre>';
		echo '</td></tr>';
	}

	echo '</table>';

	// Only show instructions for items that are missing
	$missing = array();
	if (!$ed_mx_ok) $missing[] = 'mx';
	if (!$ed_spf_ok) $missing[] = 'spf';
	if (!$ed_dkim_ok) $missing[] = 'dkim';

	if (!empty($missing)) {
		echo '<h6>Setup Required</h6>';

		echo '<p>Add these DNS records for <strong>' . htmlspecialchars($ed_domain_name) . '</strong>:</p>';
		echo '<table class="table table-sm table-bordered" style="max-width:700px">';
		echo '<thead><tr><th>Type</th><th>Name</th><th>Value</th></tr></thead><tbody>';

		if (in_array('mx', $missing)) {
			$mx_value = htmlspecialchars($ed_hostname) . '.';
			echo '<tr><td>MX</td><td>@</td><td><input type="text" class="form-control form-control-sm" readonly style="cursor:pointer;background:#fff" value="' . $mx_value . '" onclick="this.select()"> <small>Priority: 10</small></td></tr>';
		}
		if (in_array('spf', $missing)) {
			$spf_value = 'v=spf1 ip4:' . htmlspecialchars($ed_server_ip) . ' -all';
			echo '<tr><td>TXT</td><td>@</td><td><input type="text" class="form-control form-control-sm" readonly style="cursor:pointer;background:#fff" value="' . htmlspecialchars($spf_value) . '" onclick="this.select()"></td></tr>';
		}
		if (in_array('dkim', $missing)) {
			$dkim_key_file = '/etc/opendkim/keys/' . $ed_domain_name . '/mail.txt';
			$dkim_pub_key = '';
			if (is_readable($dkim_key_file)) {
				$dkim_raw = file_get_contents($dkim_key_file);
				if (preg_match('/p=([A-Za-z0-9+\/=\s]+)/', $dkim_raw, $dkim_match)) {
					$dkim_pub_key = preg_replace('/\s+/', '', $dkim_match[1]);
				}
			}
			if ($dkim_pub_key) {
				$dkim_value = 'v=DKIM1; k=rsa; p=' . $dkim_pub_key;
				echo '<tr><td>TXT</td><td>mail._domainkey</td><td><input type="text" class="form-control form-control-sm" readonly style="cursor:pointer;background:#fff" value="' . htmlspecialchars($dkim_value) . '" onclick="this.select()"></td></tr>';
			} else {
				echo '<tr><td>TXT</td><td>mail._domainkey</td><td><small class="text-muted">No DKIM key for this domain yet. Generate one with <code>opendkim-genkey</code>, add it to <code>/etc/opendkim/key.table</code> and <code>signing.table</code>, then reload &mdash; see the Email Forwarding plugin docs. Forwarding works without it; only outbound DKIM signing is affected.</small></td></tr>';
			}
		}

		echo '</tbody></table>';
	} else {
		echo '<div class="alert alert-success mt-2">All DNS records are in place for this domain.</div>';
	}
	}

	$page->end_box();
} // end show_form

// --- Domain List ---
require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_alias_class.php'));

// Show deleted domains to superadmins
$show_deleted = ($session->get_permission() >= 10);
$domain_filters = $show_deleted ? [] : ['deleted' => false];
$domains = new MultiEmailForwardingDomain($domain_filters, array('efd_delete_time' => 'ASC', 'efd_domain' => 'ASC'));
$domains->load();

$headers = array('Domain', 'Status', 'Catch-All', 'Aliases', 'DNS', 'Actions');
$altlinks = array('Add Domain' => '/plugins/email_forwarding/admin/admin_email_forwarding_domains?action=add');
$table_options = array('title' => 'Forwarding Domains', 'altlinks' => $altlinks);
$page->tableheader($headers, $table_options);

foreach ($domains as $d) {
	$domain_name = $d->get('efd_domain');
	$is_deleted = !empty($d->get('efd_delete_time'));
	$alias_count = $is_deleted ? 0 : $d->get_alias_count();

	// DNS checks (skip for deleted domains)
	$dns_status = '';
	if (!$is_deleted) {
		$mx_records = @dns_get_record($domain_name, DNS_MX);
		$dns_status .= ($mx_records && count($mx_records) > 0)
			? '<span class="badge bg-success">MX</span> '
			: '<span class="badge bg-warning text-dark">MX</span> ';

		$txt_records = @dns_get_record($domain_name, DNS_TXT);
		$spf_found = false;
		if ($txt_records) {
			foreach ($txt_records as $txt) {
				if (strpos($txt['txt'] ?? '', 'v=spf1') !== false) { $spf_found = true; break; }
			}
		}
		$dns_status .= $spf_found ? '<span class="badge bg-success">SPF</span> ' : '<span class="badge bg-warning text-dark">SPF</span> ';

		$dkim_records = @dns_get_record('mail._domainkey.' . $domain_name, DNS_TXT);
		$dkim_found = false;
		if ($dkim_records) {
			foreach ($dkim_records as $txt) {
				if (strpos($txt['txt'] ?? '', 'v=DKIM1') !== false) { $dkim_found = true; break; }
			}
		}
		$dns_status .= $dkim_found ? '<span class="badge bg-success">DKIM</span>' : '<span class="badge bg-secondary">DKIM</span>';
	} else {
		$dns_status = '<span class="text-muted">-</span>';
	}

	// Status column — combines enabled + deleted
	$status_parts = [];
	if ($is_deleted) {
		$status_parts[] = '<span class="badge bg-dark">Deleted</span>';
	} else if ($d->get('efd_is_enabled')) {
		$status_parts[] = '<span class="badge bg-success">Enabled</span>';
	} else {
		$status_parts[] = '<span class="badge bg-secondary">Disabled</span>';
	}
	$status_display = implode(' ', $status_parts);

	// Build row
	$rowvalues = [];
	$rowvalues[] = htmlspecialchars($domain_name);
	$rowvalues[] = $status_display;
	$rowvalues[] = htmlspecialchars($d->get('efd_catch_all_address') ?: '-');
	$rowvalues[] = $is_deleted ? '-' : $alias_count;
	$rowvalues[] = $dns_status;

	// Action buttons
	$actions = '';
	if ($is_deleted) {
		// Undelete
		$actions .= PublicPageBase::action_button('Restore', '/plugins/email_forwarding/admin/admin_email_forwarding_domains', [
			'hidden' => ['action' => 'undelete', 'efd_email_forwarding_domain_id' => $d->key],
			'confirm' => 'Restore this domain and its aliases?',
			'class' => 'btn btn-sm btn-outline-success',
		]);
		// Permanent delete (permission 10 only)
		if ($session->get_permission() >= 10) {
			$actions .= ' ' . PublicPageBase::action_button('Permanent Delete', '/plugins/email_forwarding/admin/admin_email_forwarding_domains', [
				'hidden' => ['action' => 'permanent_delete', 'efd_email_forwarding_domain_id' => $d->key],
				'confirm' => 'PERMANENTLY delete this domain and all its aliases? This cannot be undone.',
				'class' => 'btn btn-sm btn-outline-danger',
			]);
		}
	} else {
		$actions .= '<a href="/plugins/email_forwarding/admin/admin_email_forwarding_domains?efd_email_forwarding_domain_id=' . $d->key . '" class="btn btn-sm btn-outline-primary">Edit</a> ';
		$actions .= PublicPageBase::action_button('Delete', '/plugins/email_forwarding/admin/admin_email_forwarding_domains', [
			'hidden' => ['action' => 'delete', 'efd_email_forwarding_domain_id' => $d->key],
			'confirm' => 'Delete this domain and all its aliases?',
			'class' => 'btn btn-sm btn-outline-danger',
		]);
	}
	$rowvalues[] = $actions;

	$page->disprow($rowvalues);
}

$page->endtable();

$page->admin_footer();
?>
