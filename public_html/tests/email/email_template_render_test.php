<?php
/** @joinery-test
 * name: email_template_render
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Email template -> message rendering, offline (no mail sent).
 *
 * Locks the template processing path the whole email system depends on:
 * EmailMessage::fromTemplate() loads a named inner template (wrapped by the
 * seeded default outer + footer), substitutes *var* placeholders in the body,
 * extracts the template's subject, and derives a plain-text alternate from the
 * HTML. Then the fluent EmailMessage API composes recipients/from and lets a
 * caller override the subject.
 *
 * Uses throwaway templates it creates and deletes (registered for teardown), so
 * it never depends on a particular production template existing. It replaces the
 * subject/template coverage of the retired tests/email/suites framework, whose
 * assertions had gone stale (a removed CreateLegacyTemplate method, a
 * missing-subject "exception" the model never actually raised).
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

/** Create a throwaway inner template and register it for teardown. */
function tpl_fixture(string $subject_or_null, string $body): string {
	$name = 'zz_rendertest_' . bin2hex(random_bytes(5));
	$t = new EmailTemplateStore(NULL);
	$t->set('emt_name', $name);
	$t->set('emt_type', 2); // inner
	if ($subject_or_null !== '') { $t->set('emt_subject', $subject_or_null); }
	$t->set('emt_body', $body);
	$t->save();
	harness_register_row('emt_email_templates', 'emt_email_template_id', (int)$t->key);
	return $name;
}

// A template with a static subject and two body variables.
$tpl = tpl_fixture(
	'Ported Test Subject',
	'<h1>Hello *name*</h1><p>Your code is *act_code*.</p>'
);

section('template -> message: content + variable substitution');
$msg = EmailMessage::fromTemplate($tpl, ['name' => 'Ada', 'act_code' => 'CODE-XYZ']);
$html = (string)$msg->getHtmlBody();
$text = (string)$msg->getTextBody();
check($msg->getSubject() === 'Ported Test Subject', 'subject extracted from the template', 'got: ' . $msg->getSubject());
check($html !== '', 'HTML body is generated');
check(strpos($html, 'CODE-XYZ') !== false, 'body *var* substituted (act_code)', 'html had no CODE-XYZ');
check(strpos($html, 'Ada') !== false, 'body *var* substituted (name)');
check(strpos($html, '*act_code*') === false, 'no raw *var* placeholder survives in the body');
check($text !== '', 'plain-text alternate is derived from the HTML');
check(strpos($text, 'CODE-XYZ') !== false, 'text alternate carries the substituted value');

section('fluent compose: recipients, from, getters');
$msg->from('from@dev.example', 'From Name')->to('rcpt@dev.example', 'Recipient');
check($msg->getFrom() === 'from@dev.example', 'getFrom returns the sender address');
check($msg->getFromName() === 'From Name', 'getFromName returns the sender name');
$recips = $msg->getRecipients();
check(is_array($recips) && count($recips) === 1, 'getRecipients returns one recipient', 'count: ' . count($recips));
check(($recips[0]['email'] ?? '') === 'rcpt@dev.example', 'recipient address recorded');
check($msg->getSubject() !== null && $msg->getHtmlBody() !== null && $msg->getTextBody() !== null,
	'core getters never return null after composition');

section('subject override beats the template subject');
$msg2 = EmailMessage::fromTemplate($tpl, ['name' => 'Bo', 'act_code' => 'X']);
check($msg2->getSubject() === 'Ported Test Subject', 'starts with the template subject');
$msg2->subject('Explicit Override');
check($msg2->getSubject() === 'Explicit Override', 'subject() overrides the template subject');

section('a subjectless template renders content with an empty subject');
$tpl_nosub = tpl_fixture('', '<p>Body only, no subject *act_code*.</p>');
$msg3 = EmailMessage::fromTemplate($tpl_nosub, ['act_code' => 'NOSUB']);
check((string)$msg3->getHtmlBody() !== '', 'subjectless template still produces content');
check(strpos((string)$msg3->getHtmlBody(), 'NOSUB') !== false, 'its body variable is substituted');
check((string)$msg3->getSubject() === '', 'no subject is extracted (empty, not an error)', 'got: ' . var_export($msg3->getSubject(), true));

section('a missing template fails loudly');
$threw = false;
try {
	EmailMessage::fromTemplate('zz_does_not_exist_' . bin2hex(random_bytes(4)), []);
} catch (\Throwable $e) {
	$threw = true;
}
check($threw, 'fromTemplate on an unknown template throws (fail-loud, not a silent empty send)');

harness_finish();
