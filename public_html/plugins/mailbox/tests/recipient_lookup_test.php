<?php
/** @joinery-test
 * name: recipient_lookup
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * A colocated box refuses an address it does not have while the sender is
 * still connected, and never bounces afterwards.
 *
 * Refused after acceptance, Postfix mails a bounce to the message's sender,
 * which on spam is a forged stranger (backscatter). So:
 *
 *   - the recipient lookup install_email.sh puts in front of the pipe answers
 *     each recipient the way InboundEmailRouter would: OK for an alias, a
 *     catch-all, postmaster and an SRS bounce address; 550 5.1.1 for an
 *     unknown address on a domain that refuses unmatched mail and for anything
 *     but an SRS bounce on a forwarding subdomain; nothing for the rest;
 *   - the router agrees: everything the lookup refuses it refuses, and what
 *     the lookup accepts it never refuses (postmaster, an SRS address with SRS
 *     off) — a refusal there is a bounce;
 *   - the installer grants the map role exactly the columns the lookup reads,
 *     proves the lookup before wiring it, and wires it last;
 *   - neither the maps nor the router treat a connected Gmail/Outlook feed's
 *     anchor domain (gmail.com) as a domain this box receives for;
 *   - a deferral on the message's last retries is dropped, not left to expire
 *     into a bounce.
 *   - behind a relay, postmaster mail the relay carries in (it accepts
 *     postmaster too) is dropped by the pull consumer unless it is a report.
 *
 * The lookup is run the way Postfix runs it: the rendered query, %u and %d
 * substituted as SQL-quoted strings, against the test database.
 *
 * Run: php tests/run.php --only=plugins/mailbox/tests/recipient_lookup_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));

$db = DbConnector::get_instance()->get_db_link();
$suffix = getmypid() . '-' . random_int(1000, 9999);
$like = 'rlk-%-' . $suffix . '.example';

$domain_ids = array();
harness_defer(function () use ($db, &$domain_ids, $like) {
	foreach ($domain_ids as $id) {
		$db->exec("DELETE FROM iel_inbound_email_logs WHERE iel_ied_inbound_email_domain_id = " . (int)$id);
	}
	mailbox_purge_domains($like);
});

function rlk_domain(string $kind, string $suffix, array $fields, array &$ids): string {
	$name = 'rlk-' . $kind . '-' . $suffix . '.example';
	$d = new InboundEmailDomain(NULL);
	$d->set('ied_domain', $name);
	$d->set('ied_is_enabled', true);
	foreach ($fields as $k => $v) { $d->set($k, $v); }
	$d->save();
	$ids[] = (int)$d->key;
	return $name;
}

function rlk_alias(string $domain, string $local, bool $enabled): void {
	$d = InboundEmailDomain::GetByDomain($domain);
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$d->key);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_is_enabled', $enabled);
	$a->save();
}

$reject  = rlk_domain('reject', $suffix, array('ied_reject_unmatched' => true, 'ied_catch_all_mode' => 'forward',
	'ied_forwarding_subdomain' => 'fwd.rlk-reject-' . $suffix . '.example'), $domain_ids);
$fwd     = 'fwd.' . $reject;
$store   = rlk_domain('store', $suffix, array('ied_reject_unmatched' => true, 'ied_catch_all_mode' => 'store'), $domain_ids);
$catch   = rlk_domain('catch', $suffix, array('ied_reject_unmatched' => true, 'ied_catch_all_mode' => 'forward',
	'ied_catch_all_address' => 'someone@example.net'), $domain_ids);
$discard = rlk_domain('discard', $suffix, array('ied_reject_unmatched' => false, 'ied_catch_all_mode' => 'forward'), $domain_ids);
$off     = rlk_domain('off', $suffix, array('ied_reject_unmatched' => true, 'ied_catch_all_mode' => 'forward',
	'ied_is_enabled' => false), $domain_ids);
$imap    = rlk_domain('imap', $suffix, array('ied_reject_unmatched' => true, 'ied_catch_all_mode' => 'forward',
	'ied_is_imap_source' => true), $domain_ids);
rlk_alias($imap, 'feed', true);
rlk_alias($reject, 'box', true);
rlk_alias($reject, 'gone', false);

$map = shell_exec('IEMAP_PASSWORD=x ' . escapeshellarg(PHP_BINARY) . ' '
	. escapeshellarg(PathHelper::getIncludePath('plugins/mailbox/provisioning/render_pgsql_map.php'))
	. " probe '' recipient_access 2>&1");
preg_match('/^query = (.*)$/m', (string)$map, $m);
$query = $m[1] ?? '';

/** The lookup's answer for one address, as Postfix would get it: the first column, or '' for no row. */
$lookup = function (string $address) use ($db, $query): string {
	list($u, $d) = array_pad(explode('@', $address, 2), 2, '');
	$quote = function ($s) { return str_replace("'", "''", $s); };   // PQescapeStringConn, standard_conforming_strings on
	$sql = strtr($query, array('%u' => $quote($u), '%d' => $quote($d), '%%' => '%'));
	$rows = $db->query($sql)->fetchAll(PDO::FETCH_NUM);
	return count($rows) ? (string)$rows[0][0] : '';
};

