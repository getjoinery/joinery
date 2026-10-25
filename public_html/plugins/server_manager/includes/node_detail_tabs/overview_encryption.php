<?php
/**
 * The Encrypted data root card on a node's Overview (specs/hosted_data_promise_disk_encryption.md
 * WP2, WP8): what the node's host report says of its encrypted data root, what this
 * management node holds for it as escrow holder, and the two acts that are this
 * page's: enrol it here, and unlock it from here (D2b).
 *
 * THE UNLOCK FROM HERE. Whenever this holder is up, the unlock is the holder's,
 * not the machine's own page: a machine in someone else's hands could serve a page
 * that sends the whole recovery key, and on a hosted machine that key opens every
 * hosted machine's break-glass copy. Here, the browser works out, from the
 * machine's sealed box as this holder recorded it, the one value that opens that
 * box (assets/js/copy-key.js, the /copy-key arithmetic), and only that value is
 * kept for the machine's next ask. The page gives the evidence the judgment needs:
 * why it is locked, where it asks from and whether that address is its own, and
 * how long since it enrolled.
 *
 * Included by overview.php with $page, $node and $base_url in scope.
 *
 * @version 1.10 - what to do while the provider's layer is on is DataRootEncryptionOffer::provider_layer_fix(): a machine
 *                in its owner's account is told to prove its recovery key first where it has none (B114)
 * @version 1.9 - copy-key.js carries its file time, so a changed one is never served from a cache
 * @version 1.8 - the "keep the provider's layer as well" tick only on a management node; any other machine whose
 *                provider layer is on is pointed at its Copy tab (specs/site_copy_final_test.md E2)
 * @version 1.7 - Before it answers: a Tier 2 machine whose cloud account's key does not work is shown as Tier 2 that cannot
 *               read the activity log now, with what to do, not as Tier 1 (B77)
 * @version 1.6 - the Encrypt offer says the services stop again when it finishes, and its confirm says who stops (B59)
 * @version 1.5 - Encrypt's confirm says the services stop again for a few seconds when it finishes (B45)
 * @version 1.4 - its escrow holder is this management node from the moment the enrolment is confirmed, not
 *                from its next host report (B30); what only a holder has to say (its answers, its refusals,
 *                where it may ask from, Stop holding its key, the unlock) shows only where its key is held
 *                here (B34); a machine enrolled since this holder's newest backup says so (O11)
 * @version 1.3 - Tier 2 (D3): what the holder reads before it answers, the event that refused a boot, and
 *                the two ways on (I did this myself; a copy from backups)
 * @version 1.2 - Re-encrypt under a new key (D8); the re-encryption's progress
 * @version 1.1 - what stays on the root disk, each with its reason (D9, D10)
 * @version 1.0
 */

$dre_report = json_decode((string)$node->get('mgn_last_host_report'), true);
$dre_enc = is_array($dre_report) ? ($dre_report['data_root_encryption'] ?? null) : null;
$dre_row = DataRootEscrow::for_node((int)$node->key);
// A plain data root, where this management node offers to encrypt it (D5, D-O).
$dre_pool = is_array($dre_report) ? ($dre_report['disk_pool'] ?? null) : null;
$dre_offer = $dre_enc === 'none' && is_array($dre_pool) && (string)$node->get('mgn_container_name') === ''
	&& DataRootEncryptionOffer::offered();
