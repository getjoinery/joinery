<?php
/** @joinery-test
 * name: protect_optin
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Send protection is an opt-in, and the DNS shape follows the opt-in
 * (specs/mailbox_relay_surface_simplification.md).
 *
 * The defect this suite exists to hold shut:
 *
 *   The protected DNS shape tells the world to REJECT anything the sealed key
 *   did not sign. MailboxDkimSigner only signs with that key once
 *   ied_is_protected_identity is set. Branching the shape on the security LEVEL
 *   instead therefore handed a Fortress domain that had not opted in a record
 *   set that rejects its own outgoing mail — and a Fortress domain resting with
 *   send protection off is a legitimate, finished configuration, so that state
 *   is common rather than exotic.
 *
 * Also asserted, because each was a surface that told the operator something
 * untrue:
 *
 *   - The guided box offers NO send-protection step. Its three steps were the
 *     owner question, the activate button and the Standard-subdomain offer; all
 *     three exist only because of send protection, and a numbered list means
 *     these are things you have not done yet.
 *   - Relay rows and cards reach only mailboxes whose domain needs a relay.
 *   - The receive-mode choice no longer blocks any mailbox page.
 *   - Destroying the on-disk signing key refuses an unregistered domain, and is
 *     never wired to run on its own.
 *
 * Run: php plugins/mailbox/tests/protect_optin_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));

/** Stands in for an InboundEmailDomain: the branching rule reads one method. */
class PoFakeDomain {
	private $protected;
	private $level;
	public function __construct(bool $protected, string $level) {
		$this->protected = $protected;
		$this->level = $level;
	}
	public function is_protected_identity() { return $this->protected; }
	public function security_level() { return $this->level; }
}

class ProtectOptinTest {

	public function run() {
		$this->assertShapeFollowsTheFlag();
		$this->assertCeremonyCanStillPrescribe();
		$this->assertOnDiskKeyRow();
		$this->assertDestroyRefusesUnknownDomain();
		$this->assertGuidedBoxHasNoProtectionStep();
		$this->assertNoReceiveModeGate();
		$this->assertHelperIsProvisioned();
		$this->assertVocabulary();
	}

	// ------------------------------------------------------------------
	// THE DEFECT
	// ------------------------------------------------------------------

	private function assertShapeFollowsTheFlag() {
		section('The protected shape follows the enforcement flag, not the level');

		$applies = new ReflectionMethod('InboundEmailSetupCheck', 'protectedShapeApplies');
		$applies->setAccessible(true);
		$check = new InboundEmailSetupCheck();

		check($applies->invoke($check, new PoFakeDomain(true, 'fortress')) === true,
			'a domain with send protection ON gets the protected shape');

		// The one that matters. Before this, the shape was prescribed for any
		// Fortress domain, so this returned true and the operator was told to
		// publish records that reject their own mail.
		check($applies->invoke($check, new PoFakeDomain(false, 'fortress')) === false,
			'a Fortress domain WITHOUT send protection gets the ordinary shape, not the inverted one');

		check($applies->invoke($check, new PoFakeDomain(false, 'private')) === false,
			'a Private domain gets the ordinary shape');
		check($applies->invoke($check, new PoFakeDomain(false, 'standard')) === false,
			'a Standard domain gets the ordinary shape');
		check($applies->invoke($check, null) === false,
			'an unregistered domain gets the ordinary shape rather than an error');
		// GetByDomain() returns FALSE, not null, for a domain this box does not
		// host — so this is the value production actually passes on a miss. A
		// !== null guard here would call a method on a boolean and fatal.
		check($applies->invoke($check, false) === false,
			'a GetByDomain miss (false, not null) is handled without fataling');

		// Belt and braces: the level must not appear in the rule at all. A future
		// edit that reintroduces `|| security_level() === LEVEL_FORTRESS` restores
		// the defect exactly, and would pass the four checks above only if it also
		// kept the flag — which is precisely what the broken version did.
		$src = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/includes/InboundEmailSetupCheck.php'));
		check(strpos($src, 'LEVEL_FORTRESS') === false,
			'the security level is not consulted anywhere in the check engine\'s shape branching');
	}

	private function assertCeremonyCanStillPrescribe() {
		section('The ceremony can still ask for the shape');

		// Publishing has to happen BEFORE activation — publish, verify, activate —
		// so the ceremony needs a way to ask for a shape the flag does not yet
		// justify. That is the only caller allowed to.
		$plan = new ReflectionMethod('InboundEmailSetupCheck', 'dnsPlan');
		$params = $plan->getParameters();
		check(count($params) === 2, 'dnsPlan takes the domain plus an explicit opt-in',
			'got ' . count($params) . ' parameters');
		check($params[1]->getName() === 'force_protected',
			'the second parameter names what it does');
		check($params[1]->isDefaultValueAvailable() && $params[1]->getDefaultValue() === false,
			'and it defaults to OFF, so every existing caller keeps the ordinary shape');

		// protectedDomainChecks() is the pre-flight the ceremony verifies against.
		// It must not consult the flag either, or it could never run before it.
		check(method_exists('InboundEmailSetupCheck', 'protectedDomainChecks'),
			'the pre-flight verification is still reachable independently of the flag');
	}

