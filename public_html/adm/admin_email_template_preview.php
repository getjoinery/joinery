<?php
/**
 * admin_email_template_preview — raw HTML preview of an email template body, for
 * the iframe preview on the email-template admin page. Document load: echoes the
 * template body with no admin chrome. Query param: emt_email_template_id.
 */

require_once(PathHelper::getIncludePath('data/email_templates_class.php'));

header('Content-type: text/html');

$session = SessionControl::get_instance();
$session->check_permission(10);

$template = new EmailTemplateStore($_GET['emt_email_template_id'], TRUE);

echo $template->get('emt_body');
?>
