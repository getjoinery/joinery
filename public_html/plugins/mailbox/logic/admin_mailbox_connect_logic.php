<?php
/**
 * Logic for the connect wizard — adding a mailbox whose mail lives somewhere
 * else (specs/mailbox_connect_flow.md § A).
 *
 * The old path walked the operator through the STORAGE LAYOUT: register a
 * domain, create a mailbox under it with settings nothing could know yet, save,
 * discover the provider was never registered, leave to do that, come back,
 * connect, and go round again for the questions that only became answerable
 * once connected. Every one of those round trips existed because a row had to
 * exist before consent could attach a token to it — an implementation ordering
 * that had become the operator's ordering.
 *
 * This asks in the order the answers exist:
 *
 *   1. provider  — where does this mail live? Nothing else.
 *   2. register  — is this site registered with that provider? Shown only when
 *                  it is not, for the chosen provider only, with the fields here.
 *   3. signin    — sign in. Consent runs before anything unanswerable is asked.
 *   4. configure — now, with the address confirmed and the real folder list, the
 *                  settings that were guesses before.
 *
 * The wizard owns CREATION ONLY. Editing an existing mailbox stays in the
 * per-object editors, unchanged, and the mailbox itself is built by
 * ImapFeedProvisioner — the one path a pulled-in mailbox comes into being by.
 *
 * @version 1.4
 * @changelog 1.4 - the configure step offers the sync choice (Off / Read-only /
 *   Two-way) when the just-connected server supports it: capabilities are
 *   detected on the same connection that discovers folders, and the saved mode
 *   still passes prepare()'s capability check
 * @changelog 1.3 - a password sign-in proves itself against the mail server
 *   before anything is created: a refused credential fails on the signin form
 *   with the server's reason (and the typed address kept), never as a fetch
 *   error after the wizard has finished
 * @changelog 1.2 - sign-in offers the easiest method that works first: a host
 *   supporting both (Gmail) defaults to its app password, with OAuth behind an
 *   explicit method=oauth2 choice, so nobody is sent to a developer console who
 *   never asked for one; app passwords from preset hosts are stored without
 *   their display spacing; a delegated OAuth-capable mailbox is stamped oauth2
 *   so its Connect button appears
 * @changelog 1.1 - a refusable intent (missing reader, Private with no vault)
 *   is refused before the consent round trip; every provisioning failure is
 *   stated on the form rather than only the provisioner's own exception type;
 *   configure finishes creation only and cannot switch on a feed with no
 *   sign-in; an empty registration submit names what is still missing
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_connect_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
	require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
	require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderConfig.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapFeedProvisioner.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapConnectStash.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	// Full-mailbox credentials, like every other IMAP surface.
	$session->check_permission(10);
	$settings = Globalvars::get_instance();

	$accounts_url = '/plugins/mailbox/admin/admin_mailbox_accounts';
	$wizard_url   = '/plugins/mailbox/admin/admin_mailbox_connect';

	$provider_key = (string)($input['provider'] ?? '');
	if (!isset(InboundImapAccount::PRESETS[$provider_key])) {
		$provider_key = '';
	}
	$preset = $provider_key !== '' ? InboundImapAccount::PRESETS[$provider_key] : null;
	$oauth_key   = $preset['oauth_provider'] ?? null;
	$oauth_class = ($oauth_key !== null) ? OAuth2ProviderRegistry::get($oauth_key) : null;

	// Which sign-in this flow is making. The preset's default is the easiest
	// method that works for the host — an app password wherever the host still
	// honors one — and OAuth is chosen, never imposed, via method=oauth2. The
	// choice rides the URL so a reload or a form round trip stays on the path
	// the operator picked.
	$auth_methods = $provider_key !== '' ? InboundImapAccount::authMethodsFor($provider_key) : array();
	$method = (string)($input['method'] ?? '');
	if (!in_array($method, $auth_methods, true)) {
		$method = $auth_methods[0] ?? '';
	}
	$use_oauth = ($oauth_class !== null && $method === InboundImapAccount::AUTH_OAUTH2);

	// Who can be named as the mailbox's reader. Defaults to the operator, who is
	// the answer nearly every time — they are connecting their own account.
	$staff = new MultiUser(
		array('permission_range' => array(5, 10), 'deleted' => false, 'not_system_users' => true),
		array('usr_first_name' => 'ASC', 'usr_last_name' => 'ASC')
	);
	$staff->load();
	$reader_options = $staff->get_dropdown_array();
	$acting_user_id = intval($session->get_user_id());

	$error = null;

	// --- register: the app credentials, collected in place ------------------
	// Not a new pattern: OAuth2ProviderConfig takes a field prefix precisely so a
	// page can collect a registration where the operator already is, as the DNS
	// publish box does. The standalone providers page stays the place to manage
	// every provider at once.
	if (LibraryFunctions::isFormSubmission() && isset($input['save_registration']) && $oauth_class !== null) {
		$error = OAuth2ProviderConfig::save($oauth_class, $input, '', $session);
		if ($error === '' && !$oauth_class::isConfigured()) {
			// Blank fields are skipped by the save, so an empty submit "succeeds"
			// while changing nothing — which would re-render this same form with
			// no explanation. Name what is still missing instead.
			$error = 'Nothing was saved — the ' . $oauth_class::getLabel()
				. ' app details above are still needed before anyone can sign in.';
		}
		if ($error === '') {
			// Registration only happens on the OAuth path; keep the flow on it.
			return LogicResult::redirect($wizard_url . '?provider=' . rawurlencode($provider_key)
				. '&method=' . rawurlencode(InboundImapAccount::AUTH_OAUTH2));
		}
	}

	// --- signin: begin consent, carrying the INTENT ------------------------
	// The payload names what was chosen before signing in, and no ids, because
	// none of those rows exist yet (§ B). OAuth2State keeps it server-side in
	// this session, single-use and expiring, so intent travels safely.
	if (LibraryFunctions::isFormSubmission() && isset($input['begin_signin']) && $provider_key !== '') {
		$intent = _connect_intent($input, $acting_user_id);
		$intent_error = _connect_intent_error($intent);
		if ($intent_error !== null) {
			$error = $intent_error;
		} elseif (!$use_oauth) {
			// A password sign-in has no consent step: the app password IS the
			// sign-in, so the mailbox can be built the moment it is entered.
			return _connect_password_signin($input, $provider_key, $intent, $session, $wizard_url);
		} else {
			try {
				$client = new OAuth2Client();
				$consent_url = $client->beginConsent(
					$oauth_key,
					_connect_scopes($oauth_key, $oauth_class),
					'inbound_imap',
					array(
						'provider_key'   => $provider_key,
						'reader_user_id' => $intent['reader_user_id'],
						'security_level' => $intent['security_level'],
					),
					$wizard_url . '?provider=' . rawurlencode($provider_key)
				);
				return LogicResult::redirect($consent_url);
			} catch (Throwable $e) {
				$error = 'Could not start the sign-in: ' . $e->getMessage();
			}
		}
	}

	// --- signin: someone else will sign in ----------------------------------
	// The one thing a consent-first wizard would otherwise lose. The mailbox is
	// created with no token and left disabled on the Accounts tree with its
	// normal Connect button, finished later by a permission-10 admin on the
	// owner's device. No new consent mechanism — the existing account_id path.
	if (LibraryFunctions::isFormSubmission() && isset($input['delegate']) && $provider_key !== '') {
		$address = strtolower(trim((string)($input['address'] ?? '')));
		$intent = _connect_intent($input, $acting_user_id);
		$intent_error = _connect_intent_error($intent);
		if ($intent_error !== null) {
			$error = $intent_error;
		} else {
			try {
				$notes = array();
				$account = ImapFeedProvisioner::provision($provider_key, $address, $intent, null, $notes);
				// Delegation exists so the owner can consent later — the Connect
				// button that finishes it only appears on an OAuth account, so the
				// method is decided here, not by the host's default.
				if ($oauth_class !== null
						&& $account->get('iia_auth_method') !== InboundImapAccount::AUTH_OAUTH2) {
					$account->set('iia_auth_method', InboundImapAccount::AUTH_OAUTH2);
					$account->save();
				}
				foreach ($notes as $note) {
					$session->save_message(new DisplayMessage(
						htmlspecialchars($note), 'Protection', '~/plugins/mailbox/admin/~',
						DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
				}
				$session->save_message(new DisplayMessage(
					htmlspecialchars($address) . ' is set up and waiting to be connected. Its Connect '
						. 'button is on this page — press it while they are with you, or on their device.',
					'Mailbox created', '~/plugins/mailbox/admin/~',
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
				return LogicResult::redirect($accounts_url);
			} catch (Throwable $e) {
				$error = $e->getMessage();
			}
		}
	}

	// --- configure: the address, when the provider could not tell us --------
	// The grant is already held; this is one question, then a retry of the
	// provisioning that could not run without an answer.
	if (LibraryFunctions::isFormSubmission() && isset($input['save_address'])) {
		$held = ImapConnectStash::peek();
		if ($held === null) {
			$session->save_message(new DisplayMessage(
				'That sign-in has expired. Signing in again takes a click and loses nothing.',
				'Sign in again', '~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return LogicResult::redirect($wizard_url);
		}
		$address = strtolower(trim((string)($input['address'] ?? '')));
		try {
			$notes = array();
			$account = ImapFeedProvisioner::provision(
				$held['provider_key'], $address, $held['intent'], $held['token'], $notes);
			foreach ($notes as $note) {
				$session->save_message(new DisplayMessage(
					htmlspecialchars($note), 'Protection', '~/plugins/mailbox/admin/~',
					DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			}
			ImapConnectStash::clear();
			return LogicResult::redirect($wizard_url . '?state=configure&account_id='
				. intval($account->key) . '&connected=1');
		} catch (Throwable $e) {
			// The stash survives — the grant is still held, so a corrected answer
			// retries provisioning without another sign-in.
			$error = $e->getMessage();
		}
	}

	// --- configure: finish, and start fetching ------------------------------
	if (LibraryFunctions::isFormSubmission() && isset($input['save_configure'])) {
		$account = new InboundImapAccount(intval($input['account_id'] ?? 0), TRUE);
		if (!$account->key) {
			return LogicResult::redirect($accounts_url);
		}
		// The wizard finishes CREATION. A feed that is already running belongs to
		// its editor (rule 4: the wizard creates, the editors edit) — and a feed
		// with no credential yet cannot be switched on, only pointed at its
		// Connect button.
		if ($account->get('iia_is_enabled')) {
			return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_imap_edit?'
				. 'iia_inbound_imap_account_id=' . intval($account->key));
		}
		if (!$account->isConnectable()) {
			$session->save_message(new DisplayMessage(
				htmlspecialchars((string)$account->get('iia_username')) . ' has no sign-in yet, so it '
					. 'cannot start collecting. Press its Connect button first.',
				'Not connected yet', '~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return LogicResult::redirect($accounts_url);
		}
		$scope = (string)($input['import_scope'] ?? InboundImapAccount::SCOPE_FUTURE);
		$account->set('iia_import_scope', $scope);
		$account->set('iia_import_days', intval($input['iia_import_days'] ?? 0)
			?: InboundImapAccount::IMPORT_DAYS_DEFAULT);
		$account->set('iia_imap_folder', trim((string)($input['iia_imap_folder'] ?? 'INBOX')) ?: 'INBOX');
		$account->set('iia_label', trim((string)($input['iia_label'] ?? '')) ?: (string)$account->get('iia_username'));
		// The sync choice, offered only when the server said it could (the form
		// omits the control otherwise, so these default to Off). prepare() below
		// re-checks against the cached capability, so a forged mode cannot stick.
		$sync_mode = (string)($input['iia_sync_mode'] ?? InboundImapAccount::SYNC_OFF);
		if (!in_array($sync_mode, array(InboundImapAccount::SYNC_OFF,
				InboundImapAccount::SYNC_PULL, InboundImapAccount::SYNC_BOTH), true)) {
			$sync_mode = InboundImapAccount::SYNC_OFF;
		}
		$account->set('iia_sync_mode', $sync_mode);
		$account->set('iia_sync_deletes', !empty($input['iia_sync_deletes']));
		$account->set('iia_show_compose', !empty($input['iia_show_compose']));
		// This is the moment the feed becomes real: everything it needs has been
		// answered, so it starts fetching.
		$account->set('iia_is_enabled', true);
		try {
			$account->prepare();
			$account->save();
			$session->save_message(new DisplayMessage(
				htmlspecialchars((string)$account->get('iia_username'))
					. ' is connected and will start collecting mail on the next fetch.',
				'Mailbox connected', '~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return LogicResult::redirect($accounts_url);
		} catch (Throwable $e) {
			$error = $e->getMessage();
		}
	}

	// --- which state to render ---------------------------------------------
	// Chosen by what is KNOWN, never by a step counter in the URL, so a reload
	// or a back button lands on the step the flow is actually at.
	$state = 'provider';
	$account = null;
	$held = null;

	if ((string)($input['state'] ?? '') === 'configure') {
		$account_id = intval($input['account_id'] ?? 0);
		if ($account_id > 0) {
			$account = new InboundImapAccount($account_id, TRUE);
		}
		$held = ImapConnectStash::peek();
		if (($account !== null && $account->key) || $held !== null) {
			$state = 'configure';
			if ($account !== null && $account->key) {
				$provider_key = (string)$account->get('iia_provider_key');
			} elseif ($held !== null) {
				$provider_key = $held['provider_key'];
			}
		}
	}
	if ($state !== 'configure' && $provider_key !== '') {
		// Registration is a fact about the OAUTH path only. A host whose default
		// sign-in is an app password never routes through it uninvited.
		$state = ($use_oauth && !$oauth_class::isConfigured()) ? 'register' : 'signin';
	}
	if (!empty($input['provision_error']) && $error === null) {
		$error = (string)$input['provision_error'];
	}

	// The folder list is a fact about the CONNECTED account — the server's own
	// names, with their real capitalisation, in the account's own language — so
	// it does not exist until the connection does. That is the whole reason this
	// step comes last, and the reason it asks the server now rather than
	// offering a guess: one LIST, on the credential that was just granted.
	//
	// Best effort. A discovery that fails costs the folder picker and nothing
	// else — the mailbox is connected, INBOX is the default, and the feed
	// discovers folders on its first poll anyway.
	$folder_names = array();
	if ($state === 'configure' && $account !== null && $account->key) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
		if ($account->isConnectable()) {
			try {
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
				$ingestor = new ImapIngestor($account);
				// Same connection answers both questions: what folders exist, and
				// whether the server can keep two copies in step (CONDSTORE) — the
				// capability that decides whether the sync choice below is real.
				$ingestor->detectCapabilities();
				$ingestor->discoverFolders();
				$ingestor->close();
			} catch (Throwable $e) {
				error_log('admin_mailbox_connect: folder discovery failed for account '
					. intval($account->key) . ': ' . $e->getMessage());
			}
		}
		$folders = new MultiInboundImapFolder(array('account_id' => intval($account->key)),
			array('iif_name' => 'ASC'));
		$folders->load();
		foreach ($folders as $folder) {
			$name = (string)$folder->get('iif_name');
			if ($name !== '') { $folder_names[$name] = $name; }
		}
	}

	// Whether to offer the sync choice on the configure form. The capability was
	// stamped by detectCapabilities() on the discovery connection just above, so
	// on the normal path this is a fresh answer, not a stale cache. Same shapes
	// as the editor (admin_mailbox_imap_edit_logic) so the two forms match.
	$sync_supported = ($state === 'configure' && $account !== null && $account->key)
		? $account->supportsCondstore() : false;
	$sync_visibility = array(
		InboundImapAccount::SYNC_OFF  => array('hide' => array('iia_sync_deletes', 'iia_show_compose')),
		InboundImapAccount::SYNC_PULL => array('show' => array('iia_sync_deletes', 'iia_show_compose')),
		InboundImapAccount::SYNC_BOTH => array('show' => array('iia_sync_deletes', 'iia_show_compose')),
	);
	$sync_options = array(
		InboundImapAccount::SYNC_OFF  => 'Off',
		InboundImapAccount::SYNC_PULL => 'Read-only (follow the source)',
		InboundImapAccount::SYNC_BOTH => 'Two-way (full sync)',
	);

	return LogicResult::render(array(
		'session'         => $session,
		'settings'        => $settings,
		'state'           => $state,
		'provider_key'    => $provider_key,
		'presets'         => InboundImapAccount::PRESETS,
		'oauth_key'       => $oauth_key,
		'oauth_class'     => $oauth_class,
		'signin_method'   => $method,
		'auth_methods'    => $auth_methods,
		'prefill_address' => strtolower(trim((string)($input['address'] ?? ''))),
		'reader_options'  => $reader_options,
		'acting_user_id'  => $acting_user_id,
		'account'         => $account,
		'has_held_grant'  => ($held !== null),
		'ask_address'     => !empty($input['ask_address']),
		'folder_names'    => $folder_names,
		'sync_supported'  => $sync_supported,
		'sync_options'    => $sync_options,
		'sync_visibility' => $sync_visibility,
		'redirect_uri'    => OAuth2Client::redirectUri(),
		'error'           => ($error !== null && $error !== '') ? $error : null,
	));
}

/** What the operator chose before signing in: who reads it, how protected. */
function _connect_intent(array $input, int $acting_user_id): array {
	$reader = intval($input['reader_user_id'] ?? 0) ?: $acting_user_id;
	$level = strtolower(trim((string)($input['security_level'] ?? '')));
	if (!in_array($level, array(InboundEmailDomain::LEVEL_STANDARD, InboundEmailDomain::LEVEL_PRIVATE), true)) {
		$level = InboundEmailDomain::LEVEL_STANDARD;
	}
	return array('reader_user_id' => $reader, 'security_level' => $level);
}