section('The lookup answers each recipient the way the router would');

check($query !== '', 'the renderer prints the recipient lookup', (string)$map);
$refused = '550 5.1.1 No such address here';
$cases = array(
	array('box@' . $reject,                    'OK',     'an enabled alias'),
	array('gone@' . $reject,                   $refused, 'a disabled alias is no alias'),
	array('nobody@' . $reject,                 $refused, 'an unknown address on a domain that refuses unmatched mail'),
	array("o'brien@" . $reject,                $refused, 'an address with a quote in it is answered, not broken'),
	array('postmaster@' . $reject,             'OK',     'postmaster, with no alias, on a domain that refuses unmatched mail'),
	array('SRS0=h=TT=x.com=y@' . $reject,      'OK',     'an SRS bounce address on the domain itself'),
	array('srs0=h=tt=x.com=y@' . $fwd,         'OK',     'an SRS bounce address on the forwarding subdomain, in any case'),
	array('nobody@' . $fwd,                    $refused, 'anything else on the forwarding subdomain'),
	array('anyone@' . $store,                  'OK',     'a catch-all that stores'),
	array('anyone@' . $catch,                  'OK',     'a catch-all that forwards'),
	array('anyone@' . $discard,                '',       'a domain that discards unmatched mail: the router decides'),
	array('anyone@' . $off,                    '',       'a disabled domain: not ours, refused earlier as a relay attempt'),
	array('anyone@nowhere-' . $suffix . '.example', '', 'a domain that is not ours at all'),
	array($reject,                             '',       'the bare-domain lookup key answers nothing'),
	array('nobody@',                           '',       'the localpart@ lookup key answers nothing'),
);
foreach ($cases as list($address, $want, $label)) {
	$got = $lookup($address);
	check($got === $want, $label . ': ' . ($want === '' ? 'no answer' : $want), $address . ' -> ' . var_export($got, true));
}

section('A connected Gmail/Outlook feed\'s anchor domain is not one this box receives for');

$domains_map = shell_exec('IEMAP_PASSWORD=x ' . escapeshellarg(PHP_BINARY) . ' '
	. escapeshellarg(PathHelper::getIncludePath('plugins/mailbox/provisioning/render_pgsql_map.php'))
	. " probe '' domains 2>&1");
preg_match('/^query = (.*)$/m', (string)$domains_map, $dm);
$domain_rows = function (string $name) use ($db, $dm): int {
	return count($db->query(str_replace('%s', str_replace("'", "''", $name), $dm[1] ?? 'SELECT 1 WHERE false'))->fetchAll());
};
check($domain_rows($reject) === 1, 'a domain this box hosts is in the domain map');
check($domain_rows($imap) === 0, 'a feed\'s anchor domain is not, so Postfix refuses its mail as a relay attempt');
check($lookup('feed@' . $imap) === '' && $lookup('postmaster@' . $imap) === '',
	'and the recipient lookup answers nothing for it, even for an alias on it');

section('The router agrees: what the lookup refuses it refuses, what it accepts it never refuses');

$raw = function (string $to) {
	return "Return-Path: <spam@forged.example>\r\nFrom: Someone <spam@forged.example>\r\nTo: <" . $to . ">\r\n"
		. "Subject: hello\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@forged.example>\r\n"
		. "Date: " . gmdate('D, d M Y H:i:s') . " +0000\r\n\r\nhello\r\n";
};
$router = new InboundEmailRouter();
foreach (array('gone@' . $reject, 'nobody@' . $reject, 'nobody@' . $fwd) as $address) {
	check($router->processEmail($raw($address), $address) === 67,
		'refused by both: ' . $address, $lookup($address));
}
check($router->processEmail($raw('feed@' . $imap), 'feed@' . $imap) === 67,
	'the router does not route a feed\'s anchor domain either');
check($router->processEmail($raw('postmaster@' . $reject), 'postmaster@' . $reject) === 0,
	'postmaster with no alias is accepted and dropped, never refused');
check($router->processEmail($raw('anyone@' . $discard), 'anyone@' . $discard) === 0,
	'a discard domain drops what the lookup left to it');

harness_set_setting_mem('mailbox_srs_enabled', '0');
$srs = 'SRS0=h=TT=x.com=y@' . $fwd;
check($router->processEmail($raw($srs), $srs) === 0,
	'an SRS bounce address with SRS off is dropped, never refused');
