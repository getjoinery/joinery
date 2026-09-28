#!/usr/bin/env php
<?php
/**
 * Dev walk fixtures (specs/dev_walk_fixtures.md): a test account for an
 * acceptance walk in the MCP browser, cheap to remake whenever the browser
 * (and with it the account's virtual passkey) is reset.
 *
 * Usage:
 *   php walk_fixture.php create <purpose> [--messages=N] [--permission=P]
 *   php walk_fixture.php deliver <purpose> <N> <tag>
 *   php walk_fixture.php retire <purpose>
 *   php walk_fixture.php status <purpose>
 *
 * Use the session's own name as the purpose (`create public-html-d7`): each
 * session has its own browser (the MCP runs --isolated) and its own fixture,
 * so no session retires another's.
 *
 * A fixture is the user claude-walk-<purpose>@example.com (permission 5 unless
 * --permission says otherwise), the domain claude-walk-<purpose>.example they
 * own, at Standard, and its store mailbox box@ granted to them alone. `create`
 * retires the purpose's previous fixture first, delivers N messages (default
 * 2, each with a PDF and an inline image), sets a fresh random password and
 * leaves it for the browser in a 0600 handoff file, WALK_HANDOFF, which
 * walk_vault.js reads (never printed; delete it once the browser step ran).
 * Every run deletes a handoff older than five minutes. `create` also copies
 * walk_vault.js to WALK_SCRIPT: the MCP browser loads script files only from
 * /tmp/playwright-mcp or the web root. It also retires every claude-walk
 * fixture older than PRUNE_DAYS: sessions come and go (a reboot starts all
 * new ones), so nothing else would.
 *
 * Refuses without the `debug` setting: it makes an admin account, so it never
 * runs on production.
 *
 * @version 1.1 - the handoff carries the email (walk_vault.js signs in at /login);
 *   create prunes fixtures older than PRUNE_DAYS
 * @version 1.0
 */

$bootstrap_path = __DIR__ . '/../../public_html/includes/PathHelper.php';
if (!file_exists($bootstrap_path)) {
	fwrite(STDERR, "ERROR: Cannot find PathHelper.php at: $bootstrap_path\n");
	exit(2);
}
require_once($bootstrap_path);
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

const WALK_HANDOFF = '/tmp/playwright-mcp/walk-handoff.json';
const WALK_HANDOFF_MAX_AGE = 300;
const WALK_SCRIPT = '/tmp/playwright-mcp/walk_vault.js';
const PRUNE_DAYS = 7;

function walk_fail(string $message): void {
	fwrite(STDERR, 'walk_fixture: ' . $message . "\n");
	exit(1);
}

function walk_names(string $purpose): array {
	if (!preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $purpose)) {
		walk_fail('a purpose is 1-31 lower-case letters, digits or hyphens: ' . $purpose);
	}
	return array('email' => 'claude-walk-' . $purpose . '@example.com', 'domain' => 'claude-walk-' . $purpose . '.example');
}

function walk_user(string $email): ?User {
	foreach (new MultiUser(array('usr_email' => $email)) as $u) {
		return $u;
	}
	return null;
}

function walk_domain(string $name): ?InboundEmailDomain {
	$domain = InboundEmailDomain::GetByDomain($name);
	return $domain ? $domain : null;
}

