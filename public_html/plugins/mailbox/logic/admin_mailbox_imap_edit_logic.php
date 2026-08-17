<?php
/**
 * Logic for the IMAP account / mailbox editor.
 *
 * Two modes:
 *   - Plain feed editor (hosted-domain mailboxes): pick a bound mailbox from the
 *     dropdown; edit a feed by its account id.
 *   - Combined mailbox+feed editor (IMAP-source domains, domain_id present): the
 *     mailbox (a store-mode alias) is created/kept from the username under the
 *     domain, and a single "Edit" manages the mailbox name, its access grants,
 *     AND the feed together — creating the feed if the mailbox doesn't have one
 *     yet. This is what the Accounts tree's "+ Mailbox" and "Edit" point at for
 *     IMAP-source domains.
 *
 * Connection details for a known provider come from the preset catalog; the
 * app/basic password is a non-model field stored encrypted via setPassword().
 *
 * @version 2.6
 * @changelog 2.6 - edit-only, enforced: any arrival that resolves no existing
 *   feed is handed to the connect wizard, POSTs included; the ceremony actions
 *   (grant removal, seal/unseal batches) answer only to a POST.
 * @changelog 2.5 - a pulled-in mailbox carries its OWN protection level
 *   (specs/mailbox_connect_flow.md § D), raised and lowered through the same
 *   ceremony the domain uses, scoped to this one mailbox.
 * @changelog 2.4 - a sealing domain holds one reader, enforced where grants are
 *   written; "not checked yet" is told apart from "your provider cannot".
 * @changelog 2.3 - only a scope change that still reads backward rewinds the
 *   folder cursors; switching to future-only keeps them, so un-polled mail at
 *   the source is not skipped.
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_imap_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$settings = Globalvars::get_instance();

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

	$list_url = '/plugins/mailbox/admin/admin_mailbox_accounts';
	$editor_base = '/plugins/mailbox/admin/admin_mailbox_imap_edit';

	// --- Ceremony inline fixes and batch loops, scoped to one mailbox ---
	// The checklist this page renders offers the same in-place fixes the domain
	// editor's does, and they post back here — so the action is handled here.
	// Actions are POSTs, never links: a GET arrives with the session cookie
	// whenever any page tells the browser to fetch it, cross-site included.
	if (LibraryFunctions::isFormSubmission() && ($input['action'] ?? '') === 'ceremony_remove_grant') {
		$fix_alias = new InboundEmailAlias(intval($input['alias_id'] ?? 0), TRUE);
		$back = $list_url;
		if ($fix_alias->key) {
			$back = $editor_base . '?domain_id=' . intval($fix_alias->get('iea_ied_inbound_email_domain_id'))
				. '&alias_id=' . intval($fix_alias->key);
			$remaining = array();
			foreach (InboundEmailMailboxGrant::user_ids_for_alias(intval($fix_alias->key)) as $uid) {
				if (intval($uid) !== intval($input['user_id'] ?? 0)) {
					$remaining[] = intval($uid);
				}
			}
			try {
				InboundEmailMailboxGrant::sync_for_alias(intval($fix_alias->key), $remaining);
			} catch (InboundEmailMailboxGrantException $e) {
				// An already-sealing mailbox: this removal would leave it with no
				// one to seal to. Say so rather than failing the page — the row
				// that offered the button is still there, and the message names
				// the way out.
				$session->save_message(new DisplayMessage(
					$e->getMessage(), 'Access not changed', '~/plugins/mailbox/admin/~',
					DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			}
		}
		return LogicResult::redirect($back);
	}

	// The no-JS fallbacks behind the raise/lowering receipt cards, which render on
	// this page for a mailbox-scoped level change. Same passes the domain editor
	// runs; only the row set is narrower.
	if (LibraryFunctions::isFormSubmission()
			&& in_array(($input['action'] ?? ''), array('ceremony_seal_batch', 'ceremony_unseal_batch'), true)) {
		$scope_alias_id = intval($input['alias_scope_id'] ?? 0);
		$scope_alias = new InboundEmailAlias($scope_alias_id, TRUE);
		if ($scope_alias->key) {
			$scope_domain = new InboundEmailDomain(
				intval($scope_alias->get('iea_ied_inbound_email_domain_id')), TRUE);
			$back = $editor_base . '?domain_id=' . intval($scope_domain->key)
				. '&alias_id=' . $scope_alias_id;
			if ($input['action'] === 'ceremony_seal_batch' && $scope_alias->seals_content()) {
				mailbox_protection_seal_batch($scope_domain, 200, $scope_alias_id);
				return LogicResult::redirect($back . '&sealed_now=1');
			}
			if ($input['action'] === 'ceremony_unseal_batch' && !$scope_alias->seals_content()) {
				mailbox_protection_unseal_batch($scope_domain, intval($session->get_user_id()),
					25, $scope_alias_id);
				return LogicResult::redirect($back . '&unsealed_now=1');
			}
			return LogicResult::redirect($back);
		}
		return LogicResult::redirect($list_url);
	}

	// Combined mode: reached via "+ Mailbox" / "Edit" on an IMAP-source domain.
	// domain_id rides through the POST as a hidden field; alias_id identifies an
	// existing mailbox being edited (0 when adding a new one).
	$domain_id = intval($input['domain_id'] ?? 0);
	$domain = $domain_id > 0 ? new InboundEmailDomain($domain_id, TRUE) : null;
	$combined = (bool)($domain && $domain->key);
	$alias_id = intval($input['alias_id'] ?? 0);

	// Creation belongs to the connect wizard, and only to it
	// (specs/mailbox_connect_flow.md § A): it asks in the order the answers
	// exist, and one creation path is what keeps the mailbox, its domain, its
	// grant and its feed from being assembled two slightly different ways. This
	// editor EDITS. Arriving here with a domain and no mailbox is someone
	// following an old link to add one, so hand them to the wizard.
	if ($combined && $alias_id <= 0 && intval($input['edit_primary_key_value'] ?? 0) <= 0
			&& intval($input['iia_inbound_imap_account_id'] ?? 0) <= 0
			&& !LibraryFunctions::isFormSubmission()) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
		$suggested = InboundImapAccount::providerForEmailDomain($domain->get('ied_domain')) ?: 'imap_generic';
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_connect?provider='
			. rawurlencode($suggested));
	}

	// Load or create the account. In combined mode an alias_id with an existing
	// feed loads that feed (edit); without one, a new feed is created for it.
	$id = intval($input['edit_primary_key_value'] ?? $input['iia_inbound_imap_account_id'] ?? 0);
	if ($id > 0) {
		$account = new InboundImapAccount($id, TRUE);
		if (!$account->key) {
			return LogicResult::redirect($list_url);
		}
	} elseif ($combined && $alias_id > 0) {
		$feeds = new MultiInboundImapAccount(array('alias_id' => $alias_id, 'deleted' => false));
		$feeds->load();
		$account = count($feeds) ? new InboundImapAccount($feeds->get(0)->key, TRUE) : new InboundImapAccount(NULL);
	} else {
		$account = new InboundImapAccount(NULL);
	}

	// No feed resolved means this is an arrival on one of the old CREATE paths —
	// a bare URL, an old "+ IMAP feed" link, or a stale create-form POST. This
	// editor edits; a pulled-in mailbox comes into being through the connect
	// wizard and its provisioner, and nowhere else (specs/mailbox_connect_flow.md
	// rule 1). The GET guard above already sent the well-known link shapes there
	// with a provider suggestion; this catches every other way in.
	if (!$account->key) {
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_connect');
	}

	// Alias options for the plain (hosted) feed editor's dropdown.
	$aliases = new MultiInboundEmailAlias(array('deleted' => false), array('iea_alias' => 'ASC'));
	$aliases->load();
	$alias_options = array();
	foreach ($aliases as $a) {
		$alias_options[$a->key] = $a->get_full_address();
	}

	// Provider options + per-provider field visibility.
	$provider_options = array();
	$visibility = array();
	foreach (InboundImapAccount::PRESETS as $key => $p) {
		$provider_options[$key] = $p['label'];
		$show = array(); $hide = array();
		if ($key === 'imap_generic') {
			$show = array('iia_imap_host', 'iia_imap_port', 'iia_imap_encryption');
		} else {
			$hide = array('iia_imap_host', 'iia_imap_port', 'iia_imap_encryption');
		}
		if ($p['auth'] === InboundImapAccount::AUTH_PASSWORD) {
			$show[] = 'imap_password';
		} else {
			$hide[] = 'imap_password';
		}
		$visibility[$key] = array('show' => $show, 'hide' => $hide);
	}

	// Combined mode also manages the mailbox's access grants. Staff who can be
	// granted, plus the bound mailbox's current grants (when editing).
	$user_options = array();
	$granted_user_ids = array();
	if ($combined) {
		$staff = new MultiUser(
			array('permission_range' => array(5, 10), 'deleted' => false, 'not_system_users' => true),
			array('usr_first_name' => 'ASC', 'usr_last_name' => 'ASC')
		);
		$staff->load();
		$user_options = $staff->get_dropdown_array();

		$grant_alias_id = $account->key ? intval($account->get('iia_iea_inbound_email_alias_id')) : $alias_id;
		if ($grant_alias_id > 0) {
			$grants = new MultiInboundEmailMailboxGrant(array('alias_id' => $grant_alias_id));
			$grants->load();
			foreach ($grants as $g) {
				$granted_user_ids[] = intval($g->get('ieg_usr_user_id'));
			}
		}
	}

	// Sync settings (specs/two_way_imap_sync.md §8). Read-only / Two-way are
	// offered only when the server advertised CONDSTORE (cached on a prior
	// connect/Test); otherwise only Off, with a short note in the view.
	//
	// NOT KNOWN IS NOT NOT-SUPPORTED. The flag is a cached probe result that
	// starts false, so a mailbox that has never connected looks identical to one
	// whose provider genuinely cannot do it — and the form told the operator their
	// provider was incapable when nothing had been asked yet. The two states are
	// separated here so the view can say which one it is.
	$sync_supported = $account->supportsCondstore();
	$sync_checked   = $account->key && ($account->get('iia_last_poll_time') || $sync_supported);
	$sync_options = $sync_supported
		? array(
			InboundImapAccount::SYNC_OFF  => 'Off',
			InboundImapAccount::SYNC_PULL => 'Read-only (follow the source)',
			InboundImapAccount::SYNC_BOTH => 'Two-way (full sync)',
		)
		: array(InboundImapAccount::SYNC_OFF => 'Off');
	$sync_visibility = array(
		InboundImapAccount::SYNC_OFF  => array('hide' => array('iia_sync_deletes', 'iia_show_compose')),
		InboundImapAccount::SYNC_PULL => array('show' => array('iia_sync_deletes', 'iia_show_compose')),
		InboundImapAccount::SYNC_BOTH => array('show' => array('iia_sync_deletes', 'iia_show_compose')),
	);

	// Discovered folders the operator can track. The \All coverage view is tracked
	// silently (a coverage source, not a membership folder, §6.1) and excluded.
	$folder_options = array();
	$tracked_folder_ids = array();
	// The same folders keyed by NAME, for the "which folder does mail come from"
	// picker. Once the source has been read once there is no reason to make
	// somebody type a folder name and guess at its capitalisation — outside the
	// special Inbox, IMAP folder names are case-sensitive and a near miss simply
	// finds nothing.
	$folder_names = array();
	if ($account->key) {
		$folders = new MultiInboundImapFolder(array('account_id' => intval($account->key)), array('iif_name' => 'ASC'));
		$folders->load();
		foreach ($folders as $f) {
			$name = (string)$f->get('iif_name');
			if ($name !== '') { $folder_names[$name] = $name; }
			if ($f->get('iif_role') === InboundImapFolder::ROLE_ALL) { continue; }
			$folder_options[intval($f->key)] = $name;
			if ($f->get('iif_is_tracked')) { $tracked_folder_ids[] = intval($f->key); }
		}
	}
	// A configured folder that is no longer on the server still has to be
	// selectable, or saving any other field would silently move the feed.
	$configured_folder = (string)($account->get('iia_imap_folder') ?: 'INBOX');
	if ($folder_names && !isset($folder_names[$configured_folder])) {
		$folder_names[$configured_folder] = $configured_folder . ' (not found on the server)';
	}

	// Save.
	if (isset($input['iia_provider_key']) && ($input['_submitted'] ?? '') === '1') {
		$provider = $input['iia_provider_key'];
		if (!isset(InboundImapAccount::PRESETS[$provider])) {
			$provider = 'imap_generic';
		}
		$preset = InboundImapAccount::PRESETS[$provider];

		$account->set('iia_provider_key', $provider);
		$account->set('iia_label', trim((string)($input['iia_label'] ?? '')));
		$account->set('iia_username', trim((string)($input['iia_username'] ?? '')));
		// Import scope — changeable at any time. A change that still reads backward
		// (full, or a day window) re-seeds the feed: widening backfills further
		// (dedup prevents re-storing mail already imported), a changed day window
		// moves to its new boundary. Switching to future-only keeps the cursor, so
		// mail that arrived at the source since the last poll is still picked up.
		// Already-imported mail is never removed either way.
		// The per-folder cursor is rewound after the save (below) — it is what the
		// ingester actually reads, and rewinding it before a save that might fail
		// would strand the feed with a cleared cursor and the old import scope.
		$was_scope = $account->importScope();
		$was_days = $account->importDays();
		$account->set('iia_import_scope', $input['import_scope'] ?? InboundImapAccount::SCOPE_FUTURE);
		$account->set('iia_import_days', intval($input['iia_import_days'] ?? 0));
		// importScope()/importDays() normalize on read, so the comparison is against
		// the same values prepare() will persist — no need to normalize first.
		$rewind_for_backfill = $account->importScopeRequiresRewind($was_scope, $was_days);
		if ($rewind_for_backfill) {
			$account->set('iia_uidvalidity', null);
			$account->set('iia_last_seen_uid', null);
		}
		$account->set('iia_imap_folder', trim((string)($input['iia_imap_folder'] ?? 'INBOX')) ?: 'INBOX');
		$account->set('iia_poll_interval_seconds', intval($input['iia_poll_interval_seconds'] ?? 300) ?: 300);
		$account->set('iia_is_enabled', isset($input['iia_is_enabled']) ? true : false);

		// Sync mode + gates. prepare() downgrades the mode to Off if CONDSTORE is not
		// (yet) cached, so a non-CONDSTORE feed can never be left half-on.
		$wantMode = $input['iia_sync_mode'] ?? InboundImapAccount::SYNC_OFF;
		if (!in_array($wantMode, array(InboundImapAccount::SYNC_OFF, InboundImapAccount::SYNC_PULL, InboundImapAccount::SYNC_BOTH), true)) {
			$wantMode = InboundImapAccount::SYNC_OFF;
		}
		$account->set('iia_sync_mode', $wantMode);
		$account->set('iia_sync_deletes', isset($input['iia_sync_deletes']));
		$account->set('iia_show_compose', isset($input['iia_show_compose']));

		// Bind the mailbox. Combined mode resolves it from the username under the
		// domain (creating it / keeping it named to match); plain mode uses the
		// dropdown selection.
		$resolved_alias_id = 0;
		$alias_pre_existing = false;
		if ($combined) {
			$username = trim((string)($input['iia_username'] ?? ''));
			$local = strstr($username, '@', true);
			if ($local === false) { $local = $username; }
			$existing_alias = $account->key
				? intval($account->get('iia_iea_inbound_email_alias_id'))
				: $alias_id;
			// Remembered before the resolve, because "was this mailbox already
			// here?" decides whether a protection choice is an initial choice or a
			// change — and nothing can ask afterwards.
			$alias_pre_existing = ($existing_alias > 0);
			$resolved_alias_id = _imap_edit_resolve_mailbox($domain_id, trim($local), $existing_alias);
			$account->set('iia_iea_inbound_email_alias_id', $resolved_alias_id);
		} else {
			$account->set('iia_iea_inbound_email_alias_id', intval($input['iia_iea_inbound_email_alias_id'] ?? 0) ?: null);
		}

		// Connection details: preset-driven for known hosts, user-supplied for generic.
		if ($provider === 'imap_generic') {
			$account->set('iia_imap_host', trim((string)($input['iia_imap_host'] ?? '')));
			$account->set('iia_imap_port', intval($input['iia_imap_port'] ?? 993) ?: 993);
			$enc = $input['iia_imap_encryption'] ?? 'ssl';
			$account->set('iia_imap_encryption', in_array($enc, array('ssl', 'tls', 'none'), true) ? $enc : 'ssl');
		} else {
			$account->set('iia_imap_host', $preset['host']);
			$account->set('iia_imap_port', $preset['port']);
			$account->set('iia_imap_encryption', $preset['encryption']);
		}

		// Password: only for password-auth providers; blank-on-edit keeps the existing.
		if ($preset['auth'] === InboundImapAccount::AUTH_PASSWORD) {
			$pw = (string)($input['imap_password'] ?? '');
			if ($pw !== '') {
				$account->setPassword($pw);
			}
		}

		try {
			$account->prepare();
			$account->save();
			$account->load();

			// The scope now reaches backward from a different boundary than the
			// feed has already read to: rewind every folder cursor so the next
			// fetch re-seeds against the new scope instead of carrying on from
			// where it was.
			if ($rewind_for_backfill) {
				InboundImapFolder::rewindCursors(intval($account->key));
			}

			// Combined mode: the mailbox's protection level and its access grants,
			// which are one decision — sealing encrypts to exactly one holder's key.
			if ($combined && $resolved_alias_id > 0) {
				$submitted = array();
				// A list from the checkboxes, a single id from the sealing-mailbox
				// picker. Both mean the same thing — who holds this mailbox — so both
				// arrive under one name rather than the caller having to know which
				// control rendered.
				if (isset($input['users_with_access'])) {
					foreach ((array)$input['users_with_access'] as $uid) {
						if (intval($uid) > 0) { $submitted[] = intval($uid); }
					}
				}
				$submitted = array_values(array_unique($submitted));

				$alias_row = new InboundEmailAlias($resolved_alias_id, TRUE);
				$old_level = $alias_row->security_level();
				$new_level = _imap_edit_submitted_level($input, $domain, $old_level);
				$old_seals = in_array($old_level, array(InboundEmailDomain::LEVEL_PRIVATE,
					InboundEmailDomain::LEVEL_FORTRESS), true);
				$new_seals = in_array($new_level, array(InboundEmailDomain::LEVEL_PRIVATE,
					InboundEmailDomain::LEVEL_FORTRESS), true);

				// Changing a mailbox's protection is the same sensitive action the
				// domain editor gates — re-confirm the account's second factor first.
				// Not on creation: an initial choice is not a change, and there is no
				// mail under it yet.
				if ($alias_pre_existing && $new_level !== $old_level) {
					// target_level rides the return URL so the picker comes back on
					// the choice they made: the step-up drops the POST, and silently
					// discarding their intent is how an operator ends up thinking
					// the save did nothing.
					$stepup = $session->require_recent_second_factor(
						$editor_base . '?domain_id=' . intval($domain->key)
						. '&alias_id=' . $resolved_alias_id
						. '&target_level=' . rawurlencode($new_level));
					if ($stepup !== null) {
						return $stepup;
					}
				}

				// Lowering first, raising last. A lowering relaxes the one-holder
				// rule, so writing it before the grants sync means "share this
				// mailbox and set it to Standard" is one submit rather than two; a
				// raise has to see the final holder set before it is allowed at all.
				if (!$new_seals && $old_seals) {
					if (!_imap_edit_lowering_allowed($session)) {
						throw new InboundEmailAliasException(
							'Unlock your vault before lowering protection on this mailbox.');
					}
					_imap_edit_write_level($alias_row, $new_level);
				}

				InboundEmailMailboxGrant::sync_for_alias($resolved_alias_id, $submitted);

				if ($new_seals && $new_level !== $old_level) {
					// The same checklist the domain editor renders, gathered for this
					// one mailbox. The rows are about holders, vaults and passkeys —
					// never the level — so evaluating them here, after the grants are
					// written, judges exactly the state the raise would seal into.
					$acting_user_id = intval($session->get_user_id());
					$rows = mailbox_protection_rows(
						mailbox_protection_facts($domain, $acting_user_id, $resolved_alias_id),
						$new_level, $acting_user_id);
					if (!mailbox_protection_required_ok($rows)) {
						throw new InboundEmailAliasException(mailbox_protection_first_failure($rows));
					}
					_imap_edit_write_level($alias_row, $new_level);
				}

				// A level change may alter the acting user's max posture — drop the
				// session cache so the unlock-window caps re-evaluate.
				if ($new_level !== $old_level) {
					unset($_SESSION['max_security_level']);
					// The raise seals history, the lowering unseals it: either way the
					// receipt card on this page carries the convergence to done.
					$receipt = $new_seals ? 'sealed_now=1' : ($old_seals ? 'unsealed_now=1' : '');
					if ($receipt !== '') {
						$session->save_message(new DisplayMessage(
							'Mailbox saved.', 'Accounts', '~/plugins/mailbox/admin/~',
							DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
						return LogicResult::redirect($editor_base . '?domain_id=' . intval($domain->key)
							. '&alias_id=' . $resolved_alias_id . '&' . $receipt);
					}
				}
			}

			// Folder tracking: a hidden marker tells us the selector was shown so an
			// all-unchecked submit means "track nothing" rather than "no change". The
			// \All coverage view is always tracked and never in the toggle list.
			if ($account->key && ($input['_folders_present'] ?? '') === '1') {
				$submittedFolders = array_map('intval', (array)($input['tracked_folders'] ?? array()));
				$allFolders = new MultiInboundImapFolder(array('account_id' => intval($account->key)));
				$allFolders->load();
				foreach ($allFolders as $f) {
					if ($f->get('iif_role') === InboundImapFolder::ROLE_ALL) { continue; }
					$want = in_array(intval($f->key), $submittedFolders, true);
					if ((bool)$f->get('iif_is_tracked') !== $want) {
						$folder = new InboundImapFolder($f->key, TRUE);
						$folder->set('iif_is_tracked', $want);
						$folder->prepare();
						$folder->save();
					}
				}
			}

			$is_oauth = $account->isOAuth();
			$msg = $is_oauth && !$account->hasOAuthToken()
				? 'Mailbox saved. Click "Connect" to authorize mailbox access.'
				: 'Mailbox saved.';
			if ($rewind_for_backfill) {
				$msg .= ' The feed will re-read from the source, importing '
					. $account->describeImportScope() . '.';
			}
			$session->save_message(new DisplayMessage(
				$msg, 'Accounts', '~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect($list_url);
		} catch (Throwable $e) {
			return LogicResult::render(array(
				'session' => $session, 'settings' => $settings, 'account' => $account,
				'alias_options' => $alias_options, 'provider_options' => $provider_options,
				'visibility' => $visibility, 'combined' => $combined, 'domain' => $domain,
				'bound_alias_id' => $alias_id, 'user_options' => $user_options,
				'granted_user_ids' => $granted_user_ids,
				'sync_supported' => $sync_supported, 'sync_options' => $sync_options,
				'sync_visibility' => $sync_visibility, 'folder_options' => $folder_options,
				'folder_names' => $folder_names, 'sync_checked' => $sync_checked,
				'tracked_folder_ids' => $tracked_folder_ids,
				'ceremony' => _imap_edit_ceremony_state($domain,
					$resolved_alias_id ?: $alias_id, $session, $input),
				'error' => $e->getMessage(),
			));
		}
	}

	// New account defaults.
	if (!$account->key) {
		$account->set('iia_is_enabled', true);
		$account->set('iia_imap_port', 993);
		$account->set('iia_imap_encryption', 'ssl');
		$account->set('iia_imap_folder', 'INBOX');
		$account->set('iia_poll_interval_seconds', 300);
		$account->set('iia_provider_key', 'imap_generic');
		if ($combined) {
			// Default the provider from the IMAP-source domain (gmail.com -> Gmail).
			$account->set('iia_provider_key',
				InboundImapAccount::providerForEmailDomain($domain->get('ied_domain')) ?: 'imap_generic');
			// Editing an existing mailbox that has no feed yet — prefill the username
			// from its address so the operator doesn't retype it.
			if ($alias_id > 0) {
				$bound = new InboundEmailAlias($alias_id, TRUE);
				if ($bound->key) {
					$account->set('iia_username', $bound->get_full_address());
				}
			}
		} elseif (!empty($input['alias_id'])) {
			$account->set('iia_iea_inbound_email_alias_id', intval($input['alias_id']));
		}
	}

	return LogicResult::render(array(
		'session' => $session, 'settings' => $settings, 'account' => $account,
		'alias_options' => $alias_options, 'provider_options' => $provider_options,
		'visibility' => $visibility, 'combined' => $combined, 'domain' => $domain,
		'bound_alias_id' => $alias_id, 'user_options' => $user_options,
		'granted_user_ids' => $granted_user_ids,
		'sync_supported' => $sync_supported, 'sync_options' => $sync_options,
		'sync_visibility' => $sync_visibility, 'folder_options' => $folder_options,
				'folder_names' => $folder_names, 'sync_checked' => $sync_checked,
		'tracked_folder_ids' => $tracked_folder_ids,
		// The mailbox's protection surface: the checklist before a raise, the
		// receipt after one. Only for a pulled-in mailbox that already exists —
		// a mailbox being created has no history to converge.
		'ceremony' => _imap_edit_ceremony_state($domain,
			$account->key ? intval($account->get('iia_iea_inbound_email_alias_id')) : $alias_id,
			$session, $input),
	));
}

/**
 * The protection level the operator submitted for a pulled-in mailbox
 * (specs/mailbox_connect_flow.md § D), falling back to what it already has.
 *
 * Only Standard and Private are offered, and only on an IMAP-source domain.
 * Fortress is an identity guarantee — relay-side sealing, inverted DNS, in-app
 * signing — and none of it exists for mail pulled from somebody else's server,
 * so a stale POST cannot smuggle it in. A hosted mailbox has no level of its
 * own at all: it inherits its domain's, which is where MX, SPF, DMARC and DKIM
 * are decided.
 */