	// ------------------------------------------------------------------
	// The old on-disk key
	// ------------------------------------------------------------------

	private function assertOnDiskKeyRow() {
		section('The old on-disk signing key has a state');

		check(InboundEmailSetupCheck::localSigningKeyPath('Example.COM')
				=== '/etc/opendkim/keys/example.com/mail.txt',
			'the key path is derived from the domain, lowercased');
		check(InboundEmailSetupCheck::localSigningKeyHelper() === '/usr/local/sbin/joinery-dkim-remove',
			'the removal helper has one fixed, allowlisted path');

		$src = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/includes/InboundEmailSetupCheck.php'));

		// Emitted inside the protected branch only. Before send protection is on,
		// this key is what signs the domain's mail — reporting it as a problem
		// then would be advising the operator to break their own sending.
		check(strpos($src, '$out[] = $this->localSigningKeyResult($domain);') !== false,
			'the row is emitted from the protected branch of checkDomain');
		check(strpos($src, 'domain.local_signing_key') !== false,
			'the row has a stable id so it can be found and acted on');

		// It is a warning, not a failure: mail is correct either way. The risk is a
		// second usable signing path, not a broken one.
		check(preg_match('/localSigningKeyResult.*?self::RECOMMENDED, self::WARN/s', $src) === 1,
			'a surviving key is RECOMMENDED/WARN, not a REQUIRED failure');
	}

	private function assertDestroyRefusesUnknownDomain() {
		section('Destroying a key refuses what it cannot verify');

		$result = mailbox_destroy_local_signing_key('not-a-domain-on-this-box.invalid');
		check($result['ok'] === false, 'an unregistered domain is refused');
		check(stripos($result['message'], 'no such domain') !== false,
			'and the refusal says why', $result['message']);

		$result = mailbox_destroy_local_signing_key('');
		check($result['ok'] === false, 'an empty domain is refused');

		// NEVER AUTOMATIC. This deletes key material and cannot be undone from a
		// browser, so nothing may call it except the operator's own press.
		$callers = array();
		foreach (array('plugins/mailbox/includes', 'plugins/mailbox/logic', 'plugins/mailbox/admin',
			'plugins/mailbox/tasks') as $dir) {
			$path = PathHelper::getIncludePath($dir);
			if (!is_dir($path)) { continue; }
			foreach (glob($path . '/*.php') ?: array() as $file) {
				$body = (string)file_get_contents($file);
				if (strpos($body, 'mailbox_destroy_local_signing_key(') !== false
						&& basename($file) !== 'protect_identity.php') {
					$callers[] = basename($file);
				}
			}
		}
		check($callers === array('admin_mailbox_setup_logic.php'),
			'the only caller is the Setup tab action a human presses',
			'callers: ' . implode(', ', $callers));

		// And that caller gates it on the two facts that make it safe.
		$logic = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/logic/admin_mailbox_setup_logic.php'));
		check(strpos($logic, '!$model->is_protected_identity()') !== false,
			'the action refuses while send protection is off');
		check(strpos($logic, 'protectedDomainChecks($model)') !== false,
			'the action refuses until the protected DNS shape verifies');
	}

	// ------------------------------------------------------------------
	// The surfaces
	// ------------------------------------------------------------------

	private function assertGuidedBoxHasNoProtectionStep() {
		section('The guided box never mentions send protection');

		$view = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/admin/admin_mailbox_setup.php'));
		$guided = substr($view, 0, strpos($view, 'Advanced — server-wide setup & diagnostics'));
		check($guided !== '' && $guided !== false, 'the guided half of the page was located');

		check(strpos($guided, 'protect_activate') === false,
			'no turn-it-on button in the guided box');
		check(strpos($guided, 'protect_generate') === false,
			'no owner question in the guided box');
		check(strpos($guided, 'prefill_domain') === false,
			'no Standard-subdomain offer in the guided box — it exists only because of send protection');
		check(stripos($guided, 'send protection') === false
				|| stripos($guided, 'SEND PROTECTION IS NOT A STEP HERE') !== false,
			'send protection appears in the guided half only as the comment saying it must not');

		// The whole ceremony lives in one place instead.
		$advanced = substr($view, strpos($view, 'Advanced — server-wide setup & diagnostics'));
		foreach (array('protect_activate', 'protect_generate', 'protect_rotate', 'protect_disable') as $act) {
			check(strpos($advanced, $act) !== false, $act . ' lives under Advanced');
		}
		check(strpos($advanced, 'protect_setup') !== false,
			'the ceremony opens on an explicit gesture, not on the mere presence of a key');
		check(strpos($advanced, 'Your vault is locked') !== false,
			'the unlock gate is rendered before the press, not discovered by pressing');
	}

