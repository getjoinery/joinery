<?php
/** @joinery-test
 * name: fortress_scope
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * Fortress mail's per-row custody (specs/client_custody_mail.md § R1, WP0).
 *
 *  - The seal-scope hook: `mail` for a mailbox at Fortress — its own level, an
 *    inheriting mailbox on a Fortress domain, a domain-owned (catch-all) row on
 *    one — and `user` for a Private mailbox on a Fortress domain and for a
 *    Standard one. The per-request memo forgets on a level save.
 *  - The domain level: set_security_level('fortress') is accepted and read
 *    back; a legacy unconverted 'fortress' row still reads as Private.
 *  - resolveSealTarget(): a Fortress mailbox seals to its owner's client-custody
 *    `mail` vault, and without one the message is declined, naming the vault.
 *    The grant invariant asks for the same vault.
 *  - The Fortress refusals the domain editor applies, each with its reason.
 *
 * Run: php tests/run.php test-db --filter=fortress_scope
 *
 * @version 1.2 - the Setup page's mail-vault step
 * @version 1.1 - feed refusal on a Fortress mailbox; the relay exporter never gives it the server key
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_domains_logic.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

/** Exposes the protected per-row hook, so the row form is tested as core calls it. */
class FortressScopeProbe extends InboundEmailMessage {
	public static function scopeOfRow(array $row): string {
		return static::sealScopeForWrite($row);
	}
}

/** A live domain; $level goes through set_security_level() unless it is Standard. */
function fsc_domain(string $level, ?int $owner_id = null, bool $imap_source = false): InboundEmailDomain {
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'harnesstest-fsc-' . bin2hex(random_bytes(4)) . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_is_imap_source', $imap_source);
	if ($owner_id !== null) {
		$domain->set('ied_owner_usr_user_id', $owner_id);
	}
	$domain->save();
	$domain->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));
	if ($level !== InboundEmailDomain::LEVEL_STANDARD) {
		$domain->set_security_level($level);
		$domain->save();
		$domain = new InboundEmailDomain(intval($domain->key), TRUE);
	}
	return $domain;
}

/** A live mailbox on $domain, with an optional level of its own. */
function fsc_alias(InboundEmailDomain $domain, string $local, ?string $level = null,
		string $mode = InboundEmailAlias::MODE_STORE): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
	$alias->set('iea_alias', 'harnesstest_' . $local);
	$alias->set('iea_delivery_mode', $mode);
	$alias->set('iea_destinations', $mode === InboundEmailAlias::MODE_STORE ? '' : 'elsewhere@example.com');
	$alias->set('iea_is_enabled', true);
	if ($level !== null) {
		$alias->set('iea_security_level', $level);
	}
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	return $alias;
}

/** Grant without the invariant (fixture setup). */
function fsc_grant_raw(int $alias_id, array $user_ids): void {
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

/** A client-custody `mail` vault for $user_id, from a keypair the test mints. */
function fsc_mail_vault(int $user_id): int {
	$pair = (new SealedBox())->generateKeypair();
	return vault_fixture_client_vault($user_id,
		base64_encode(SealedBox::b64url_decode($pair['public'])), InboundEmailMessage::SEAL_SCOPE_FORTRESS);
}

/** A server-custody `user` vault for $user_id. */
function fsc_user_vault(int $user_id): void {
	$keys = sodium_crypto_box_keypair();
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', $user_id);
	$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($keys)));
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));
}

