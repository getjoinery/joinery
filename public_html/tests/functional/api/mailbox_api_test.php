<?php
/**
 * Mailbox API action-layer functional tests
 * (specs/implemented/mobile_native_email.md § Test-gate hardening).
 *
 * Exercises the mailbox/thread, mailbox/thread_action, and mailbox/send
 * _api() actions over real HTTP with API-key credentials — the native mobile
 * transport — one layer above MailboxService's own scope tests
 * (plugins/mailbox/tests/mailbox_reader_test.php,
 * plugins/mailbox/tests/profile_mailbox_test.php), which call the service
 * directly rather than going through api/apiv1.php + ApiLogicEndpoint session
 * simulation.
 *
 * A mailbox grant (ieg_inbound_email_mailbox_grants) is per USER, not per
 * key, so "a granted API key" here means a machine key owned by a user who
 * holds a grant — session simulation (ApiLogicEndpoint::executeAction()) runs
 * the action as that user, and MailboxViewer::fromSession() resolves the same
 * grants the web reader would see.
 *
 * Covers:
 *  - A granted key's thread/thread_action/send succeed only within its
 *    granted alias; the same key is denied for a thread/alias it does not
 *    hold.
 *  - A grantless key (no mailbox grants at all) gets empty reads and no-op
 *    mutations, never an authentication error (matches
 *    profile_mailbox_test.php's grantless convention).
 *  - Signed attachment/inline-image URLs minted by mailbox/thread
 *    (MailboxService::withSignedTransport/resolveInlineImages) actually
 *    expire after their TTL and reject a tampered expiry, exactly like
 *    tests/functional/files/signed_urls_test.php. They are never obtainable
 *    by a viewer outside the granting alias in the first place — minting
 *    only ever runs on a getThread() payload already scope-checked, and the
 *    URLs themselves are deliberately bearer-token / session-independent
 *    (MailboxService.php's resolveInlineImages() docstring), so the
 *    enforcement point is "can this viewer's key ever reach the thread",
 *    which the scope tests above pin.
 *  - Round trip: a mobile-originated mailbox/send (multipart, with an
 *    attachment) stores the outbound row and its attachment manifest, and
 *    the same read path the web reader uses (MailboxService::getThread /
 *    withSignedTransport, reached here via mailbox/thread) shows the sent
 *    copy with the same attachment.
 *
 * The round-trip send is a real send through the platform's configured email
 * service (docs/email_system.md) — MailboxSender has no test/dry-run mode,
 * and profile_mailbox_test.php's existing convention is to stop short of a
 * real send by triggering a validation error first. This suite goes one step
 * further for exactly the case the spec calls out as missing coverage, so it
 * completes one real send (from a disposable, self-cleaning fixture alias,
 * to the site's own webmaster_email) to prove the round trip end-to-end.
 *
 * Run: php tests/functional/api/mailbox_api_test.php [base_url] [origin_ip]
 *
 * @version 1.0.0
 */

