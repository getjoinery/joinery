<?php
/** @joinery-test
 * name: sandbox_email
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Mail from a sandboxed plugin (spec sandboxed_plugin_email): the plugin's
 * code sends with EmailMessage and EmailSender as native code does, and the
 * site sends it with the same classes.
 *
 * What is pinned here, unfenced through the same PHP command line and, where
 * this box has the plugin's user, in the real fence:
 *  - send(): To, Cc, Bcc, subject, HTML and text, a header, an attachment by
 *    path (its own file, arriving as bytes) and one by data reach the site's
 *    EmailSender as written, which fills in the site's From; it answers true
 *    and lastSendReport() comes back;
 *  - quickSend() with HTML, and EmailMessage::fromTemplate(), are filled from
 *    the site's templates (the fence has none) and the subject reads back in
 *    the plugin's code; a missing template throws EmailTemplateError;
 *  - sendBatch() answers success and its failed list;
 *  - a transport object is refused, saying why;
 *  - in the fence, attaching the site's config file by path fails: the
 *    plugin cannot read it, so it cannot mail it;
 *  - each send is a line in the call log (door email);
 *  - a plugin not approved to send mail is refused, saying so, and a new
 *    version that asks for it waits on approval; the approval screen says
 *    it, and warns beside personal records;
 *  - the manifest takes only true or false;
 *  - on the site, outside the fence, EmailSender::$remote is null.
 *
 * Mail is a dry run here (email_dry_run, this process only): nothing leaves.
 *
 * Run: php tests/run.php db --filter=sandbox_email
 *
 * @version 1.1 - reviewer2 P1-P8: header rules, a text footer may not loop, dates, the type of a file attached by path,
 *                mail loaded on first use (however EmailSender was loaded, P10), From after send, addresses in the
 *                log, attachment fields held to one line (P9)
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();

/** The plugin's source. */
function sbx_em_files(): array {
	$plugin_php = <<<'PHP'
<?php
function sbx_try(callable $fn) {
	try {
		return array('ok' => $fn());
	} catch (Throwable $e) {
		return array('threw' => get_class($e), 'message' => $e->getMessage());
	}
}

function on_ui(array $input) {
	$v = $input['values'] ?? array();
	$o = array();
	// Nothing of mail is loaded until the plugin's code names it (P6).
	$o['lazy'] = class_exists('EmailSender', false) || class_exists('SandboxRemoteMail', false);
	// Loaded by a require of its path, as much native code still does: no
	// autoload runs, and the send still goes to the site (P10).
	$o['by_path'] = sbx_try(function () use ($v) {
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		return (new EmailSender())->send(EmailMessage::create($v['to'], 'Sandbox by path ' . $v['tag'], 'Sent after a require'));
	});
	$o['send'] = sbx_try(function () use ($v) {
		$m = EmailMessage::create($v['to'], 'Sandbox send ' . $v['tag'], '<p>Hello <b>there</b></p>');
		$m->to($v['to2'], 'Second Person')->cc($v['cc'])->bcc($v['bcc'])->header('X-Sbx-Test', $v['tag']);
		$m->attach(__DIR__ . '/assets/note.txt', 'note.txt');
		$m->attachData("\x00\x01binary", 'raw.bin', 'application/octet-stream');
		$m->attach(__DIR__ . '/assets/report.pdf');
		$sender = new EmailSender();
		$sent = $sender->send($m);
		return array('sent' => $sent, 'report' => $sender->lastSendReport(), 'from_after' => $m->getFrom());
	});
	$o['quick'] = sbx_try(function () use ($v) {
		return EmailSender::quickSend($v['to'], 'Sandbox quick ' . $v['tag'], '<p>Quick body ' . $v['tag'] . '</p>');
	});
	$o['template'] = sbx_try(function () use ($v) {
		$m = EmailMessage::fromTemplate('blank_template', array('subject' => 'Templated ' . $v['tag'], 'body' => '<p>Templated body</p>'));
		$subject = $m->getSubject();
		$m->to($v['to']);
		return array('subject' => $subject, 'sent' => (new EmailSender())->send($m));
	});
	$o['missing'] = sbx_try(function () {
		return EmailMessage::fromTemplate('sbx_no_such_template_here', array());
	});
	$o['header_from'] = sbx_try(function () use ($v) {
		return EmailMessage::create($v['to'], 'x', 'x')->header('From', 'ceo@other.example');
	});
	$o['header_crlf'] = sbx_try(function () use ($v) {
		return EmailMessage::create($v['to'], 'x', 'x')->header('X-Note', "1\r\nBcc: z@example.com");
	});
	$o['footer_loop'] = sbx_try(function () {
		return EmailMessage::fromTemplate('blank_template', array('subject' => 'x', 'body' => 'x', 'items' => array(1, 2)),
			array('body' => '{loop items as i}*i*{end}'));
	});
	$o['dated'] = sbx_try(function () {
		$when = DateTime::createFromFormat('Y-m-d H:i:s', '2026-03-04 05:06:07', new DateTimeZone('UTC'));
		$m = EmailMessage::fromTemplate('blank_template', array('subject' => 'Dated', 'body' => '<p>b</p>', 'when' => $when),
			array('body' => '<p>On *when|Y-m-d*</p>'));
		return $m->getHtmlBody();
	});
	$o['batch'] = sbx_try(function () use ($v) {
		$m = EmailMessage::create($v['to'], 'Sandbox batch ' . $v['tag'], 'Plain batch body');
		return (new EmailSender())->sendBatch($m, array($v['to'], $v['to2'], $v['cc']));
	});
	$o['transport'] = sbx_try(function () use ($v) {
		$m = EmailMessage::create($v['to'], 'Sandbox transport', 'x');
		$t = new SbxTestTransport();
		return (new EmailSender())->send($m, true, $t);
	});
	if (!empty($v['config'])) {
		$o['config'] = sbx_try(function () use ($v) {
			$m = EmailMessage::create($v['to'], 'Sandbox config', 'x');
			$m->attach($v['config'], 'site.php');
			return (new EmailSender())->send($m);
		});
	}
	return $o;
}
PHP;
	$transport = <<<'PHP'
<?php
class SbxTestTransport implements EmailServiceProvider {
	public static function getKey(): string { return 'sbxtest'; }
	public static function getLabel(): string { return 'Test'; }
	public static function getSpfMechanism(string $domain): string { return ''; }
	public static function validateConfiguration(): array { return array(); }
	public function send(EmailMessage $message): bool { return true; }
	public function sendBatch(EmailMessage $message, array $recipients): array { return array(); }
	public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array { return array(); }
	public static function getDkimStatus(string $domain): array { return array(); }
	public static function createSendingDomain(string $domain): array { return array(); }
	public static function apiKeyPattern(): string { return ''; }
	public function lastSendReceipt(): ?array { return null; }
	public function deliveryEvents(string $message_id_header, string $from_domain): ?array { return null; }
}
PHP;
	return array('plugin.php' => $plugin_php, 'includes/SbxTestTransport.php' => $transport, 'assets/note.txt' => "A note from the plugin's own folder.\n",
		'assets/report.pdf' => "%PDF-1.4\n%sandbox\n");
}

