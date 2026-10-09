<?php
/** @joinery-test
 * name: spam_filtering
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The spam verdict and the classifier's pure parts (spam_learning_in_core.md).
 *
 * Everything here runs without a database:
 *   - classifySpam() is pure: every fact it reads is handed in, so each row of
 *     the verdict order is asserted directly — filing off, the auth rule beating
 *     everything, SCANNER_FLOOR beating a contact and a reply but only for a
 *     score rspamd produced, the relationships and their DMARC requirement, a
 *     single spam teaching cancelling contact / correspondent / rescued, sender
 *     history, Bayes at 0.99 / 0.01, the scanner signal last;
 *   - SpamBayes: the tokenizer (words, pairs up to 4 apart, URL hosts, sender,
 *     meta tokens, the 2,000 cap), hashing with an explicit key, chi-square
 *     combining against fixed counts, the 50/50 voting gate;
 *   - the meta tokens per scanner source, so scales never mix;
 *   - readSpamHeader / resolveContentSpam: the arriving signal, with its source;
 *   - the plain-words reason text the timeline and the Spam view share;
 *   - what the plugin manifest declares.
 *
 * The database half (relationships looked up for real, teaching, the sealed
 * window, the upgrade) is spam_learning_test (test-db).
 *
 * Run: php plugins/mailbox/tests/spam_filtering_test.php
 *
 * @version 2.0 - the verdict order of spam learning in core; the ingest re-scan tests are gone
 * @version 1.5
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

class SpamFilteringTest {

	private function eq($expected, $actual, $label) {
		return check($expected === $actual, $label, 'expected ' . var_export($expected, true)
			. ', got ' . var_export($actual, true));
	}

	private function classify(array $facts): array {
		$m = new ReflectionMethod('InboundEmailRouter', 'classifySpam');
		return $m->invoke(new InboundEmailRouter(), $facts);
	}

	/** classifySpam with a clean baseline: DMARC pass, no scanner signal, nothing known. */
	private function verdict(array $over = array()): string {
		$facts = $over + array(
			'auth'    => $this->auth('pass', 'pass', 'pass'),
			'scanner' => array('signal' => 'none', 'score' => null, 'source' => null),
			'reply'   => false,
			'contact' => false,
			'record'  => null,
			'bayes'   => array('state' => 'untrained', 'p' => null),
		);
		$r = $this->classify($facts);
		return (string)$r['verdict'] . '/' . (string)$r['reason'];
	}

	private function readSpamHeader(string $raw): array {
		$m = new ReflectionMethod('InboundEmailRouter', 'readSpamHeader');
		return $m->invoke(new InboundEmailRouter(), $raw);
	}

	private function resolveContentSpam(string $raw, $provider_spam = null): array {
		$m = new ReflectionMethod('InboundEmailRouter', 'resolveContentSpam');
		return $m->invoke(new InboundEmailRouter(), $raw, $provider_spam);
	}

	private function auth($spf, $dkim, $dmarc, $source = 'milter'): array {
		return array('spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc, 'source' => $source);
	}

	private function rspamd(float $score, string $signal = 'none'): array {
		return array('signal' => $signal, 'score' => $score, 'source' => 'rspamd');
	}

	function run() {
		harness_set_setting_mem('mailbox_spam_filtering_enabled', '1');

		section('filing off');
		harness_set_setting_mem('mailbox_spam_filtering_enabled', '0');
		$this->eq('/', $this->verdict(array('auth' => $this->auth('fail', 'fail', 'fail'))),
			'filing off → no verdict, whatever failed');
		harness_set_setting_mem('mailbox_spam_filtering_enabled', '1');

		section('step 1: the auth rule beats everything');
		$fail = $this->auth('pass', 'pass', 'fail');
		$this->eq('spam/auth', $this->verdict(array('auth' => $fail, 'reply' => true, 'contact' => true,
			'record' => array('sent' => 3, 'spam' => 0, 'ham' => 5),
			'bayes' => array('state' => 'scored', 'p' => 0.0))),
			'DMARC fail → spam despite a reply, a contact, a correspondent and Bayes ham');
		$this->eq('spam/auth', $this->verdict(array('auth' => $this->auth('fail', 'fail', 'none'))),
			'no DMARC + SPF and DKIM both fail → spam');
		$this->eq('ham/none', $this->verdict(array('auth' => $this->auth('fail', 'pass', 'none'))),
			'no DMARC + one failing → not the auth rule');

		section('step 1: a DMARC fail under p=none is a signal, not the auth rule');
		$monitored = $this->auth('pass', 'pass', 'fail') + array('dmarc_policy' => 'none');
		$this->eq('ham/none', $this->verdict(array('auth' => $monitored, 'scanner' => $this->rspamd(2.19))),
			'a p=none fail with a low rspamd score (Gil Duran, 2.19) → ham');
		$this->eq('spam/auth', $this->verdict(array('auth' => $this->auth('pass', 'pass', 'fail') + array('dmarc_policy' => 'reject'))),
			'p=reject fail → still the auth rule');
		$this->eq('spam/auth', $this->verdict(array('auth' => $this->auth('pass', 'pass', 'fail') + array('dmarc_policy' => 'quarantine'))),
			'p=quarantine fail → still the auth rule');
		$this->eq('spam/auth', $this->verdict(array('auth' => $this->auth('pass', 'pass', 'fail'))),
			'a fail with the policy unknown → still the auth rule (fail-safe)');
		$this->eq('spam/scanner', $this->verdict(array('auth' => $monitored, 'scanner' => $this->rspamd(15.0))),
			'a p=none fail is not spam by itself, but rspamd\'s floor still files it');
		check(InboundEmailMessage::authRuleSaysSpam(array('dmarc' => 'fail', 'dmarc_policy' => 'none')) === false,
			'authRuleSaysSpam: fail under p=none → false');
		check(InboundEmailMessage::authRuleSaysSpam(array('dmarc' => 'fail', 'dmarc_policy' => 'NONE ')) === false,
			'authRuleSaysSpam: the policy compares case- and space-insensitively');
		check(InboundEmailMessage::authRuleSaysSpam(array('dmarc' => 'fail')) === true,
			'authRuleSaysSpam: fail with no policy → true');
		check(InboundEmailMessage::authRuleSaysSpam(array('dmarc' => 'fail', 'spf' => 'fail', 'dkim' => 'fail', 'dmarc_policy' => 'none')) === false,
			'authRuleSaysSpam: p=none does not fall through to the SPF+DKIM fallback either');
		check(InboundEmailMessage::authRuleSaysSpam(array('dmarc' => 'none', 'spf' => 'fail', 'dkim' => 'fail')) === true,
			'authRuleSaysSpam: no DMARC verdict, SPF and DKIM both fail → still true');

		section('step 2: SCANNER_FLOOR beats relationships, for rspamd scores only');
		$this->eq('spam/scanner', $this->verdict(array('scanner' => $this->rspamd(15.0), 'contact' => true)),
			'an rspamd score at the floor beats a contact');
		$this->eq('spam/scanner', $this->verdict(array('scanner' => $this->rspamd(22.5), 'reply' => true)),
			'and a reply');
		$this->eq('ham/contact', $this->verdict(array('scanner' => $this->rspamd(14.9, 'spam'), 'contact' => true)),
			'just under the floor, the contact wins');
		$this->eq('ham/contact', $this->verdict(array(
			'scanner' => array('signal' => 'spam', 'score' => 99.0, 'source' => 'mailgun'), 'contact' => true)),
			'a webhook provider\'s score never triggers the floor');

		section('step 3: relationships');
		$this->eq('ham/reply', $this->verdict(array('reply' => true, 'scanner' => $this->rspamd(9, 'spam'))),
			'a reply to the user\'s own mail is ham over a scanner spam flag');
		$this->eq('ham/reply', $this->verdict(array('reply' => true, 'auth' => $this->auth('none', 'none', 'none'))),
			'reply needs no DMARC pass');
		$this->eq('ham/contact', $this->verdict(array('contact' => true)), 'a contact with DMARC pass → ham');
		$this->eq('ham/none', $this->verdict(array('contact' => true, 'auth' => $this->auth('pass', 'pass', 'none'))),
			'a contact without DMARC pass is not trusted');
		$this->eq('ham/correspondent', $this->verdict(array('record' => array('sent' => 1, 'spam' => 0, 'ham' => 0))),
			'someone the user wrote to → ham');
		$this->eq('ham/rescued', $this->verdict(array('record' => array('sent' => 0, 'spam' => 0, 'ham' => 1))),
			'a sender the user rescued before → ham');
		$this->eq('ham/none', $this->verdict(array('contact' => true, 'record' => array('sent' => 2, 'spam' => 1, 'ham' => 3))),
			'one spam teaching cancels contact, correspondent and rescued');
		$this->eq('spam/scanner', $this->verdict(array('contact' => true, 'scanner' => $this->rspamd(8, 'spam'),
			'record' => array('sent' => 0, 'spam' => 1, 'ham' => 0))),
			'so the scanner decides that contact\'s mail');

		section('step 4: sender history');
		$this->eq('spam/sender_history', $this->verdict(array('record' => array('sent' => 0, 'spam' => 2, 'ham' => 0))),
			'taught spam twice, never ham → spam');
		$this->eq('spam/sender_history', $this->verdict(array('auth' => $this->auth('none', 'none', 'none'),
			'record' => array('sent' => 0, 'spam' => 3, 'ham' => 0))),
			'needs no DMARC: the spam direction cannot be borrowed');
		$this->eq('ham/none', $this->verdict(array('record' => array('sent' => 0, 'spam' => 2, 'ham' => 1))),
			'one ham teaching keeps it from deciding');
		$this->eq('ham/none', $this->verdict(array('record' => array('sent' => 0, 'spam' => 1, 'ham' => 0))),
			'once is not enough');

		section('steps 5-6: Bayes, only when voting, only past the thresholds');
		$this->eq('spam/bayes', $this->verdict(array('bayes' => array('state' => 'scored', 'p' => 0.995))), 'p ≥ 0.99 → spam');
		$this->eq('ham/bayes', $this->verdict(array('bayes' => array('state' => 'scored', 'p' => 0.005),
			'scanner' => $this->rspamd(7, 'spam'))), 'p ≤ 0.01 → ham, over a scanner flag');
		$this->eq('spam/scanner', $this->verdict(array('bayes' => array('state' => 'scored', 'p' => 0.6),
			'scanner' => $this->rspamd(7, 'spam'))), 'undecided → the scanner decides');
		$this->eq('spam/scanner', $this->verdict(array('bayes' => array('state' => 'untrained', 'p' => null),
			'scanner' => $this->rspamd(7, 'spam'))), 'untrained → the scanner decides');
		$this->eq('ham/none', $this->verdict(array('bayes' => array('state' => 'off', 'p' => null))),
			'learning off → nothing to say');

		section('steps 7-8: the scanner signal, else ham');
		$this->eq('spam/scanner', $this->verdict(array(
			'scanner' => array('signal' => 'spam', 'score' => 3.1, 'source' => 'sendgrid'))),
			'a webhook provider\'s flag still counts at step 7');
		$this->eq('ham/none', $this->verdict(), 'nothing said anything → ham');

		section('SpamBayes tokenizer');
		$words = SpamBayes::words('Hello, WORLD! a x' . str_repeat('y', 41) . ' Ünïcode 2026');
		$this->eq(array('hello', 'world', 'ünïcode', '2026'), $words, 'words: lowercased, 2-40 characters, unicode kept');
		$tokens = SpamBayes::tokens(array(
			'subject' => 'Cheap watches', 'body_plain' => 'one two three four five six',
			'body_html' => '<a href="https://Shop.Example.com/x">x</a>',
			'sender' => '"Watch Guy" <guy@spam.example>', 'meta' => array('first_contact', 'dmarc:pass'),
		));
		foreach (array('meta:first_contact', 'meta:dmarc:pass', 'from_domain:spam.example', 'from_name:watch guy',
				'url:shop.example.com', 's:cheap', 's:cheap watches', 'w:one', 'w:one two', 'w:one five') as $t) {
			check(in_array($t, $tokens, true), 'token ' . $t);
		}
		check(!in_array('w:one six', $tokens, true), 'no pair more than 4 apart');
		$many = SpamBayes::tokens(array('body_plain' => implode(' ', array_map(function ($i) { return 'w' . $i; }, range(1, 5000)))));
		$this->eq(SpamBayes::MAX_TOKENS, count($many), 'capped at the first 2,000 distinct tokens');
		$key = str_repeat("\x01", 32);
		$h1 = SpamBayes::hashes(array('w:one', 'w:two', 'w:one'), $key);
		$this->eq(2, count($h1), 'hashes are distinct');
		check($h1 === array_values(array_unique($h1)) && $h1[0] <= $h1[1], 'and ascending (the lock order)');
		check(!in_array(0, $h1, true), 'never 0 (the totals row)');
		check(SpamBayes::hashes(array('w:one'), $key) !== SpamBayes::hashes(array('w:one'), str_repeat("\x02", 32)),
			'keyed: another deployment\'s key gives other hashes');
		$this->eq(1, SpamBayes::TOKENIZER_VERSION, 'the tokenizer version is stamped on what it teaches');
		// A body past the 64 KB cut, with a multibyte character straddling it: the
		// cut must not leave invalid UTF-8, or the unicode split drops every
		// non-Latin word of the message.
		$prefix = 'Привет ';
		$long = $prefix . str_repeat('a', SpamBayes::MAX_TEXT_BYTES - 1 - strlen($prefix)) . 'é мир';
		check(!mb_check_encoding(substr($long, 0, SpamBayes::MAX_TEXT_BYTES), 'UTF-8'), 'the fixture really splits a character at the cut');
		$t = SpamBayes::tokens(array('body_plain' => $long));
		check(in_array('w:привет', $t, true), 'a cut through a multibyte character keeps the non-Latin words');

		section('SpamBayes scoring');
		$spammy = array_fill(0, 20, array(40, 1));
		$hammy  = array_fill(0, 20, array(1, 40));
		check(SpamBayes::score($spammy, 50, 50) > 0.99, 'tokens seen in spam → near 1',
			(string)SpamBayes::score($spammy, 50, 50));
		check(SpamBayes::score($hammy, 50, 50) < 0.01, 'tokens seen in ham → near 0',
			(string)SpamBayes::score($hammy, 50, 50));
		$this->eq(0.5, SpamBayes::score(array(array(5, 5), array(10, 10)), 50, 50), 'neutral tokens → 0.5');
		$this->eq(0.5, SpamBayes::score(array(), 50, 50), 'nothing known → 0.5');
		$mixed = SpamBayes::score(array_merge(array_fill(0, 10, array(40, 1)), array_fill(0, 10, array(1, 40))), 50, 50);
		check($mixed > 0.01 && $mixed < 0.99, 'evidence both ways → undecided', (string)$mixed);
		$long = SpamBayes::score(array_fill(0, 400, array(500, 0)), 500, 500);
		check($long > 0.99 && is_finite($long), 'many decisive tokens never underflow', (string)$long);
		check(abs(SpamBayes::chi2Q(2.0, 2) - exp(-1.0)) < 1e-12, 'chi2Q(2, 2) = e^-1');

		section('the 50/50 gate');
		check(!SpamBayes::trained(array('spam' => 49, 'ham' => 500)), '49 spam: not voting');
		check(!SpamBayes::trained(array('spam' => 500, 'ham' => 49)), '49 ham: not voting');
		check(SpamBayes::trained(array('spam' => 50, 'ham' => 50)), '50 and 50: voting');

		section('meta tokens per scanner source');
		$router = new InboundEmailRouter();
		$meta = $router->spamMetaTokens(7, $this->auth('pass', 'fail', 'none'), $this->rspamd(7.3), null);
		foreach (array('first_contact', 'dmarc:none', 'spf:pass', 'dkim:fail', 'scanner:rspamd:6') as $t) {
			check(in_array($t, $meta, true), 'meta ' . $t, json_encode($meta));
		}
		check(!in_array('catch_all', $meta, true), 'a mailbox → not catch_all');
		$meta = $router->spamMetaTokens(7, $this->auth('pass', 'pass', 'fail') + array('dmarc_policy' => 'none'), $this->rspamd(2.19), null);
		check(in_array('dmarc_monitored_fail', $meta, true) && in_array('dmarc:fail', $meta, true),
			'a p=none DMARC fail is tagged dmarc_monitored_fail, and still counts as dmarc:fail');
		foreach (array(array('dmarc' => 'fail', 'dmarc_policy' => 'reject'), array('dmarc' => 'fail'),
				array('dmarc' => 'pass', 'dmarc_policy' => 'none')) as $auth) {
			$auth += array('spf' => 'pass', 'dkim' => 'pass');
			check(!in_array('dmarc_monitored_fail', $router->spamMetaTokens(7, $auth,
					array('signal' => 'none', 'score' => null, 'source' => null), null), true),
				'no dmarc_monitored_fail for ' . json_encode($auth));
		}
		$meta = $router->spamMetaTokens(0, $this->auth('pass', 'pass', 'pass'),
			array('signal' => 'spam', 'score' => 7.3, 'source' => 'mailgun'), array('messages' => 4));
		check(in_array('scanner:mailgun:6', $meta, true) && !in_array('scanner:rspamd:6', $meta, true),
			'a provider\'s score is tagged with the provider, never mixed with rspamd\'s');
		check(in_array('catch_all', $meta, true) && !in_array('first_contact', $meta, true),
			'catch_all without a mailbox; a known sender is not a first contact');

		section('the stored meta form');
		$all = array('first_contact', 'catch_all', 'burst', 'dmarc:temperror', 'spf:permerror', 'dkim:unverified', 'scanner:sendgrid:-20');
		$enc = SpamMeta::encode($all, 'undecided');
		check(strlen($enc) <= 64, 'the longest meta value stays under the sealed-egress limit of 64', $enc);
		$this->eq(array('tokens' => $all, 'bayes' => 'undecided'), SpamMeta::decode($enc), 'it decodes to exactly the tokens encoded');
		$canon = $router->spamMetaTokens(1, array('dmarc' => 'Weird', 'spf' => '', 'dkim' => 'PASS'),
			array('signal' => 'none', 'score' => 3.0, 'source' => 'postmark'), array('messages' => 2));
		$this->eq(array('dmarc:other', 'spf:unverified', 'dkim:pass', 'scanner:other:2'), $canon,
			'odd values are canonical at ingest, so teaching reads back the same tokens');
		$this->eq($canon, SpamMeta::decode(SpamMeta::encode($canon, 'off'))['tokens'], 'and round-trip');
		$monitored_meta = array('dmarc:fail', 'dmarc_monitored_fail', 'spf:pass');
		$this->eq(array('tokens' => $monitored_meta, 'bayes' => 'spam'),
			SpamMeta::decode(SpamMeta::encode($monitored_meta, 'spam')),
			'the monitored-fail flag round-trips through the stored form');

		section('readSpamHeader');
		$r = $this->readSpamHeader("From: a@b.com\nX-Spam: Yes\nX-Spam-Status: Yes, score=7.31 required=6.00\n\nbody");
		$this->eq(array('signal' => 'spam', 'score' => 7.31, 'source' => 'rspamd'), $r, 'flagged: spam, score, source rspamd');
		$r = $this->readSpamHeader("From: a@b.com\nX-Spam-Status: No, score=1.20 required=6.00\n\nbody");
		$this->eq(array('signal' => 'none', 'score' => 1.2, 'source' => 'rspamd'), $r,
			'unflagged: the score is still read (the floor and the meta token need it)');
		$r = $this->readSpamHeader("From: a@b.com\nX-Spam-Flag: YES\nX-Spam-Score: 9.0\n\nbody");
		$this->eq(9.0, $r['score'], 'a bare X-Spam-Score is preferred');
		$r = $this->readSpamHeader("From: a@b.com\nSubject: hi\n\nbody with X-Spam: Yes in the text");
		$this->eq(array('signal' => 'none', 'score' => null, 'source' => null), $r, 'a body mention is not a header');

		section('resolveContentSpam');
		$prov = $this->resolveContentSpam('', array('result' => 'spam', 'score' => 4.2, 'source' => 'mailgun'));
		$this->eq(array('signal' => 'spam', 'score' => 4.2, 'source' => 'mailgun'), $prov, 'a provider flag names its source');
		$this->eq('rspamd', $this->resolveContentSpam("X-Spam: Yes\n\nb")['source'], 'a header is rspamd\'s');
		check(!method_exists('InboundEmailRouter', 'scanContentSpam') && !method_exists('InboundEmailRouter', 'interpretScanResponse'),
			'nothing in the router talks to a scanner');

		section('the reason, in plain words');
		$this->eq('Not spam: a reply to mail you sent', InboundEmailMessage::spamReasonText('reply', 'ham'), 'reply');
		$this->eq('Spam: you marked this sender as spam at least twice',
			InboundEmailMessage::spamReasonText('sender_history', 'spam'), 'sender history');
		$this->eq('Spam: learned from your corrections', InboundEmailMessage::spamReasonText('bayes', 'spam'), 'bayes');
		$this->eq('Spam: the scanner\'s score was very high', InboundEmailMessage::spamReasonText('scanner', 'spam', 18),
			'the floor');
		$this->eq('Spam: the scanner flagged it (the filter was still learning and not voting yet)',
			InboundEmailMessage::spamReasonText('scanner', 'spam', 7, '|u'),
			'below step 6, the timeline says Bayes was not voting');
		$this->eq(null, InboundEmailMessage::spamReasonText(null, 'ham'), 'no reason recorded → no text');

		section('declared settings');
		$manifest = json_decode((string)file_get_contents(PathHelper::getAbsolutePath('plugins/mailbox/plugin.json')), true);
		$declared = array();
		foreach (($manifest['settings'] ?? array()) as $s) {
			$declared[(string)($s['name'] ?? '')] = $s;
		}
		$this->eq('1', $declared['mailbox_spam_filtering_enabled']['default'] ?? null, 'filing ships on');
		$this->eq('1', $declared['mailbox_spam_learning_enabled']['default'] ?? null, 'learning ships on');
		check(!isset($declared['mailbox_rspamd_controller_url']), 'the controller URL setting is gone');
		foreach (array('mailbox_sender_fingerprint_key', 'mailbox_spam_token_key') as $k) {
			check(!empty($declared[$k]['managed']) && !isset($declared[$k]['label']) && ($declared[$k]['type'] ?? '') !== 'secret',
				$k . ' is managed: machine-written, never on a form');
		}
		$prov = array();
		foreach (($manifest['provisioners'] ?? array()) as $p) {
			$prov[(string)($p['key'] ?? '')] = $p;
		}
		$this->eq('provisioning/provision_spam_scanner.sh', $prov['content_spam_scanner']['script'] ?? null,
			'the scanner health entry\'s fix is the scanner provisioner');
		check(stripos((string)($prov['content_spam_scanner']['details'] ?? ''), 'redis') === false,
			'and its text names rspamd only');
	}
}

(new SpamFilteringTest())->run();
harness_finish();
