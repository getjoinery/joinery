<?php
/** @joinery-test
 * name: email_schedule_job_draft_exclusion
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Compose maturity fix pack Fix 9 — EmailScheduleJob::nextItem() must never surface a
 * draft row (the drafts-are-never-AI-read invariant its two siblings, EmailTriageJob
 * and EmailSecurityScanJob, already enforce). A half-written draft reaching the model
 * would also log a recipe-item dedup mark that suppresses the real scan of the later
 * sent (morphed, same-id) version.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(__DIR__ . '/../lib/llm_fixtures.php');
// Jobs are handed the run's model resolution so they can size a digest against the
// room they actually got. These tests exercise selection, not sizing.
$fake_resolution = fake_model_resolution();

require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('includes/DescriptorValidator.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

$db = DbConnector::get_instance()->get_db_link();
$owner_uid = (int)$db->query("SELECT usr_user_id FROM usr_users WHERE usr_permission >= 10 AND usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetchColumn();
if ($owner_uid <= 0) {
	harness_skip('needs an active permission-10 admin to own the test recipe');
	harness_finish();
	return;
}

$suffix = gmdate('His') . '-' . mt_rand(1000, 9999);
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', "zzsched-{$suffix}.example");
$domain->set('ied_is_enabled', true);
$domain->set('ied_reject_unmatched', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'zzsched');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$alias_id = (int)$alias->key;
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $alias_id);
$address = 'zzsched@' . $domain->get('ied_domain');

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
$grant->set('ieg_usr_user_id', $owner_uid);
$grant->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);

$recipe = new Recipe(NULL);
$recipe->set('rcp_name', "email-schedule-draft-test-{$suffix}");
$recipe->set('rcp_mode', Recipe::MODE_PIPELINE);
$recipe->set('rcp_pipeline_job', 'email_schedule');
$recipe->set('rcp_owner_user_id', $owner_uid);
$recipe->set('rcp_source_config', ['mailbox_aliases' => [$address]]);
$recipe->set('rcp_max_iterations', 5);
$recipe->set('rcp_max_tokens', 5000);
$recipe->prepare();
$recipe->save();
harness_register_row('rcp_recipes', 'rcp_recipe_id', (int)$recipe->key);

$mk = function ($direction, $subject) use ($domain, $alias_id) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', $direction);
	$m->set('iem_sender', 'sender@example.com');
	$m->set('iem_recipient', 'zzsched@example.com');
	$m->set('iem_subject', $subject);
	$m->set('iem_body_plain', 'Meeting Friday 3pm at the office.');
	$m->set('iem_body_html', '');
	$m->set('iem_message_id_header', 'zzsched-' . bin2hex(random_bytes(8)) . '@example.com');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};

$job = PipelineJobRegistry::get('email_schedule');
$config = DescriptorValidator::coerce($job->configDescriptor(), Recipe::decodeSourceConfig($recipe));

section('schedule job excludes drafts');

// A draft on the mailbox, and NO other candidate → nextItem returns nothing.
$draft_id = $mk('draft', 'Half-written draft');
$item = $job->nextItem($config, $recipe, $fake_resolution);
check($item === null, 'a draft on the mailbox is never returned by nextItem', json_encode($item));

// The same content, sent (morphed in place, same id) → now a candidate.
InboundEmailMessage::updateColumns($draft_id, array('iem_direction' => 'outbound'));
$item2 = $job->nextItem($config, $recipe, $fake_resolution);
check($item2 !== null && (int)$item2['item_key'] === $draft_id, 'the morphed (outbound) row IS selectable', json_encode($item2));

harness_finish();