/** Check one run's answer and what the site saw. */
function sbx_em_checks(array $result, array $seen, string $label, array $values, bool $fenced): void {
	check(($result['status'] ?? '') === 'ok', $label . ': the call answers ok', json_encode(array($result['status'] ?? null, $result['message'] ?? null)));
	$o = is_array($result['output'] ?? null) ? $result['output'] : array();
	$settings = Globalvars::get_instance();

	$send = $o['send']['ok'] ?? null;
	check(is_array($send) && $send['sent'] === true, $label . ': send() answers true', json_encode($o['send'] ?? null));
	check(is_array($send['report'] ?? null) && array_key_exists('transport', $send['report']), $label . ': lastSendReport() comes back', json_encode($send['report'] ?? null));
	$m = null;
	foreach ($seen as $message) {
		if ($message->getSubject() === 'Sandbox send ' . $values['tag']) {
			$m = $message;
		}
	}
	check($m instanceof EmailMessage, $label . ': the site sends the message the plugin wrote');
	if ($m instanceof EmailMessage) {
		check(array_column($m->getRecipients(), 'email') === array($values['to'], $values['to2']), $label . ': its To', json_encode($m->getRecipients()));
		check(($m->getRecipients()[1]['name'] ?? null) === 'Second Person', $label . ': a recipient\'s name');
		check(array_column($m->getCc(), 'email') === array($values['cc']) && array_column($m->getBcc(), 'email') === array($values['bcc']), $label . ': its Cc and Bcc');
		check(strpos((string)$m->getHtmlBody(), '<b>there</b>') !== false && trim((string)$m->getTextBody()) === 'Hello there', $label . ': its HTML and text', json_encode(array($m->getHtmlBody(), $m->getTextBody())));
		check(($m->getHeaders()['X-Sbx-Test'] ?? null) === $values['tag'], $label . ': its header');
		$files = array();
		foreach ($m->getAttachments() as $a) {
			$files[$a['name']] = $a;
		}
		check(($files['note.txt']['data'] ?? null) === "A note from the plugin's own folder.\n" && !isset($files['note.txt']['path']),
			$label . ': a file attached by path arrives as its bytes', json_encode(array_keys($files['note.txt'] ?? array())));
		check(($files['raw.bin']['data'] ?? null) === "\x00\x01binary", $label . ': attached bytes arrive unchanged');
		check($m->getFrom() === $settings->get_setting('defaultemail'), $label . ': the site fills in its own From', (string)$m->getFrom());
		check(($files['report.pdf']['type'] ?? null) === 'application/pdf', $label . ': a file attached by path keeps its type, from its name', json_encode($files['report.pdf']['type'] ?? null));
	}
	check(($send['from_after'] ?? null) === $settings->get_setting('defaultemail'), $label . ': after send() the plugin\'s message holds the From the site filled in', json_encode($send['from_after'] ?? null));
	check(($o['lazy'] ?? null) === false, $label . ': nothing of mail is loaded until the plugin names it');
	$by_path = false;
	foreach ($seen as $message) {
		$by_path = $by_path || $message->getSubject() === 'Sandbox by path ' . $values['tag'];
	}
	check(($o['by_path']['ok'] ?? null) === true && $by_path, $label . ': EmailSender loaded by a require of its path still sends through the site', json_encode($o['by_path'] ?? null));
	check(($o['header_from']['threw'] ?? null) === 'InvalidArgumentException', $label . ': a From header is refused', json_encode($o['header_from'] ?? null));
	check(($o['header_crlf']['threw'] ?? null) === 'InvalidArgumentException', $label . ': a header value with a line break is refused', json_encode($o['header_crlf'] ?? null));
	check(strpos((string)($o['footer_loop']['message'] ?? ''), 'may not hold a loop') !== false, $label . ': a footer given as text may not loop', json_encode($o['footer_loop'] ?? null));
	check(strpos((string)($o['dated']['ok'] ?? ''), 'On 2026-03-04') !== false, $label . ': a date among the values is formatted as on the site', json_encode($o['dated'] ?? null));

	$quick = null;
	foreach ($seen as $message) {
		if ($message->getSubject() === 'Sandbox quick ' . $values['tag']) {
			$quick = $message;
		}
	}
	check(($o['quick']['ok'] ?? null) === true, $label . ': quickSend() answers true', json_encode($o['quick'] ?? null));
	check($quick instanceof EmailMessage && strpos((string)$quick->getHtmlBody(), 'Quick body ' . $values['tag']) !== false
		&& strlen((string)$quick->getHtmlBody()) > strlen('<p>Quick body ' . $values['tag'] . '</p>') + 50,
		$label . ': quickSend()\'s body is in the site\'s outer template', $quick ? substr((string)$quick->getHtmlBody(), 0, 200) : 'not seen');

	check(($o['template']['ok']['subject'] ?? null) === 'Templated ' . $values['tag'], $label . ': fromTemplate() is filled on the site; its subject reads back', json_encode($o['template'] ?? null));
	check(($o['template']['ok']['sent'] ?? null) === true, $label . ': and it sends');
	check(($o['missing']['threw'] ?? null) === 'Exception' && strpos((string)($o['missing']['message'] ?? ''), 'sbx_no_such_template_here') !== false,
		$label . ': a missing template throws as on the site', json_encode($o['missing'] ?? null));

	check(($o['batch']['ok']['success'] ?? null) === true && ($o['batch']['ok']['failed_recipients'] ?? null) === array(),
		$label . ': sendBatch() answers success and its failed list', json_encode($o['batch'] ?? null));
	check(strpos((string)($o['transport']['message'] ?? ''), 'cannot pass a transport') !== false, $label . ': a transport is refused, saying why', json_encode($o['transport'] ?? null));

	if ($fenced) {
		check(isset($o['config']['threw']) && strpos((string)$o['config']['message'], 'could not be read') !== false
			|| strpos((string)($o['config']['message'] ?? ''), 'not found') !== false,
			$label . ': the site\'s config file cannot be attached: the plugin cannot read it', json_encode($o['config'] ?? null));
		$leaked = false;
		foreach ($seen as $message) {
			$leaked = $leaked || $message->getSubject() === 'Sandbox config';
		}
		check(!$leaked, $label . ': and nothing was sent with it');
	}

	$doors = array();
	foreach ($result['calls'] ?? array() as $c) {
		if ($c['door'] === 'email') {
			$doors[] = $c['target'] . ' ' . $c['status'];
		}
	}
	check(in_array('send ok', $doors, true) && in_array('batch ok', $doors, true) && in_array('render blank_template ok', $doors, true),
		$label . ': each send and fill is a line in the call log', implode('; ', $doors));
	$named = false;
	foreach ($result['calls'] ?? array() as $c) {
		$named = $named || ($c['door'] === 'email' && $c['target'] === 'send' && strpos((string)$c['detail'], 'to ' . $values['to'] . ', ' . $values['to2'] . ', ' . $values['cc'] . ' and 1 more') === 0);
	}
	check($named, $label . ': the log line names the first three addresses and how many more');
}