function _imap_edit_submitted_level(array $input, ?InboundEmailDomain $domain, string $current): string {
	if (!$domain || !$domain->key || !$domain->get('ied_is_imap_source')
			|| !array_key_exists('iea_security_level', $input)) {
		return $current;
	}
	$want = strtolower(trim((string)$input['iea_security_level']));
	return in_array($want, array(InboundEmailDomain::LEVEL_STANDARD,
		InboundEmailDomain::LEVEL_PRIVATE), true) ? $want : $current;
}

/**
 * Persist a mailbox's own level. Standard on an IMAP-source mailbox is stored
 * explicitly rather than as "inherit": the operator chose it for this mailbox,
 * and a later change to the domain must not silently reopen mail they decided
 * to leave in the clear — or seal mail they decided to leave readable.
 */
function _imap_edit_write_level(InboundEmailAlias $alias, string $level): void {
	$alias->set('iea_security_level', $level);
	$alias->prepare();
	$alias->save();
	$alias->load();
}

/**
 * Lowering protection needs the acting admin's own key open — an idle session
 * must not quietly downgrade a mailbox. Someone with no vault at all is not
 * being asked for one they cannot have.
 */
function _imap_edit_lowering_allowed($session): bool {
	$acting_user_id = intval($session->get_user_id());
	if ($acting_user_id <= 0) {
		return false;
	}
	if (UserEncryptionVault::loadForUser($acting_user_id) === null) {
		return true;
	}
	return VaultUnlock::isOpen($acting_user_id);
}

