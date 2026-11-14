<?php
/** @joinery-test
 * name: protect_press_live
 * tier: live
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Send protection in one press, for real (specs/send_protection_one_press.md WP1, Tests: Live).
 *
 * The same press the Setup page runs, against a throwaway name under the
 * Cloudflare test zone (joinerytest2.com) with the real Cloudflare driver, the
 * zone's real nameservers, a real vault window and a real sealed key. Before it
 * runs, the name gets a site verification, an ordinary SPF and a DMARC record
 * with an extra report address, so the press has something to replace and
 * something to leave alone. Every record at the name is deleted afterwards.
 *
 * The token is read from ~/.config/joinery-test/cloudflare_test_token and never
 * printed. Run: php tests/run.php live --only=protect_press_live
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));
require_once(PathHelper::getIncludePath('includes/dns/AuthoritativeDns.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));

const PL_ZONE = 'joinerytest2.com';

if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

$token_file = getenv('HOME') . '/.config/joinery-test/cloudflare_test_token';
$token = is_readable($token_file) ? trim((string)file_get_contents($token_file)) : '';
if ($token === '') {
	harness_skip('no Cloudflare test token', $token_file);
	harness_finish();
}
$driver_class = DnsDriverRegistry::get('cloudflare');
$driver = new $driver_class(array('api_token' => $token, 'account_id' => ''));
unset($token);
$zone = $driver->zoneFor(PL_ZONE);
check($zone !== null, 'the token can see the test zone');

$name = 'press-' . bin2hex(random_bytes(3)) . '.' . PL_ZONE;
$cleanup = function () use ($driver, $zone, $name) {
	foreach ($driver->listRecords($zone) as $r) {
		if ($r->name === $name || substr($r->name, -strlen('.' . $name)) === '.' . $name) {
			try { $driver->deleteRecord($zone, $r); } catch (Throwable $e) { error_log('protect_press_live cleanup: ' . $e->getMessage()); }
		}
	}
};

/** The zone's own nameservers' TXT answers at $at (every one that answered). */
function pl_served(string $at): array {
	$out = array();
	foreach (AuthoritativeDns::lookupAll($at, DNS_TXT, PL_ZONE) as $ip => $records) {
		$out[$ip] = $records === null ? null : array_values(array_map(function ($r) { return $r['txt']; }, $records));
	}
	return $out;
}

