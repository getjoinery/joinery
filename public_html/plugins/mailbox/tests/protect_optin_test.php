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

/**
 * A real InboundEmailDomain with the two facts under test overridden.
 *
 * A subclass rather than a duck-typed stub on purpose: the methods being
 * exercised type-hint InboundEmailDomain, and loosening a production signature
 * so a test can pass something else would be the test damaging the code it is
 * meant to protect. Constructed with NULL — an unsaved row, no database.
 */
class PoFakeDomain extends InboundEmailDomain {
	private $po_protected;
	private $po_level;
	public function __construct(bool $protected, string $level) {
		parent::__construct(NULL);
		$this->po_protected = $protected;
		$this->po_level = $level;
		$this->set('ied_domain', 'example.com');
	}
	public function is_protected_identity() { return $this->po_protected; }
	public function security_level() { return $this->po_level; }
}

class ProtectOptinTest {

	public function run() {
		$this->assertShapeFollowsTheFlag();
		$this->assertFortressCompletionCard();
		$this->assertSigningStartsBeforeStrictRecords();
		$this->assertProviderCapabilityIsMeasured();
		$this->assertLiftingDoesNotStrandDns();
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

		// Belt and braces against the exact edit that caused it: reintroducing
		// `|| security_level() === LEVEL_FORTRESS` into the shape rule would pass
		// the checks above only if it also kept the flag — which is precisely what
		// the broken version did.
		//
		// Scoped to the shape rule, not the whole file: the level IS legitimately
		// consulted elsewhere, to decide whether to emit the send-protection row
		// at all. Which shape to prescribe and whether Fortress is finished are
		// different questions.
		$src = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/includes/InboundEmailSetupCheck.php'));
		$rule = substr($src, strpos($src, 'private function protectedShapeApplies'));
		$rule = substr($rule, 0, strpos($rule, "\n\t}"));
		check(strpos($rule, 'LEVEL_FORTRESS') === false && strpos($rule, 'security_level') === false,
			'the shape rule consults the enforcement flag and nothing else');
		check(preg_match('/is_protected_identity\(\)\s*\|\|\s*\$model->security_level\(\)/', $src) === 0,
			'the old level-or-flag disjunction is gone from the whole engine');
	}

	private function assertFortressCompletionCard() {
		section('Fortress is not finished until sending is locked');

		$m = new ReflectionMethod('InboundEmailSetupCheck', 'sendProtectionResult');
		$m->setAccessible(true);

		// Four states, four fixes. Driven by stubbing the two facts the row reads,
		// so no DNS is touched: is it signing, and do the published records already
		// demand that signature.
		$row = function (bool $signing, bool $strict) use ($m) {
			$check = new class($strict) extends InboundEmailSetupCheck {
				private $strict;
				public function __construct($strict) { parent::__construct(); $this->strict = $strict; }
				public function strictRecordsPublished(InboundEmailDomain $model): bool { return $this->strict; }
			};
			$mm = new ReflectionMethod($check, 'sendProtectionResult');
			$mm->setAccessible(true);
			return $mm->invoke($check, 'example.com', new PoFakeDomain($signing, 'fortress'));
		};

		$done = $row(true, true);
		check($done['status'] === InboundEmailSetupCheck::PASS,
			'signing with the strict records live is finished', $done['status']);

		$unfinished = $row(false, false);
		check($unfinished['status'] === InboundEmailSetupCheck::FAIL,
			'not signing is a REQUIRED failure — Fortress is not finished');
		check($unfinished['severity'] === InboundEmailSetupCheck::REQUIRED,
			'so it turns the mailbox verdict to attention');

		$half = $row(true, false);
		check($half['status'] === InboundEmailSetupCheck::WARN,
			'signing without the strict records warns: forgeries are not rejected yet');

		// THE OUTAGE. Records demanding a signature nothing is producing — mail
		// accepted by the provider and silently discarded by recipients. This is
		// the state the whole change exists to catch, and it must not read as one
		// more amber row.
		$broken = $row(false, true);
		check($broken['status'] === InboundEmailSetupCheck::FAIL,
			'strict records without signing is a failure');
		check(stripos($broken['summary'], 'rejecting your own mail') !== false,
			'and says plainly that the domain is rejecting its own mail', $broken['summary']);
		check($broken['summary'] !== $unfinished['summary'],
			'the outage and the merely-unfinished state do not share wording');

		// POLARITY. Whether the published records already demand a signature is
		// what separates "unfinished" from "actively rejecting your own mail", and
		// getting it backwards reports every stranded domain as merely unfinished
		// — which is what a first cut of this did, on a real domain that was
		// dropping its own mail at the time.
		$spf = new ReflectionMethod('InboundEmailSetupCheck', 'spfAuthorizesNothing');
		$spf->setAccessible(true);
		$c = new InboundEmailSetupCheck();
		foreach (array('v=spf1 -all', 'V=SPF1 -ALL', 'v=spf1   -all  ') as $rec) {
			check($spf->invoke($c, $rec) === true, 'authorizes nothing: ' . trim($rec));
		}
		foreach (array('v=spf1 include:mailgun.org -all', 'v=spf1 ip4:1.2.3.4 -all',
			'v=spf1 ~all', 'v=spf1 a mx -all', '') as $rec) {
			check($spf->invoke($c, $rec) === false,
				'may authorize somebody, so never alarms: ' . ($rec ?: '(empty)'));
		}
		$engine = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/includes/InboundEmailSetupCheck.php'));
		check(strpos($engine, '$spf_rejects = ($spf !== \'\' && $this->spfAuthorizesNothing($spf));') !== false,
			'the caller reads it un-negated');

		// The verdict consequence, asserted rather than assumed.
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_scope.php'));
		foreach (array($unfinished, $broken) as $r) {
			$verdict = mailbox_setup_verdict(array('receiving' => array($r), 'forwarding' => array()));
			check($verdict['status'] === 'attention',
				'an unfinished Fortress domain reads attention', $verdict['status']);
		}
	}