try {
	section('On the site');
	check(EmailSender::$remote === null, 'EmailSender::$remote is null outside the fence');

	section('EmailMessage, for every caller');
	$refused = function (string $name, $value) {
		try {
			(new EmailMessage())->header($name, $value);
			return false;
		} catch (InvalidArgumentException $e) {
			return true;
		}
	};
	check($refused('From', 'a@example.com') && $refused('bcc', 'a@example.com') && $refused('Content-Type', 'text/plain'), 'A header the message\'s own fields write is refused');
	check($refused('X-Note', "a\r\nBcc: b@example.com") && $refused('X Note', 'a'), 'A value with a line break, or a name that is not a token, is refused');
	check(!$refused('X-Priority', '1') && !$refused('In-Reply-To', '<a@b>') && !$refused('Content-Language', 'en'), 'The headers core sets are allowed');
	check((new EmailMessage())->subject("One\r\nBcc: b@example.com")->getSubject() === 'One Bcc: b@example.com', 'A subject is kept to one line');
	$parts = EmailMessage::fromTransfer(array('attachments' => array(
		array('data' => base64_encode('x'), 'name' => "a\r\nb.png", 'type' => "image/png\r\nX-Evil: 1", 'cid' => "c\r\nX: 1", 'inline' => true),
		array('data' => base64_encode('y'), 'name' => 'i.ics', 'type' => 'text/calendar; method=REQUEST'))))->getAttachments();
	check($parts[0]['name'] === 'a b.png' && $parts[0]['cid'] === 'c X: 1' && $parts[0]['type'] === 'application/octet-stream',
		'An attachment\'s name and Content-ID keep to one line; a type that is not type/subtype is octet-stream', json_encode($parts[0]['type']));
	check($parts[1]['type'] === 'text/calendar; method=REQUEST', 'A type keeps its parameters');
	check(in_array('Invalid Cc or Bcc email: not an address', EmailMessage::create('a@example.com', 's', 'b')->cc('not an address')->validate(), true), 'A Cc that is not an address fails validation');

	section('The manifest');
	$base = array('name' => 'hts_mfx', 'title' => 'X', 'version' => '1.0.0', 'platform' => 1, 'id' => '0123456789abcdef0123456789abcdef');
	check(SandboxManifest::problems($base + array('send_email' => true)) === array(), 'send_email true is valid');
	check(in_array('send_email must be true or false', SandboxManifest::problems($base + array('send_email' => 'yes')), true), 'send_email "yes" is refused');

	harness_set_setting_mem('email_dry_run', '1');
	$admin = make_user('SbxMailViewer', 10);
	$tag = bin2hex(random_bytes(3));
	$values = array('tag' => $tag, 'to' => harness_fixture_email('sbxmail-to'), 'to2' => harness_fixture_email('sbxmail-to2'),
		'cc' => harness_fixture_email('sbxmail-cc'), 'bcc' => harness_fixture_email('sbxmail-bcc'));
	$manifest = array('send_email' => true);
	$seen = array();
	SandboxRelay::$mail_seen = function (EmailSender $sender, EmailMessage $message) use (&$seen) {
		$seen[] = $message;
	};
	harness_defer(function () { SandboxRelay::$mail_seen = null; SandboxRunner::$command_override = null; });

	$run = function (string $name, string $label, bool $fenced, array $values) use ($admin, &$seen) {
		$pm = PluginManager::getInstance();
		$pm->install($name, false);
		sandbox_approve($name);
		$pm->activate($name);
		SandboxPlugins::reset();
		if (!$fenced) {
			SandboxRunner::$command_override = function (array $envelope) {
				$cmd = SandboxRunner::command($envelope);
				return array_slice($cmd, array_search('--', $cmd, true) + 1);
			};
		}
		$seen = array();
		$result = SandboxHost::ui($name, 'go', $values, $admin);
		SandboxRunner::$command_override = null;
		sbx_em_checks($result, $seen, $label, $values, $fenced);
	};

	section('Unfenced, the same PHP command line');
	$name = 'hts_' . bin2hex(random_bytes(3));
	sandbox_fixture_plugin($manifest, array('name' => $name, 'files' => sbx_em_files()));
	$run($name, 'Unfenced', false, $values);

	section('Approval');
	$grants = SandboxConsent::grantsOf(SandboxPlugins::manifest($name));
	check($grants['email'] === array('send'), 'Sending mail is a grant');
	$words = SandboxConsent::describe($grants);
	check(isset($words['Send email']), 'The approval screen says it sends email', json_encode(array_keys($words)));
	$warned = SandboxConsent::describe(array('records' => array('User' => array('read')), 'email' => array('send')));
	check(strpos(implode(' ', $warned['Warning'] ?? array()), 'send email to any address') !== false, 'Beside personal records, it warns that mail is a way out', json_encode($warned['Warning'] ?? null));

	section('Not approved');
	$plain = 'hts_' . bin2hex(random_bytes(3));
	$fixture = sandbox_fixture_plugin(array(), array('name' => $plain, 'files' => sbx_em_files()));
	$pm = PluginManager::getInstance();
	$pm->install($plain, false);
	sandbox_approve($plain);
	$pm->activate($plain);
	SandboxPlugins::reset();
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	$seen = array();
	$result = SandboxHost::ui($plain, 'go', $values, $admin);
	SandboxRunner::$command_override = null;
	$o = $result['output'] ?? array();
	check(strpos((string)($o['send']['message'] ?? ''), 'not approved to send email') !== false, 'A plugin not approved for mail is refused, saying so', json_encode($o['send'] ?? null));
	check($seen === array(), 'and the site sends nothing for it');
	// A new version asking for mail waits on approval, and until then has none.
	$data = json_decode((string)file_get_contents($fixture['dir'] . '/manifest.json'), true);
	$data['version'] = '1.0.1';
	$data['send_email'] = true;
	file_put_contents($fixture['dir'] . '/manifest.json', json_encode($data));
	SandboxPlugins::reset();
	check(SandboxConsent::needsApproval($plain), 'A new version asking to send mail waits on approval');
	check((SandboxConsent::pending($plain)['email'] ?? null) === array('send'), 'and names it as what is new');
	check(SandboxConsent::effective($plain)['email'] === array(), 'and may not send until then');

	section('In the fence');
	$fence = 'sbxmail';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced mail layer', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence, 'files' => sbx_em_files(), 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced mail layer', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$run($fence, 'Fenced', true, $values + array('config' => PathHelper::getRootDir() . '/../config/Globalvars_site.php'));
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