try {
	section('fixtures: an owner with an open vault, a Private domain with a sealed key, and live records');
	$owner = make_user('PressLive');
	$owner_id = intval($owner->key);
	$kp = (new SealedBox())->generateKeypair();
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', $owner_id);
	$vault->set('uev_public_key', $kp['public']);
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));

	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', $name);
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$domain->set('ied_send_lock_requested', true);
	$domain->save();
	$domain->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));
	check(mailbox_protect_seal_new_key($domain, $owner_id) === null, 'the key is sealed to the owner\'s vault');
	$domain = new InboundEmailDomain(intval($domain->key), TRUE);
	vault_fixture_open_window($owner_id, $kp['secret'], UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
	check(VaultUnlock::isOpen($owner_id) && MailboxDkimSigner::openSealedKey($domain) !== null,
		'the owner\'s window is open and the sealed key opens in it');

	foreach (array(
		new DnsRecord('TXT', $name, 'google-site-verification=presslive'),
		new DnsRecord('TXT', $name, 'v=spf1 include:mailgun.org ~all'),
		new DnsRecord('TXT', '_dmarc.' . $name, 'v=DMARC1; p=none; rua=mailto:reports@dmarc.example'),
	) as $r) {
		$driver->createRecord($zone, $r);
	}
	// Every nameserver, both names: Cloudflare's replicas take a few seconds
	// to agree, and a confirmation built from one that has not caught up is
	// (rightly) refused at the press.
	$deadline = time() + 60;
	do {
		$seen = array('apex' => pl_served($name), 'dmarc' => pl_served('_dmarc.' . $name));
		$ready = true;
		foreach (array('apex' => 2, 'dmarc' => 1) as $k => $n) {
			$answered = array_filter($seen[$k], function ($v) { return $v !== null; });
			if (empty($answered) || count(array_filter($answered, function ($v) use ($n) { return count($v) === $n; })) !== count($answered)) {
				$ready = false;
			}
		}
		if (!$ready) { sleep(2); }
	} while (!$ready && time() < $deadline);
	check($ready, 'the seeded records are served by the zone\'s nameservers', json_encode($seen));

	section('the confirmation, as the page builds it');
	$checker = new InboundEmailSetupCheck();
	$rows = (new DnsReconciler(new MemoryDnsOwnershipStore()))->diffAgainstPublicDns($checker->protectPressPlan($name));
	check(mailbox_protect_press_unsuitable($rows) === '', 'the press is offered', mailbox_protect_press_unsuitable($rows));
	$consent = array_map('mailbox_protect_consent_token', $rows);

	section('one press');
	$started = microtime(true);
	// The forwarding subdomain's MX row asks that the MX land on this server,
	// which only a box that is a mail host can satisfy; a dev box is not one, so
	// that row is the one readiness row left out here. Every nameserver serving
	// every record, and the sealed key's own DKIM row, are checked for real.
	$silent = array();
	$live_blockers = function (InboundEmailDomain $d, DnsRecordPlan $plan) use (&$silent) {
		$out = mailbox_protect_not_served_everywhere($plan, $silent);
		foreach ((new InboundEmailSetupCheck())->signingReadinessChecks($d) as $r) {
			if ($r['id'] === 'domain.dkim' && $r['status'] !== InboundEmailSetupCheck::PASS) {
				$out[] = $r['label'] . ' — ' . $r['summary'];
			}
		}
		return $out;
	};
	$result = mailbox_protect_press($domain, $owner_id, $driver, $zone, $consent,
		array('reconciler' => new DnsReconciler(new MemoryDnsOwnershipStore()), 'blockers' => $live_blockers));
	$took = round(microtime(true) - $started, 1);
	$steps = array();
	foreach ($result['steps'] as $s) { $steps[] = $s['id'] . '=' . $s['status'] . ($s['detail'] !== '' ? ' (' . $s['detail'] . ')' : ''); }
	check($result['ok'], 'the press finishes, in ' . $took . ' s', implode(' | ', $steps));
	check($took < 60, 'well inside a proxied request\'s 100 s', $took . ' s');
	$domain = new InboundEmailDomain(intval($domain->key), TRUE);
	check($domain->is_protected_identity(), 'send protection is on');

	section('what the world is told');
	$held = array();
	foreach ($driver->listRecords($zone) as $r) {
		if ($r->type === 'TXT' && ($r->name === $name || $r->name === '_dmarc.' . $name)) { $held[] = $r->value; }
	}
	sort($held);
	$want = 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@' . $name . ',mailto:reports@dmarc.example';
	check($held === array('google-site-verification=presslive', $want, 'v=spf1 -all'),
		'Cloudflare holds SPF -all, the untouched site verification, and the strict DMARC', json_encode($held));
	// The nameservers take a few seconds to agree after a write.
	$deadline = time() + 60;
	do {
		$apex = pl_served($name);
		$dmarc = pl_served('_dmarc.' . $name);
		$caught_up = true;
		foreach (array_merge(array_values($apex), array_values($dmarc)) as $v) {
			if ($v !== null && (in_array('v=spf1 include:mailgun.org ~all', $v, true)
					|| in_array('v=DMARC1; p=none; rua=mailto:reports@dmarc.example', $v, true))) { $caught_up = false; }
		}
		if (!$caught_up) { sleep(2); }
	} while (!$caught_up && time() < $deadline);
	$apex = pl_served($name);
	$every = true;
	foreach ($apex as $ip => $values) {
		if ($values === null) { continue; }
		sort($values);
		if ($values !== array('google-site-verification=presslive', 'v=spf1 -all')) { $every = false; }
	}
	check($every, 'every nameserver serves SPF -all beside the untouched site verification', json_encode($apex));
	$dmarc = pl_served('_dmarc.' . $name);
	$every = true;
	foreach ($dmarc as $values) { if ($values !== null && $values !== array($want)) { $every = false; } }
	check($every, 'DMARC is strict and keeps the extra report address', json_encode($dmarc));
	$dkim = pl_served((string)$domain->get('ied_dkim_selector') . '._domainkey.' . $name);
	$first = array_values(array_filter($dkim))[0][0] ?? '';
	check(MailboxDkimSigner::proveKeyMatchesRecord((string)MailboxDkimSigner::openSealedKey($domain), $first) === null,
		'a signature from the sealed key verifies against the record the nameservers serve');

	section('pressing again changes nothing');
	$writes_before = count($driver->listRecords($zone));
	$again = mailbox_protect_press($domain, $owner_id, $driver, $zone, $consent,
		array('reconciler' => new DnsReconciler(new MemoryDnsOwnershipStore())));
	check(!$again['ok'] && count($driver->listRecords($zone)) === $writes_before,
		'a second press on a protected domain refuses and writes nothing');
} finally {
	$cleanup();
	VaultUnlock::lockAll($owner_id ?? 0);
}

harness_finish();