/**
 * What would make this intent refusable, said NOW — before the consent round
 * trip, on the form where the choice was made. The reader must be a real user,
 * and a Private mailbox seals to its reader's vault, so a reader without one is
 * the ceremony's own refusal, moved to the moment it is cheapest to hear.
 * ImapFeedProvisioner re-checks at the write; this is the early copy.
 */
function _connect_intent_error(array $intent): ?string {
	$reader = new User(intval($intent['reader_user_id']), TRUE);
	if (!$reader->key) {
		return 'That reader does not exist — choose who this mailbox belongs to.';
	}
	if ($intent['security_level'] === InboundEmailDomain::LEVEL_PRIVATE) {
		return InboundEmailMailboxGrant::grant_set_error(true, array(intval($reader->key)));
	}
	return null;
}

/**
 * The scopes one consent asks for. ONE consent grants BOTH directions (§6
 * unified onboarding): IMAP read for the feed and SMTP send for outbound.
 * Google's mail scope already authorizes SMTP; Microsoft needs SMTP.Send named
 * alongside IMAP, and offline_access for the refresh token. The provider's own
 * identity scopes ride along so the flow can learn which address consented,
 * rather than trusting one typed in advance (§ C).
 */
function _connect_scopes(string $oauth_key, string $oauth_class): array {
	$scopes = ($oauth_key === 'microsoft')
		? array(
			'https://outlook.office365.com/IMAP.AccessAsUser.All',
			'https://outlook.office365.com/SMTP.Send',
			'offline_access',
		)
		: array('https://mail.google.com/');
	return array_values(array_unique(array_merge($scopes, $oauth_class::identityScopes())));
}