/** A message with a plain and an HTML body, an inline image and a PDF named after $word. */
function walk_raw(string $address, string $subject, string $word): array {
	$mid = '<walk-' . bin2hex(random_bytes(6)) . '@elsewhere.example>';
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
	$line = 'The ' . $word . ' figures for the quarter are attached. ';
	return array($mid, implode("\r\n", array(
		'From: "Walk Sender" <sender@elsewhere.example>', 'To: ' . $address, 'Subject: ' . $subject,
		'Message-ID: ' . $mid, 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000', 'MIME-Version: 1.0',
		'Content-Type: multipart/mixed; boundary="OUT"', '',
		'--OUT', 'Content-Type: multipart/related; boundary="REL"', '',
		'--REL', 'Content-Type: multipart/alternative; boundary="ALT"', '',
		'--ALT', 'Content-Type: text/plain; charset=UTF-8', '', str_repeat($line, 3),
		'--ALT', 'Content-Type: text/html; charset=UTF-8', '',
		'<p>' . str_repeat($line, 3) . '</p><img src="cid:logo123@elsewhere.example">', '--ALT--',
		'--REL', 'Content-Type: image/png; name="logo.png"', 'Content-ID: <logo123@elsewhere.example>',
		'Content-Disposition: inline; filename="logo.png"', 'Content-Transfer-Encoding: base64', '',
		chunk_split(base64_encode($png)), '--REL--',
		'--OUT', 'Content-Type: application/pdf; name="' . $word . '-report.pdf"',
		'Content-Disposition: attachment; filename="' . $word . '-report.pdf"', 'Content-Transfer-Encoding: base64', '',
		chunk_split(base64_encode('%PDF-1.4 ' . $word . ' report')), '--OUT--', '',
	)));
}

function walk_deliver(string $address, int $n, string $tag): void {
	$words = array('zebracorn', 'quokkafig', 'lemonade', 'pangolin', 'marmalade', 'axolotl');
	$db = DbConnector::get_instance()->get_db_link();
	for ($i = 0; $i < $n; $i++) {
		$word = $words[$i % count($words)] . $tag;
		list($mid, $raw) = walk_raw($address, ucfirst($tag) . ' message ' . ($i + 1) . ': ' . $word, $word);
		(new InboundEmailRouter())->processEmail($raw, $address);
		$q = $db->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_message_id_header = ?');
		$q->execute(array($mid));
		$id = intval($q->fetchColumn());
		echo $id > 0 ? "delivered message $id ($word)\n" : "NOT delivered: $word\n";
	}
}

/** Remove the purpose's fixture: the domain (its mailbox, grants and messages with it), then the user. */
function walk_retire(array $names): void {
	$domain = walk_domain($names['domain']);
	if ($domain) {
		$domain->permanent_delete();
		echo "retired domain {$names['domain']}\n";
	}
	$user = walk_user($names['email']);
	if ($user) {
		$id = intval($user->key);
		$user->permanent_delete();
		echo "retired user $id\n";
	}
}

/** Retire every claude-walk fixture made more than PRUNE_DAYS ago (its user's birth stamp). */
function walk_prune(): void {
	$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT usr_email FROM usr_users
		WHERE usr_email LIKE 'claude-walk-%@example.com' AND usr_terms_accepted_time < ?");
	$q->execute(array(gmdate('Y-m-d H:i:s', time() - PRUNE_DAYS * 86400)));
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $email) {
		if (preg_match('/^claude-walk-([a-z0-9-]+)@example\.com$/', $email, $m)) {
			try {
				walk_retire(walk_names($m[1]));
			} catch (\Throwable $e) {
				fwrite(STDERR, 'walk_fixture: could not prune ' . $email . ': ' . $e->getMessage() . "\n");
			}
		}
	}
}

function walk_expire_handoff(): void {
	if (is_file(WALK_HANDOFF) && filemtime(WALK_HANDOFF) < time() - WALK_HANDOFF_MAX_AGE) {
		@unlink(WALK_HANDOFF);
	}
}

function walk_write_handoff(User $user): void {
	$password = bin2hex(random_bytes(16));
	$user->set('usr_password', User::GeneratePassword($password));
	$user->save();
	if (!is_dir(dirname(WALK_HANDOFF))) {
		@mkdir(dirname(WALK_HANDOFF), 0700, true);
	}
	$old = umask(077);
	file_put_contents(WALK_HANDOFF, json_encode(array('user_id' => intval($user->key),
		'email' => (string)$user->get('usr_email'), 'password' => $password)));
	umask($old);
	chmod(WALK_HANDOFF, 0600);
}

// ------------------------------------------------------------------ main

if (!Globalvars::get_instance()->get_setting('debug')) {
	walk_fail('refused: the debug setting is off (this is not a dev site)');
}
walk_expire_handoff();

