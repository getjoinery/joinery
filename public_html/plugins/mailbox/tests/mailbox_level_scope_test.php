<?php
/** @joinery-test
 * name: mailbox_level_scope
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Protection per mailbox, and the invariant that makes it safe
 * (specs/mailbox_connect_flow.md § D and § E).
 *
 *  - Resolution: a mailbox's own level wins, NULL inherits its domain's, and a
 *    stored value that is not a level falls back to Standard.
 *  - maxSecurityLevelForUser() sees an alias-only Private. This is the one place
 *    where missing the change would be QUIET rather than loud — the user would
 *    silently get a Standard-length unlock window over sealed mail — so it gets
 *    its own section.
 *  - The grant invariant: a sealing mailbox refuses a second holder, the removal
 *    of its last holder, and a holder with no vault, whichever surface asks.
 *    Including the surface that does not go through sync_for_alias at all:
 *    deleting the sole holder as a user.
 *  - Store time: a sealing mailbox that cannot resolve a seal target DECLINES
 *    the message rather than writing it in plaintext — and the decline is
 *    retryable, so callers hold the mail rather than bouncing it.
 *  - ImapFeedProvisioner find-or-create, for a new provider domain and for one
 *    this deployment already hosts.
 *
 * Run: php tests/run.php db --filter=mailbox_level_scope
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapFeedProvisioner.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

/** A live domain at $level, registered for teardown. */
function mls_domain(string $level, bool $imap_source = false): InboundEmailDomain {
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'mls-' . bin2hex(random_bytes(4)) . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_security_level', $level);
	$domain->set('ied_is_imap_source', $imap_source);
	$domain->save();
	$domain->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));
	return $domain;
}

/** A live store-mode mailbox on $domain, with an optional level of its own. */
function mls_alias(InboundEmailDomain $domain, string $local, ?string $level = null): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	if ($level !== null) {
		$alias->set('iea_security_level', $level);
	}
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	return $alias;
}

/** A user with a Sealed Vault. Returns [user, secret_key_b64]. */
function mls_vault_user(string $name): array {
	$user = make_user($name);
	$keys = sodium_crypto_box_keypair();
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', intval($user->key));
	$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($keys)));
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));
	return array($user, SealedBox::b64url(sodium_crypto_box_secretkey($keys)));
}

/** Grant $alias to $user_ids without going through the invariant (fixture setup). */
function mls_grant_raw(int $alias_id, array $user_ids): void {
	foreach ($user_ids as $uid) {
		$grant = new InboundEmailMailboxGrant(NULL);
		$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
		$grant->set('ieg_usr_user_id', intval($uid));
		$grant->save();
		$grant->load();
		harness_register_row('ieg_inbound_email_mailbox_grants',
			'ieg_inbound_email_mailbox_grant_id', intval($grant->key));
	}
}

