<?php
/**
 * Email Forwarding - Domain Management
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/logic/admin_email_forwarding_domains_logic.php'));

$page_vars = process_logic(admin_email_forwarding_domains_logic($_GET, $_POST));
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

// Check for mydestination conflict
$mydest_output = array();
exec('postconf -h mydestination 2>/dev/null', $mydest_output);
$mydest_line = implode('', $mydest_output);
$mydest_conflict = false;
if ($mydest_line) {
	// Check later when we have the domain list
	$mydest_conflict_check = $mydest_line;
}

if (!$postfix_installed || !$transport_configured || !$opendkim_installed) {
	$script_path_display = PathHelper::getIncludePath('plugins/email_forwarding/setup_email_forwarding.sh');
	echo '<p class="mt-2">Run the setup script to fix missing components:</p>';
	echo '<pre class="bg-light p-2"><code>sudo bash ' . htmlspecialchars($script_path_display) . '</code></pre>';
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

	// Check Postfix
	$ed_vmd_output = array();
	exec('postconf virtual_mailbox_domains 2>/dev/null', $ed_vmd_output);
	$ed_vmd_line = implode('', $ed_vmd_output);
	$ed_in_postfix = (strpos($ed_vmd_line, $ed_domain_name) !== false);

	echo '<h6 class="mt-3">DNS & Server Status for ' . htmlspecialchars($ed_domain_name) . '</h6>';
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
	echo '<tr><td><strong>Postfix</strong></td><td>';
	echo $ed_in_postfix ? '<span class="badge bg-success">Configured</span>' : '<span class="badge bg-warning text-dark">Domain not in Postfix</span>';
	echo '</td></tr>';

	// Check mydestination conflict
	$ed_mydest_conflict = isset($mydest_conflict_check) && strpos($mydest_conflict_check, $ed_domain_name) !== false;
	if ($ed_mydest_conflict) {
		echo '<tr><td><strong>mydestination</strong></td><td>';
		echo '<span class="badge bg-danger">Conflict</span> Domain is in Postfix <code>mydestination</code> — virtual forwarding will not work.';
		echo '<br><pre class="bg-light p-2 mt-1"><code>sudo bash ' . htmlspecialchars(PathHelper::getIncludePath('plugins/email_forwarding/setup_email_forwarding.sh')) . '</code></pre>';
		echo '</td></tr>';
	}

	echo '</table>';

	// Only show instructions for items that are missing
	$missing = array();
	if (!$ed_in_postfix) $missing[] = 'postfix';
	if (!$ed_mx_ok) $missing[] = 'mx';
	if (!$ed_spf_ok) $missing[] = 'spf';
	if (!$ed_dkim_ok) $missing[] = 'dkim';

	if (!empty($missing)) {
		echo '<h6>Setup Required</h6>';

		if (in_array('postfix', $missing)) {
			echo '<p>Run the setup script to add this domain to Postfix:</p>';
			echo '<pre class="bg-light p-2"><code>sudo bash ' . htmlspecialchars(PathHelper::getIncludePath('plugins/email_forwarding/setup_email_forwarding.sh')) . '</code></pre>';
		}

		if (in_array('mx', $missing) || in_array('spf', $missing) || in_array('dkim', $missing)) {
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
					echo '<tr><td>TXT</td><td>mail._domainkey</td><td><small class="text-muted">DKIM key not yet generated. Run the setup script first, then reload.</small></td></tr>';
				}
			}

			echo '</tbody></table>';
		}
	} else {
		echo '<div class="alert alert-success mt-2">All DNS records and server configuration are in place for this domain.</div>';
	}
	}

	$page->end_box();
} // end show_form

// --- Domain List ---
$domains = new MultiEmailForwardingDomain(array('deleted' => false), array('efd_domain' => 'ASC'));
$domains->load();

$headers = array('Domain', 'Enabled', 'Catch-All', 'Reject Unmatched', 'Aliases', 'DNS Status', 'Actions');
$altlinks = array('Add Domain' => '/plugins/email_forwarding/admin/admin_email_forwarding_domains?action=add');
$table_options = array('title' => 'Forwarding Domains', 'altlinks' => $altlinks);
$page->tableheader($headers, $table_options);

// Get Postfix virtual_mailbox_domains for checking
$vmd_output = array();
exec('postconf virtual_mailbox_domains 2>/dev/null', $vmd_output);
$vmd_line = implode('', $vmd_output);

foreach ($domains as $d) {
	$domain_name = $d->get('efd_domain');
	$alias_count = $d->get_alias_count();

	// DNS checks
	$dns_status = '';

	// Check MX
	$mx_records = @dns_get_record($domain_name, DNS_MX);
	if ($mx_records && count($mx_records) > 0) {
		$dns_status .= '<span class="badge bg-success">MX</span> ';
	} else {
		$dns_status .= '<span class="badge bg-warning text-dark">MX</span> ';
	}

	// Check SPF
	$txt_records = @dns_get_record($domain_name, DNS_TXT);
	$spf_found = false;
	if ($txt_records) {
		foreach ($txt_records as $txt) {
			if (strpos($txt['txt'] ?? '', 'v=spf1') !== false) {
				$spf_found = true;
				break;
			}
		}
	}
	$dns_status .= $spf_found ? '<span class="badge bg-success">SPF</span> ' : '<span class="badge bg-warning text-dark">SPF</span> ';

	// Check DKIM
	$dkim_records = @dns_get_record('mail._domainkey.' . $domain_name, DNS_TXT);
	$dkim_found = false;
	if ($dkim_records) {
		foreach ($dkim_records as $txt) {
			if (strpos($txt['txt'] ?? '', 'v=DKIM1') !== false) {
				$dkim_found = true;
				break;
			}
		}
	}
	$dns_status .= $dkim_found ? '<span class="badge bg-success">DKIM</span> ' : '<span class="badge bg-secondary">DKIM</span> ';

	// Check Postfix
	$in_postfix = (strpos($vmd_line, $domain_name) !== false);
	$dns_status .= $in_postfix ? '<span class="badge bg-success">Postfix</span>' : '<span class="badge bg-warning text-dark">Postfix</span>';

	$rowvalues = array();
	array_push($rowvalues, htmlspecialchars($domain_name));
	array_push($rowvalues, $d->get('efd_is_enabled') ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>');
	array_push($rowvalues, htmlspecialchars($d->get('efd_catch_all_address') ?: '-'));
	array_push($rowvalues, $d->get('efd_reject_unmatched') ? 'Yes' : 'No');
	array_push($rowvalues, $alias_count);
	array_push($rowvalues, $dns_status);

	$actions = '<a href="/plugins/email_forwarding/admin/admin_email_forwarding_domains?efd_email_forwarding_domain_id=' . $d->key . '" class="btn btn-sm btn-outline-primary">Edit</a> '
		. '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this domain and all its aliases?\')">'
		. '<input type="hidden" name="action" value="delete">'
		. '<input type="hidden" name="efd_email_forwarding_domain_id" value="' . $d->key . '">'
		. '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>'
		. '</form>';
	array_push($rowvalues, $actions);

	$page->disprow($rowvalues);
}

$page->endtable();

// --- Generate setup script silently (used by per-domain edit view) ---
$hostname = gethostname();
$site_path = rtrim(PathHelper::getBasePath(), '/');
$pipe_script = $site_path . '/plugins/email_forwarding/scripts/email_forwarder.php';

$all_domain_names = array();
foreach ($domains as $d) {
	$all_domain_names[] = $d->get('efd_domain');
}
$domain_list = implode(' ', $all_domain_names) ?: 'example.com';

$script_path = PathHelper::getIncludePath('plugins/email_forwarding/setup_email_forwarding.sh');
$script = "#!/bin/bash\n";
$script .= "# Email Forwarding Setup Script\n";
$script .= "# Generated " . date('Y-m-d H:i:s') . "\n";
$script .= "# Run with: sudo bash $script_path\n\n";
$script .= "set -e\n\n";

if (!$postfix_installed) {
	$script .= "echo '=== Installing Postfix ==='\n";
	$script .= "debconf-set-selections <<< \"postfix postfix/mailname string " . $hostname . "\"\n";
	$script .= "debconf-set-selections <<< \"postfix postfix/main_mailer_type string 'Internet Site'\"\n";
	$script .= "apt install -y postfix\n\n";
}

if (!$transport_configured || !$postfix_installed) {
	$script .= "echo '=== Configuring Postfix ==='\n";
	$script .= "postconf -e \"virtual_transport = joinery\"\n";
	$script .= "postconf -e \"virtual_mailbox_domains = " . $domain_list . "\"\n";
	$script .= "postconf -e \"smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination, reject_rbl_client zen.spamhaus.org, reject_rbl_client bl.spamcop.net, reject_rbl_client b.barracudacentral.org, reject_rhsbl_helo dbl.spamhaus.org, reject_rhsbl_sender dbl.spamhaus.org, permit\"\n\n";
	// Remove forwarding domains from mydestination (virtual transport only works if domain is NOT in mydestination)
	$script .= "echo '=== Ensuring forwarding domains are not in mydestination ==='\n";
	$script .= "postconf -e \"mydestination = localhost, localhost.localdomain\"\n\n";
	$script .= "echo '=== Adding pipe transport to master.cf ==='\n";
	$script .= "if ! grep -q '^joinery' /etc/postfix/master.cf; then\n";
	$script .= "  cat >> /etc/postfix/master.cf << 'MASTEREOF'\n";
	$script .= "joinery   unix  -       n       n       -       5       pipe\n";
	$script .= "  flags=DRhu user=www-data\n";
	$script .= '  argv=/usr/bin/php ' . $pipe_script . ' ${recipient}' . "\n";
	$script .= "MASTEREOF\n";
	$script .= "  echo 'Added joinery transport to master.cf'\n";
	$script .= "else\n";
	$script .= "  echo 'joinery transport already exists in master.cf'\n";
	$script .= "fi\n\n";
	$script .= "postfix reload\n\n";
} else {
	$needs_update = false;
	foreach ($all_domain_names as $dn) {
		if (strpos($vmd_line, $dn) === false) {
			$needs_update = true;
			break;
		}
	}
	if ($needs_update) {
		$script .= "echo '=== Updating Postfix domain list ==='\n";
		$script .= "postconf -e \"virtual_mailbox_domains = " . $domain_list . "\"\n";
		$script .= "postconf -e \"mydestination = localhost, localhost.localdomain\"\n";
		$script .= "postfix reload\n\n";
	}
}

// Always check for mydestination conflict — forwarding domains must not be in mydestination
$mydest_has_conflict = false;
$mydest_raw = '';
exec('postconf -h mydestination 2>/dev/null', $mydest_raw_arr);
$mydest_raw = implode('', $mydest_raw_arr ?? []);
foreach ($all_domain_names as $dn) {
	if (strpos($mydest_raw, $dn) !== false) {
		$mydest_has_conflict = true;
		break;
	}
}
if ($mydest_has_conflict) {
	$script .= "echo '=== Fixing mydestination conflict ==='\n";
	$script .= "postconf -e \"mydestination = localhost, localhost.localdomain\"\n";
	$script .= "postfix reload\n\n";
}

if (!$opendkim_installed) {
	$script .= "echo '=== Installing opendkim ==='\n";
	$script .= "apt install -y opendkim opendkim-tools\n";
	$script .= "postconf -e \"milter_default_action = accept\"\n";
	$script .= "postconf -e \"smtpd_milters = inet:localhost:8891\"\n";
	$script .= "postconf -e \"non_smtpd_milters = inet:localhost:8891\"\n\n";
}

// Generate DKIM keys for any domain that doesn't have one yet
$dkim_keys_generated = false;
foreach ($all_domain_names as $dn) {
	$key_file = '/etc/opendkim/keys/' . $dn . '/mail.private';
	if (!file_exists($key_file)) {
		$script .= "echo '=== Generating DKIM keys for " . $dn . " ==='\n";
		$script .= "mkdir -p /etc/opendkim/keys/" . $dn . "\n";
		$script .= "opendkim-genkey -s mail -d " . $dn . " -D /etc/opendkim/keys/" . $dn . "\n";
		$script .= "chown opendkim:opendkim /etc/opendkim/keys/" . $dn . "/mail.private\n";
		$script .= "chmod 644 /etc/opendkim/keys/" . $dn . "/mail.txt\n\n";
		$dkim_keys_generated = true;
	}
}
if ($dkim_keys_generated || !$opendkim_installed) {
	$script .= "systemctl restart opendkim\n\n";
}

$script .= "echo '=== Opening firewall port 25 ==='\n";
$script .= "ufw allow 25\n\n";
$script .= "echo '=== Setup complete ==='\n";

file_put_contents($script_path, $script);
chmod($script_path, 0755);

$page->admin_footer();
?>
