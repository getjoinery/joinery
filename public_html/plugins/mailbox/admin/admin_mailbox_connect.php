<?php
/**
 * Inbound Email - Connect a mailbox
 *
 * One page, four states, chosen by what is known rather than by a step counter
 * in the URL (specs/mailbox_connect_flow.md § A). Each state asks only what can
 * be answered at that moment:
 *
 *   provider  — where does this mail live?
 *   register  — this site is not registered with that provider yet; the fields
 *               to fix that are right here, for that provider only.
 *   signin    — sign in, and grant access.
 *   configure — everything that only became knowable once connected.
 *
 * The mailbox itself is built by ImapFeedProvisioner, the one path a pulled-in
 * mailbox comes into being by; this page collects answers and nothing more.
 *
 * @version 1.1
 * @changelog 1.1 - the delegate form asks the protection question too; the
 *   configure step says honestly whether the feed is connected; a Standard
 *   mailbox about to take an import is told what that order costs
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_connect_logic.php'));

$page_vars = process_logic(admin_mailbox_connect_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox_accounts',
		'Connect a mailbox' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Accounts');

if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

$provider_label = ($provider_key !== '' && isset($presets[$provider_key]))
	? $presets[$provider_key]['label'] : '';
$oauth_label = ($oauth_class !== null) ? $oauth_class::getLabel() : '';

// ---------------------------------------------------------------- provider
if ($state === 'provider') {
	$page->begin_box(array('title' => 'Where does this mail live?'));
	?>
	<p>Pick the service that holds the mailbox. That is the only question this step asks &mdash;
	everything else depends on the answer.</p>
	<div class="jy-provider-cards" style="display:flex;flex-wrap:wrap;gap:.75rem;margin:1rem 0;">
		<?php foreach ($presets as $key => $p): ?>
			<a class="btn btn-outline-primary"
			   href="?provider=<?php echo rawurlencode($key); ?>"><?php echo htmlspecialchars($p['label']); ?></a>
		<?php endforeach; ?>
	</div>
	<p style="margin-top:1.5rem;">Hosting the mail here instead, on a domain of your own?
	<a href="/plugins/mailbox/admin/admin_mailbox_domains?action=add">Add a domain</a> &mdash; that is
	a different setup, because the mail arrives by MX rather than being collected.</p>
	<?php
	$page->end_box();
	$page->admin_footer();
	return;
}

// ---------------------------------------------------------------- register
if ($state === 'register') {
	$page->begin_box(array('title' => $oauth_label . ' needs to know about this site'));
	?>
	<p><?php echo htmlspecialchars($oauth_label); ?> will not let anyone sign in to a site it has never
	heard of, so this site has to be registered with it once. Enter the app details below and you will
	come straight back here to sign in. It is one-time, for every <?php echo htmlspecialchars($oauth_label); ?>
	mailbox this site will ever collect.</p>

	<div class="alert alert-info" style="margin-bottom:1.25rem;">
		<strong>Callback URL</strong> &mdash; paste this exact value into the
		<?php echo htmlspecialchars($oauth_label); ?> console. It must match byte-for-byte.
		<div style="margin-top:.5rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
			<code><?php echo htmlspecialchars($redirect_uri); ?></code>
			<button type="button" class="btn btn-sm btn-secondary"
					data-jy-copy="<?php echo htmlspecialchars($redirect_uri); ?>">Copy</button>
		</div>
	</div>
	<?php
	$formwriter = $page->getFormWriter('register_form');
	$formwriter->begin_form();
	$formwriter->hiddeninput('provider', '', array('value' => $provider_key));

	// These are declared settings, so the fields belong to the manifest and are
	// drawn by the one renderer that knows how; this page supplies only the
	// context around them, and the registration guide beside the first one.
	$guide = $oauth_class::configGuide();
	foreach ($oauth_class::configFields() as $setting => $spec) {
		$options = array();
		if (!empty($spec['help'])) {
			$options['helptext_append'] = $spec['help'];
		}
		if ($guide !== null) {
			$options['help_modal'] = $guide;
			$guide = null;
		}
		$declaration = SettingsDeclarations::get($setting);
		$is_secret = !empty($declaration['secret']) || !empty($spec['secret']);
		if ($is_secret) {
			// Written by OAuth2ProviderConfig, not SettingsWriter, so the
			// renderer's Clear box would do nothing.
			$options['clearable'] = false;
		}
		if ($declaration !== null) {
			SettingsFieldRenderer::renderGroup($formwriter, $declaration['_group'], array(
				'only'          => array($setting),
				'field_options' => array($setting => $options),
			));
			continue;
		}
		unset($options['helptext_append']);
		if (!empty($spec['help'])) {
			$options['helptext'] = $spec['help'];
		}
		$value = $settings->get_setting($setting, false, true);
		if ($is_secret) {
			SettingsFieldRenderer::secretField($formwriter, $setting,
				$spec['label'] ?? $setting, $value, $options);
			continue;
		}
		$formwriter->textinput($setting, $spec['label'] ?? $setting,
			array_merge(array('value' => $value), $options));
	}

	$formwriter->submitbutton('save_registration', 'Save and continue');
	$formwriter->end_form();
	?>
	<p style="margin-top:1rem;"><a href="?">&larr; Choose a different service</a></p>
	<?php
	$page->end_box();
	$page->admin_footer();
	return;
}

// ------------------------------------------------------------------ signin
if ($state === 'signin') {
	$is_oauth = ($oauth_class !== null);
	$page->begin_box(array('title' => 'Sign in to ' . $provider_label));

	$formwriter = $page->getFormWriter('signin_form');
	$formwriter->begin_form();
	$formwriter->hiddeninput('provider', '', array('value' => $provider_key));

	if (!$is_oauth) {
		// A password provider has no consent round trip: the app password IS the
		// sign-in, so the address has to be typed. Nothing can confirm it for us.
		$formwriter->textinput('address', 'Email address', array(
			'validation' => array('required' => true),
			'helptext' => 'The address whose mail is collected. It is also the username the '
				. 'connection signs in with.',
		));
		$formwriter->passwordinput('imap_password', 'App password', array(
			'helptext' => 'Not the account password: create an app-specific password in '
				. $provider_label . ' and paste it here. It is stored encrypted.',
			'autocomplete' => 'new-password',
		));
		if ($provider_key === 'imap_generic') {
			$formwriter->textinput('iia_imap_host', 'IMAP host', array(
				'validation' => array('required' => true),
				'placeholder' => 'imap.example.com',
			));
			$formwriter->numberinput('iia_imap_port', 'IMAP port', array('value' => 993));
			$formwriter->dropinput('iia_imap_encryption', 'Encryption', array(
				'options' => array('ssl' => 'SSL/TLS (993)', 'tls' => 'STARTTLS (143)', 'none' => 'None'),
			));
		}
	}

	$formwriter->dropinput('reader_user_id', 'Who reads this mailbox', array(
		'options' => $reader_options,
		'value' => $acting_user_id,
		'helptext' => 'The person this mailbox belongs to. On Private, their vault is the key its '
			. 'mail seals to.',
	));

	// Asked BEFORE the import, deliberately: sealing happens per message as it
	// lands, so a mailbox set to Private now seals its whole history for free.
	// Set afterwards, the same end state means rewriting every message already
	// stored, batch by batch, from the browser.
	$formwriter->radioinput('security_level', 'Mail protection', array(
		'options' => array(
			InboundEmailDomain::LEVEL_STANDARD => 'Standard — stored ready to read',
			InboundEmailDomain::LEVEL_PRIVATE  => 'Private — sealed to its reader as it arrives',
		),
		'value' => InboundEmailDomain::LEVEL_STANDARD,
		'helptext' => 'Private encrypts this mailbox to one person as it arrives, so reading it takes '
			. 'their unlock. Choosing it now costs nothing; choosing it after mail has been imported '
			. 'means sealing the whole history again afterwards.',
	));

	if ($is_oauth) {
		?>
		<p>Signing in opens <?php echo htmlspecialchars($oauth_label); ?>, where you approve this site's
		access to the mailbox. You come back here afterwards, and this site will already know which
		address you signed in as.</p>
		<?php
		$formwriter->submitbutton('begin_signin', 'Sign in with ' . $oauth_label);
		$formwriter->end_form();

		// The one capability a consent-first wizard would otherwise lose: setting
		// up a mailbox for someone who has to authorize on their own device. It is
		// an explicit choice here rather than the path everybody walks.
		$page->end_box();
		$page->begin_box(array('title' => 'Someone else will sign in'));
		?>
		<p>Set the mailbox up now and leave the sign-in for its owner. It appears on the Accounts page
		switched off, with a <strong>Connect</strong> button &mdash; press that while they are with you,
		or on their device.</p>
		<?php
		$formwriter2 = $page->getFormWriter('delegate_form');
		$formwriter2->begin_form();
		$formwriter2->hiddeninput('provider', '', array('value' => $provider_key));
		$formwriter2->textinput('address', 'Their email address', array(
			'validation' => array('required' => true),
		));
		$formwriter2->dropinput('reader_user_id', 'Who reads this mailbox', array(
			'options' => $reader_options,
			'value' => $acting_user_id,
		));
		$formwriter2->radioinput('security_level', 'Mail protection', array(
			'options' => array(
				InboundEmailDomain::LEVEL_STANDARD => 'Standard — stored ready to read',
				InboundEmailDomain::LEVEL_PRIVATE  => 'Private — sealed to its reader as it arrives',
			),
			'value' => InboundEmailDomain::LEVEL_STANDARD,
			'helptext' => 'The same choice as above, asked now for the same reason: sealing happens as '
				. 'each message lands, so a mailbox that should be Private is cheapest to make Private '
				. 'before it collects anything.',
		));
		$formwriter2->submitbutton('delegate', 'Create it, unconnected');
		$formwriter2->end_form();
	} else {
		$formwriter->submitbutton('begin_signin', 'Connect');
		$formwriter->end_form();
	}
	?>
	<p style="margin-top:1rem;"><a href="?">&larr; Choose a different service</a></p>
	<?php
	$page->end_box();
	$page->admin_footer();
	return;
}

// --------------------------------------------------------------- configure
// The address could not be learned from the provider, so ask — with the
// connection already in hand, so nothing is lost by having to.
if ($ask_address && $has_held_grant) {
	$page->begin_box(array('title' => 'Which address did you sign in as?'));
	?>
	<p>You are signed in and connected. <?php echo htmlspecialchars($provider_label ?: 'That service'); ?>
	does not tell us which address it was, so this is the one thing left to say. It has to match exactly
	&mdash; it is the address the connection signs in with.</p>
	<?php
	$formwriter = $page->getFormWriter('address_form');
	$formwriter->begin_form();
	$formwriter->textinput('address', 'Email address', array(
		'validation' => array('required' => true),
	));
	$formwriter->submitbutton('save_address', 'Create the mailbox');
	$formwriter->end_form();
	$page->end_box();
	$page->admin_footer();
	return;
}

$address = ($account !== null && $account->key) ? (string)$account->get('iia_username') : '';
$is_connected = ($account !== null && $account->key && $account->isConnectable());
$page->begin_box(array('title' => 'Set up ' . ($address !== '' ? htmlspecialchars($address) : 'this mailbox')));
?>
<p><?php echo $is_connected
	? 'Connected. These are the settings that could not be answered before &mdash; they are all editable later, from the mailbox itself.'
	: 'Not connected yet &mdash; its sign-in is still to come, from its Connect button on the Accounts page. The settings below can wait for that moment too.'; ?></p>
<?php
$formwriter = $page->getFormWriter('configure_form');
$formwriter->begin_form();
$formwriter->hiddeninput('account_id', '', array('value' => $account ? $account->key : 0));

$formwriter->textinput('iia_label', 'Name for this mailbox', array(
	'value' => (string)($account ? ($account->get('iia_label') ?: $address) : ''),
	'helptext' => 'What to call it in the admin. The address itself is a fine answer.',
));

// The real folder list, from the server, in the account's own language — which
// is exactly why it could not be offered before signing in.
if (!empty($folder_names)) {
	$formwriter->dropinput('iia_imap_folder', 'Collect mail from', array(
		'options' => $folder_names,
		'value' => (string)($account ? ($account->get('iia_imap_folder') ?: 'INBOX') : 'INBOX'),
		'helptext' => 'Inbox is where new mail arrives, and is what most people want.',
	));
}

// Sealing happens per message as it lands, so an import into a Standard
// mailbox that should have been Private means resealing the whole history
// afterwards — said here, where the large archive is about to be chosen.
$import_help = 'Reaching back starts a backfill, oldest first, over many fetches. The mail stays '
	. 'on the source either way.';
$mailbox_seals = false;
if ($account !== null && $account->key) {
	$configure_alias = new InboundEmailAlias(intval($account->get('iia_iea_inbound_email_alias_id')), TRUE);
	$mailbox_seals = (bool)$configure_alias->key && $configure_alias->seals_content();
}
if (!$mailbox_seals) {
	$import_help .= ' This mailbox is Standard, so imported mail is stored ready to read. If it '
		. 'should be Private, raise it before importing — sealing happens as each message lands, '
		. 'so done in that order it costs nothing.';
}
$formwriter->dropinput('import_scope', 'Existing mail', array(
	'options' => array(
		InboundImapAccount::SCOPE_FUTURE => 'Only mail that arrives from now on',
		InboundImapAccount::SCOPE_DAYS   => 'The last few days of mail',
		InboundImapAccount::SCOPE_FULL   => 'The full history',
	),
	'value' => (string)($account ? $account->importScope() : InboundImapAccount::SCOPE_FUTURE),
	'helptext' => $import_help,
	'visibility_rules' => array(
		InboundImapAccount::SCOPE_FUTURE => array('hide' => array('iia_import_days')),
		InboundImapAccount::SCOPE_DAYS   => array('show' => array('iia_import_days')),
		InboundImapAccount::SCOPE_FULL   => array('hide' => array('iia_import_days')),
	),
));
$formwriter->numberinput('iia_import_days', 'Days of mail to bring in', array(
	'value' => intval($account ? $account->get('iia_import_days') : 0)
		?: InboundImapAccount::IMPORT_DAYS_DEFAULT,
	'min' => 1,
	'max' => InboundImapAccount::IMPORT_DAYS_MAX,
));

$formwriter->submitbutton('save_configure', 'Finish');
$formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