try {
	$db = DbConnector::get_instance()->get_db_link();

	// -----------------------------------------------------------------------
	section('the mail scope is declared');
	check(VaultScopes::isClientCustody('mail'), 'the mailbox plugin declares `mail` as a client-custody scope');
	check(VaultScopes::prfContext('mail') === 'vault-mail-kek', 'its PRF context derives as vault-mail-kek');

	// -----------------------------------------------------------------------
	section('the domain level: Fortress is settable, a legacy row still reads Private');

	$fortress_domain = fsc_domain(InboundEmailDomain::LEVEL_FORTRESS);
	check($fortress_domain->security_level() === InboundEmailDomain::LEVEL_FORTRESS,
		'set_security_level(fortress) is stored and read back as Fortress');
	check(trim((string)$fortress_domain->get('ied_level_set_time')) !== '', 'and stamps ied_level_set_time');
	check($fortress_domain->is_fortress() && $fortress_domain->seals_content() && !$fortress_domain->is_unconverted(),
		'a Fortress domain seals, is Fortress, and is not an unconverted row');

	$legacy = fsc_domain(InboundEmailDomain::LEVEL_STANDARD);
	$db->prepare("UPDATE ied_inbound_email_domains SET ied_security_level = 'fortress', ied_level_set_time = NULL
		WHERE ied_inbound_email_domain_id = ?")->execute(array(intval($legacy->key)));
	$legacy = new InboundEmailDomain(intval($legacy->key), TRUE);
	check($legacy->is_unconverted() && $legacy->security_level() === InboundEmailDomain::LEVEL_PRIVATE,
		'a stored fortress with no set time is a legacy row and reads as Private');

	$refused = null;
	try { $legacy->set_security_level('bogus'); } catch (InboundEmailDomainException $e) { $refused = $e->getMessage(); }
	check($refused !== null, 'an unknown level is refused');

	// -----------------------------------------------------------------------
	section('the seal-scope hook answers per mailbox');

	$private_domain  = fsc_domain(InboundEmailDomain::LEVEL_PRIVATE);
	$standard_domain = fsc_domain(InboundEmailDomain::LEVEL_STANDARD);

	$own_fortress = fsc_alias($private_domain, 'ownfort', InboundEmailDomain::LEVEL_FORTRESS);
	$inherits     = fsc_alias($fortress_domain, 'inherits');
	$own_private  = fsc_alias($fortress_domain, 'ownpriv', InboundEmailDomain::LEVEL_PRIVATE);
	$standard     = fsc_alias($standard_domain, 'plain');

	$scope = function (?InboundEmailAlias $alias, InboundEmailDomain $domain) {
		return FortressScopeProbe::scopeOfRow(array(
			'iem_iea_inbound_email_alias_id'  => $alias ? intval($alias->key) : null,
			'iem_ied_inbound_email_domain_id' => intval($domain->key),
		));
	};
	check($scope($own_fortress, $private_domain) === 'mail', 'a mailbox at Fortress of its own seals to `mail`');
	check($scope($inherits, $fortress_domain) === 'mail', 'an inheriting mailbox on a Fortress domain seals to `mail`');
	check($scope(null, $fortress_domain) === 'mail', 'a domain-owned row on a Fortress domain seals to `mail`');
	check($scope($own_private, $fortress_domain) === 'user', 'a Private mailbox on a Fortress domain seals to `user`');
	check($scope($standard, $standard_domain) === 'user', 'a Standard mailbox answers `user`');
	check($own_fortress->is_fortress() && $own_fortress->seals_content(), 'the alias model agrees: Fortress, sealing');

	// The memo must not outlive a level change made in the same request.
	$flip = new InboundEmailAlias(intval($inherits->key), TRUE);
	$flip->set('iea_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$flip->save();
	check($scope($inherits, $fortress_domain) === 'user', 'a mailbox level saved mid-request is read fresh');
	$flip->set('iea_security_level', null);
	$flip->save();
	check($scope($inherits, $fortress_domain) === 'mail', 'and back');

	// -----------------------------------------------------------------------
	section('resolveSealTarget: the mail vault, or a declined message');

	$owner = make_user('FscOwner');
	$owner_id = intval($owner->key);
	fsc_user_vault($owner_id);   // a server vault alone must not satisfy Fortress
	$router = new InboundEmailRouter();

	$fortress_box = fsc_alias($fortress_domain, 'box');
	fsc_grant_raw(intval($fortress_box->key), array($owner_id));
	$declined = null;
	try {
		$router->resolveSealTarget($fortress_box, $fortress_domain);
	} catch (MailboxSealTargetMissing $e) { $declined = $e->getMessage(); }
	check($declined !== null, 'a Fortress mailbox whose owner holds no mail vault declines the message');
	check($declined !== null && stripos($declined, 'mail vault') !== false, 'and names the mail vault', (string)$declined);

	$mail_vault_id = fsc_mail_vault($owner_id);
	$resolved = $router->resolveSealTarget($fortress_box, $fortress_domain);
	check($resolved['sealing'] === true && $resolved['owner_id'] === $owner_id, 'it resolves to the owner');
	check($resolved['vault'] !== null && intval($resolved['vault']->key) === $mail_vault_id
		&& (string)$resolved['vault']->get('uev_custody') === 'client',
		'and to the owner\'s client-custody mail vault, not the server one');

	$private_box = fsc_alias($private_domain, 'pbox');
	fsc_grant_raw(intval($private_box->key), array($owner_id));
	$resolved = $router->resolveSealTarget($private_box, $private_domain);
	check($resolved['vault'] !== null && (string)$resolved['vault']->get('uev_scope') === 'user',
		'a Private mailbox of the same owner still seals to the server vault');

	// The grant invariant asks for the vault the mailbox seals to.
	$novault = make_user('FscNoMailVault');
	fsc_user_vault(intval($novault->key));
	$refused = null;
	try {
		InboundEmailMailboxGrant::sync_for_alias(intval($fortress_box->key), array(intval($novault->key)));
	} catch (InboundEmailMailboxGrantException $e) { $refused = $e->getMessage(); }
	check($refused !== null && stripos($refused, 'mail vault') !== false,
		'a Fortress mailbox refuses a holder with no mail vault', (string)$refused);

	// -----------------------------------------------------------------------
	section('the Fortress refusals, each with its reason');

	$refusal = function (InboundEmailDomain $d, int $actor) {
		return admin_mailbox_domains_fortress_refusal(new InboundEmailDomain(intval($d->key), TRUE), $actor);
	};

	$ok_domain = fsc_domain(InboundEmailDomain::LEVEL_PRIVATE, $owner_id);
	$ok_box = fsc_alias($ok_domain, 'okbox');
	fsc_grant_raw(intval($ok_box->key), array($owner_id));
	fsc_alias($ok_domain, 'fwd', null, InboundEmailAlias::MODE_FORWARD);   // forwards store nothing
	check($refusal($ok_domain, $owner_id) === null,
		'the single owner of every mailbox, holding a mail vault, is not refused');

	$other = make_user('FscOther');
	$other_id = intval($other->key);
	$r = $refusal($ok_domain, $other_id);
	check($r !== null && stripos($r, 'owner') !== false, 'another admin is refused: the domain is not theirs', (string)$r);

	$imap_domain = fsc_domain(InboundEmailDomain::LEVEL_STANDARD, $owner_id, true);
	$r = $refusal($imap_domain, $owner_id);
	check($r !== null && stripos($r, 'password') !== false, 'a provider domain is refused', (string)$r);

	$feed_domain = fsc_domain(InboundEmailDomain::LEVEL_PRIVATE, $owner_id);
	$feed_box = fsc_alias($feed_domain, 'feed');
	fsc_grant_raw(intval($feed_box->key), array($owner_id));
	$feed = $db->prepare('INSERT INTO iia_inbound_imap_accounts (iia_iea_inbound_email_alias_id, iia_label)
		VALUES (?, ?) RETURNING iia_inbound_imap_account_id');
	$feed->execute(array(intval($feed_box->key), 'harnesstest feed'));
	harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', intval($feed->fetchColumn()));
	$r = $refusal($feed_domain, $owner_id);
	check($r !== null && stripos($r, 'password that can read the whole source mailbox') !== false,
		'a mailbox with an IMAP feed is refused, saying why', (string)$r);

	$group_domain = fsc_domain(InboundEmailDomain::LEVEL_STANDARD, $owner_id);
	$group_box = fsc_alias($group_domain, 'group');
	fsc_grant_raw(intval($group_box->key), array($owner_id, $other_id));
	$r = $refusal($group_domain, $owner_id);
	check($r !== null && stripos($r, 'shared') !== false, 'a group mailbox is refused', (string)$r);

	$orphan_domain = fsc_domain(InboundEmailDomain::LEVEL_STANDARD, $owner_id);
	fsc_alias($orphan_domain, 'orphan');
	$r = $refusal($orphan_domain, $owner_id);
	check($r !== null && stripos($r, 'no owner') !== false, 'a mailbox with no owner is refused', (string)$r);

	$theirs_domain = fsc_domain(InboundEmailDomain::LEVEL_STANDARD, $owner_id);
	$theirs_box = fsc_alias($theirs_domain, 'theirs');
	fsc_grant_raw(intval($theirs_box->key), array($other_id));
	$r = $refusal($theirs_domain, $owner_id);
	check($r !== null && stripos($r, 'someone else') !== false, 'a mailbox owned by someone else is refused', (string)$r);

	$novault_domain = fsc_domain(InboundEmailDomain::LEVEL_STANDARD, intval($novault->key));
	$novault_box = fsc_alias($novault_domain, 'novault');
	fsc_grant_raw(intval($novault_box->key), array(intval($novault->key)));
	$r = $refusal($novault_domain, intval($novault->key));
	check($r !== null && stripos($r, 'unlock your vault') !== false, 'an owner without a mail key is refused, told to unlock their vault once', (string)$r);

	// -----------------------------------------------------------------------
	section('the Setup page shows the mail-vault step');

	$setup = new InboundEmailSetupCheck();
	$vault_row = function (InboundEmailAlias $a) use ($setup) {
		$rows = (new ReflectionMethod($setup, 'checkAddress'))->invoke($setup, $a->get_full_address());
		foreach ($rows as $row) { if ($row['id'] === 'address.mail_vault') { return $row; } }
		return null;
	};
	$waiting_owner = make_user('FscWaiting');
	$waiting_box = fsc_alias($fortress_domain, 'waiting');
	fsc_grant_raw(intval($waiting_box->key), array(intval($waiting_owner->key)));
	$row = $vault_row(new InboundEmailAlias(intval($waiting_box->key), TRUE));
	check($row !== null && $row['status'] === 'fail' && stripos($row['summary'], 'held') !== false,
		'an owner without a mail vault: a failing step that says mail is held', (string)($row['summary'] ?? ''));
	fsc_mail_vault(intval($waiting_owner->key));
	$row = $vault_row(new InboundEmailAlias(intval($waiting_box->key), TRUE));
	check($row !== null && $row['status'] === 'pass', 'with the vault set up, the step passes');
	check($vault_row(new InboundEmailAlias(intval($own_private->key), TRUE)) === null,
		'a Private mailbox has no such step');

	// -----------------------------------------------------------------------
	section('a Fortress mailbox takes no feed, and never the relay\'s server-key seal');

	$r = ImapFeedProvisioner::fortressRefusal((string)$fortress_domain->get('ied_domain'), 'harnesstest_box');
	check($r !== null && stripos($r, 'password') !== false, 'a feed on an existing Fortress mailbox is refused', (string)$r);
	$r = ImapFeedProvisioner::fortressRefusal((string)$fortress_domain->get('ied_domain'), 'harnesstest_brandnew');
	check($r !== null, 'and one on a mailbox that would inherit Fortress from its domain');
	$r = ImapFeedProvisioner::fortressRefusal((string)$fortress_domain->get('ied_domain'), 'harnesstest_ownpriv');
	check($r === null, 'a Private mailbox on that domain is not refused');
	check(ImapFeedProvisioner::fortressRefusal('harnesstest-nowhere-' . bin2hex(random_bytes(3)) . '.example', 'x') === null,
		'nor an address on a domain not hosted here');

	// The relay add-on on a Fortress domain: the exporter must not hand out the
	// owner's server key (relay mail would become a Private row).
	$relay_domain = new InboundEmailDomain(intval($fortress_domain->key), TRUE);
	$relay_domain->set('ied_relay_seals_to_owner', true);
	$relay_domain->save();
	$relay_domain = new InboundEmailDomain(intval($fortress_domain->key), TRUE);
	check($relay_domain->relay_seals_to_owner(), 'the domain has Seal at the relay in force');
	$exporter = (new ReflectionClass('RelayMapExporter'))->newInstanceWithoutConstructor();
	$prop = new ReflectionProperty('RelayMapExporter', 'transport_public_key');
	$prop->setValue($exporter, 'transport-key-fixture');
	$target = new ReflectionMethod('RelayMapExporter', 'sealTargetForAlias');
	list($pk, $kind) = $target->invoke($exporter, new InboundEmailAlias(intval($fortress_box->key), TRUE), $relay_domain);
	check($kind === 'transport' && $pk === 'transport-key-fixture',
		'a Fortress mailbox takes the transport key, never the owner\'s server key');

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
