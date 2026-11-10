<?php
/** @joinery-test
 * name: campaign_templates
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The campaign templates belong to the plugin (spec email_lists_plugin).
 *
 * What is pinned here:
 *  - core's template list ships neither newsletter-1 nor a footer that speaks
 *    of mailing lists;
 *  - with the plugin on, campaigns go out with mailing_list_footer, and a
 *    campaign to a list carries the list's unsubscribe link, even where the
 *    site has deleted that row (the plugin's own text stands in);
 *  - el_002 adds the two templates where a site has none: the campaign footer
 *    is the site's own default_footer when that still carries the list block
 *    (an edited one included), the plugin's file otherwise; a row a site has
 *    is never changed.
 *
 * The migration runs inside a transaction that is rolled back.
 *
 * Run: php tests/run.php db --filter=campaign_templates
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('Core ships no list template');
$core_names = array_column(EmailTemplateDefaults::all(), 'name');
check(!in_array('newsletter-1', $core_names, true) && !in_array('mailing_list_footer', $core_names, true),
	'email_templates.json lists neither newsletter-1 nor mailing_list_footer');
$core_footer = EmailTemplateDefaults::get('default_footer');
check($core_footer && stripos($core_footer['body'], 'mailing_list') === false, 'core\'s default_footer says nothing of mailing lists');

if (!PluginHelper::isPluginActive('email_lists')) {
	section('Campaign footer');
	harness_skip('email_lists active', 'plugin inactive on this deployment');
	harness_finish();
	return;
}

section('Campaigns go out with the plugin\'s footer');
check(CampaignSender::footer() === 'mailing_list_footer', 'the campaign footer is mailing_list_footer');
$member = make_user('CampaignFooter');
$html = EmailMessage::fromTemplate('blank_template', array(
	'subject' => 'x', 'body' => '<p>x</p>', 'mailing_list_id' => 987654, 'mailing_list_string' => 'Footer List',
	'recipient' => $member->export_as_array(),
), CampaignSender::footer())->getHtmlBody();
check(strpos($html, 'contact_preferences?mailing_list_id=987654') !== false && strpos($html, 'Footer List') !== false,
	'a campaign to a list carries its unsubscribe link');

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
try {
	$db->exec("UPDATE emt_email_templates SET emt_delete_time = now() WHERE emt_name = 'mailing_list_footer'");
	$footer = CampaignSender::footer();
	$html = EmailMessage::fromTemplate('blank_template', array(
		'subject' => 'x', 'body' => '<p>x</p>', 'mailing_list_id' => 987654, 'mailing_list_string' => 'Footer List',
		'recipient' => $member->export_as_array(),
	), $footer)->getHtmlBody();
	check(is_array($footer) && strpos($html, 'contact_preferences?mailing_list_id=987654') !== false,
		'with the site\'s footer deleted, a campaign to a list still carries its unsubscribe link');
} finally {
	$db->rollBack();
}

section('el_002 adds the templates a site does not have');
$db = DbConnector::get_instance()->get_db_link();
$up = null;
foreach (require(PathHelper::getIncludePath('plugins/email_lists/migrations/migrations.php')) as $m) {
	if ($m['id'] === 'el_002_campaign_templates') {
		$up = $m['up'];
	}
}
check(is_callable($up), 'el_002 is declared');
$body_of = function (string $name) use ($db) {
	$q = $db->prepare('SELECT emt_body FROM emt_email_templates WHERE emt_name = ? LIMIT 1');
	$q->execute(array($name));
	return $q->fetchColumn();
};
$plugin_footer = rtrim(file_get_contents(PathHelper::getIncludePath('plugins/email_lists/email_templates/mailing_list_footer.html')), "\n");
$set_default_footer = $db->prepare("UPDATE emt_email_templates SET emt_body = ? WHERE emt_name = 'default_footer'");

$db->beginTransaction();
try {
	$db->exec("DELETE FROM emt_email_templates WHERE emt_name IN ('newsletter-1', 'mailing_list_footer')");
	$edited = "<small>{mailing_list_id}Leave *mailing_list_string* here.{end mailing_list_id} Our own words.</small>";
	$set_default_footer->execute(array($edited));
	$up(DbConnector::get_instance());
	check($body_of('mailing_list_footer') === $edited, 'an edited default_footer with the list block becomes the campaign footer');
	check(is_string($body_of('newsletter-1')) && $body_of('newsletter-1') !== '', 'newsletter-1 is added');

	$db->exec("DELETE FROM emt_email_templates WHERE emt_name = 'mailing_list_footer'");
	$set_default_footer->execute(array($core_footer['body']));
	$up(DbConnector::get_instance());
	check($body_of('mailing_list_footer') === $plugin_footer, 'without the list block it is the plugin\'s file');

	$db->exec("UPDATE emt_email_templates SET emt_body = 'kept' WHERE emt_name IN ('newsletter-1', 'mailing_list_footer')");
	$up(DbConnector::get_instance());
	check($body_of('newsletter-1') === 'kept' && $body_of('mailing_list_footer') === 'kept', 'a row a site has is never changed');
} finally {
	$db->rollBack();
}

harness_finish();