	private function assertNoReceiveModeGate() {
		section('The receive-mode choice does not gate the mailbox pages');

		foreach (array('alias', 'reader', 'accounts', 'domains', 'setup') as $page) {
			$src = (string)file_get_contents(PathHelper::getIncludePath(
				'plugins/mailbox/admin/admin_mailbox_' . $page . '.php'));
			check(strpos($src, "mailbox_receive_mode() === ''") === false,
				'admin_mailbox_' . $page . ' renders without demanding the receive mode first');
		}

		// The resolver itself is unchanged — three honest states, and '' means
		// undecided rather than broken.
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
		check(mailbox_receive_mode_resolve(false, '', false) === '',
			'an untouched deployment is still reported as undecided');
		check(mailbox_receive_mode_resolve(false, 'relay', false) === 'relay',
			'a stored choice still wins');
	}

	private function assertHelperIsProvisioned() {
		section('The removal helper is installed and allowlisted');

		$sh = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/provisioning/provision_relay_main.sh'));
		check(strpos($sh, 'DKIM_REMOVE_HELPER="/usr/local/sbin/joinery-dkim-remove"') !== false,
			'provision_relay_main.sh installs the helper');
		check(strpos($sh, 'NOPASSWD: ${DKIM_REMOVE_HELPER}') !== false,
			'and adds exactly it to the sudoers drop-in — not a blanket rule');
		check(strpos($sh, 'DKIM_REMOVED') !== false,
			'the helper emits a success marker the caller can demand');
		check(strpos($sh, 'refusing malformed domain') !== false,
			'the helper validates its own argument rather than trusting the caller');
	}

	private function assertVocabulary() {
		section('One word, one meaning');

		// "Relay" meant three unrelated things one row apart: the ingest VPS, the
		// outbound provider credential, and the smarthost setting. The middle one
		// is the sending route and has nothing to do with a relay.
		$src = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/includes/InboundEmailSetupCheck.php'));
		check(strpos($src, 'Outbound forwarding relay') === false,
			'the outbound provider check is no longer called a relay');
		check(preg_match('/\'plugin\.relay\'.*?\'Sending route\'/s', $src) === 1,
			'it is called the sending route');

		// "Protection" meant both arrival sealing and the sending identity. A
		// Fortress domain already has the first, so the bare word read as an
		// instruction to redo what was done.
		foreach (array('plugins/mailbox/includes/protect_identity.php',
			'plugins/mailbox/admin/admin_mailbox_domains.php') as $file) {
			$body = (string)file_get_contents(PathHelper::getIncludePath($file));
			check(preg_match('/(?<!Send )(?<!send )[Pp]rotection is (on|off|already on|not on)/', $body) === 0,
				basename($file) . ' always says WHICH protection');
		}

		// "Smarthost" is Postfix's word for the plumbing. It tells a reader what
		// the component is called, not what happens to their mail, so the stored
		// value keeps it and no reader-facing string may. The setting's own
		// options, helptexts and check descriptions live in plugin.json, which is
		// the file most likely to reintroduce it.
		$manifest = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/plugin.json'));
		$decoded = json_decode($manifest, true);
		check(is_array($decoded), 'plugin.json parses');

		$reader_text = array();
		foreach (($decoded['provisioners'] ?? array()) as $prov) {
			$reader_text[] = (string)($prov['label'] ?? '');
			$reader_text[] = (string)($prov['details'] ?? '');
		}
		foreach (($decoded['settings'] ?? array()) as $setting) {
			$reader_text[] = (string)($setting['label'] ?? '');
			$reader_text[] = (string)($setting['helptext'] ?? '');
			foreach ((array)($setting['options'] ?? array()) as $opt) {
				$reader_text[] = (string)$opt;
			}
		}
		$leaks = array_values(array_filter($reader_text, function ($t) {
			return stripos($t, 'smarthost') !== false;
		}));
		check($leaks === array(),
			'no reader-facing string in plugin.json says smarthost',
			implode(' | ', $leaks));

		// The stored value is untouched on purpose — renaming it would be a
		// settings migration for no reader benefit, exactly as with the setting key.
		$mode = null;
		foreach (($decoded['settings'] ?? array()) as $setting) {
			if (($setting['name'] ?? '') === 'mailbox_relay_outbound_mode') { $mode = $setting; }
		}
		check(is_array($mode) && isset($mode['options']['smarthost']),
			'the stored value stays smarthost — only the words shown changed');
		check(is_array($mode) && stripos((string)$mode['options']['smarthost'], 'relay') !== false,
			'and the reader is shown the relay instead');
	}
}

(new ProtectOptinTest())->run();
harness_finish();
?>