	private function assertSigningStartsBeforeStrictRecords() {
		section('No step of the ceremony causes silent rejection');

		// Gating activation on the finished shape forced the operator to publish
		// reject-anything-unsigned while nothing was signing — a guaranteed window
		// of mail accepted by the provider and discarded downstream. Signing now
		// starts first, needing only records that ask nothing of anyone.
		check(method_exists('InboundEmailSetupCheck', 'signingReadinessChecks'),
			'there is a readiness set distinct from the finished shape');

		$src = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/includes/protect_identity.php'));
		$activate = substr($src, strpos($src, "\$action === 'protect_activate'"));
		$activate = substr($activate, 0, strpos($activate, 'protect_rotate'));

		check(strpos($activate, 'signingReadinessChecks') !== false,
			'activation gates on signing readiness');
		check(strpos($activate, 'protectedDomainChecks') === false,
			'and no longer demands the strict records first');
		check(strpos($activate, 'VaultUnlock::isOpen') !== false,
			'the unlock gate is untouched — this still decides what the world accepts');

		// The readiness set must not contain the two records that do the rejecting.
		$readiness = substr($src, 0, 0);   // read from the engine, not this file
		$engine = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/includes/InboundEmailSetupCheck.php'));
		$fn = substr($engine, strpos($engine, 'public function signingReadinessChecks'));
		$fn = substr($fn, 0, strpos($fn, "\n\t}"));
		check(strpos($fn, 'protectedSpfResult') === false && strpos($fn, 'protectedDmarcResult') === false,
			'readiness excludes the strict SPF and DMARC — those are the last step, not the first');
		check(strpos($fn, 'protectedDkimResult') !== false,
			'and includes the sealed key record, which asks nobody to reject anything');

		// THE PAGE HAS TO AGREE WITH THE BUTTON. Guarding activation correctly
		// while the ceremony still rendered the finished shape produced the worst
		// of both: a red card telling the operator to strip the provider from SPF
		// and publish `v=spf1 -all` — by hand or by one click of the publish box —
		// while the sealed key was signing nothing. The button would have passed;
		// following the page would have taken the domain's mail down.
		check(method_exists('InboundEmailSetupCheck', 'signingReadinessPlan'),
			'there is a publish plan matching the readiness set, not just checks');

		$plan_fn = substr($engine, strpos($engine, 'private function signingStageRecords'));
		$plan_fn = substr($plan_fn, 0, strpos($plan_fn, "\n\t}"));
		check(strpos($plan_fn, 'v=spf1 -all') === false,
			'the signing-stage records carry no reject-everything SPF');
		check(strpos($plan_fn, 'p=reject') === false,
			'and no rejecting DMARC — nothing here asks anyone to reject anything');
		check(strpos($plan_fn, '_domainkey') !== false,
			'they do carry the sealed key record, which is what starts signing');
		check(substr_count($plan_fn, 'forwarding_subdomain') === 1,
			'and the forwarding subdomain, so bounces have somewhere to land');

		// The finished shape still has to contain everything the ceremony
		// published, or the next reconcile would revert step one's records.
		$engine_plan = substr($engine, strpos($engine, 'public function dnsPlan'));
		$engine_plan = substr($engine_plan, 0, strpos($engine_plan, "\n\t}"));
		check(strpos($engine_plan, 'signingStageRecords') !== false,
			'the finished shape is built from the same records, so the two cannot drift');
		check(strpos($engine_plan, 'v=spf1 -all') !== false && strpos($engine_plan, 'p=reject') !== false,
			'and it is still the shape that carries the rejection instruction');

		// The ceremony wiring itself.
		$setup_logic = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/logic/admin_mailbox_setup_logic.php'));
		check(strpos($setup_logic, '$protect_preflight = $checker->signingReadinessChecks(') !== false,
			'the ceremony shows the checks the button actually gates on');
		check(strpos($setup_logic, 'protectedDomainChecks($focus_domain_model)') === false,
			'and no longer shows the finished shape as though it blocked the press');
		check(strpos($setup_logic, '$checker->signingReadinessPlan($focus_domain)') !== false,
			'the ceremony publish box offers the signing-stage records');
		check(strpos($setup_logic, 'dnsPlan($focus_domain, true)') === false,
			'and never offers Apply on the strict shape before the key is signing');

		// APPLY RESOLVES THE PLAN AGAIN rather than trusting the POST, so the
		// handler and the box have to agree. A box showing three harmless records
		// over a handler that wrote the rejection instruction would be a one-click
		// outage with nothing on screen naming it.
		$resolver = substr($setup_logic, strpos($setup_logic, 'function _setup_dns_plan_for_domain'));
		$resolver = substr($resolver, 0, strpos($resolver, "\n}"));
		check(strpos($resolver, 'signingReadinessPlan($name)') !== false,
			'the ceremony Apply handler resolves the same signing-stage plan the box showed');
		check(strpos($resolver, 'dnsPlan($name, $protected)') === false
				&& strpos($resolver, 'dnsPlan($name, true)') === false,
			'and can no longer resolve the strict shape for a domain that is not signing');

		// The heading has to say which step it is, or two publish boxes across
		// the ceremony read as the same offer repeated.
		$view = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/admin/admin_mailbox_setup.php'));
		check(strpos($view, 'Step 1 — publish the records that let your key sign') !== false,
			'the ceremony publish box says which step it is');

		// NOTHING TO CONFIGURE MEANS NO CONFIGURE BOX. The publish box does not
		// read live DNS until its button is pressed, so on a domain whose records
		// are already live it renders a promise above an empty space and the only
		// way to learn there is nothing to do is to press it. The readiness rows
		// beside it already answer that, so they decide whether it renders.
		check(strpos($setup_logic, '$protect_signing_ready = _setup_rows_all_pass($protect_preflight)') !== false,
			'the ceremony asks whether step one has any work left');
		check(strpos($setup_logic, "if (!\$protect_signing_ready) {\n\t\t\t\$protect_dns_box = DnsPublishBox::build(") !== false,
			'and builds the publish box only when it does');
		check(strpos($view, 'Step 1 is already done.') !== false,
			'a step with nothing to do says so rather than rendering an empty gap');

		// UNKNOWN is not PASS: a row whose lookup failed is an open question, and
		// counting it as settled would hide the control that could close it.
		$all_pass = substr($setup_logic, strpos($setup_logic, 'function _setup_rows_all_pass'));
		$all_pass = substr($all_pass, 0, strpos($all_pass, "\n}"));
		check(strpos($all_pass, 'InboundEmailSetupCheck::PASS') !== false
				&& strpos($all_pass, '!==') !== false,
			'readiness is measured against PASS itself, so UNKNOWN keeps the box on screen');
	}