/**
 * The ceremony state this editor renders for ONE mailbox: the checklist for a
 * raise to Private, and the raise/lowering receipt that converges history
 * afterwards. The same functions the domain editor calls, scoped to this alias
 * — there is no second ceremony.
 */
function _imap_edit_ceremony_state($domain, int $alias_id, $session, array $input): ?array {
	if (!$domain || !$domain->key || $alias_id <= 0 || !$domain->get('ied_is_imap_source')) {
		return null;
	}
	$alias = new InboundEmailAlias($alias_id, TRUE);
	if (!$alias->key) {
		return null;
	}
	$acting_user_id = intval($session->get_user_id());
	$facts = mailbox_protection_facts($domain, $acting_user_id, $alias_id);
	$backlog = mailbox_protection_backlog_count(intval($domain->key), $alias_id);
	$seals = $alias->seals_content();

	$state = array(
		'alias_id'    => $alias_id,
		'address'     => (string)$alias->get_full_address(),
		'level'       => $alias->security_level(),
		'facts'       => $facts,
		'rows_private' => mailbox_protection_rows($facts, InboundEmailDomain::LEVEL_PRIVATE, $acting_user_id),
		'backlog'     => $backlog,
		'sealed_total' => mailbox_protection_sealed_count(intval($domain->key), $alias_id),
		'acting_user_id' => $acting_user_id,
		'editor_url'  => '/plugins/mailbox/admin/admin_mailbox_imap_edit?domain_id='
			. intval($domain->key) . '&alias_id=' . $alias_id,
		// The receipt renders on arrival from a raise and on any later visit while
		// a backlog remains, so a backlog that appears later still converges.
		'sealing_active' => $seals && ($backlog > 0 || !empty($input['sealed_now'])),
		'unseal_active'  => false,
	);
	if (!$seals) {
		$counts = mailbox_protection_unseal_counts($domain, $acting_user_id, $alias_id);
		$state['unseal_own_backlog']    = $counts['own'];
		$state['unseal_others_backlog'] = $counts['others'];
		$state['unseal_active'] = ($counts['own'] + $counts['others'] > 0)
			|| !empty($input['unsealed_now']);
		$state['window_open'] = ($acting_user_id > 0) && VaultUnlock::isOpen($acting_user_id);
	}
	return $state;
}

/**
 * Resolve the store-mode mailbox (alias) for a combined feed under $domain_id,
 * named after the username's local part. If $existing_alias_id is given it is
 * kept (renamed to match $local when needed); otherwise an existing same-named
 * mailbox is reused or a new one created. Returns the alias id.
 */
function _imap_edit_resolve_mailbox(int $domain_id, string $local, int $existing_alias_id = 0): int {
	if ($local === '') {
		throw new InboundEmailAliasException('Enter the mailbox username (the address to poll).');
	}

	if ($existing_alias_id > 0) {
		$alias = new InboundEmailAlias($existing_alias_id, TRUE);
		if ($alias->key) {
			if (strcasecmp((string)$alias->get('iea_alias'), $local) !== 0) {
				$alias->set('iea_alias', $local);
				$alias->prepare();
				$alias->save();
			}
			return intval($alias->key);
		}
	}

	$existing = new MultiInboundEmailAlias(array('domain_id' => $domain_id, 'alias' => $local, 'deleted' => false));
	$existing->load();
	if (count($existing)) {
		return intval($existing->get(0)->key);
	}

	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_is_enabled', true);
	$alias->prepare();
	$alias->save();
	$alias->load();
	return intval($alias->key);
}
?>
