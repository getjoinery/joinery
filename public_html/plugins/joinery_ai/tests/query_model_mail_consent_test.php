<?php
/** @joinery-test
 * name: query_model_mail_consent
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * An agent or chat read of mail keeps to each domain's AI consent
 * (InboundEmailMessage::ai_travel_filter(), ModelQueryExecutor).
 *
 * Two Standard domains, one whose mail stays on local models and one that
 * lets it reach any cloud, each with one message. Through query_model:
 *   - a local model reads both;
 *   - a cloud model reads only the cloud-consent domain's;
 *   - a trusted-vendor model reads only the cloud-consent domain's too
 *     (local consent is stricter than trusted), and a trusted-consent
 *     domain's when there is one;
 *   - a context the loop never told its endpoint reads as cloud;
 *   - AgentLoop tells the context its resolution's trust.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

class MailConsentReadContext implements ToolContext {
	private $trust;
	public function __construct(?string $trust) { $this->trust = $trust; }
	public function actingUserId(): int { return 0; }
	public function ownerTimezone(): string { return 'UTC'; }
	public function untrustedNonce(): string { return 'testnonce'; }
	public function endpointTrust(): string { return $this->trust ?? 'cloud'; }
	public function setEndpointTrust(string $trust): void { $this->trust = $trust; }
	public function allowedModels(): array { return array('InboundEmailMessage'); }
	public function allowedActions(): array { return array(); }
	public function queuesWrites(): bool { return false; }
	public function executesInline(string $tool_name): bool { return true; }
	public function writeProvenance(): string { return 'test'; }
	public function enqueueProposedAction(array $tool_use): array { throw new LogicException('no queue here'); }
	public function ownerScopedReads(?string $model_class = null): bool { return false; }
	public function sealedReadsAllowed(): bool { return false; }
	public function shouldContinue(): ?array { return null; }
	public function shouldAbort(): bool { return false; }
	public function beginToolCall(array $entry): void {}
	public function finishToolCall(array $entry): void {}
	public function appendToolCall(array $entry): void {}
	public function emitText(string $delta): void {}
	public function noteActivity(string $label): void {}
}

$tag = substr(bin2hex(random_bytes(4)), 0, 8);

function qmc_domain(string $consent, string $tag): int {
	$d = new InboundEmailDomain(NULL);
	$d->set('ied_domain', 'qmc-' . $consent . '-' . $tag . '.example');
	$d->set('ied_is_enabled', true);
	$d->set('ied_security_level', InboundEmailDomain::LEVEL_STANDARD);
	$d->set('ied_ai_processing_consent', $consent);
	$d->save();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$d->key);
	return (int)$d->key;
}

function qmc_message(int $domain_id, string $tag): int {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', $domain_id);
	$m->set('iem_message_id_header', '<qmc-' . $domain_id . '-' . $tag . '@example.test>');
	$m->set('iem_recipient', 'me@example.test');
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', 'sender@elsewhere.test');
	$m->set('iem_subject', 'Consent fixture ' . $tag);
	$m->set('iem_body_plain', 'Body text.');
	$m->set('iem_raw_storage_driver', 'remote');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->prepare();
	$m->save();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', (int)$m->key);
	return (int)$m->key;
}

/** The fixture domains whose message this context can read. */
function qmc_readable(?string $trust, array $domains): array {
	$seen = array();
	foreach ($domains as $consent => $domain_id) {
		$rows = ModelQueryExecutor::query('InboundEmailMessage',
			array('iem_ied_inbound_email_domain_id' => $domain_id), array(), 10,
			array('iem_inbound_email_message_id'), new MailConsentReadContext($trust));
		if (count($rows) > 0) $seen[] = $consent;
	}
	return $seen;
}

$domains = array();
foreach (array(InboundEmailDomain::CONSENT_LOCAL, InboundEmailDomain::CONSENT_TRUSTED, InboundEmailDomain::CONSENT_CLOUD) as $consent) {
	$domains[$consent] = qmc_domain($consent, $tag);
	qmc_message($domains[$consent], $tag);
}

section('Each endpoint reads only the mail its domains let travel that far');
check(qmc_readable('local', $domains) === array('local', 'trusted', 'cloud'), 'a local model reads every domain\'s mail',
	json_encode(qmc_readable('local', $domains)));
check(qmc_readable('trusted', $domains) === array('trusted', 'cloud'), 'a trusted-vendor model reads trusted and cloud consent only',
	json_encode(qmc_readable('trusted', $domains)));
check(qmc_readable('cloud', $domains) === array('cloud'), 'a cloud model reads cloud consent only',
	json_encode(qmc_readable('cloud', $domains)));
check(qmc_readable(null, $domains) === array('cloud'), 'a context never told its endpoint reads as cloud',
	json_encode(qmc_readable(null, $domains)));

section('A consent value nobody recognises reads as local');
$db = DbConnector::get_instance()->get_db_link();
$db->prepare('UPDATE ied_inbound_email_domains SET ied_ai_processing_consent = ? WHERE ied_inbound_email_domain_id = ?')
	->execute(array('everywhere', $domains['cloud']));
check(qmc_readable('cloud', $domains) === array(), 'an unreadable consent is not a permission',
	json_encode(qmc_readable('cloud', $domains)));

section('The loop tells its context the endpoint');
$src = file_get_contents(PathHelper::getIncludePath('plugins/joinery_ai/includes/AgentLoop.php'));
check(strpos($src, '$context->setEndpointTrust($resolution->trust());') !== false,
	'AgentLoop::run() sets the context\'s endpoint trust from its resolution');

harness_finish();