try {

	// -----------------------------------------------------------------------
	section('resolution: the mailbox answers, the domain is the fallback');

	$standard_domain = mls_domain(InboundEmailDomain::LEVEL_STANDARD);
	$private_domain  = mls_domain(InboundEmailDomain::LEVEL_PRIVATE);

	$inherits_standard = mls_alias($standard_domain, 'inherits');
	check($inherits_standard->security_level() === InboundEmailDomain::LEVEL_STANDARD,
		'a mailbox with no level of its own inherits Standard');
	check($inherits_standard->seals_content() === false, 'and does not seal');
	check($inherits_standard->has_own_security_level() === false, 'and knows it is inheriting');

	$inherits_private = mls_alias($private_domain, 'inherits');
	check($inherits_private->security_level() === InboundEmailDomain::LEVEL_PRIVATE,
		'a mailbox on a Private domain inherits Private');
	check($inherits_private->seals_content() === true, 'and seals');

	// The case the whole change exists for: two mailboxes on ONE provider domain,
	// differing. gmail.com is not an identity this deployment holds.
	$provider_domain = mls_domain(InboundEmailDomain::LEVEL_STANDARD, true);
	$own_private = mls_alias($provider_domain, 'mine', InboundEmailDomain::LEVEL_PRIVATE);
	$neighbour   = mls_alias($provider_domain, 'theirs', InboundEmailDomain::LEVEL_STANDARD);
	check($own_private->seals_content() === true && $neighbour->seals_content() === false,
		'two mailboxes on one provider domain can differ');
	check($own_private->has_own_security_level() === true, 'the sealing one carries its own level');

	// A stored value that is not a level at all falls back to Standard, the same
	// rule the domain applies — the column is only ever written through validated
	// pickers, so this is a floor, not a path anything takes.
	$bogus = mls_alias($provider_domain, 'bogus');
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare('UPDATE iea_inbound_email_aliases SET iea_security_level = ?
		WHERE iea_inbound_email_alias_id = ?');
	$stmt->execute(array('nonsense', intval($bogus->key)));
	$bogus_reloaded = new InboundEmailAlias(intval($bogus->key), TRUE);
	check($bogus_reloaded->security_level() === InboundEmailDomain::LEVEL_STANDARD,
		'an unrecognized stored value falls back to Standard');

	check(InboundEmailAlias::domainHasSealingMailbox(intval($provider_domain->key)) === true,
		'a Standard domain reports that one of its mailboxes seals');
	check(InboundEmailAlias::domainHasSealingMailbox(intval($standard_domain->key)) === false,
		'and reports nothing when none does');

	// -----------------------------------------------------------------------
	// The quiet one: this drives the per-level unlock-window caps and the
	// Fortress 2FA gate, so a domain-only answer would silently give a user a
	// Standard-length window over sealed mail.
	section('maxSecurityLevelForUser sees an alias-only Private');

	list($reader, $reader_secret) = mls_vault_user('MlsReader');
	$reader_id = intval($reader->key);
	check(InboundEmailDomain::maxSecurityLevelForUser($reader_id) === InboundEmailDomain::LEVEL_STANDARD,
		'a user who touches nothing protected is Standard');

	$pulled_in = mls_alias($provider_domain, 'pulled', InboundEmailDomain::LEVEL_PRIVATE);
	mls_grant_raw(intval($pulled_in->key), array($reader_id));
	check(InboundEmailDomain::maxSecurityLevelForUser($reader_id) === InboundEmailDomain::LEVEL_PRIVATE,
		'a grant on a Private MAILBOX raises the answer, though its domain is Standard');

	// -----------------------------------------------------------------------
	section('the grant invariant: one holder, and that holder has a vault');

	$novault = make_user('MlsNoVault');
	$sealing = mls_alias($provider_domain, 'invariant', InboundEmailDomain::LEVEL_PRIVATE);

	$refused = null;
	try {
		InboundEmailMailboxGrant::sync_for_alias(intval($sealing->key), array());
	} catch (InboundEmailMailboxGrantException $e) { $refused = $e->getMessage(); }
	check($refused !== null, 'a sealing mailbox refuses being left with no holder', (string)$refused);

	$refused = null;
	try {
		InboundEmailMailboxGrant::sync_for_alias(intval($sealing->key), array(intval($novault->key)));
	} catch (InboundEmailMailboxGrantException $e) { $refused = $e->getMessage(); }
	check($refused !== null, 'and refuses a holder with no vault', (string)$refused);

	InboundEmailMailboxGrant::sync_for_alias(intval($sealing->key), array($reader_id));
	check(InboundEmailMailboxGrant::user_ids_for_alias(intval($sealing->key)) === array($reader_id),
		'one holder with a vault is accepted');

	$refused = null;
	try {
		InboundEmailMailboxGrant::sync_for_alias(intval($sealing->key),
			array($reader_id, intval($novault->key)));
	} catch (InboundEmailMailboxGrantException $e) { $refused = $e->getMessage(); }
	check($refused !== null, 'a second holder is refused');
	check(InboundEmailMailboxGrant::user_ids_for_alias(intval($sealing->key)) === array($reader_id),
		'and the refusal changed nothing');

	// Handing a sealing mailbox from one holder to another is legitimate, and
	// must not be caught by the last-holder refusal on the way through zero.
	list($successor, $successor_secret) = mls_vault_user('MlsSuccessor');
	InboundEmailMailboxGrant::sync_for_alias(intval($sealing->key), array(intval($successor->key)));
	check(InboundEmailMailboxGrant::user_ids_for_alias(intval($sealing->key))
		=== array(intval($successor->key)), 'a holder swap is allowed in one call');
	InboundEmailMailboxGrant::sync_for_alias(intval($sealing->key), array($reader_id));

	// A NON-sealing mailbox has no constraint at all: shared team inboxes are
	// ordinary there, which is the rule this must not have broken.
	$shared = mls_alias($standard_domain, 'shared');
	InboundEmailMailboxGrant::sync_for_alias(intval($shared->key),
		array($reader_id, intval($novault->key)));
	check(count(InboundEmailMailboxGrant::user_ids_for_alias(intval($shared->key))) === 2,
		'a Standard mailbox still keeps several members');
	InboundEmailMailboxGrant::sync_for_alias(intval($shared->key), array());

	// -----------------------------------------------------------------------
	// The one grant write that does NOT pass through sync_for_alias.
	section('deleting the sole holder of a sealing mailbox is refused');

	$doomed_alias = mls_alias($provider_domain, 'cascade', InboundEmailDomain::LEVEL_PRIVATE);
	list($doomed_holder, $doomed_secret) = mls_vault_user('MlsDoomed');
	InboundEmailMailboxGrant::sync_for_alias(intval($doomed_alias->key), array(intval($doomed_holder->key)));

	$refused = null;
	try {
		$doomed_holder->permanent_delete();
	} catch (\Throwable $e) { $refused = $e->getMessage(); }
	// The MESSAGE is asserted, not just "some Throwable": this environment
	// carries unrelated deletion rules that can abort a user cascade first, and
	// a green check that never exercised the grant refusal proves nothing.
	check($refused !== null && strpos($refused, 'protected mailbox') !== false,
		'deleting the sole holder is refused BY THE GRANT RULE', (string)$refused);
	check(count(InboundEmailMailboxGrant::user_ids_for_alias(intval($doomed_alias->key))) === 1,
		'and the grant survives the attempt');

	// -----------------------------------------------------------------------
	section('store time: a sealing mailbox with no key declines the message');

	$router = new InboundEmailRouter();

	$resolved = $router->resolveSealTarget($inherits_standard, $standard_domain);
	check($resolved['sealing'] === false && $resolved['vault'] === null,
		'a Standard mailbox resolves to no sealing at all');

	$resolved = $router->resolveSealTarget($sealing, $provider_domain);
	check($resolved['sealing'] === true && $resolved['owner_id'] === $reader_id,
		'a sealing mailbox with one vault-holding member resolves to that member');

	$holderless = mls_alias($provider_domain, 'holderless', InboundEmailDomain::LEVEL_PRIVATE);
	$declined = null;
	try {
		$router->resolveSealTarget($holderless, $provider_domain);
	} catch (MailboxSealTargetMissing $e) { $declined = $e->getMessage(); }
	check($declined !== null, 'a sealing mailbox with no member declines rather than resolving');
	check($declined !== null && strpos($declined, $holderless->get_full_address()) !== false,
		'and the refusal names the mailbox to repair', (string)$declined);

	// The whole point: nothing was written. The alternative — a plaintext row on
	// a Private mailbox — would render plaintext forever, because the read path
	// dispatches on the row's own iem_content_sealed column.
	$before = new MultiInboundEmailMessage(array('alias_id' => intval($holderless->key)));
	$before->load();
	$stored = count($before);
	$raw = "From: someone@elsewhere.example\r\nTo: " . $holderless->get_full_address()
		. "\r\nSubject: held\r\nMessage-ID: <mls-" . bin2hex(random_bytes(4)) . "@elsewhere.example>\r\n\r\nbody\r\n";
	$declined = null;
	try {
		$router->storeMessage($raw, $router->parseEmail($raw), $holderless, $provider_domain,
			$holderless->get_full_address(),
			array('dkim' => 'none', 'spf' => 'none', 'dmarc' => 'none', 'source' => 'none'),
			array('signal' => 'none', 'score' => null));
	} catch (MailboxSealTargetMissing $e) { $declined = $e->getMessage(); }
	check($declined !== null, 'storeMessage declines');
	$after = new MultiInboundEmailMessage(array('alias_id' => intval($holderless->key)));
	$after->load();
	check(count($after) === $stored, 'and wrote no row — nothing landed in plaintext');

	// Direct carries the identical plaintext fallback, so it gets the identical
	// refusal. A store path that opted out would be a silent leak on one transport.
	$declined = null;
	try {
		$router->storeDirectMessage(
			array('sender' => 'someone@elsewhere.example', 'subject' => 'held',
				'message_id' => '<mls-direct-' . bin2hex(random_bytes(4)) . '@elsewhere.example>'),
			array('body_plain' => 'body', 'body_html' => '', 'attachments' => array()),
			$holderless, $provider_domain, $holderless->get_full_address(), true);
	} catch (MailboxSealTargetMissing $e) { $declined = $e->getMessage(); }
	check($declined !== null, 'storeDirectMessage declines the same way');
	$after = new MultiInboundEmailMessage(array('alias_id' => intval($holderless->key)));
	$after->load();
	check(count($after) === $stored, 'and wrote no row either');

	// Retryable, not a bounce: every caller keys on this ONE type to mean "hold
	// it and try again", so the type is part of the contract, not an accident.
	check(is_subclass_of('MailboxSealTargetMissing', 'RuntimeException'),
		'the decline is a distinct, catchable type callers can hold on');

	// Repairing the mailbox is all it takes — the same store then succeeds, which
	// is what makes holding the mail the right answer rather than dropping it.
	InboundEmailMailboxGrant::sync_for_alias(intval($holderless->key), array($reader_id));
	$repaired = $router->storeMessage($raw, $router->parseEmail($raw), $holderless, $provider_domain,
		$holderless->get_full_address(),
		array('dkim' => 'none', 'spf' => 'none', 'dmarc' => 'none', 'source' => 'none'),
		array('signal' => 'none', 'score' => null));
	check(empty($repaired['dedup']) && !empty($repaired['message']),
		'once the mailbox has a member with a vault, the held message stores');
	if (!empty($repaired['message'])) {
		harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id',
			intval($repaired['message']->key));
		$row = new InboundEmailMessage(intval($repaired['message']->key), TRUE);
		check((bool)$row->get('iem_content_sealed') === true, 'and it stores SEALED, not plaintext');
	}

	// -----------------------------------------------------------------------
	section('provisioner: find-or-create, for a new provider domain and a hosted one');

	$address = 'mls' . bin2hex(random_bytes(3)) . '@gmail.com';
	$account = ImapFeedProvisioner::provision('imap_gmail', $address,
		array('reader_user_id' => $reader_id, 'security_level' => InboundEmailDomain::LEVEL_PRIVATE), null);
	harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', intval($account->key));
	$made_alias = new InboundEmailAlias(intval($account->get('iia_iea_inbound_email_alias_id')), TRUE);
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($made_alias->key));
	$made_domain = new InboundEmailDomain(intval($made_alias->get('iea_ied_inbound_email_domain_id')), TRUE);
	foreach (InboundEmailMailboxGrant::user_ids_for_alias(intval($made_alias->key)) as $ignored) { /* registered below */ }

	check($account->key > 0, 'the feed exists');
	check((string)$account->get('iia_username') === $address, 'named for the address that consented');
	check(!$account->get('iia_is_enabled'),
		'and is created DISABLED — an abandoned flow leaves nothing quietly fetching');
	check((string)$made_domain->get('ied_domain') === 'gmail.com', 'the provider domain is gmail.com');
	check((bool)$made_domain->get('ied_is_imap_source') === true, 'flagged as an IMAP source');
	check($made_domain->security_level() === InboundEmailDomain::LEVEL_STANDARD,
		'and makes no protection claim of its own');
	check($made_alias->security_level() === InboundEmailDomain::LEVEL_PRIVATE,
		'while the MAILBOX carries the level that was asked for');
	check(InboundEmailMailboxGrant::user_ids_for_alias(intval($made_alias->key)) === array($reader_id),
		'granted to the reader named in the intent');

	// Idempotent: the same call again reuses everything rather than building a
	// second domain, mailbox or feed.
	$again = ImapFeedProvisioner::provision('imap_gmail', $address,
		array('reader_user_id' => $reader_id, 'security_level' => InboundEmailDomain::LEVEL_PRIVATE), null);
	check(intval($again->key) === intval($account->key), 'a second run reuses the same feed');
	check(intval($again->get('iia_iea_inbound_email_alias_id')) === intval($made_alias->key),
		'and the same mailbox');

	// An address on a domain this deployment already HOSTS reuses that domain and
	// keeps its level: that domain is an identity we hold, so it decides.
	list($hosted_reader, $hosted_secret) = mls_vault_user('MlsHosted');
	$hosted_address = 'pulledin@' . $private_domain->get('ied_domain');
	$hosted_account = ImapFeedProvisioner::provision('imap_generic', $hosted_address,
		array('reader_user_id' => intval($hosted_reader->key),
			'security_level' => InboundEmailDomain::LEVEL_STANDARD), null);
	harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', intval($hosted_account->key));
	$hosted_alias = new InboundEmailAlias(intval($hosted_account->get('iia_iea_inbound_email_alias_id')), TRUE);
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($hosted_alias->key));
	check(intval($hosted_alias->get('iea_ied_inbound_email_domain_id')) === intval($private_domain->key),
		'a hosted domain is reused, not duplicated');
	check($hosted_alias->has_own_security_level() === false,
		'and the mailbox does not override it — the domain is an identity we hold');
	check($hosted_alias->security_level() === InboundEmailDomain::LEVEL_PRIVATE,
		'so it inherits Private');

	// Grants created by the provisioner are torn down with everything else.
	harness_defer(function () use ($made_alias, $hosted_alias, $sealing, $doomed_alias, $holderless) {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('DELETE FROM ieg_inbound_email_mailbox_grants
			WHERE ieg_iea_inbound_email_alias_id = ANY(?)');
		$stmt->execute(array('{' . implode(',', array(
			intval($made_alias->key), intval($hosted_alias->key),
			intval($sealing->key), intval($doomed_alias->key), intval($holderless->key),
		)) . '}'));
	});

} catch (\Throwable $e) {
	check(false, 'uncaught ' . get_class($e), $e->getMessage());
}

harness_finish();