if ($dre_offer && !$dre_row) {
	echo '<div id="node-data-root-encryption"></div>';
	$page->begin_box(['title' => 'Encrypted data root']);
	echo '<p class="mb-2">Not encrypted. Its data root can be encrypted where it stands: its services stop for seconds, then it encrypts in the background, at idle disk priority, for hours, and they stop again for seconds when it finishes.</p>';
	echo '<ul class="small mb-2">';
	foreach (DataRootEncryptionOffer::preconditions($node) as $p) {
		echo '<li>' . ($p['ok'] ? '<span class="text-success">&#10003;</span> ' : '<span class="text-danger">&#10007;</span> ')
			. htmlspecialchars($p['label']) . ($p['note'] !== '' ? ' <span class="text-muted">(' . htmlspecialchars($p['note']) . ')</span>' : '') . '</li>';
	}
	$layer = DataRootEncryptionOffer::provider_layer($node);
	echo '<li>The provider\'s disk encryption: ' . ($layer === 'disabled' ? 'off, so this is the one layer'
		: ($layer === 'enabled' ? '<strong>on</strong>' : '<strong>not known</strong>')
		. ': one layer is the rule. ' . (DataRootEncryptionOffer::may_keep_provider_layer($node)
			? 'A management node never copies itself, so it may keep both until it is replaced.'
			: DataRootEncryptionOffer::provider_layer_fix($node, 'its <a href="' . $base_url . '&tab=copy">Copy tab</a>'))) . '</li>';
	echo '</ul>';
	$ready = DataRootEncryptionOffer::ready($node);
	echo '<form id="data_root_encrypt_form" method="post" action="' . $base_url . '">'
		. '<input type="hidden" name="action" value="data_root_encrypt">' . SmAdminCsrf::field();
	if ($layer !== 'disabled' && DataRootEncryptionOffer::may_keep_provider_layer($node)) {
		echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="keep_provider_layer" value="1" id="keep_provider_layer">'
			. '<label class="form-check-label small" for="keep_provider_layer">Keep the provider\'s layer as well (two layers; this node page will say so)</label></div>';
	}
	echo '<button type="button" class="btn btn-sm btn-primary"' . ($ready ? '' : ' disabled')
		. ' onclick="JoineryModal.confirm(\'Encrypt this data root where it stands? Its sites and services stop for a few seconds, then it encrypts in the background for hours, and they stop again for a few seconds when it finishes, as its logs move onto it. A reboot part-way opens it with the recovery key.\', function(){ document.getElementById(\'data_root_encrypt_form\').submit(); })">Encrypt the data root</button></form>';
	$page->end_box();
}
if (is_array($dre_enc) || $dre_row) {
	$dre_h = function ($v) { return htmlspecialchars((string)$v); };
	// A stored time, in the viewer's own zone.
	$dre_when = function ($col) use ($dre_row) {
		return ($dre_row && $dre_row->get($col)) ? (string)$dre_row->get_local($col) : '';
	};
	$dre_open = is_array($dre_enc) ? !empty($dre_enc['open']) : null;
	$dre_status = $dre_row ? (string)$dre_row->get('dre_status') : '';
	// Its key is held here, or is on its way to being: what only a holder has
	// to say (answers, refusals, addresses, the unlock) is about such a row.
	$held_here = in_array($dre_status, array('enrolled', 'approved', 'pending'), true);
	$own = rtrim((string)LibraryFunctions::get_absolute_url(), '/');
	$report_holder = is_array($dre_enc) ? (string)$dre_enc['holder'] : '';
	$names_other = $report_holder !== '' && strcasecmp($report_holder, $own) !== 0;

	echo '<div id="node-data-root-encryption"></div>';
	$page->begin_box(['title' => 'Encrypted data root']);
	echo '<table class="table table-sm mb-3"><tbody>';
	$row_out = function ($label, $html) {
		echo '<tr><th class="svm-w180 fw-normal text-muted">' . $label . '</th><td>' . $html . '</td></tr>';
	};
	if (is_array($dre_enc)) {
		$row_out('State', $dre_open
			? '<span class="text-success">open</span>' . ($dre_enc['opened_by'] !== '' ? ' &middot; opened by ' . $dre_h($dre_enc['opened_by']) : '')
			: '<strong class="text-danger">locked</strong>: waiting for its key; its sites and services start once it opens');
		$row_out('Volume', $dre_enc['backing'] === 'luks-device' ? 'LUKS2 on a device' : 'LUKS2 in an image file on the root disk');
		if (($dre_enc['reencrypt'] ?? '') !== '') {
			$row_out(($dre_enc['reencrypt'] === 'encrypt' ? 'Encrypting' : ($dre_enc['reencrypt'] === 'reencrypt' ? 'Re-encrypting under a new key' : 'Decrypting')),
				'<span class="text-warning">' . ($dre_enc['reencrypt_percent'] !== '' ? $dre_h($dre_enc['reencrypt_percent']) . '% done' : 'running')
				. ', in the background at idle disk priority; a reboot part-way opens with the recovery key, then it resumes</span>');
		}
		$layer_now = DataRootEncryptionOffer::provider_layer($node);
		$row_out('Layers', $layer_now === 'enabled'
			? '<strong>two</strong>: the provider\'s disk encryption and ours. This machine predates the one-layer rule; it ends when the machine is replaced.'
			: ($layer_now === 'disabled' ? 'one: ours (the provider\'s disk encryption is off)' : 'ours; the provider\'s disk encryption is not known'));
		// What is not on the encrypted volume, each with its reason (D10).
		$row_out('Stays on the root disk', '<span class="small">TLS private keys (the unlock page needs them); the SSH host keys '
			. '(a copy impersonates the host to an SSH client, nothing more); the agent\'s and the unlock identity (needed before '
			. 'the unlock; the address check bounds the second); the recovery recipient (public); Postfix\'s database lookup '
			. 'password and TLS session cache (they open nothing without the database). Everything else that holds the data, '
			. 'the sites\' <code>config/</code>, <code>/var/log</code>, PHP\'s sessions and temporary files and the DKIM keys '
			. 'among it, is on the volume; <code>/tmp</code> is in memory and core dumps are off.</span>');
		if ($dre_enc['recipient_fingerprint'] !== '') {
			$row_out('Break-glass copy', 'sealed to the recovery key <code>' . $dre_h(substr($dre_enc['recipient_fingerprint'], 0, 16)) . '</code>');
		}
		if ($dre_enc['identity'] !== '') {
			$row_out('Unlock identity', '<code>' . $dre_h(DataRootEscrow::fingerprint_of($dre_enc['identity'])) . '</code>');
		}
		if (($dre_enc['reboot_held'] ?? '') !== '' && preg_match('/^(\d+)\s+(.*)$/', $dre_enc['reboot_held'], $hm)) {
			$row_out('Reboot', '<span class="text-warning">an update asked for one; held back since '
				. $dre_h(LibraryFunctions::time_ago(gmdate('Y-m-d H:i:s', (int)$hm[1]), SessionControl::get_instance()->get_timezone()))
				. ': ' . $dre_h($hm[2]) . '. The old kernel runs until its escrow holder answers.</span>');
		}
		// The host report says it once the machine next reports; an enrolment
		// confirmed here is this management node from that moment.
		if ($report_holder !== '') {
			$holder_html = $dre_h($report_holder) . (is_int($dre_enc['slot']) ? ' &middot; key slot ' . (int)$dre_enc['slot'] : '');
		} elseif ($dre_status === 'enrolled') {
			$holder_html = 'this management node &middot; key slot ' . (int)$dre_row->get('dre_slot');
		} else {
			$holder_html = '<span class="text-muted">none yet: it opens by its recovery key</span>';
		}
		$row_out('Its escrow holder', $holder_html);
	}
	if ($dre_row) {
		$held = array(
			'enrolled' => 'enrolled ' . $dre_when('dre_enrolled_time') . ', key slot ' . (int)$dre_row->get('dre_slot'),
			'approved' => 'approved, waiting for the machine to take its key',
			'pending'  => 'asked to enrol, waiting for approval on the dashboard',
			'known'    => 'not enrolled: known from its host report only',
			'retired'  => 'no longer held here',
		);
		$row_out('Held here', $dre_h($held[$dre_status] ?? $dre_status)
			. (!$held_here && $names_other ? ' &middot; its key is held by ' . $dre_h($report_holder) : ''));
		// O11: rebuilt from a backup older than the enrolment, this holder
		// would not hold the key.
		if ($dre_status === 'enrolled') {
			$dre_last_backup = EscrowHolderBackup::last_backup();
			if (EscrowHolderBackup::enrolment_uncovered($dre_row, $dre_last_backup)) {
				$row_out('This holder\'s backup', '<span class="text-warning">' . ($dre_last_backup === null
					? 'This management node has no backup in backup storage: rebuilt, it would hold no key, and this machine would open by its recovery key at its next boot.'
					: 'Enrolled after this management node\'s newest backup ('
						. $dre_h(LibraryFunctions::convert_time($dre_last_backup, 'UTC', SessionControl::get_instance()->get_timezone(), 'M j, Y g:i a'))
						. '): rebuilt from that backup, it would not hold this key, and this machine would open by its recovery key. '
						. 'A backup runs within the hour of an enrolment where this site takes backups of its own.')
					. '</span>');
			}
		}
	}
	if ($dre_row && $held_here) {
		if ($dre_row->get('dre_answered_time')) {
			$row_out('Last answer', $dre_h($dre_when('dre_answered_time') . ', to ' . $dre_row->get('dre_answered_address'))
				. ($dre_row->get('dre_unlocked_time') && strtotime($dre_row->get('dre_unlocked_time')) >= strtotime($dre_row->get('dre_answered_time'))
					? ' &middot; unlocked' : ' &middot; <span class="text-warning">no unlock reported since</span>'));
		}
		if ($dre_row->get('dre_refusal')) {
			$row_out('Last refusal', '<span class="text-danger">' . $dre_h($dre_when('dre_refusal_time')) . ': '
				. $dre_h($dre_row->get('dre_refusal')) . '</span>');
		}
		// Tier 2 (D3): what the holder reads before it answers.
		$t2_why = '';
		$t2_blocked = null;
		$t2 = EscrowTier2::context($dre_row, $t2_why, $t2_blocked) !== null;
		$row_out('Before it answers', $t2
			? 'Tier 2: it reads the provider\'s activity log, and refuses a boot after a rescue boot, a root-password reset, a rebuild, a clone or a restore'
			: ($t2_blocked !== null
				// Tier 2 still, and unable to check (B77): its boots are refused.
				? '<span class="text-danger">Tier 2, and it cannot read the activity log now. ' . $dre_h($t2_blocked) . '</span>'
				: 'Tier 1: it answers from the machine\'s own addresses. <span class="text-muted">Not Tier 2: ' . $dre_h($t2_why) . '.</span>'));
		if ((string)$dre_row->get('dre_tier2_event') !== '') {
			$row_out('Refused for', '<span class="text-danger">' . $dre_h($dre_row->get('dre_tier2_event'))
				. '</span>. Nothing on its root disk can be trusted after that. Two ways on: say you did it yourself, or recover the site by '
				. '<a href="' . $base_url . '&tab=copy">a copy from its backups</a> onto a new server and retire this one.'
				. '<form id="data_root_escrow_excuse_form" method="post" action="' . $base_url . '" class="mt-2">'
				. '<input type="hidden" name="action" value="data_root_escrow_excuse">' . SmAdminCsrf::field()
				. '<button type="button" class="btn btn-sm btn-outline-danger" onclick="JoineryModal.confirm(\'Say you caused this yourself (a rescue boot or a password reset you ran)? It is recorded with your name, and its next ask is answered: whatever was written to its root disk then is trusted from now on.\', function(){ document.getElementById(\'data_root_escrow_excuse_form\').submit(); })">I did this myself</button></form>');
		}
		if ($dre_row->get('dre_tier2_excused_time')) {
			$excuser = new User((int)$dre_row->get('dre_tier2_excused_usr_user_id'), TRUE);
			$row_out('Excused', $dre_h($dre_when('dre_tier2_excused_time')) . ' by ' . $dre_h($excuser->key ? $excuser->display_name() : 'a user since removed'));
		}
		$addrs = $dre_row->allowed_addresses();
		$row_out('May ask from', $addrs ? $dre_h(implode(', ', $addrs)) : '<span class="text-muted">nowhere yet</span>');
		if ($dre_row->get('dre_box_changed_time')) {
			$row_out('Sealed box', '<span class="text-warning">changed ' . $dre_h($dre_when('dre_box_changed_time'))
				. ': expected after a recovery key rotation (re-seal), a warning otherwise</span>');
		}
	}
	echo '</tbody></table>';

	// Enrol here: when this plane is its holder (its machine names none, or this one).
	$may_enrol = !$node->is_self() && $dre_open && !$names_other && !in_array($dre_status, array('enrolled', 'pending'), true);
	if ($may_enrol && !DataRootEscrow::takes_new_enrolments()) {
		echo '<p class="text-muted small mb-2">' . $dre_h(DataRootEscrow::NO_NEW_ENROLMENTS) . '</p>';
	} elseif ($may_enrol && JobCommandBuilder::has_primitive($node, 'data_root_enrol')) {
		echo '<form id="data_root_enrol_form" method="post" action="' . $base_url . '" class="d-inline">'
			. '<input type="hidden" name="action" value="data_root_enrol">' . SmAdminCsrf::field()
			. '<button type="submit" class="btn btn-sm btn-primary">Enrol with this management node</button></form> ';
		echo '<span class="text-muted small ms-2">This management node then opens its data root at every boot.</span>';
	} elseif ($node->is_self() && is_array($dre_enc)) {
		echo '<p class="text-muted small mb-2">This management node\'s own key is held by another management node, never by itself.</p>';
	}

	// Unlock from here (D2b): a locked machine, and the box to open. Locked is
	// what its host report says, or, sooner, what its own asks say: a locked
	// machine asks this holder every ten seconds, and has not said it is open.
	// Only where this holder is the one it asks.
	$parts = ($dre_row && !$names_other && in_array($dre_status, array('enrolled', 'approved'), true)) ? $dre_row->box_parts() : null;
	$asking_now = $dre_row && $dre_row->is_asking_now();
	if (($dre_open === false || $asking_now) && $parts !== null) {
		$asking = (string)$dre_row->get('dre_last_request_address');
		$enrolled = $dre_row->get('dre_enrolled_time');
		echo '<div class="border rounded p-3 mt-2">';
		echo '<h6 class="mb-2">Unlock it from here</h6>';
		echo '<ul class="small mb-2">';
		echo '<li>Why it is locked: ' . ($dre_row->get('dre_refusal')
			? 'this holder refused it: ' . $dre_h($dre_row->get('dre_refusal'))
			: ($dre_status === 'enrolled' ? 'it has not asked this holder since it rebooted' : 'it is not enrolled here, so only its recovery key opens it')) . '</li>';
		if ($asking !== '') {
			echo '<li>It last asked from <code>' . $dre_h($asking) . '</code>, '
				. ($dre_row->address_allowed($asking) ? 'one of its own addresses' : '<strong class="text-danger">not an address it has stated</strong>')
				. ', ' . $dre_h($dre_when('dre_last_request_time')) . '.</li>';
		}
		echo '<li>' . ($enrolled ? 'Enrolled ' . $dre_h($dre_when('dre_enrolled_time')) . '.' : 'Never enrolled here.') . '</li>';
		echo '</ul>';
		echo '<p class="small text-muted mb-2">Your recovery key stays in this browser: it works out the one value that opens this machine\'s sealed key, and only that is kept for the machine\'s next ask. Unlocking hands the machine its own key: a machine that is in someone else\'s hands, unlocked this way, has its data open. If you did not expect it to be locked, find out why first.</p>';
		echo '<div class="mb-2"><input type="password" class="form-control form-control-sm" id="copy_key_secret" autocomplete="off" spellcheck="false" placeholder="Recovery key (44 characters of base64)"></div>';
		echo '<form id="data_root_unlock_form" method="post" action="' . $base_url . '">'
			. '<input type="hidden" name="action" value="data_root_unlock">'
			. '<input type="hidden" name="shared" value=""><input type="hidden" name="public_key" value="">' . SmAdminCsrf::field()
			. '<button type="button" class="btn btn-sm btn-warning" id="copy_key_open">Unlock</button>'
			. '<div id="copy_key_status" class="small mt-2"></div></form>';
		echo '<script>window.copyKeyCeremony = ' . json_encode(array('fingerprint' => $parts['fingerprint'], 'ephemeralPublic' => $parts['ephemeral'])) . ';</script>';
		echo '<script src="/assets/js/copy-key.js?v=' . filemtime(PathHelper::getIncludePath('assets/js/copy-key.js')) . '"></script>';
		echo '</div>';
	}

	// Undo (D5 step 7): back to plain, online, from wherever it stands.
	// A recovery key that may have been seen (D8): the volume key changes too.
	if (is_array($dre_enc) && $dre_open && ($dre_enc['reencrypt'] ?? '') === ''
			&& JobCommandBuilder::has_primitive($node, 'data_root_rekey')) {
		echo '<form id="data_root_rekey_form" method="post" action="' . $base_url . '" class="mt-3 d-inline-block me-2">'
			. '<input type="hidden" name="action" value="data_root_rekey">' . SmAdminCsrf::field()
			. '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="JoineryModal.confirm(\'Re-encrypt this data root under a new volume key? Do this when someone else could have seen the recovery key it was sealed to: from then on that key opens no copy of its disk. It runs in the background for hours; its escrow slot is made again with a new secret. One machine at a time.\', function(){ document.getElementById(\'data_root_rekey_form\').submit(); })">Re-encrypt under a new key&hellip;</button></form>';
	}
	if (is_array($dre_enc) && $dre_open && !in_array(($dre_enc['reencrypt'] ?? ''), array('decrypt', 'reencrypt'), true)
			&& JobCommandBuilder::has_primitive($node, 'data_root_decrypt')) {
		echo '<form id="data_root_decrypt_form" method="post" action="' . $base_url . '" class="mt-3 d-inline-block me-2">'
			. '<input type="hidden" name="action" value="data_root_decrypt">' . SmAdminCsrf::field()
			. '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="JoineryModal.confirm(\'Decrypt this data root, back to plain, online? It runs in the background for hours; its data is readable in a copy of its disk from then on.\', function(){ document.getElementById(\'data_root_decrypt_form\').submit(); })">Decrypt (undo)</button></form>';
	}

	if ($dre_row && $held_here) {
		echo '<form id="data_root_escrow_retire_form" method="post" action="' . $base_url . '" class="mt-3">'
			. '<input type="hidden" name="action" value="data_root_escrow_retire">' . SmAdminCsrf::field()
			. '<button type="button" class="btn btn-sm btn-outline-danger" onclick="JoineryModal.confirm(\'Stop holding this machine\\\'s key? It opens by its recovery key at every boot until it is enrolled again.\', function(){ document.getElementById(\'data_root_escrow_retire_form\').submit(); })">Stop holding its key</button></form>';
	}
	$page->end_box();
}