/**
 * A password provider's sign-in: there is no consent round trip, so the app
 * password IS the moment of connection. The mailbox is created here and the
 * password stored on it, then the flow lands on configure exactly as the OAuth
 * path does — one shape of ending, not two.
 */
function _connect_password_signin(array $input, string $provider_key, array $intent,
		$session, string $wizard_url): LogicResult {
	$address  = strtolower(trim((string)($input['address'] ?? '')));
	$password = (string)($input['imap_password'] ?? '');
	// Hosts display app passwords in spaced groups (abcd efgh …) that are not
	// part of the password; people paste them as shown. A generic IMAP server's
	// password is kept verbatim — only there could a space be real.
	if ($provider_key !== 'imap_generic') {
		$password = str_replace(' ', '', $password);
	}
	if ($password === '') {
		$session->save_message(new DisplayMessage(
			'Enter the app password for ' . ($address !== '' ? htmlspecialchars($address) : 'this mailbox')
				. ' to connect it.',
			'Password needed', '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($wizard_url . '?provider=' . rawurlencode($provider_key));
	}
	$back_url = $wizard_url . '?provider=' . rawurlencode($provider_key)
		. ($address !== '' ? '&address=' . rawurlencode($address) : '');
	if (strpos($address, '@') === false) {
		$session->save_message(new DisplayMessage(
			'Enter the full email address — it is also the username the connection signs in with.',
			'Address needed', '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($back_url);
	}

	// The sign-in IS the credential check: prove the mail server accepts this
	// address and password before any row exists. A mistyped password — or an
	// account password where only an app password works — fails here, on the
	// form it was typed into, never as a fetch error after the wizard has
	// finished. The probe account is never saved; it exists to carry the
	// connection details to one login.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
	$preset = InboundImapAccount::PRESETS[$provider_key];
	$probe = new InboundImapAccount(NULL);
	$probe->set('iia_provider_key', $provider_key);
	$probe->set('iia_auth_method', InboundImapAccount::AUTH_PASSWORD);
	$probe->set('iia_username', $address);
	if ($provider_key === 'imap_generic') {
		$probe->set('iia_imap_host', trim((string)($input['iia_imap_host'] ?? '')));
		$probe->set('iia_imap_port', intval($input['iia_imap_port'] ?? 993) ?: 993);
		$enc = (string)($input['iia_imap_encryption'] ?? 'ssl');
		$probe->set('iia_imap_encryption', in_array($enc, array('ssl', 'tls', 'none'), true) ? $enc : 'ssl');
	} else {
		$probe->set('iia_imap_host', $preset['host']);
		$probe->set('iia_imap_port', $preset['port']);
		$probe->set('iia_imap_encryption', $preset['encryption']);
	}
	$probe->setPassword($password);
	try {
		$tester = new ImapIngestor($probe);
		try {
			$tester->getClient();
		} finally {
			$tester->close();
		}
	} catch (Throwable $e) {
		$hint = !empty($preset['app_password_url'])
			? ' Check that the password is an app password created on exactly this account — the '
				. 'account password itself is always refused, and an app password made while signed '
				. 'in to a different account belongs to that account.'
			: '';
		$session->save_message(new DisplayMessage(
			'Nothing was created. ' . htmlspecialchars($address) . ' could not sign in: '
				. htmlspecialchars($e->getMessage()) . $hint,
			'Sign-in refused', '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($back_url);
	}

	try {
		$notes = array();
		$account = ImapFeedProvisioner::provision($provider_key, $address, $intent, null, $notes);
		foreach ($notes as $note) {
			$session->save_message(new DisplayMessage(
				htmlspecialchars($note), 'Protection', '~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}

		if ($provider_key === 'imap_generic') {
			$account->set('iia_imap_host', trim((string)($input['iia_imap_host'] ?? '')));
			$account->set('iia_imap_port', intval($input['iia_imap_port'] ?? 993) ?: 993);
			$enc = (string)($input['iia_imap_encryption'] ?? 'ssl');
			$account->set('iia_imap_encryption', in_array($enc, array('ssl', 'tls', 'none'), true) ? $enc : 'ssl');
		}
		$account->setPassword($password);
		$account->set('iia_last_status', 'Password stored ' . gmdate('Y-m-d H:i') . ' UTC.');
		$account->prepare();
		$account->save();
	} catch (Throwable $e) {
		// The mailbox rows are idempotent and the password is still in the
		// operator's hands, so a stated failure and a resubmit lose nothing.
		$session->save_message(new DisplayMessage($e->getMessage(), 'Could not create the mailbox',
			'~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ERROR,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($back_url);
	}

	return LogicResult::redirect($wizard_url . '?state=configure&account_id='
		. intval($account->key) . '&connected=1');
}
?>
