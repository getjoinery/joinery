<?php
/** @joinery-test
 * name: campaign_included_mail
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * Campaigns need the site's own email provider; included email runs out
 * loudly (spec email_lists_plugin §5).
 *
 * What is pinned here:
 *  - on the included email a campaign is refused at queueing, and
 *    queue_to_list() writes nothing;
 *  - a campaign queued before the site moved back to included email is held
 *    at send time, with the reason on the row, and not sent;
 *  - a test send to yourself is still allowed;
 *  - on the site's own provider the same campaign queues;
 *  - the plan banner's email row leads with "Connect your own email provider"
 *    at 95% and over, not below; the direct-message page's prompt likewise.
 *
 * Settings are set in memory; sends run with email_dry_run on.
 *
 * Run: php tests/run.php db --filter=campaign_included_mail
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!PluginHelper::isPluginActive('email_lists')) {
	section('Campaigns on included email');
	harness_skip('email_lists active', 'plugin inactive on this deployment');
	harness_finish();
	return;
}

harness_set_setting_mem('email_dry_run', '1');
$db = DbConnector::get_instance()->get_db_link();
$run = bin2hex(random_bytes(3));

$list = new MailingList(NULL);
$list->set('mlt_name', 'HarnessTest included ' . $run);
$list->set('mlt_link', 'harnesstest-included-' . $run);
$list->set('mlt_is_active', true);
$list->set('mlt_visibility', MailingList::VISIBILITY_PUBLIC);
$list->save();
$list->load();
harness_register_row('mlt_mailing_lists', 'mlt_mailing_list_id', $list->key);
$member = make_user('IncludedMember');
$list->add_registrant($member->key, array('source' => MailingListRegistrant::SOURCE_ADMIN));

$included = function () {
	harness_set_setting_mem('email_service', ServicesClient::MAIL_SERVICE_KEY);
};
$own = function () {
	harness_set_setting_mem('email_service', 'smtp');
};
$count_subject = function (string $subject) use ($db): int {
	$q = $db->prepare('SELECT count(*) FROM eml_emails WHERE eml_subject = ?');
	$q->execute(array($subject));
	return (int)$q->fetchColumn();
};

section('Refused at queueing on included email');
$included();
check(CampaignRules::refusal() !== null && CampaignRules::banner() !== '', 'the rule refuses and the compose banner says so');
$subject = 'HarnessTest included refused ' . $run;
$threw = false;
try {
	CampaignSender::queue_to_list((int)$list->key, $subject, '<p>x</p>', 'blank_template');
} catch (CampaignRefusedException $e) {
	$threw = true;
}
check($threw && $count_subject($subject) === 0, 'queue_to_list() refuses and writes nothing');

$email = new Email(NULL);
$email->set('eml_subject', 'HarnessTest included composed ' . $run);
$email->set('eml_message_html', '<p>Hello</p>');
$email->set('eml_message_template_html', 'blank_template');
$email->set('eml_status', Email::EMAIL_CREATED);
$email->set('eml_mlt_mailing_list_id', $list->key);
$email->save();
$email->load();
harness_register_model('Email', $email->key);
$threw = false;
try {
	$email->queue();
} catch (CampaignRefusedException $e) {
	$threw = true;
}
$email->load();
check($threw && (int)$email->get('eml_status') === Email::EMAIL_CREATED, 'a composed campaign is not queued');

section('A test send to yourself is still allowed');
check(CampaignSender::send_test($email, $member), 'send_test() goes out');

section('On the site\'s own provider it queues');
$own();
check(CampaignRules::refusal() === null && CampaignRules::banner() === '', 'no refusal, no banner');
check($email->queue() >= 1, 'the same campaign queues');

section('Held at send time once back on included email');
$included();
$other_queued = (int)$db->query('SELECT count(*) FROM eml_emails WHERE eml_status = ' . Email::EMAIL_QUEUED . ' AND eml_email_id <> ' . (int)$email->key)->fetchColumn();
if ($other_queued > 0) {
	harness_skip('send_due() holds it', 'other queued campaigns exist on this box, which a send pass would touch');
} else {
	$totals = CampaignSender::send_due();
	$email->load();
	check($totals['held'] === 1 && $totals['sent'] === 0, 'send_due() holds it and sends nothing', json_encode($totals));
	check((int)$email->get('eml_status') === Email::EMAIL_QUEUED && $email->get('eml_held_reason') === CampaignRules::HELD,
		'it stays queued, and its page says why');
}
$threw = false;
try {
	CampaignSender::send_email($email);
} catch (CampaignRefusedException $e) {
	$threw = true;
}
check($threw, 'sending it directly is refused too');
$own();

section('The plan banner and the direct-message prompt');
harness_set_setting_mem('hosted_plan_state', 'subscribed');
$allowances = function (int $percent) {
	harness_set_setting_mem('hosted_plan_allowances', json_encode(array(array(
		'label' => 'Email sent this month', 'used' => (string)(20 * $percent), 'allowance' => '2,000', 'percent' => $percent,
		'action_label' => 'Use your own email account', 'action_url' => 'https://example.com/referral'))));
};
$allowances(96);
$html = HostedPlanNotice::render();
check(strpos($html, HostedPlanNotice::OWN_EMAIL_PROVIDER_LABEL) !== false && strpos($html, 'href="' . HostedPlanNotice::OWN_EMAIL_PROVIDER_URL . '"') !== false,
	'at 96% the email row leads with Connect your own email provider');
$allowances(50);
check(strpos(HostedPlanNotice::render(), HostedPlanNotice::OWN_EMAIL_PROVIDER_LABEL) === false, 'at 50% it does not');

$included();
$allowances(96);
check(strpos(HostedPlanNotice::emailUsePrompt(), HostedPlanNotice::OWN_EMAIL_PROVIDER_LABEL) !== false, 'the direct-message page urges at 96%');
$allowances(50);
$prompt = HostedPlanNotice::emailUsePrompt();
check($prompt !== '' && strpos($prompt, HostedPlanNotice::OWN_EMAIL_PROVIDER_LABEL) === false, 'and only shows the month\'s use at 50%');
$own();
check(HostedPlanNotice::emailUsePrompt() === '', 'on the site\'s own provider it says nothing');

harness_finish();
