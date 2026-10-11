<?php
/**
 * Shared fixtures for the mail_apps suites. Not a test file (no
 * @joinery-test header). Required after harness_boot().
 *
 * A domain, a mailbox (Standard or Private) granted to a member, messages
 * stored the way mail arrives (InboundEmailRouter::storeMessage(), so lean
 * records with private Files), and a MailAppContext acting as that member —
 * the session simulation the API gives a request.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../../tests/lib/vault_fixtures.php');

/** A domain this site hosts, registered for teardown. */
function mafx_domain(string $prefix = 'mailapps'): InboundEmailDomain {
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', $prefix . '-' . bin2hex(random_bytes(4)) . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->save();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);
	return $domain;
}

/** A store-mode mailbox on $domain granted to $user_id; Private when $level says so. */
function mafx_alias(InboundEmailDomain $domain, string $local, int $user_id, string $level = ''): InboundEmailAlias {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$a->set('iea_is_enabled', true);
	if ($level !== '') {
		$a->set('iea_security_level', $level);
	}
	$a->prepare();
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	$g = new InboundEmailMailboxGrant(NULL);
	$g->set('ieg_iea_inbound_email_alias_id', (int)$a->key);
	$g->set('ieg_usr_user_id', $user_id);
	$g->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);
	return new InboundEmailAlias((int)$a->key, TRUE);
}

/**
 * A raw message. $parts: list of ['type','body','name'?,'cid'?] for a
 * multipart/mixed; empty means a plain text message of $text.
 */
function mafx_raw(string $to, string $subject, string $text, array $attachments = array(), string $html = ''): string {
	$id = '<mafx-' . bin2hex(random_bytes(8)) . '@sender.example>';
	$head = "From: \"Sender Person\" <sender@sender.example>\r\nTo: <$to>\r\nSubject: $subject\r\n"
		. 'Date: ' . gmdate('D, d M Y H:i:s +0000') . "\r\nMessage-ID: $id\r\nMIME-Version: 1.0\r\n";
	if (!$attachments && $html === '') {
		return $head . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n";
	}
	$b = 'mafx' . bin2hex(random_bytes(6));
	$out = $head . "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n";
	if ($html !== '') {
		$ab = 'alt' . bin2hex(random_bytes(6));
		$out .= "--$b\r\nContent-Type: multipart/alternative; boundary=\"$ab\"\r\n\r\n"
			. "--$ab\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n"
			. "--$ab\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n--$ab--\r\n";
	} else {
		$out .= "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n";
	}
	foreach ($attachments as $att) {
		$disp = !empty($att['cid']) ? 'inline' : 'attachment';
		$out .= "--$b\r\nContent-Type: {$att['type']}; name=\"{$att['name']}\"\r\n"
			. "Content-Disposition: $disp; filename=\"{$att['name']}\"\r\nContent-Transfer-Encoding: base64\r\n"
			. (!empty($att['cid']) ? "Content-ID: <{$att['cid']}>\r\n" : '')
			. "\r\n" . chunk_split(base64_encode($att['body']), 76, "\r\n");
	}
	return $out . "--$b--\r\n";
}

/** Store a raw message into $alias the way mail arrives. Returns the message id. */
function mafx_deliver(InboundEmailAlias $alias, InboundEmailDomain $domain, string $raw): int {
	$router = new InboundEmailRouter();
	$stored = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain, $alias->get_full_address(),
		array('spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass', 'source' => 'test'),
		array('score' => null, 'decision' => array('verdict' => null, 'reason' => null, 'meta' => null, 'fingerprint' => null)),
		array('run_filters' => false));
	$id = (int)$stored['message']->key;
	harness_register_model('InboundEmailMessage', $id);
	mafx_register_files($id);
	return $id;
}

/** Register the private Files a message's attachments were stored as, for teardown. */
function mafx_register_files(int $message_id): void {
	$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT ima_fil_file_id FROM ima_inbound_message_attachments
		WHERE ima_iem_inbound_email_message_id = ? AND ima_fil_file_id IS NOT NULL');
	$stmt->execute(array($message_id));
	foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fid) {
		harness_register_model('File', (int)$fid);
	}
}

/** A MailAppContext for $address, acting as $user_id — the session the API simulates. */
function mafx_context(int $user_id, string $address): MailAppContext {
	SessionControl::get_instance()->set_api_user($user_id, null);
	return MailAppContext::forMailbox($address);
}

/** The numbers live in a folder: uid => message id (null for a notice). */
function mafx_live(MailAppContext $ctx, string $key): array {
	$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT mau_uid, mau_iem_inbound_email_message_id
		FROM mau_mail_app_uids WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL
		ORDER BY mau_uid');
	$stmt->execute(array($ctx->alias_id, $key));
	$out = array();
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$out[(int)$r['mau_uid']] = $r['mau_iem_inbound_email_message_id'] !== null ? (int)$r['mau_iem_inbound_email_message_id'] : null;
	}
	return $out;
}

/** Every number a folder ever handed out, live or retired. */
function mafx_all_uids(MailAppContext $ctx, string $key): array {
	$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT mau_uid FROM mau_mail_app_uids
		WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? ORDER BY mau_uid');
	$stmt->execute(array($ctx->alias_id, $key));
	return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
?>
