<?php
/**
 * seed_copy_source.php - give a fresh TEST site one of everything a copy must
 * carry, so check_copy_target.php can prove the copy carries it
 * (specs/site_copy_anything_to_anything.md D10, specs/site_copy_final_test.md).
 *
 * TEST SITES ONLY: it writes made-up rows and a made-up OAuth secret. The
 * live tests copy it to a test box over SSH and run it there as root:
 *
 *   php seed_copy_source.php /var/www/html/SITE
 *
 * It prints one line, SEED={...}: the seed's id and, for each item, what was
 * made and the ids the checker looks for. No secret is printed: every made-up
 * value is derived from the seed id (copy_seed_value()), so the checker
 * derives the same value and compares.
 *
 * What it makes, each its own subsystem:
 *   - the key canary, if the site has none, and the sealed-secret registry;
 *   - a marker file (a public File) whose bytes name the seed;
 *   - a disabled backup target with a sealed credential;
 *   - the Google OAuth client secret, a sealed setting;
 *   - with the mailbox plugin on: a domain, a store alias, an IMAP account with
 *     a sealed password, and a message whose raw .eml is kept in storage/;
 *   - with the cloud file store on: a private file, offloaded to the bucket;
 *   - the fingerprint of the site's own DKIM key, where it has one.
 * A passkey and a vault are made by the live test's browser: no script can.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit(1);
}
$site = rtrim((string)($argv[1] ?? ''), '/');
if (!preg_match('#^/var/www/html/[A-Za-z0-9][A-Za-z0-9_-]*$#', $site) || !is_file($site . '/public_html/includes/PathHelper.php')) {
	fwrite(STDERR, "usage: php seed_copy_source.php /var/www/html/SITE\n");
	exit(2);
}
require_once($site . '/public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(__DIR__ . '/copy_seed_values.php');

$id = bin2hex(random_bytes(6));
$out = array('id' => $id, 'site' => basename($site), 'time' => gmdate('Y-m-d H:i:s'), 'items' => array());
$item = function (string $name, array $facts) use (&$out) {
	$out['items'][$name] = $facts;
};

try {
	// The canary and the registry: the census reads both.
	if (SecretBox::canaryState() !== 'ok') {
		SecretBox::provisionCanary();
	}
	SealedSecretRegistry::seed_from_manifests();
	$item('canary', array('state' => SecretBox::canaryState()));

	$owner = (int)DbConnector::get_instance()->get_db_link()->query("SELECT usr_user_id FROM usr_users WHERE usr_permission >= 10
		AND usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetchColumn();
	$marker = File::createFromBytes(copy_seed_value($id, 'marker'), 'copy-seed-' . $id . '.txt', 'text/plain', $owner);
	$item('marker', array('file_id' => (int)$marker->key));

	$t = new BackupTarget(NULL);
	foreach (array('bkt_name' => 'copy seed ' . $id, 'bkt_provider' => 'generic', 'bkt_bucket' => 'copy-seed-' . $id,
			'bkt_purpose' => BackupTarget::PURPOSE_BACKUPS, 'bkt_enabled' => false,
			'bkt_credentials' => array('access_key' => 'seed' . $id, 'secret_key' => copy_seed_value($id, 'target'),
				'region' => 'seed-1', 'endpoint' => 'https://seed.invalid')) as $k => $v) {
		$t->set($k, $v);
	}
	$t->prepare();
	$t->save();
	$t->load();
	$raw = $t->get('bkt_credentials');
	$raw = is_string($raw) ? json_decode($raw, true) : $raw;
	if (!is_array($raw) || !SecretBox::looksEncrypted((string)($raw['enc'] ?? ''))) {
		throw new Exception('the backup target\'s credential was stored unsealed: this site has no secret_box_key');
	}
	$item('backup_target', array('target_id' => (int)$t->key));

	Setting::put('oauth_google_client_secret', copy_seed_value($id, 'oauth'));
	$item('oauth_secret', array('setting' => 'oauth_google_client_secret'));

	if (class_exists('InboundEmailDomain')) {
		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'seed-' . $id . '.invalid');
		$domain->set('ied_is_enabled', true);
		$domain->save();
		$alias = new InboundEmailAlias(NULL);
		$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
		$alias->set('iea_alias', 'seed');
		$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$alias->set('iea_is_enabled', true);
		$alias->prepare();
		$alias->save();
		$acct = new InboundImapAccount(NULL);
		foreach (array('iia_label' => 'copy seed ' . $id, 'iia_provider_key' => 'imap_generic', 'iia_imap_host' => 'imap.seed.invalid',
				'iia_iea_inbound_email_alias_id' => (int)$alias->key, 'iia_username' => 'seed@seed.invalid', 'iia_is_enabled' => false) as $k => $v) {
			$acct->set($k, $v);
		}
		$acct->setPassword(copy_seed_value($id, 'imap'));
		$acct->prepare();
		$acct->save();
		$msg = new InboundEmailMessage(NULL);
		foreach (array('iem_ied_inbound_email_domain_id' => (int)$domain->key, 'iem_iea_inbound_email_alias_id' => (int)$alias->key,
				'iem_sender' => 'sender@seed.invalid', 'iem_recipient' => 'seed@seed-' . $id . '.invalid', 'iem_subject' => 'copy seed ' . $id,
				'iem_message_id_header' => '<' . $id . '@seed.invalid>', 'iem_received_time' => gmdate('Y-m-d H:i:s')) as $k => $v) {
			$msg->set($k, $v);
		}
		$msg->save();
		$d = RawMessageStore::write((int)$msg->key, copy_seed_raw($id));
		$msg->set('iem_raw_storage_driver', $d['driver']);
		$msg->set('iem_raw_storage_key', $d['key']);
		$msg->save();
		$item('mailbox', array('domain_id' => (int)$domain->key, 'imap_account_id' => (int)$acct->key, 'message_id' => (int)$msg->key));
	} else {
		$item('mailbox', array('skipped' => 'the mailbox plugin is not active here'));
	}

	if (class_exists('CloudStorageLifecycle') && CloudStorageDriverFactory::current() !== null) {
		$private = File::createFromBytes(copy_seed_value($id, 'offloaded'), 'copy-seed-private-' . $id . '.txt', 'text/plain', $owner,
			array('fil_private' => true));
		CloudOffloadEngine::syncBatch(new BlobStorageProfile());
		$private->load();
		$blob = new FileBlob((int)$private->get('fil_fbb_file_blob_id'), TRUE);
		$item('offloaded', array('file_id' => (int)$private->key, 'driver' => (string)$blob->get('fbb_storage_driver')));
	} else {
		$item('offloaded', array('skipped' => 'no cloud file store is on here'));
	}

	$dkim = '/etc/rspamd/dkim/' . preg_replace('#^https?://#', '', rtrim((string)Globalvars::get_instance()->get_setting('webDir'), '/')) . '/mail.private';
	$key = is_readable($dkim) ? openssl_pkey_get_private((string)file_get_contents($dkim)) : false;
	$item('dkim', $key ? array('public_sha256' => hash('sha256', (string)openssl_pkey_get_details($key)['key']))
		: array('skipped' => 'the site has no DKIM key of its own here'));
} catch (Throwable $e) {
	fwrite(STDERR, 'seed_copy_source: ' . $e->getMessage() . "\n");
	echo 'SEED=' . json_encode($out) . "\n";
	exit(1);
}
echo 'SEED=' . json_encode($out) . "\n";
exit(0);