harness_set_setting_mem('mailbox_srs_enabled', '1');
check($router->processEmail($raw($srs), $srs) === 0,
	'an SRS bounce address with SRS on goes to the SRS handling (an invalid one is dropped)');
check(SRSRewriter::isSRSAddress('srs0=h=tt=x.com=y@' . $fwd), 'an SRS address is recognised in any case, as the lookup does');

section('Behind a relay: postmaster carried in is filed if a report, dropped otherwise');

// The relay accepts postmaster on a refusing domain too and carries it here
// sealed to the transport key; the pull consumer must drop a non-report the
// way the router does, never store it as mail.
$box = new SealedBox();
$kp = $box->generateKeypair();
$consumer = new RelaySpoolConsumer(new MailboxRelay(NULL));
(new ReflectionProperty(RelaySpoolConsumer::class, 'transport_secret'))->setValue($consumer, $kp['secret']);
$ingest = new ReflectionMethod(RelaySpoolConsumer::class, 'ingestOne');
$stage = sys_get_temp_dir() . '/rlk-' . $suffix;
@mkdir($stage, 0700, true);
harness_defer(function () use ($stage) { array_map('unlink', glob($stage . '/*') ?: array()); @rmdir($stage); });
$pull = function (string $to) use ($box, $kp, $raw, $stage, $ingest, $consumer): string {
	$spool_id = '1791000000-' . bin2hex(random_bytes(4));
	file_put_contents($stage . '/' . $spool_id . '.seal', $box->sealDek($raw($to), $kp['public']));
	file_put_contents($stage . '/' . $spool_id . '.meta', json_encode(array('recipient' => $to, 'key_kind' => 'transport',
		'public_key' => $kp['public'], 'received_utc' => gmdate('Y-m-d\\TH:i:s\\Z'))));
	return (string)$ingest->invoke($consumer, $stage . '/' . $spool_id . '.seal', $stage . '/' . $spool_id . '.meta', $spool_id);
};
$pm = 'postmaster@' . $reject;
check($pull($pm) === 'discarded', 'postmaster with no alias, not a report: dropped and acked');
$stored = $db->prepare("SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_recipient = ?");
$stored->execute(array($pm));
check((int)$stored->fetchColumn() === 0, 'and nothing was stored as mail');
$logged = $db->prepare("SELECT COUNT(*) FROM iel_inbound_email_logs WHERE iel_to_address = ? AND iel_status = 'discarded'");
$logged->execute(array($pm));
check((int)$logged->fetchColumn() >= 1, 'and the drop is in the routing log');

section('The installer grants what the lookup reads, proves it, and wires it last');

$installer = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/provisioning/install_email.sh'));
preg_match_all('/\b(iea_[a-z_]+)\b/', $query, $read);
$read = array_values(array_diff(array_unique($read[1]), array('iea_inbound_email_aliases')));
sort($read);
preg_match('/GRANT SELECT \(([^)]*)\) ON iea_inbound_email_aliases/', $installer, $g);
$granted = array_map('trim', explode(',', $g[1] ?? ''));
sort($granted);
check($read === $granted, 'the map role is granted exactly the alias columns the lookup reads',
	implode(',', $read) . ' vs ' . implode(',', $granted));
check(preg_match_all('/\bied_[a-z_]+\b/', $query) > 0 && strpos($installer, 'GRANT SELECT ON ied_inbound_email_domains TO') !== false,
	'and SELECT on the domains table it reads');

$probe_at = strpos($installer, 'recipient lookup answers as');
$wire_at = strpos($installer, 'check_recipient_access ${RCPT_MAP}');
check($probe_at !== false && $wire_at !== false && $probe_at < $wire_at,
	'the lookup is proved as the map role before Postfix is pointed at it');
$asked_at = strpos($installer, 'RCPT_QUERY="$(grep -oP \'^query = \K.*\' "${MAP_TMP}"');
$installed_at = strpos($installer, 'install -m 640 -o root -g postfix "${MAP_TMP}" "${RCPT_MAP_FILE}"');
check($asked_at !== false && $installed_at !== false && $asked_at < $installed_at,
	'the freshly rendered copy is asked before it replaces the installed lookup Postfix may already read');
check(strpos($installer, 'too many connections for role') !== false && strpos($installer, 'RCPT_WIRE=0') !== false,
	'a probe refused by the role\'s connection limit leaves the proven lookup in place instead of failing the run');
check(preg_match('/smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination, (reject_r[a-z_]+ [a-z.]+, )+check_recipient_access \$\{RCPT_MAP\}, permit"/', $installer) === 1,
	'it comes after the domain check and the block lists, just before the final permit');
check(substr_count($installer, 'postconf -e "smtpd_recipient_restrictions') === 1,
	'the restrictions are written in one place');
check(strpos($installer, '\${recipient} \${queue_id}') !== false, 'the pipe passes the queue id to the handler');

