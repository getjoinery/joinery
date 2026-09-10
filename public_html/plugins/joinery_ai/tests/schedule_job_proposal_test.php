<?php
/** @joinery-test
 * name: schedule_job_proposal
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The email schedule job proposes; the owner writes (specs/security_inventory.md S17).
 *
 * A stranger's message used to become a calendar row with nobody clicking:
 * the job's verdict went straight to CalendarEntryImporter. It now goes to the
 * owner's approval queue as a create_calendar_entry proposal, rendered from
 * the literal arguments, and the entry exists only once the owner approves.
 *
 * What this pins down:
 *
 *  - recordVerdict() writes no calendar entry and queues exactly one pending
 *    recipe-sourced proposal on the job's area, naming the recipe on the card;
 *  - a re-judged message (log-row reset, re-run) replaces its pending proposal
 *    instead of adding a second;
 *  - approving executes under the recipe's scope with no conversation, and the
 *    entry lands tentative on the owner's calendar with email provenance;
 *  - declining writes nothing;
 *  - a proposal whose recipe is gone fails closed on approval.
 *
 * Run: php tests/run.php db --filter=schedule_job_proposal
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionQueue.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_queued_actions_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));

$db = DbConnector::get_instance()->get_db_link();
$owner_uid = (int)$db->query("SELECT usr_user_id FROM usr_users WHERE usr_permission >= 10 AND usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetchColumn();
if ($owner_uid <= 0) {
	harness_skip('needs an active permission-10 admin to own the test recipe');
	harness_finish();
	return;
}

$suffix = gmdate('His') . '-' . mt_rand(1000, 9999);
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', "zzprop-{$suffix}.example");
$domain->set('ied_is_enabled', true);
$domain->set('ied_reject_unmatched', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'zzprop');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$alias_id = (int)$alias->key;
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $alias_id);
$address = 'zzprop@' . $domain->get('ied_domain');

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
$grant->set('ieg_usr_user_id', $owner_uid);
$grant->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);

$recipe = new Recipe(NULL);
$recipe->set('rcp_name', "schedule proposal test {$suffix}");
$recipe->set('rcp_mode', Recipe::MODE_PIPELINE);
$recipe->set('rcp_pipeline_job', 'email_schedule');
$recipe->set('rcp_owner_user_id', $owner_uid);
$recipe->set('rcp_source_config', ['mailbox_aliases' => [$address]]);
$recipe->set('rcp_max_iterations', 5);
$recipe->set('rcp_max_tokens', 5000);
$recipe->prepare();
$recipe->save();
harness_register_row('rcp_recipes', 'rcp_recipe_id', (int)$recipe->key);

$mk = function ($subject) use ($domain, $alias_id) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', 'stranger@example.com');
	$m->set('iem_recipient', 'zzprop@example.com');
	$m->set('iem_subject', $subject);
	$m->set('iem_body_plain', 'Meeting Friday 3pm at the office.');
	$m->set('iem_body_html', '');
	$m->set('iem_message_id_header', 'zzprop-' . bin2hex(random_bytes(8)) . '@example.com');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};

$pending_for_recipe = function () use ($owner_uid, $recipe) {
	$rows = new MultiAiQueuedAction([
		'owner_user_id'     => $owner_uid,
		'status'            => AiQueuedAction::STATUS_PENDING,
		'aqa_rcp_recipe_id' => (int)$recipe->key,
	]);
	$out = [];
	foreach ($rows as $r) {
		harness_register_model('AiQueuedAction', (int)$r->key);
		$out[] = $r;
	}
	return $out;
};
$entries_for = function (string $item_key) use ($owner_uid) {
	$rows = new MultiCalendarEntry([
		'subject_type' => CalendarSubject::TYPE_USER,
		'subject_id'   => $owner_uid,
		'deleted'      => false,
		'source'       => 'email',
		'source_ref'   => $item_key,
	]);
	$out = [];
	foreach ($rows as $r) {
		harness_register_model('CalendarEntry', (int)$r->key);
		$out[] = $r;
	}
	return $out;
};

$job = PipelineJobRegistry::get('email_schedule');
$verdict = [
	'event_found' => true,
	'title'       => 'Stranger says: board meeting',
	'start_local' => '2026-10-02 15:00:00',
	'timezone'    => 'America/New_York',
	'all_day'     => false,
];

// =====================================================================
section('the verdict becomes a proposal, not a row');
// =====================================================================

$first = $mk('Board meeting Friday');
$job->recordVerdict((string)$first, $verdict, $recipe, 'test-model');

check(count($entries_for((string)$first)) === 0, 'no calendar entry exists after the verdict');
$pending = $pending_for_recipe();
check(count($pending) === 1, 'exactly one pending proposal is queued', count($pending));
if (count($pending) === 1) {
	$row = $pending[0];
	check((string)$row->get('aqa_source_type') === AiQueuedAction::SOURCE_RECIPE, 'sourced from a recipe');
	check((string)$row->get('aqa_tool') === 'create_calendar_entry', 'as a create_calendar_entry call');
	check((string)$row->get('aqa_area') === $job->area(), 'on the job\'s own area page', (string)$row->get('aqa_area'));
	check((int)$row->get('aqa_aic_conversation_id') === 0, 'with no conversation behind it');
	$card = ActionQueue::card($row);
	check(!$card['locked'] && is_array($card['facts'])
		&& strpos((string)$card['facts'][0], 'Stranger says: board meeting') !== false,
		'the card headline is rendered from the literal title', json_encode($card['facts']));
	check(($card['recipe_name'] ?? '') === (string)$recipe->get('rcp_name'),
		'and the card names the proposing recipe');
	check(strpos(json_encode($card['facts']), '2026-10-02 15:00:00 to 2026-10-02 16:00:00') !== false,
		'the end defaulted to an hour after the start, and both show on the card', json_encode($card['facts']));
}

// =====================================================================
section('a re-judged message replaces its pending proposal');
// =====================================================================

$changed = $verdict;
$changed['title'] = 'Stranger says: board meeting (moved)';
$changed['start_local'] = '2026-10-03 15:00:00';
$job->recordVerdict((string)$first, $changed, $recipe, 'test-model');
$pending = $pending_for_recipe();
check(count($pending) === 1, 'still exactly one pending proposal', count($pending));
if (count($pending) === 1) {
	$args = json_decode((string)$pending[0]->get('aqa_arguments'), true);
	check(($args['title'] ?? '') === 'Stranger says: board meeting (moved)'
		&& ($args['start_local'] ?? '') === '2026-10-03 15:00:00',
		'carrying the newer verdict', json_encode($args));
}

// =====================================================================
section('approving writes the entry, as the owner, with email provenance');
// =====================================================================

if (count($pending) === 1) {
	$resolved = ActionQueue::resolve((int)$pending[0]->key, $owner_uid, 'approve');
	check((string)$resolved->get('aqa_status') === AiQueuedAction::STATUS_APPROVED,
		'the proposal resolves approved', (string)$resolved->get('aqa_result'));
	$entries = $entries_for((string)$first);
	check(count($entries) === 1, 'and one calendar entry now exists', count($entries));
	if (count($entries) === 1) {
		$e = $entries[0];
		check((string)$e->get('cal_status') === 'tentative', 'tentative, as every AI-originated entry is');
		check((int)$e->get('cal_subject_id') === $owner_uid
			&& (string)$e->get('cal_subject_type') === CalendarSubject::TYPE_USER,
			'on the recipe owner\'s own calendar');
		check((string)$e->get('cal_title') === 'Stranger says: board meeting (moved)', 'with the approved title');
		check((string)$e->get('cal_source') === 'email' && (string)$e->get('cal_source_event_id') === (string)$first,
			'and the message as its provenance');
	}
	check(count($pending_for_recipe()) === 0, 'nothing is left pending');
}

// =====================================================================
section('declining writes nothing');
// =====================================================================

$second = $mk('Another dated thing');
$job->recordVerdict((string)$second, $verdict, $recipe, 'test-model');
$pending = $pending_for_recipe();
check(count($pending) === 1, 'the second message queues its own proposal', count($pending));
if (count($pending) === 1) {
	$resolved = ActionQueue::resolve((int)$pending[0]->key, $owner_uid, 'decline');
	check((string)$resolved->get('aqa_status') === AiQueuedAction::STATUS_DECLINED, 'declined');
	check(count($entries_for((string)$second)) === 0, 'and no entry was written');
}

// =====================================================================
section('a proposal whose recipe is gone fails closed');
// =====================================================================

$third = $mk('Orphaned proposal');
$job->recordVerdict((string)$third, $verdict, $recipe, 'test-model');
$pending = $pending_for_recipe();
check(count($pending) === 1, 'the third message queues a proposal', count($pending));
if (count($pending) === 1) {
	$recipe->set('rcp_delete_time', gmdate('Y-m-d H:i:s'));
	$recipe->save();
	$resolved = ActionQueue::resolve((int)$pending[0]->key, $owner_uid, 'approve');
	check((string)$resolved->get('aqa_status') === AiQueuedAction::STATUS_FAILED,
		'approving fails rather than executing under no scope', (string)$resolved->get('aqa_result'));
	check(strpos((string)$resolved->get('aqa_result'), 'recipe') !== false,
		'and says the recipe is gone', (string)$resolved->get('aqa_result'));
	check(count($entries_for((string)$third)) === 0, 'no entry was written');
}

harness_finish();
