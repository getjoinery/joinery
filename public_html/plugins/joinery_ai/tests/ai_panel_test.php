<?php
/** @joinery-test
 * name: ai_panel
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Multi-mailbox recipe bindings + the area AI panel
 * (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md). Covers:
 *
 *  - Union candidate selection across a recipe's mailbox_aliases list, newest
 *    first, and the live re-resolution that drops a revoked grant with a
 *    coverage note instead of a silent gap.
 *  - validateConfig: per-address grant check, empty list legal.
 *  - The scheduling split: a sealed-only binding still refuses cron, a mixed
 *    binding is cron-runnable for its standard remainder.
 *  - AiPanelService: state shape (own cards + template cards), owner scoping,
 *    toggle round-trips including last-mailbox removal, the taint confirm
 *    handshake, and the dashboard-only kill switch.
 *  - Template instantiation: first toggle-ON creates the caller's own enabled
 *    instance (rcp_template_key, never rcp_declared_key) and the seeded row is
 *    never touched; the second toggle edits that same instance.
 *  - The two API actions resolve and answer through the real logic wrappers.
 *
 * Run: php tests/run.php db --only=plugins/joinery_ai/tests/ai_panel_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxAliasConfig.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/EmailJobCandidates.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiPanelService.php'));

function aip_domain(string $level, bool $ai_ok): InboundEmailDomain {
	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', 'aip-' . bin2hex(random_bytes(4)) . '.example');
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_security_level', $level);
	$dom->set('ied_ai_processing_enabled', $ai_ok);
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));
	return $dom;
}

function aip_alias(int $domain_id, string $local, int $holder_id): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	InboundEmailMailboxGrant::sync_for_alias($alias->key, array($holder_id));
	harness_defer(function () use ($alias) {
		InboundEmailMailboxGrant::sync_for_alias($alias->key, array());
	});
	return $alias;
}

function aip_message(int $domain_id, int $alias_id, string $subject, string $offset_minutes): int {
	$msg = new InboundEmailMessage(NULL);
	$msg->set('iem_ied_inbound_email_domain_id', $domain_id);
	$msg->set('iem_iea_inbound_email_alias_id', $alias_id);
	$msg->set('iem_direction', 'inbound');
	$msg->set('iem_sender', 'sender@elsewhere.example');
	$msg->set('iem_recipient', 'test@example.com');
	$msg->set('iem_subject', $subject);
	$msg->set('iem_body_plain', 'Body of ' . $subject);
	$msg->set('iem_message_id_header', 'aip-' . bin2hex(random_bytes(8)) . '@example.com');
	$msg->set('iem_received_time', gmdate('Y-m-d H:i:s', strtotime("$offset_minutes minutes")));
	$msg->save();
	$msg->load();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', intval($msg->key));
	return intval($msg->key);
}

function aip_recipe(int $owner_id, array $addresses, bool $enabled = true,
		bool $tainted_ok = true): Recipe {
	$recipe = new Recipe(NULL);
	$recipe->set('rcp_name', 'aip test ' . bin2hex(random_bytes(3)));
	$recipe->set('rcp_mode', Recipe::MODE_PIPELINE);
	$recipe->set('rcp_pipeline_job', 'email_triage');
	$recipe->set('rcp_source_config', array('mailbox_aliases' => $addresses));
	$recipe->set('rcp_owner_user_id', $owner_id);
	$recipe->set('rcp_enabled', $enabled);
	$recipe->set('rcp_schedule_frequency', 'hourly');
	$recipe->set('rcp_allow_tainted_writes', $tainted_ok);
	$recipe->save();
	$recipe->load();
	harness_register_row('rcp_recipes', 'rcp_recipe_id', intval($recipe->key));
	return $recipe;
}

$member = make_user('AipMember');
$member_id = intval($member->key);
$other = make_user('AipOther');
$other_id = intval($other->key);

$standard = aip_domain(InboundEmailDomain::LEVEL_STANDARD, false);
$a1 = aip_alias(intval($standard->key), 'one', $member_id);
$a2 = aip_alias(intval($standard->key), 'two', $member_id);
$addr1 = 'one@' . $standard->get('ied_domain');
$addr2 = 'two@' . $standard->get('ied_domain');

$job = PipelineJobRegistry::get('email_triage');

// -----------------------------------------------------------------------------
section('Union candidate selection across the bound list');

$older = aip_message(intval($standard->key), intval($a1->key), 'Older on one', '-30');
$newer = aip_message(intval($standard->key), intval($a2->key), 'Newer on two', '-5');

$recipe = aip_recipe($member_id, array($addr1, $addr2));
$config = array('mailbox_aliases' => array($addr1, $addr2));

$resolved = MailboxAliasConfig::resolveBoundAliases($config, $member_id);
check(count($resolved) === 2, 'both addresses resolve with live grants', json_encode($resolved));

$item = $job->nextItem($config, $recipe);
check($item !== null && $item['item_key'] === (string)$newer,
	'the newest unread message across the UNION is selected first',
	json_encode($item));
check($job->hasWork($config, $recipe) === true, 'hasWork sees the union');
check($job->countWork($config, $recipe) === 2, 'countWork counts across both mailboxes');
check($job->countWork($config, $recipe, PipelineJobInterface::POSTURE_SEALED) === 0,
	'the sealed subset of an all-standard binding is empty');
check($job->countWork($config, $recipe, PipelineJobInterface::POSTURE_STANDARD) === 2,
	'and the standard subset carries everything');

// -----------------------------------------------------------------------------
section('A revoked grant drops its address live, with a coverage note');

InboundEmailMailboxGrant::sync_for_alias($a2->key, array());
$resolved = MailboxAliasConfig::resolveBoundAliases($config, $member_id);
check(count($resolved) === 1 && in_array($addr1, $resolved, true),
	'the revoked address is out of the resolved set at once', json_encode($resolved));
$notes = $job->coverageNotes($config, $recipe);
check(count($notes) === 1 && strpos($notes[0], $addr2) !== false,
	'and the run tally note names the dropped address', json_encode($notes));
$item = $job->nextItem($config, $recipe);
check($item !== null && $item['item_key'] === (string)$older,
	'selection now sees only the still-granted mailbox');

InboundEmailMailboxGrant::sync_for_alias($a2->key, array($member_id));
check(count($job->coverageNotes($config, $recipe)) === 0,
	'restoring the grant clears the note');

// -----------------------------------------------------------------------------
section('validateConfig: per-address grant, empty list legal');

$refused = false;
try {
	$job->validateConfig(array('mailbox_aliases' => array($addr1, 'nobody@nowhere.example')),
		$recipe);
} catch (InvalidArgumentException $e) {
	$refused = true;
}
check($refused, 'an address that resolves to nothing is refused by name at save time');

$empty_ok = true;
try {
	$job->validateConfig(array('mailbox_aliases' => array()), $recipe);
} catch (InvalidArgumentException $e) {
	$empty_ok = false;
}
check($empty_ok, 'an empty list is legal — the recipe simply covers nothing');

// -----------------------------------------------------------------------------
section('The scheduling split: sealed-only refuses cron, mixed does not');

$fortress = aip_domain(InboundEmailDomain::LEVEL_FORTRESS, true);
$sealed_alias = aip_alias(intval($fortress->key), 'sealed', $member_id);
$sealed_addr = 'sealed@' . $fortress->get('ied_domain');
MailboxAliasConfig::clearPostureCache();

$sealed_only = aip_recipe($member_id, array($sealed_addr));
$mixed = aip_recipe($member_id, array($addr1, $sealed_addr));

check(RecipeVaultScope::requiresWindow($sealed_only), 'a sealed-only binding needs a window');
check(RecipeVaultScope::cronRunnable($sealed_only) === false,
	'and stays un-runnable from cron');
check(RecipeVaultScope::requiresWindow($mixed), 'a mixed binding also declares the scope');
check(RecipeVaultScope::cronRunnable($mixed) === true,
	'but IS cron-runnable — the standard remainder drains there');
check(RecipeVaultScope::cronRunnable($recipe) === true,
	'an all-standard binding is untouched by the split');

$mixed_cfg = array('mailbox_aliases' => array($addr1, $sealed_addr));
check($job->hasUnsealedBinding($mixed_cfg) === true, 'hasUnsealedBinding: mixed → true');
check($job->hasUnsealedBinding(array('mailbox_aliases' => array($sealed_addr))) === false,
	'hasUnsealedBinding: sealed-only → false');
// On this CLI process (no vault window) the sealed address contributes nothing.
check($job->countWork($mixed_cfg, $mixed, PipelineJobInterface::POSTURE_STANDARD) === 1,
	'the standard subset of a mixed binding reads fine without a window');
check($job->countWork($mixed_cfg, $mixed, PipelineJobInterface::POSTURE_SEALED) === 0,
	'while the sealed subset fails closed');

// -----------------------------------------------------------------------------
section('Panel state: own cards plus template cards, server-rendered facts');

$context = array('mailbox' => $addr1);
$cards = AiPanelService::state($member_id, 1, 'mailbox', $context);
check(count($cards) >= 4,
	'the member sees their own recipes plus shipped template cards', count($cards) . ' cards');

$own_card = null;
$template_card = null;
foreach ($cards as $card) {
	if (($card['recipe_id'] ?? null) === intval($recipe->key)) $own_card = $card;
	if (($card['template_key'] ?? null) === 'email_triage_default') $template_card = $card;
}
check($own_card !== null, 'the union recipe renders a card');
check($own_card && $own_card['covered'] === true, 'covered: the open mailbox is on its list');
check($own_card && $own_card['other_count'] === 1, 'also on 1 other mailbox');
check($own_card && $own_card['paused'] === false && $own_card['blocked_reason'] === null,
	'nothing blocks it');
check($own_card && $own_card['dashboard_url'] === null,
	'a member card carries no dashboard link');
check($template_card !== null, 'a shipped declaration the member has no instance of shows as a template card');
check($template_card && $template_card['covered'] === false, 'template cards start off');

$admin_cards = AiPanelService::state($member_id, 10, 'mailbox', $context);
$admin_own = null;
foreach ($admin_cards as $card) {
	if (($card['recipe_id'] ?? null) === intval($recipe->key)) $admin_own = $card;
}
check($admin_own && is_string($admin_own['dashboard_url'])
		&& strpos($admin_own['dashboard_url'], (string)$recipe->key) !== false,
	'a permission-10 viewer gets the dashboard link');

// -----------------------------------------------------------------------------
section('Owner scoping and the dashboard-only kill switch');

$foreign = aip_recipe($other_id, array());
$refused = '';
try {
	AiPanelService::toggle($member_id, 1, 'mailbox', $context, intval($foreign->key), '', true, false);
} catch (AiPanelServiceException $e) {
	$refused = $e->getMessage();
}
check($refused !== '', 'toggling someone else\'s recipe is refused', $refused);

$paused = aip_recipe($member_id, array($addr1), false);
$refused = '';
try {
	AiPanelService::toggle($member_id, 1, 'mailbox', $context, intval($paused->key), '', false, false);
} catch (AiPanelServiceException $e) {
	$refused = $e->getMessage();
}
check(stripos($refused, 'paused') !== false,
	'a toggle against a globally disabled recipe is refused server-side', $refused);

// -----------------------------------------------------------------------------
section('Toggle round-trips, including last-mailbox removal');

$card = AiPanelService::toggle($member_id, 1, 'mailbox', $context, intval($recipe->key), '', false, false);
check($card['covered'] === false && $card['other_count'] === 1,
	'toggle OFF removes the open mailbox and keeps the other', json_encode($card));

$card = AiPanelService::toggle($member_id, 1, 'mailbox', $context, intval($recipe->key), '', true, false);
check($card['covered'] === true && $card['other_count'] === 1,
	'toggle ON binds it again (acceptance already made, no confirm)', json_encode($card));

$ctx2 = array('mailbox' => $addr2);
AiPanelService::toggle($member_id, 1, 'mailbox', $ctx2, intval($recipe->key), '', false, false);
$card = AiPanelService::toggle($member_id, 1, 'mailbox', $context, intval($recipe->key), '', false, false);
$after = new Recipe(intval($recipe->key), TRUE);
$after_cfg = Recipe::decodeSourceConfig($after);
check(($after_cfg['mailbox_aliases'] ?? null) === array(),
	'removing the last mailbox leaves an empty list', json_encode($after_cfg));
check((bool)$after->get('rcp_enabled') === true,
	'and the recipe is NOT auto-disabled — the kill switch stays dashboard-only');

// -----------------------------------------------------------------------------
section('The taint confirm handshake');

$unaccepted = aip_recipe($member_id, array(), true, false);
$confirm_text = '';
try {
	AiPanelService::toggle($member_id, 1, 'mailbox', $context, intval($unaccepted->key), '', true, false);
} catch (AiPanelConfirmRequired $e) {
	$confirm_text = $e->getMessage();
}
check($confirm_text !== '' && stripos($confirm_text, 'one verdict for one item') !== false,
	'turning ON without the acceptance answers confirm-required with the TaintGate wording',
	$confirm_text);
$check_cfg = Recipe::decodeSourceConfig(new Recipe(intval($unaccepted->key), TRUE));
check(($check_cfg['mailbox_aliases'] ?? array()) === array(),
	'and binds nothing until the person agrees');

$card = AiPanelService::toggle($member_id, 1, 'mailbox', $context, intval($unaccepted->key), '', true, true);
$after = new Recipe(intval($unaccepted->key), TRUE);
check($card['covered'] === true, 'retrying with the acceptance binds the mailbox');
check((bool)$after->get('rcp_allow_tainted_writes') === true,
	'and records the acceptance on the recipe');

// -----------------------------------------------------------------------------
section('Template instantiation: a per-user instance, never the seeded row');

$db = DbConnector::get_instance()->get_db_link();
$seeded_before = $db->query(
	"SELECT rcp_recipe_id, rcp_owner_user_id, rcp_enabled, rcp_source_config::text AS cfg
	   FROM rcp_recipes WHERE rcp_declared_key = 'email_triage_default'")->fetch(PDO::FETCH_ASSOC);

$confirm_text = '';
try {
	AiPanelService::toggle($member_id, 1, 'mailbox', $context, null, 'email_triage_default', true, false);
} catch (AiPanelConfirmRequired $e) {
	$confirm_text = $e->getMessage();
}
check($confirm_text !== '', 'first toggle-ON of a template card asks for the acceptance first');
$instances = new MultiRecipe(array('template_key' => 'email_triage_default',
	'owner_user_id' => $member_id, 'deleted' => false));
check($instances->count_all() === 0, 'and declining creates nothing');

$card = AiPanelService::toggle($member_id, 1, 'mailbox', $context, null, 'email_triage_default', true, true);
check($card['recipe_id'] > 0 && $card['covered'] === true,
	'accepting creates the member\'s own instance, bound to the open mailbox', json_encode($card));

$instance = new Recipe(intval($card['recipe_id']), TRUE);
harness_register_row('rcp_recipes', 'rcp_recipe_id', intval($instance->key));
check((string)$instance->get('rcp_template_key') === 'email_triage_default',
	'the instance carries rcp_template_key');
check((string)$instance->get('rcp_declared_key') === '',
	'and never the seeder\'s unique rcp_declared_key');
check(intval($instance->get('rcp_owner_user_id')) === $member_id, 'owner = the caller');
check((bool)$instance->get('rcp_enabled') === true,
	'created ENABLED — the toggle is itself the enablement choice');
check((bool)$instance->get('rcp_allow_tainted_writes') === true,
	'with the acceptance made in the same dialog');

if ($seeded_before) {
	$seeded_after = $db->query(
		"SELECT rcp_recipe_id, rcp_owner_user_id, rcp_enabled, rcp_source_config::text AS cfg
		   FROM rcp_recipes WHERE rcp_declared_key = 'email_triage_default'")->fetch(PDO::FETCH_ASSOC);
	check($seeded_after == $seeded_before, 'the seeded row is untouched');
} else {
	harness_skip('seeded-row untouched check', 'no seeded email_triage_default row on this install');
}

$card = AiPanelService::toggle($member_id, 1, 'mailbox', $ctx2, null, 'email_triage_default', true, false);
check($card['recipe_id'] === intval($instance->key),
	'a second template toggle edits the SAME instance, never a second copy');
$cards = AiPanelService::state($member_id, 1, 'mailbox', $context);
$dup = 0;
foreach ($cards as $c) {
	if (($c['template_key'] ?? null) === 'email_triage_default') $dup++;
	if (($c['recipe_id'] ?? null) === intval($instance->key)) $dup++;
}
check($dup === 1, 'the panel shows the instance once — its template card is gone');

// -----------------------------------------------------------------------------
section('The API actions answer through the real logic wrappers');

$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = $member_id;
$_SESSION['permission'] = 1;

$r = harness_call_logic('plugins/joinery_ai/logic/ai_panel_state_logic.php',
	'ai_panel_state_logic', array('area' => 'mailbox', 'mailbox' => $addr1));
check($r->error === null && is_array($r->data['cards'] ?? null),
	'ai_panel_state returns the cards list', var_export($r->error, true));

$r = harness_call_logic('plugins/joinery_ai/logic/ai_panel_toggle_logic.php',
	'ai_panel_toggle_logic', array('area' => 'mailbox', 'mailbox' => $addr1,
		'recipe_id' => intval($recipe->key), 'enabled' => '1'));
check($r->error === null && ($r->data['card']['covered'] ?? null) === true,
	'ai_panel_toggle round-trips through the logic wrapper', var_export($r->error, true));

harness_finish();