section('A deferral on its last retries is dropped instead of expiring into a bounce');

$msg = "Delivered-To: box@example.com\r\nReturn-Path: <a@b.example>\r\n"
	. "Authentication-Results: mail.example.com; dkim=none\r\n"
	. "Received: from relay.example (relay.example [192.0.2.7])\r\n\tby mail.example.com (Postfix) with ESMTPS id 4Zabc123XyZ\r\n"
	. "\tfor <box@example.com>; Sun, 27 Sep 2026 10:00:00 +0000 (UTC)\r\n"
	. "Received: from earlier.example by relay.example with SMTP id 4Zabc123XyZQQ; Sat, 26 Sep 2026 10:00:00 +0000\r\n"
	. "Subject: x\r\n\r\nReceived: by body.example id 4Zabc123XyZ; Fri, 1 Jan 2021 00:00:00 +0000\r\n";
check(InboundDeferralExpiry::arrival_time($msg, '4Zabc123XyZ') === strtotime('2026-09-27 10:00:00 UTC'),
	'arrival is read from the folded Received header naming the queue id');
check(InboundDeferralExpiry::arrival_time($msg, 'NOPE1') === strtotime('2026-09-27 10:00:00 UTC'),
	'no Received header names the queue id (requeued): timed from the topmost Received header, the latest hop');
check(InboundDeferralExpiry::arrival_time("Subject: x\r\n\r\nReceived: by body id NOPE1; Fri, 1 Jan 2021 00:00:00 +0000\r\n", 'NOPE1') === null,
	'no Received header at all (a body line does not count): unknown, never guessed');
check(InboundDeferralExpiry::arrival_time($msg, '') === null, 'no queue id: unknown');
check(InboundDeferralExpiry::seconds('5d', 'd') === 432000 && InboundDeferralExpiry::seconds('4000s', 's') === 4000
	&& InboundDeferralExpiry::seconds('7', 'd') === 604800 && InboundDeferralExpiry::seconds('3h', 'd') === 10800
	&& InboundDeferralExpiry::seconds('soon', 'd') === null,
	'Postfix time values parse, with the parameter\'s default unit');
$arr = strtotime('2026-09-27 10:00:00 UTC');
check(!InboundDeferralExpiry::is_expiring($arr, $arr + 432000 - 8001, 432000, 4000), 'more than two backoffs left: defer');
check(InboundDeferralExpiry::is_expiring($arr, $arr + 432000 - 8000, 432000, 4000), 'two backoffs left: drop');
check(InboundDeferralExpiry::is_expiring($arr, $arr, 0, 4000), 'a queue lifetime of 0 (one try): drop at once, as Postfix would bounce at once');
check(!InboundDeferralExpiry::is_expiring($arr, $arr, 7200, 4000), 'a 2 h queue lifetime: a first deferral at arrival is never a drop');
check(InboundDeferralExpiry::is_expiring($arr, $arr + 3600, 7200, 4000) && !InboundDeferralExpiry::is_expiring($arr, $arr + 3599, 7200, 4000),
	'and its last stretch is its second half');

$held_to = 'box@' . $reject;
$held = "Received: from relay.example (relay.example [192.0.2.7])\r\n\tby mail.example.com (Postfix) with ESMTPS id 4Zheld9\r\n"
	. "\tfor <" . $held_to . ">; " . gmdate('D, d M Y H:i:s', $arr) . " +0000 (UTC)\r\n" . $raw($held_to);
$limits = array(432000, 4000);
check(InboundDeferralExpiry::defer_or_drop($held, $held_to, '4Zheld9', 'the store cap was reached', $arr + 3600, $limits) === 75,
	'an hour in: deferred, Postfix retries');
check(InboundDeferralExpiry::defer_or_drop($raw($held_to), $held_to, 'NOPE1', 'the store cap was reached', $arr + 432000, $limits) === 75,
	'arrival unknown: deferred, never dropped on a guess');
check(InboundDeferralExpiry::defer_or_drop($held, $held_to, '4Zheld9', 'the store cap was reached', $arr + 432000 - 3600, $limits) === 0,
	'an hour before the queue lifetime ends: dropped, so Postfix has nothing to bounce');
$note = $db->query("SELECT iel_status || '|' || coalesce(iel_error_message, '') FROM iel_inbound_email_logs
	WHERE iel_ied_inbound_email_domain_id = " . (int)$domain_ids[0] . " AND iel_error_message LIKE 'Deferred since%'
	ORDER BY iel_inbound_email_log_id DESC LIMIT 1")->fetchColumn();
check(is_string($note) && strpos($note, 'discarded|Deferred since ' . gmdate('Y-m-d H:i', $arr) . ' UTC (the store cap was reached)') === 0,
	'and the drop is in the domain\'s routing log, saying when it arrived and why it waited', var_export($note, true));

harness_finish();
