<?php
/** @joinery-test
 * name: joinery_ai_private_ai_consent
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Where a member's Private content may be read by AI (PrivateContentConsent,
 * specs/implemented/private_content_cloud_floor.md):
 *   - the default is local, and the comparison reads as the catalog does;
 *   - a Private chat on a cloud model is refused at send, in words that name
 *     the way out, and nothing is persisted; a Standard chat is not refused;
 *   - raising the setting admits the model; a sealed-derived Standard chat is
 *     bound too;
 *   - the resolver's trust floor for a chat follows the setting;
 *   - loosening asks a second factor of a member who has one; tightening does not.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
$has_session = vault_ensure_session();
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));
require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('joinery_ai')) { harness_skip('joinery_ai plugin inactive'); harness_finish(); }
require_once(PathHelper::getIncludePath('includes/PrivateContentConsent.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatLevel.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/catalog/AiModelRequirementBuilder.php'));

$db = DbConnector::get_instance()->get_db_link();
$saved_session = array('usr_user_id' => $_SESSION['usr_user_id'] ?? null, 'loggedin' => $_SESSION['loggedin'] ?? null,
	'permission' => $_SESSION['permission'] ?? null);
harness_defer(function () use ($saved_session) {
	foreach ($saved_session as $k => $v) { if ($v === null) { unset($_SESSION[$k]); } else { $_SESSION[$k] = $v; } }
});
function pac_as($user) {
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
	PrivateContentConsent::forget();
}
function pac_chat(int $owner_id, string $level, string $model): AiConversation {
	$c = new AiConversation(NULL);
	$c->set('aic_owner_user_id', $owner_id);
	$c->set('aic_security_level', $level);
	$c->set('aic_model', $model);
	$c->set('aic_title', 'Consent test');
	$c->save();
	$c->load();
	harness_register_row('aic_conversations', 'aic_conversation_id', (int)$c->key);
	return $c;
}
function pac_messages(AiConversation $c): int {
	return (int)DbConnector::get_instance()->get_db_link()
		->query('SELECT COUNT(*) FROM aim_conversation_messages WHERE aim_aic_conversation_id = ' . (int)$c->key)->fetchColumn();
}
function pac_save(string $consent): LogicResult {
	return harness_call_logic('logic/private_ai_consent_logic.php', 'private_ai_consent_logic',
		array('action' => 'save', 'consent' => $consent));
}

// A cloud model and a local model, by DECLARATION: every endpoint's served
// models whether or not the endpoint is configured here (a dev box holds no
// Anthropic key; the gate asks where a model would go, never whether it can).
$cloud_model = '';
$local_model = '';
foreach (AiEndpointRegistry::endpoints() as $key => $endpoint) {
	$trust = (string)($endpoint['trust'] ?? '');
	foreach (array_keys(AiEndpointRegistry::modelsFor((string)$key)) as $id) {
		if ($cloud_model === '' && $trust === 'cloud') $cloud_model = (string)$id;
		if ($local_model === '' && $trust === AiModelRequirement::TRUST_LOCAL) $local_model = (string)$id;
	}
}

$owner = make_user('PacOwner', 5);
$twofa = make_user('PacTwoFactor', 5);
$twofa->enable_totp('JBSWY3DPEHPK3PXP');
$twofa->save();
vault_fixture_server_vault((int)$owner->key);
vault_fixture_server_vault((int)$twofa->key);

// ---------------------------------------------------------------------------
section('The default and the comparison');

check(PrivateContentConsent::forUser((int)$owner->key) === PrivateContentConsent::LOCAL, 'a member starts at local');
check(PrivateContentConsent::allows('local', 'local') && !PrivateContentConsent::allows('local', 'trusted') && !PrivateContentConsent::allows('local', 'cloud'),
	'local admits only local endpoints');
check(PrivateContentConsent::allows('trusted', 'local') && PrivateContentConsent::allows('trusted', 'trusted') && !PrivateContentConsent::allows('trusted', 'cloud'),
	'trusted admits local and trusted');
check(PrivateContentConsent::allows('cloud', 'cloud') && !PrivateContentConsent::allows('local', null),
	'cloud admits everything; an endpoint nothing classifies counts as cloud');
check(PrivateContentConsent::trustFloor('local') === 'local' && PrivateContentConsent::trustFloor('trusted') === 'trusted'
	&& PrivateContentConsent::trustFloor('cloud') === 'any', 'each answer is the matching trust floor');
check(PrivateContentConsent::normalize('nonsense') === 'local', 'an unknown stored value reads as local');

// ---------------------------------------------------------------------------
section('A Private chat on a cloud model is refused at send; a Standard chat is not');

if ($cloud_model === '') {
	harness_skip('send refusal', 'the catalog declares no cloud model');
} else {
	pac_as($owner);
	$private = pac_chat((int)$owner->key, AiConversation::LEVEL_PRIVATE, $cloud_model);
	$why = ChatLevel::privateContentRefusal($private, $cloud_model, (int)$owner->key);
	check($why !== null && stripos($why, 'cloud') !== false && stripos($why, 'Security') !== false && stripos($why, 'local model') !== false,
		'the refusal names the model\'s class, the setting and both ways out', (string)$why);
	$before = pac_messages($private);
	$r = harness_call_logic('plugins/joinery_ai/logic/chat_send_logic.php', 'chat_send_logic',
		array('conversation_id' => (int)$private->key, 'message' => 'What is on my calendar this week?'));
	check($r->error !== null && !empty($r->data['requires_consent']) && pac_messages($private) === $before,
		'chat_send refuses with requires_consent and persists nothing', (string)$r->error);

	$standard = pac_chat((int)$owner->key, AiConversation::LEVEL_STANDARD, $cloud_model);
	check(ChatLevel::privateContentRefusal($standard, $cloud_model, (int)$owner->key) === null,
		'a Standard chat (which never opens sealed content) is not bound');
	AiConversation::updateColumns((int)$standard->key, array('aic_egress_restricted' => true));
	$standard->load();
	check(ChatLevel::privateContentRefusal($standard, $cloud_model, (int)$owner->key) !== null,
		'a Standard chat whose transcript became sealed-derived is bound');

	if ($local_model !== '') {
		check(ChatLevel::privateContentRefusal($private, $local_model, (int)$owner->key) === null,
			'the same Private chat on a local model is fine');
	}

	PrivateContentConsent::set((int)$owner->key, PrivateContentConsent::CLOUD);
	check(ChatLevel::privateContentRefusal($private, $cloud_model, (int)$owner->key) === null,
		'with the setting at cloud, the cloud model is admitted');
	PrivateContentConsent::set((int)$owner->key, PrivateContentConsent::TRUSTED);
	check(ChatLevel::privateContentRefusal($private, $cloud_model, (int)$owner->key) !== null,
		'at trusted, a cloud model is still refused');
	PrivateContentConsent::set((int)$owner->key, PrivateContentConsent::LOCAL);
}

// ---------------------------------------------------------------------------
section('The resolver\'s floor follows the setting');

$model_for_floor = $cloud_model !== '' ? $cloud_model : ($local_model !== '' ? $local_model : 'qwen3:4b-instruct');
$private = pac_chat((int)$owner->key, AiConversation::LEVEL_PRIVATE, $model_for_floor);
$standard = pac_chat((int)$owner->key, AiConversation::LEVEL_STANDARD, $model_for_floor);
PrivateContentConsent::set((int)$owner->key, PrivateContentConsent::LOCAL);
check(AiModelRequirementBuilder::forConversation($private)->trustFloor() === AiModelRequirement::TRUST_LOCAL,
	'a Private chat\'s requirement states the local floor');
check(AiModelRequirementBuilder::forConversation($standard)->trustFloor() === AiModelRequirement::TRUST_ANY,
	'a Standard chat\'s requirement states no floor');
PrivateContentConsent::set((int)$owner->key, PrivateContentConsent::TRUSTED);
check(AiModelRequirementBuilder::forConversation($private)->trustFloor() === AiModelRequirement::TRUST_TRUSTED,
	'at trusted, the floor is trusted');
PrivateContentConsent::set((int)$owner->key, PrivateContentConsent::CLOUD);
check(AiModelRequirementBuilder::forConversation($private)->trustFloor() === AiModelRequirement::TRUST_ANY,
	'at cloud, the floor is open');
if ($cloud_model !== '') {
	// The invariant: the resolver never hands a Private chat a model outside
	// the floor. An available cloud pin is refused outright ("pinned to …");
	// one this install cannot reach (no key) falls through to the requirement,
	// which may only choose within the floor.
	PrivateContentConsent::set((int)$owner->key, PrivateContentConsent::LOCAL);
	$verdict = '';
	try {
		$resolution = AiModelResolver::resolve(AiModelRequirementBuilder::forConversation($private));
		$verdict = (AiEndpointRegistry::trustForModel($resolution->modelId()) === AiModelRequirement::TRUST_LOCAL)
			? 'substituted a local model' : 'RESOLVED ONTO ' . $resolution->modelId();
	} catch (LlmProviderException $e) {
		$verdict = stripos($e->getMessage(), 'pinned') !== false ? 'refused the pin' : 'FAILED: ' . $e->getMessage();
	} catch (Throwable $e) {
		$verdict = 'FAILED: ' . $e->getMessage();
	}
	check(strpos($verdict, 'FAILED') === false && strpos($verdict, 'RESOLVED ONTO') === false,
		'the resolver never hands a Private chat a cloud model while its owner keeps private content local', $verdict);
}
PrivateContentConsent::set((int)$owner->key, PrivateContentConsent::LOCAL);

// ---------------------------------------------------------------------------
section('The setting: loosening asks a second factor; tightening does not');

pac_as($owner);
$r = pac_save('cloud');
check($r->error === null && PrivateContentConsent::forUser((int)$owner->key) === 'cloud',
	'a member without a second factor loosens freely', (string)$r->error);
$r = pac_save('nonsense');
check($r->error !== null, 'an unknown answer is refused');
$r = harness_call_logic('logic/private_ai_consent_logic.php', 'private_ai_consent_logic', array());
check(($r->data['consent'] ?? '') === 'cloud' && count($r->data['options'] ?? array()) === 3, 'the read answers the current value and the three choices');
$r = pac_save('local');
check($r->error === null && PrivateContentConsent::forUser((int)$owner->key) === 'local', 'and tightens back');

if (!$has_session || session_id() === '') {
	harness_skip('step-up', 'no session could be started on the CLI');
} else {
	$sid = session_id();
	$clear_markers = function () use ($sid) {
		DbConnector::get_instance()->get_db_link()
			->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?")->execute(array($sid));
	};
	harness_defer($clear_markers);
	$clear_markers();
	pac_as($twofa);
	$r = pac_save('trusted');
	check($r->error !== null && !empty($r->data['requires_stepup']) && PrivateContentConsent::forUser((int)$twofa->key) === 'local',
		'loosening without a recent confirmation: refused with requires_stepup, nothing changes', (string)$r->error);
	SessionControl::get_instance()->stamp_second_factor();
	$r = pac_save('trusted');
	check($r->error === null && PrivateContentConsent::forUser((int)$twofa->key) === 'trusted', 'confirmed: the loosening goes through', (string)$r->error);
	$clear_markers();
	$r = pac_save('local');
	check($r->error === null && PrivateContentConsent::forUser((int)$twofa->key) === 'local', 'tightening never asks');
}

harness_finish();
