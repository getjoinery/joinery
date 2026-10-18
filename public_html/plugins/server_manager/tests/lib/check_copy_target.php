<?php
/**
 * check_copy_target.php - on a copy, after the switch: does it carry and open
 * everything seed_copy_source.php gave its source
 * (specs/site_copy_anything_to_anything.md D10, specs/site_copy_final_test.md)?
 * A census that matches says the rows and files arrived; this says they work.
 *
 *   php check_copy_target.php /var/www/html/SITE 'SEED={...}'
 *
 * Read-only. One line per item: PASS, FAIL or SKIP, what, and its evidence;
 * then the census judged alone (the canary opens, no sealed secret is dead,
 * the sampled offloaded files answer). Exit 0 when nothing failed.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit(1);
}
$site = rtrim((string)($argv[1] ?? ''), '/');
$seed = json_decode(preg_replace('/^SEED=/', '', (string)($argv[2] ?? '')), true);
if (!preg_match('#^/var/www/html/[A-Za-z0-9][A-Za-z0-9_-]*$#', $site) || !is_file($site . '/public_html/includes/PathHelper.php')
		|| !is_array($seed) || !preg_match('/^[0-9a-f]{12}$/', (string)($seed['id'] ?? ''))) {
	fwrite(STDERR, "usage: php check_copy_target.php /var/www/html/SITE 'SEED={...}'\n");
	exit(2);
}
require_once($site . '/public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(__DIR__ . '/copy_seed_values.php');

$id = (string)$seed['id'];
$items = (array)($seed['items'] ?? array());
$failed = false;
$row = function (string $word, string $what, string $evidence) use (&$failed) {
	printf("%-6s %s - %s\n", $word, $what, $evidence);
	$failed = $failed || $word === 'FAIL';
};
$check = function (string $what, callable $fn) use ($row) {
	try {
		list($ok, $evidence) = $fn();
		$row($ok ? 'PASS' : 'FAIL', $what, $evidence);
	} catch (Throwable $e) {
		$row('FAIL', $what, get_class($e) . ': ' . $e->getMessage());
	}
};

$check('The key canary opens', function () {
	$s = SecretBox::canaryState();
	return array($s === 'ok', 'canary ' . $s);
});

$check('The marker file is here, its bytes the seed\'s', function () use ($items, $id) {
	$f = new File((int)($items['marker']['file_id'] ?? 0), TRUE);
	return array($f->key && $f->read_bytes() === copy_seed_value($id, 'marker'), 'file ' . (int)$f->key);
});

$check('The backup target\'s sealed credential opens', function () use ($items, $id) {
	$t = new BackupTarget((int)($items['backup_target']['target_id'] ?? 0), TRUE);
	$c = $t->key ? $t->get_credentials() : array();
	return array(($c['secret_key'] ?? null) === copy_seed_value($id, 'target'), 'target ' . (int)$t->key);
});

$check('The OAuth client secret opens', function () use ($id) {
	$v = Globalvars::get_instance()->get_setting('oauth_google_client_secret');
	return array($v === copy_seed_value($id, 'oauth'), $v === null ? 'dead or absent' : 'opened');
});

if (isset($items['mailbox']['skipped'])) {
	$row('SKIP', 'The mailbox\'s sealed IMAP password and raw message', (string)$items['mailbox']['skipped']);
} else {
	$check('The IMAP account\'s sealed password opens', function () use ($items, $id) {
		$a = new InboundImapAccount((int)($items['mailbox']['imap_account_id'] ?? 0), TRUE);
		return array($a->key && $a->getPassword() === copy_seed_value($id, 'imap'), 'account ' . (int)$a->key);
	});
	$check('The raw message is found in storage', function () use ($items, $id) {
		$m = new InboundEmailMessage((int)($items['mailbox']['message_id'] ?? 0), TRUE);
		return array($m->key && $m->getRawMessage() === copy_seed_raw($id), 'message ' . (int)$m->key);
	});
}

if (isset($items['offloaded']['skipped'])) {
	$row('SKIP', 'The offloaded file downloads from the bucket', (string)$items['offloaded']['skipped']);
} else {
	$check('The offloaded file downloads from the bucket', function () use ($items, $id) {
		$f = new File((int)($items['offloaded']['file_id'] ?? 0), TRUE);
		$blob = new FileBlob((int)$f->get('fil_fbb_file_blob_id'), TRUE);
		return array($f->key && $f->read_bytes() === copy_seed_value($id, 'offloaded'),
			'file ' . (int)$f->key . ', ' . $blob->get('fbb_storage_driver'));
	});
}

if (isset($items['dkim']['skipped'])) {
	$row('SKIP', 'The site\'s DKIM key came with it', (string)$items['dkim']['skipped']);
} else {
	$check('The site\'s DKIM key came with it', function () use ($items) {
		$dkim = '/etc/rspamd/dkim/' . preg_replace('#^https?://#', '', rtrim((string)Globalvars::get_instance()->get_setting('webDir'), '/'))
			. '/mail.private';
		$key = is_readable($dkim) ? openssl_pkey_get_private((string)file_get_contents($dkim)) : false;
		$sum = $key ? hash('sha256', (string)openssl_pkey_get_details($key)['key']) : '';
		return array($sum !== '' && hash_equals((string)$items['dkim']['public_sha256'], $sum), $key ? 'key read' : 'no key at ' . $dkim);
	});
}

$check('The census, judged alone: no dead secret, the offloaded files answer', function () {
	$verdict = SiteCensus::check(SiteCensus::take());
	return array((int)$verdict['blocking'] === 0, json_encode(array('blocking' => $verdict['blocking'])));
});

exit($failed ? 1 : 0);