	private function assertProviderCapabilityIsMeasured() {
		section('No relay provider authorized means no provider CAN send, not no include');

		// THE HOLE THIS CLOSES. A protected domain publishes `v=spf1 -all`, so SPF
		// authorizes nobody and fails for everybody — and DMARC passes on DKIM
		// alignment alone. Inspecting only the SPF include therefore measured the
		// harmless half: an operator who removed the include got a green row while
		// the provider's DKIM key stayed published and its API key kept sending
		// mail that aligned strictly and passed DMARC. The row asserted the exact
		// capability it had stopped looking for.
		$m = new ReflectionMethod('InboundEmailSetupCheck', 'providerVerificationResult');
		check($m->getNumberOfParameters() === 4,
			'the check is given the domain model, so it knows which key is legitimately ours',
			'got ' . $m->getNumberOfParameters() . ' parameters');

		check(method_exists('InboundEmailSetupCheck', 'foreignDkimSigner'),
			'a published key that is not ours is something the engine can find');

		$engine = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/includes/InboundEmailSetupCheck.php'));
		$fn = substr($engine, strpos($engine, 'private function foreignDkimSigner'));
		$fn = substr($fn, 0, strpos($fn, "\n\t}"));

		check(strpos($fn, 'ied_dkim_selector') !== false
				&& strpos($fn, 'ied_dkim_pending_selector') !== false,
			'the sealed key and its staged rotation are excluded — those belong there');
		check(strpos($fn, 'providerDkimStatus') !== false,
			'the configured provider is asked which records it issues, rather than guessed at');
		check(strpos($fn, "'registered'") !== false,
			'and only a domain the provider still holds counts');

		// A revoked key (empty p=) is not a capability, and neither is a name that
		// does not answer. Both would be false alarms on a domain that is fine.
		$res = substr($engine, strpos($engine, 'private function dkimSelectorResolves'));
		$res = substr($res, 0, strpos($res, "\n\t}"));
		check(strpos($res, 'p\s*=\s*[A-Za-z0-9+\/]') !== false,
			'a key is identified by a non-empty public key, so a revoked one reads as gone');
		check(strpos($res, 'getCname') !== false,
			'a delegated selector counts too — handing the key out is still handing out the capability');
		check(strpos($res, 'if (!$txtOk) {') !== false && strpos($res, 'return false;') !== false,
			'a failed lookup is not evidence of a key');

		// The fix has to name the record to delete. "De-verify at your provider"
		// is not something an operator can check they have done.
		$check_fn = substr($engine, strpos($engine, 'private function providerVerificationResult'));
		$check_fn = substr($check_fn, 0, strpos($check_fn, "\n\t}"));
		check(strpos($check_fn, "'dns_record' => array('type' => 'TXT'") !== false,
			'the fix names the exact record to delete');
		check(strpos($check_fn, 'foreignDkimSigner') !== false
				&& strpos($check_fn, 'foreignDkimSigner') < strpos($check_fn, 'provider_hosts'),
			'and the DKIM half is reported first, being the half that still passes DMARC');

		// The PASS wording must claim what was actually established.
		check(strpos($check_fn, 'no signing key ') !== false,
			'a passing row says no key but yours is published, not merely that SPF is clean');
	}