/** @joinery-test
 * name: api_mailbox
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');
api_test_boot($argv);

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

$db = DbConnector::get_instance()->get_db_link();
$suffix = substr(md5(uniqid('mapi', true)), 0, 8);
$msg_counter = 0;

$files_to_delete = array(); // File objects, permanent_delete()d in the finally block

/** Remove any leftover fixture rows from a prior aborted run (self-healing). */
function mapi_preclean($db) {
	try {
		$db->exec("DELETE FROM ieg_inbound_email_mailbox_grants
			WHERE ieg_iea_inbound_email_alias_id NOT IN
			(SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases)");
		$dids = $db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
			WHERE ied_domain LIKE 'mapi-test-%'")->fetchAll(PDO::FETCH_COLUMN);
		if ($dids) {
			$in = implode(',', array_map('intval', $dids));
			$aids = $db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
				WHERE iea_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
			if ($aids) {
				$ain = implode(',', array_map('intval', $aids));
				$db->exec("DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id IN ($ain)");
			}
			$mids = $db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				WHERE iem_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
			if ($mids) {
				$min = implode(',', array_map('intval', $mids));
				mapi_delete_files_for_messages($db, $min);
				$db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN ($min)");
			}
			$db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id IN ($in)");
			$db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id IN ($in)");
			$db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id IN ($in)");
		}
		$db->exec("DELETE FROM usr_users WHERE usr_email LIKE 'mapi\\_%@example.test'");
	} catch (\Throwable $e) {}
	mapi_sweep_round_trip($db);
}

/** Permanent_delete() every File backing the given (comma-joined) message ids'
 * attachments — proper cleanup (unlinks bytes, cascades the ima row), used
 * before a raw sweep deletes the messages themselves. */
function mapi_delete_files_for_messages($db, $message_ids_in) {
	try {
		$fids = $db->query("SELECT DISTINCT ima_fil_file_id FROM ima_inbound_message_attachments
			WHERE ima_iem_inbound_email_message_id IN ($message_ids_in) AND ima_fil_file_id IS NOT NULL")
			->fetchAll(PDO::FETCH_COLUMN);
		foreach ($fids as $fid) {
			try {
				$f = new File(intval($fid), TRUE);
				if ($f->key) {
					$f->permanent_delete();
				}
			} catch (\Throwable $e) {}
		}
	} catch (\Throwable $e) {}
}

/**
 * Sweep any orphaned round-trip send artifacts (message + attachment + file)
 * by the unique subject prefix this suite always sends round-trip messages
 * with, independent of any id captured by the run that created them. The
 * round-trip message/file are created by a live HTTP request handled by a
 * separate PHP-FPM worker, so a CLI run's own id-based cleanup can
 * occasionally race that worker's commit; this sweep is the self-healing
 * backstop, run both before and after each suite run.
 */
function mapi_sweep_round_trip($db) {
	try {
		$mids = $db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			WHERE iem_subject LIKE 'Mapi round trip %'")->fetchAll(PDO::FETCH_COLUMN);
		if (!$mids) {
			return;
		}
		$in = implode(',', array_map('intval', $mids));
		mapi_delete_files_for_messages($db, $in);
		$db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN ($in)");
		$db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id IN ($in)");
	} catch (\Throwable $e) {}
}

function mapi_make_alias($domain_id, $local) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', $domain_id);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$a->set('iea_is_enabled', true);
	$a->prepare();
	$a->save();
	return intval($a->key);
}

/** Raw insert (bypasses User's email-deliverability validation), permission 1 (member). */
function mapi_make_user($db, $email) {
	$stmt = $db->prepare("INSERT INTO usr_users
		(usr_first_name, usr_email, usr_timezone, usr_permission)
		VALUES ('MailboxApi', ?, 'UTC', 1) RETURNING usr_user_id");
	$stmt->execute(array($email));
	return intval($stmt->fetchColumn());
}

function mapi_make_grant($alias_id, $user_id) {
	$grant = new InboundEmailMailboxGrant(NULL);
	$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
	$grant->set('ieg_usr_user_id', $user_id);
	$grant->save();
	return intval($grant->key);
}

function mapi_insert_msg($db, $domain_id, $alias_id, $thread_key, $subject, $body_html, $suffix, $counter) {
	$message_id = '<mapi' . $counter . '_' . $suffix . '@x>';
	$sql = "INSERT INTO iem_inbound_email_messages
		(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_sender,
		 iem_recipient, iem_subject, iem_body_plain, iem_body_html, iem_message_id_header,
		 iem_thread_key, iem_is_read, iem_is_starred, iem_received_time)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'f', 'f', now())
		RETURNING iem_inbound_email_message_id";
	$stmt = $db->prepare($sql);
	$stmt->execute(array(
		$domain_id, $alias_id,
		'sender_' . $suffix . '@out.test',
		'rcpt_' . $suffix . '@in.test',
		$subject, 'plain body ' . $subject, $body_html,
		$message_id, $thread_key,
	));
	return intval($stmt->fetchColumn());
}

/** POST a multipart/form-data action request (mailbox/send with an attachment). */
function mapi_send_multipart($base_url, $origin_ip, $headers, array $fields, $file_path, $file_name, $file_type) {
	$host = parse_url($base_url, PHP_URL_HOST);
	$ch = curl_init($base_url . '/api/v1/action/mailbox/send');
	$post = $fields;
	$post['attachments[]'] = new CURLFile($file_path, $file_type, $file_name);
	$headers[] = 'Accept: application/json';
	curl_setopt_array($ch, array(
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => $post,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_RESOLVE        => array($host . ':443:' . $origin_ip, $host . ':80:' . $origin_ip),
	));
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return array('status' => $status, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw);
}

/** GET an absolute signed URL with no session/key headers, pinned to the origin IP. */
function mapi_get_signed($signed_url, $origin_ip) {
	$host = parse_url($signed_url, PHP_URL_HOST);
	$ch = curl_init($signed_url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_RESOLVE        => array($host . ':443:' . $origin_ip, $host . ':80:' . $origin_ip),
	));
	$body = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return array('status' => $status, 'body' => (string)$body);
}