$args = array_values(array_filter(array_slice($argv, 1), function ($a) { return strpos($a, '--') !== 0; }));
$opts = array();
foreach (array_slice($argv, 1) as $a) {
	if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) {
		$opts[$m[1]] = $m[2];
	}
}
$command = $args[0] ?? '';
if ($command === '' || !isset($args[1])) {
	walk_fail('usage: create|deliver|retire|status <purpose> ... (see the file header)');
}
$names = walk_names($args[1]);
$address = 'box@' . $names['domain'];

switch ($command) {
	case 'create':
		walk_prune();
		walk_retire($names);
		$user = new User(NULL);
		$user->set('usr_first_name', 'Claude');
		$user->set('usr_last_name', 'Walk ' . $args[1]);
		$user->set('usr_email', $names['email']);
		$user->set('usr_password', User::GeneratePassword(bin2hex(random_bytes(24))));
		$user->set('usr_permission', intval($opts['permission'] ?? 5));
		$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
		$user->set('usr_is_activated', true);
		$user->set('usr_setup_dismissed_time', gmdate('Y-m-d H:i:s'));
		$user->save();
		$user->load();
		$uid = intval($user->key);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', $names['domain']);
		$domain->set('ied_is_enabled', true);
		$domain->set('ied_owner_usr_user_id', $uid);
		$domain->save();
		$domain = new InboundEmailDomain(intval($domain->key), TRUE);
		$domain->set_security_level(InboundEmailDomain::LEVEL_STANDARD);
		$domain->save();

		$alias = new InboundEmailAlias(NULL);
		$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
		$alias->set('iea_alias', 'box');
		$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$alias->set('iea_destinations', '');
		$alias->set('iea_is_enabled', true);
		$alias->save();
		InboundEmailMailboxGrant::sync_for_alias(intval($alias->key), array($uid));

		echo "user $uid ({$names['email']}), domain " . intval($domain->key) . " ({$names['domain']}), mailbox "
			. intval($alias->key) . " ($address)\n";
		walk_deliver($address, max(0, intval($opts['messages'] ?? 2)), 'standard');
		walk_write_handoff(new User($uid, TRUE));
		copy(__DIR__ . '/walk_vault.js', WALK_SCRIPT);
		echo "handoff written: " . WALK_HANDOFF . " (delete it after the browser step)\n";
		echo "browser step: browser_run_code_unsafe with filename " . WALK_SCRIPT . "\n";
		break;

	case 'deliver':
		if (!walk_domain($names['domain'])) {
			walk_fail('no fixture for ' . $args[1] . '; create it first');
		}
		walk_deliver($address, max(1, intval($args[2] ?? 1)), preg_replace('/[^a-z0-9]/', '', strtolower((string)($args[3] ?? 'more'))) ?: 'more');
		break;

	case 'retire':
		walk_retire($names);
		break;

	case 'status':
		$user = walk_user($names['email']);
		$domain = walk_domain($names['domain']);
		if (!$user && !$domain) {
			echo "no fixture for {$args[1]}\n";
			break;
		}
		echo 'user ' . ($user ? intval($user->key) : '-') . ', domain ' . ($domain ? intval($domain->key) . ' at ' . $domain->security_level() : '-') . "\n";
		if ($domain) {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare("SELECT m.iem_inbound_email_message_id AS id, SUBSTRING(COALESCE(m.iem_sealed_key, '(plain)') FROM 1 FOR 18) AS key_frame,
					(SELECT STRING_AGG(COALESCE(NULLIF(a.ima_filename, ''), '(nameless)'), ', ' ORDER BY a.ima_inbound_message_attachment_id)
					   FROM ima_inbound_message_attachments a WHERE a.ima_iem_inbound_email_message_id = m.iem_inbound_email_message_id) AS parts
				FROM iem_inbound_email_messages m WHERE m.iem_ied_inbound_email_domain_id = ? ORDER BY 1");
			$q->execute(array(intval($domain->key)));
			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
				echo "  message {$r['id']}: {$r['key_frame']}  parts: " . ($r['parts'] ?? '') . "\n";
			}
		}
		break;

	default:
		walk_fail('unknown command ' . $command);
}
exit(0);