	private function assertLiftingDoesNotStrandDns() {
		section('Lifting protection does not strand the DNS');

		$src = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/includes/protect_identity.php'));
		$disable = substr($src, strpos($src, "\$action === 'protect_disable'"));

		check(function_exists('mailbox_protect_restore_ambient_dns'),
			'lifting computes what the DNS must become');
		check(strpos($disable, 'mailbox_protect_restore_ambient_dns') !== false,
			'and the disable path calls it');
		check(strpos($disable, "'dns_show' => '1'") !== false,
			'landing the operator on the records that would otherwise reject their mail');

		// The confirm has to state the consequence, not just ask.
		$view = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/admin/admin_mailbox_setup.php'));
		$confirm = substr($view, strpos($view, "'action' => 'protect_disable'"));
		$confirm = substr($confirm, 0, 1400);
		check(stripos($confirm, 'anyone who breaks into it') !== false,
			'the confirm names who else gains the ability to send');
		check(stripos($confirm, 'send nothing') !== false || stripos($confirm, 'have to come down') !== false,
			'and warns that the records must come down too');
		check(stripos($confirm, 'arriving mail stays sealed') !== false,
			'and says what is NOT affected, so nobody is scared off a change they are entitled to make');
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
		section('The guided box carries the finishing step, and only that');

		$view = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/admin/admin_mailbox_setup.php'));

		// SCOPED BY STRUCTURE, NOT BY POSITION IN THE FILE. An earlier version of
		// this split the source at the Advanced heading and asserted the ceremony
		// markup fell below it — which broke the moment the panel was made to
		// render at the top of the page while it is open, even though the rule it
		// was protecting had not changed at all.
		$guided = substr($view, strpos($view, '$steps = array();'));
		$guided = substr($guided, 0, strpos($guided, 'if (!empty($steps))'));
		check($guided !== '' && $guided !== false, 'the guided box steps were located');

		check(strpos($guided, 'protect_activate') === false,
			'no turn-it-on button among the guided steps — the ceremony is its own panel');
		check(strpos($guided, 'protect_generate') === false,
			'no owner question among the guided steps');
		check(strpos($guided, 'prefill_domain') === false,
			'no Standard-subdomain offer among the guided steps');

		// Fortress is a two-sided promise and the guided box says so — with a link,
		// not a control. Gated on the arrival side working first.
		check(strpos($guided, 'Finish Fortress') !== false,
			'the guided box names Fortress as unfinished while sending is unlocked');
		check(strpos($guided, '$setup_url') !== false,
			'and links to the ceremony rather than reimplementing it');
		check(preg_match('/!\$is_protected\s*&&\s*\$active_relay !== null\s*&&\s*_setup_domain_mx_is_cut_over/', $guided) === 1,
			'rendering only when unprotected, with a live relay, and the MX cut over');
		check(strpos($view, 'function _setup_domain_mx_is_cut_over') !== false,
			'the cutover test reads the domain.mx row the page already computed');

		// The whole ceremony lives in one closure, defined once.
		$panel = substr($view, strpos($view, '$render_sending_identity = function ()'));
		$panel = substr($panel, 0, strpos($panel, "\n};"));
		foreach (array('protect_activate', 'protect_rotate', 'protect_disable') as $act) {
			check(strpos($panel, $act) !== false, $act . ' lives in the sending-identity panel');
		}

		// OWNERSHIP IS NOT A SETUP STEP. Who the signing key belongs to is a
		// property of the domain, decided where the security level is. It was also
		// the one control that never asked: the old Start-over button posted no
		// owner, so it silently resealed the domain to whoever pressed it.
		check(strpos($view, 'protect_generate') === false,
			'the Setup tab hosts no key-ownership control at all');
		// The comment where it used to be still names it, deliberately — a future
		// reader should find out why it went rather than reinvent it. So assert on
		// the control, not the words.
		check(strpos($view, "action_button('Start over") === false,
			'and the button that reassigned the owner without asking is gone');
		$domains = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/admin/admin_mailbox_domains.php'));
		check(strpos($domains, "'value' => 'protect_generate'") !== false,
			'ownership is decided on the domain editor');
		check(strpos($domains, "'owner_user_id'") !== false,
			'and it always asks who, rather than defaulting to the person clicking');
		check(strpos($domains, 'The signing key belongs to') !== false,
			'the owner is stated — it was written by the raise and shown on no screen');
		$dlogic = (string)file_get_contents(PathHelper::getIncludePath(
			'plugins/mailbox/logic/admin_mailbox_domains_logic.php'));
		check(strpos($dlogic, 'mailbox_protect_handle_action') !== false,
			'the domain page routes the action to protect_identity.php rather than reimplementing it');
		check(strpos($panel, 'Your vault is locked') !== false,
			'the unlock gate is rendered before the press, not discovered by pressing');
		check(substr_count($view, '$render_sending_identity = function ()') === 1,
			'the panel is written once, not duplicated per position');

		// WHATEVER IS BEING WORKED ON GOES TO THE TOP. The panel renders in one of
		// two places and never both: at the top while the ceremony is open, under
		// Advanced otherwise. A reload that dumps the operator at the top of a long
		// page, with the thing they just pressed still buried, is the jank this
		// exists to remove.
		check(substr_count($view, '$render_sending_identity();') === 2,
			'the panel is called from exactly two places');
		check(strpos($view, "if (\$setup_open) {\n\t\$render_sending_identity();") !== false,
			'rendered at the top of the page while the ceremony is open');
		check(strpos($view, "if (!\$setup_open) {\n\t\t\$render_sending_identity();") !== false,
			'and under Advanced when it is not');

		// ONE PUBLISH BOX AT A TIME. The ceremony renders its own, for the
		// signing-stage records; the page's own renders the shape for whatever
		// state the domain is actually in. Both on screen meant two panels with
		// the same heading showing contradictory records — the failure the
		// provider-supplied table was removed for, reintroduced by another route.
		check(substr_count($view, 'if (!$setup_open && ') >= 2,
			'the page suppresses its own publish box while the ceremony is open');
		check(strpos($view, "'Step 1 — publish the records that let your key sign'") !== false,
			'and the ceremony box names its step rather than repeating a generic heading');
		$box = (string)file_get_contents(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
		check(strpos($box, 'string $title = \'\'') !== false,
			'the renderer takes a heading override so two boxes can never read alike');

		// Opening the ceremony must not also expand Advanced — that would bury the
		// task under every server-wide box the operator did not ask about.
		check(strpos($view, "'protect_setup=1';") !== false
			&& strpos($view, "'advanced=1&protect_setup=1';") === false,
			'opening the ceremony does not turn Advanced on as well');
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