/** First https:// URL found inside a signed <img src="..."> body (post-cid-rewrite). */
function mapi_extract_signed_img_url($body_html) {
	if (preg_match('/<img[^>]+src="(https:[^"]+)"/i', (string)$body_html, $m)) {
		return $m[1];
	}
	return null;
}

mapi_preclean($db);

try {
	echo "Base URL: $BASE_URL\nTest suffix: $suffix\n";

	// ------------------------------------------------------------------
	section('Setup');

	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'mapi-test-' . $suffix . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->save();
	$domain_id = intval($domain->key);
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', $domain_id);

	$granted_alias = mapi_make_alias($domain_id, 'granted' . $suffix);
	$other_alias   = mapi_make_alias($domain_id, 'other' . $suffix);
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $granted_alias);
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $other_alias);

	$granted_user   = mapi_make_user($db, 'mapi_granted_' . $suffix . '@example.test');
	$foreign_user   = mapi_make_user($db, 'mapi_foreign_' . $suffix . '@example.test');
	$grantless_user = mapi_make_user($db, 'mapi_grantless_' . $suffix . '@example.test');

	$grant1 = mapi_make_grant($granted_alias, $granted_user); // granted_user <-> granted_alias
	$grant2 = mapi_make_grant($other_alias, $foreign_user);   // foreign_user <-> other_alias (elsewhere)
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', $grant1);
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', $grant2);

	// API keys — session-simulation authenticates each as its owning user, so
	// "granted key" / "foreign key" / "grantless key" below tracks the grant
	// on that user, exactly as a mobile client's key would.
	$granted_key   = make_machine_key($granted_user, 'mapi-granted-' . $suffix);
	$foreign_key   = make_machine_key($foreign_user, 'mapi-foreign-' . $suffix);
	$grantless_key = make_machine_key($grantless_user, 'mapi-grantless-' . $suffix);
	$granted_h   = key_headers($granted_key['api_key']->get('apk_public_key'), $granted_key['secret_key']);
	$foreign_h   = key_headers($foreign_key['api_key']->get('apk_public_key'), $foreign_key['secret_key']);
	$grantless_h = key_headers($grantless_key['api_key']->get('apk_public_key'), $grantless_key['secret_key']);

	// A file-backed attachment + a file-backed inline image on one message in
	// the granted mailbox, so mailbox/thread has both signed-URL shapes to
	// enrich (withSignedTransport() for the attachment, resolveInlineImages()
	// for the cid: rewrite).
	$att_bytes = 'mapi-attachment-bytes-' . $suffix;
	$att_file = File::createFromBytes($att_bytes, 'mapi_att_' . $suffix . '.txt', 'text/plain', 1,
		array('fil_private' => true));
	$files_to_delete[] = $att_file;

	$inline_bytes = 'mapi-inline-image-bytes-' . $suffix; // not real image bytes; only byte-equality is asserted
	$inline_file = File::createFromBytes($inline_bytes, 'mapi_inline_' . $suffix . '.png', 'image/png', 1,
		array('fil_private' => true));
	$files_to_delete[] = $inline_file;

	$granted_thread_key = '<mapi-granted-' . $suffix . '@x>';
	$msg_granted = mapi_insert_msg($db, $domain_id, $granted_alias, $granted_thread_key,
		'Granted thread ' . $suffix,
		'<p>body</p><img src="cid:mapi_inline_' . $suffix . '">',
		$suffix, ++$msg_counter);
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', $msg_granted);

	$other_thread_key = '<mapi-other-' . $suffix . '@x>';
	$msg_other = mapi_insert_msg($db, $domain_id, $other_alias, $other_thread_key,
		'Other thread ' . $suffix, '', $suffix, ++$msg_counter);
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', $msg_other);

	$att_row = InboundMessageAttachment::CreateEntry(array(
		'ima_iem_inbound_email_message_id' => $msg_granted,
		'ima_filename'     => 'mapi_att_' . $suffix . '.txt',
		'ima_content_type' => 'text/plain',
		'ima_size_bytes'   => strlen($att_bytes),
		'ima_is_inline'    => false,
		'ima_fil_file_id'  => intval($att_file->key),
	));
	harness_register_row('ima_inbound_message_attachments', 'ima_inbound_message_attachment_id', intval($att_row->key));

	$inline_row = InboundMessageAttachment::CreateEntry(array(
		'ima_iem_inbound_email_message_id' => $msg_granted,
		'ima_filename'     => 'mapi_inline_' . $suffix . '.png',
		'ima_content_type' => 'image/png',
		'ima_size_bytes'   => strlen($inline_bytes),
		'ima_content_id'   => 'mapi_inline_' . $suffix,
		'ima_is_inline'    => true,
		'ima_fil_file_id'  => intval($inline_file->key),
	));
	harness_register_row('ima_inbound_message_attachments', 'ima_inbound_message_attachment_id', intval($inline_row->key));

	echo "  domain=$domain_id granted_alias=$granted_alias other_alias=$other_alias\n";
	echo "  granted_user=$granted_user foreign_user=$foreign_user grantless_user=$grantless_user\n";

	// ------------------------------------------------------------------
	section('mailbox/thread — succeeds only within the granted alias (acceptance #1)');

	$r = api_request('POST', '/api/v1/action/mailbox/thread', $granted_h, array('thread_key' => $granted_thread_key));
	check($r['status'] === 200, 'granted key: thread in its own alias returns 200', $r['raw']);
	$msgs = $r['json']['data']['messages'] ?? array();
	check(count($msgs) === 1, 'granted key: sees exactly the one message', $r['raw']);
	check(($msgs[0]['subject'] ?? '') === 'Granted thread ' . $suffix, 'granted key: subject matches');
	check(count($msgs[0]['attachments'] ?? array()) === 1, 'granted key: sees the one non-inline attachment');
	$attachment_url = $msgs[0]['attachments'][0]['url'] ?? null;
	check(!empty($attachment_url), 'granted key: attachment carries a signed url', $r['raw']);
	$inline_img_url = mapi_extract_signed_img_url($msgs[0]['body_html'] ?? '');
	check(!empty($inline_img_url), 'granted key: inline image cid: was rewritten to a signed url',
		$msgs[0]['body_html'] ?? '');

	$r = api_request('POST', '/api/v1/action/mailbox/thread', $granted_h, array('thread_key' => $other_thread_key));
	check($r['status'] === 200, 'granted key: request for a thread outside its alias still returns 200 (not an error)', $r['raw']);
	check(($r['json']['data']['messages'] ?? array(-1)) === array(),
		'granted key: thread outside its alias returns empty messages', $r['raw']);

	// ------------------------------------------------------------------
	section('mailbox/thread — grantless and foreign keys get empty, not an error (acceptance #2)');

	$r = api_request('POST', '/api/v1/action/mailbox/thread', $grantless_h, array('thread_key' => $granted_thread_key));
	check($r['status'] === 200, 'grantless key: request returns 200 (not AuthenticationError)', $r['raw']);
	check(($r['json']['data']['messages'] ?? array(-1)) === array(), 'grantless key: sees no messages', $r['raw']);

	$r = api_request('POST', '/api/v1/action/mailbox/thread', $foreign_h, array('thread_key' => $granted_thread_key));
	check($r['status'] === 200, 'foreign key (granted elsewhere): request returns 200', $r['raw']);
	check(($r['json']['data']['messages'] ?? array(-1)) === array(),
		'foreign key: sees no messages in an alias it does not hold', $r['raw']);

	// Sanity: the foreign key's OWN alias still works — proves the emptiness
	// above is scope, not a broken key.
	$r = api_request('POST', '/api/v1/action/mailbox/thread', $foreign_h, array('thread_key' => $other_thread_key));
	check($r['status'] === 200 && count($r['json']['data']['messages'] ?? array()) === 1,
		'foreign key: sees its own granted alias fine', $r['raw']);

	// ------------------------------------------------------------------
	section('Signed URLs from mailbox/thread: tamper + expiry (acceptance #3)');

	$parsed = parse_url($attachment_url);
	parse_str($parsed['query'] ?? '', $q);
	check(isset($q['expires'], $q['sig']), 'attachment url carries expires+sig query params', $attachment_url);

	$r = mapi_get_signed($attachment_url, $ORIGIN_IP);
	check($r['status'] == 200, 'valid signed attachment url serves 200', 'status ' . $r['status']);
	check($r['body'] === $att_bytes, 'signed attachment response bytes match the stored file');

	$tampered = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path']
		. '?expires=' . ($q['expires'] + 86400) . '&sig=' . $q['sig'];
	$r = mapi_get_signed($tampered, $ORIGIN_IP);
	check($r['status'] == 404, 'extended-expiry (tampered) signature is denied', 'status ' . $r['status']);

	$r = mapi_get_signed($inline_img_url, $ORIGIN_IP);
	check($r['status'] == 200 && $r['body'] === $inline_bytes,
		'signed inline-image url serves the stored bytes', 'status ' . $r['status']);

	// Real TTL expiry: same production function thread_logic() calls
	// (MailboxService::getThread()/withSignedTransport()), invoked here with
	// an explicit short TTL to prove the expiry timer itself works, mirroring
	// signed_urls_test.php's short-TTL + sleep technique rather than waiting
	// out the real (900s) production TTL.
	$granted_viewer = MailboxViewer::forUser($granted_user, 1);
	$svc = new MailboxService($granted_viewer);
	$short_lived = $svc->withSignedTransport($svc->getThread($granted_alias, $granted_thread_key), 1);
	$short_url = $short_lived[0]['attachments'][0]['url'] ?? null;
	check(!empty($short_url), 'short-TTL mint via the same service call produced a url');
	// Minted outside an HTTPS request (this is a CLI process), so
	// get_absolute_url() falls back to http://; the site redirects that to
	// https unsigned, which would trip the signature check before it ever
	// runs. Rewrite the scheme — the signature itself covers only the path
	// and query, not the scheme, so this changes nothing being tested.
	$short_url = preg_replace('#^http://#', 'https://', (string)$short_url);
	$r = mapi_get_signed($short_url, $ORIGIN_IP);
	check($r['status'] == 200, 'short-TTL signed url serves before expiry', 'status ' . $r['status']);
	sleep(2);
	$r = mapi_get_signed($short_url, $ORIGIN_IP);
	check($r['status'] == 404, 'short-TTL signed url is denied once its TTL has elapsed', 'status ' . $r['status']);

	// ------------------------------------------------------------------
	section('mailbox/thread_action — succeeds only within the granted alias');

	$r = api_request('POST', '/api/v1/action/mailbox/thread_action', $grantless_h,
		array('action' => 'star', 'thread_key' => $granted_thread_key));
	check($r['status'] === 200 && ($r['json']['data']['count'] ?? -1) === 0,
		'grantless key: star is a no-op (count 0)', $r['raw']);

	$r = api_request('POST', '/api/v1/action/mailbox/thread_action', $foreign_h,
		array('action' => 'star', 'thread_key' => $granted_thread_key));
	check($r['status'] === 200 && ($r['json']['data']['count'] ?? -1) === 0,
		'foreign key: star on a non-held alias is a no-op (count 0)', $r['raw']);

	$is_starred = $db->query("SELECT iem_is_starred FROM iem_inbound_email_messages
		WHERE iem_inbound_email_message_id = $msg_granted")->fetchColumn();
	check($is_starred === false || $is_starred === 'f', 'message is still unstarred after the no-op attempts');

	$r = api_request('POST', '/api/v1/action/mailbox/thread_action', $granted_h,
		array('action' => 'star', 'thread_key' => $granted_thread_key));
	check($r['status'] === 200 && ($r['json']['data']['count'] ?? 0) === 1,
		'granted key: star within its own alias affects 1 row', $r['raw']);
	$is_starred = $db->query("SELECT iem_is_starred FROM iem_inbound_email_messages
		WHERE iem_inbound_email_message_id = $msg_granted")->fetchColumn();
	check($is_starred === true || $is_starred === 't', 'message is starred in the database');

	// ------------------------------------------------------------------
	section('mailbox/send — succeeds only within the granted alias');

	$denied_mailbox = 'You do not have access to this mailbox.';
	$denied_compose = 'You do not have a mailbox to send from.';

	$r = api_request('POST', '/api/v1/action/mailbox/send', $grantless_h, array(
		'mode' => 'new', 'alias_id' => $granted_alias, 'to' => 'nobody@example.test',
		'subject' => 'should not send', 'body' => 'x',
	));
	check($r['status'] >= 400, 'grantless key: send is rejected', $r['raw']);
	check(($r['json']['error'] ?? '') === $denied_compose, 'grantless key: rejected for having no mailbox at all',
		$r['json']['error'] ?? '');

	$r = api_request('POST', '/api/v1/action/mailbox/send', $foreign_h, array(
		'mode' => 'reply', 'source_id' => $msg_granted, 'to' => 'nobody@example.test',
	));
	check($r['status'] >= 400, 'foreign key: reply into an alias it does not hold is rejected', $r['raw']);
	check(($r['json']['error'] ?? '') === $denied_mailbox, 'foreign key: rejected by the per-alias scope check',
		$r['json']['error'] ?? '');

	$r = api_request('POST', '/api/v1/action/mailbox/send', $granted_h, array(
		'mode' => 'new', 'alias_id' => $other_alias, 'to' => 'nobody@example.test',
		'subject' => 'should not send', 'body' => 'x',
	));
	check($r['status'] >= 400, 'granted key: send AS an alias it does not hold is rejected', $r['raw']);
	check(($r['json']['error'] ?? '') === $denied_mailbox,
		'granted key: same key is denied outside its own granted alias', $r['json']['error'] ?? '');

	// ------------------------------------------------------------------
	section('mailbox/send — round trip: mobile send with an attachment shows in the web reader (acceptance #4)');

	// NOT webmaster_email / dev.getjoinery.com: that domain is this same
	// platform's own live inbound domain, so a send there loops back through
	// the inbound pipeline and lands as a second, untracked INBOUND copy —
	// this fixture's own throwaway domain has no MX and is not configured for
	// inbound anywhere, so the send still exercises real outbound submission
	// without creating an uncleanable side effect.
	$to_address = 'sink-' . $suffix . '@' . $domain->get('ied_domain');

	$upload_bytes = 'mapi-roundtrip-upload-' . $suffix;
	$tmp_path = sys_get_temp_dir() . '/mapi_roundtrip_' . $suffix . '.txt';
	file_put_contents($tmp_path, $upload_bytes);
	$upload_name = 'roundtrip_' . $suffix . '.txt';
	$send_subject = 'Mapi round trip ' . $suffix;

	$r = mapi_send_multipart($BASE_URL, $ORIGIN_IP, $granted_h, array(
		'mode'     => 'new',
		'alias_id' => (string)$granted_alias,
		'to'       => $to_address,
		'subject'  => $send_subject,
		'body'     => 'Round trip body ' . $suffix,
	), $tmp_path, $upload_name, 'text/plain');
	@unlink($tmp_path);

	$sent_ok = check($r['status'] === 200, 'round-trip send: mailbox/send returns 200', $r['raw']);
	$outbound_id = intval($r['json']['data']['outbound_id'] ?? 0);
	check($outbound_id > 0, 'round-trip send: response carries an outbound_id', $r['raw']);

	$sent_thread_key = null;
	if ($sent_ok && $outbound_id > 0) {
		harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', $outbound_id);

		$row = $db->query("SELECT iem_thread_key, iem_direction, iem_subject FROM iem_inbound_email_messages
			WHERE iem_inbound_email_message_id = $outbound_id")->fetch(PDO::FETCH_ASSOC);
		check($row && $row['iem_direction'] === 'outbound', 'stored row is direction=outbound');
		check($row && $row['iem_subject'] === $send_subject, 'stored row subject matches what was sent');
		$sent_thread_key = $row ? $row['iem_thread_key'] : null;

		$att_ids = $db->query("SELECT ima_inbound_message_attachment_id, ima_fil_file_id, ima_filename
			FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = $outbound_id")
			->fetchAll(PDO::FETCH_ASSOC);
		check(count($att_ids) === 1, 'exactly one attachment manifest row was stored for the outbound message');
		if (count($att_ids) === 1) {
			harness_register_row('ima_inbound_message_attachments', 'ima_inbound_message_attachment_id',
				intval($att_ids[0]['ima_inbound_message_attachment_id']));
			check($att_ids[0]['ima_filename'] === $upload_name, 'stored attachment filename matches the upload');
			$sent_file = new File(intval($att_ids[0]['ima_fil_file_id']), TRUE);
			if ($sent_file->key) {
				$files_to_delete[] = $sent_file;
			}
		}
	}

	if ($sent_thread_key) {
		// The same read path the web reader renders through: mailbox/thread ->
		// MailboxService::getThread() + withSignedTransport(), reached here via
		// the API action instead of the ajax endpoint — one brain, either door.
		$r = api_request('POST', '/api/v1/action/mailbox/thread', $granted_h, array('thread_key' => $sent_thread_key));
		check($r['status'] === 200, 'reading the sent thread back returns 200', $r['raw']);
		$sent_msgs = $r['json']['data']['messages'] ?? array();
		check(count($sent_msgs) === 1, 'the sent copy is visible as its own thread', $r['raw']);
		if (count($sent_msgs) === 1) {
			check(($sent_msgs[0]['subject'] ?? '') === $send_subject, 'read-back subject matches the sent copy');
			check(($sent_msgs[0]['direction'] ?? '') === 'outbound', 'read-back direction is outbound');
			$read_atts = $sent_msgs[0]['attachments'] ?? array();
			check(count($read_atts) === 1 && ($read_atts[0]['filename'] ?? '') === $upload_name,
				'read-back attachment manifest shows the same filename');
			$read_url = $read_atts[0]['url'] ?? null;
			check(!empty($read_url), 'read-back attachment carries a signed url');
			if ($read_url) {
				$dl = mapi_get_signed($read_url, $ORIGIN_IP);
				check($dl['status'] == 200 && $dl['body'] === $upload_bytes,
					'downloading the read-back url returns the exact bytes that were uploaded',
					'status ' . $dl['status']);
			}
		}

		// A viewer outside the granting alias still cannot read the sent copy.
		$r = api_request('POST', '/api/v1/action/mailbox/thread', $foreign_h, array('thread_key' => $sent_thread_key));
		check($r['status'] === 200 && ($r['json']['data']['messages'] ?? array(-1)) === array(),
			'foreign key cannot read the round-trip sent copy', $r['raw']);
	} else {
		check(false, 'round-trip send did not complete — skipped the read-back assertions (see send response above)');
	}

} catch (Exception $e) {
	$failed++;
	echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
	section('Cleanup');
	foreach ($files_to_delete as $f) {
		try {
			if ($f && $f->key) {
				$f->permanent_delete();
			}
		} catch (\Throwable $e) {
			echo "  WARNING: could not delete file " . ($f ? $f->key : '?') . ": " . $e->getMessage() . "\n";
		}
	}
	echo "  Removed " . count($files_to_delete) . " test files\n";

	// usr_users created via raw insert (mapi_make_user) rather than make_user()
	// aren't tracked by the harness's User-object cleanup list; remove them
	// directly, same as mailbox_reader_test.php / profile_mailbox_test.php.
	try {
		$db->exec("DELETE FROM usr_users WHERE usr_email LIKE 'mapi\\_%@example.test'");
	} catch (\Throwable $e) {
		echo "  WARNING: could not delete test users: " . $e->getMessage() . "\n";
	}

	harness_teardown_data();

	// Belt-and-suspenders: the round-trip send's message/attachment/file are
	// created by a live HTTP request handled by a separate PHP-FPM worker, so
	// this CLI process's read-back of their ids can occasionally race that
	// worker's commit. mapi_sweep_round_trip() re-sweeps by the unique
	// subject prefix (independent of any id captured above) so a missed row
	// is still removed, this run or a prior one — self-healing, matching
	// mailbox_reader_test.php's preClean() discipline.
	mapi_sweep_round_trip($db);
}

harness_finish();
