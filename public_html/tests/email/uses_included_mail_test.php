<?php
/** @joinery-test
 * name: uses_included_mail
 * tier: db
 * env: any
 * needs: []
 * timeout: 60
 */
/**
 * EmailSender::usesIncludedMail() — whether the site sends through the
 * included Joinery email (spec email_lists_plugin §5).
 *
 * What is pinned here:
 *  - the getjoinery services provider is included mail;
 *  - plain SMTP is included mail exactly while its host and username are the
 *    ones HostedMailSettingsMap::apply() recorded (host case aside);
 *  - an owner's own SMTP account, another username on the same host, or no
 *    recorded identity, is not.
 *
 * Settings are set in memory only.
 *
 * Run: php tests/run.php db --filter=uses_included_mail
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('The getjoinery services provider');
harness_set_setting_mem('email_service', ServicesClient::MAIL_SERVICE_KEY);
check(EmailSender::usesIncludedMail(), 'joinery_services is included mail');

section('A Managed site\'s SMTP credential');
harness_set_setting_mem('email_service', 'smtp');
harness_set_setting_mem('included_mail_identity', 'mail.smtp2go.com|t9-site');
harness_set_setting_mem('smtp_host', 'Mail.SMTP2GO.com');
harness_set_setting_mem('smtp_username', 't9-site');
check(EmailSender::usesIncludedMail(), 'the recorded host and username are included mail');

harness_set_setting_mem('smtp_username', 'owners-own-account');
check(!EmailSender::usesIncludedMail(), 'the owner\'s own account on the same host is not');

harness_set_setting_mem('smtp_host', 'smtp.example.org');
harness_set_setting_mem('smtp_username', 't9-site');
check(!EmailSender::usesIncludedMail(), 'another host is not');

section('Another provider');
harness_set_setting_mem('email_service', 'mailgun');
harness_set_setting_mem('smtp_host', 'mail.smtp2go.com');
check(!EmailSender::usesIncludedMail(), 'a provider of the owner\'s own is not');

harness_finish();
