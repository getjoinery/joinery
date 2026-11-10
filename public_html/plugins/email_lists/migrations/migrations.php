<?php
/**
 * Email Lists plugin migrations.
 *
 * @version 1.1 - el_002 adds the campaign templates: newsletter-1 and mailing_list_footer
 * @version 1.0 - el_001 records the consent of subscribers who joined before consent was recorded
 */
return [
	[
		// Double opt-in (spec email_lists_plugin §10) records how each
		// subscription was confirmed. Subscribers from before are kept, marked
		// existing, not asked to confirm again; the address they joined with is
		// the account's address now. Idempotent: only rows with no source.
		'id' => 'el_001_existing_subscribers',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("UPDATE mlr_mailing_list_registrants r
				SET mlr_confirm_source = 'existing',
					mlr_confirm_time = COALESCE(r.mlr_change_time, now()),
					mlr_confirm_email = lower(u.usr_email)
				FROM usr_users u
				WHERE u.usr_user_id = r.mlr_usr_user_id AND r.mlr_confirm_source IS NULL");
			return true;
		},
	],
	[
		// The campaign templates, in plugins/email_lists/email_templates/: the
		// newsletter-1 inner template, and mailing_list_footer, the footer
		// campaigns go out with (its block for a list carries the unsubscribe
		// link). Each is added only where no row of its name exists, live or
		// deleted. A site whose default_footer still carries the list block
		// (an edited one included) gets that body as its campaign footer, so
		// its campaigns read as they did.
		'id' => 'el_002_campaign_templates',
		'version' => '1.1.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dir = PathHelper::getIncludePath('plugins/email_lists/email_templates');
			$exists = $dblink->prepare('SELECT 1 FROM emt_email_templates WHERE emt_name = ? LIMIT 1');
			$insert = $dblink->prepare('INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
				VALUES (?, ?, ?, ?, now(), now())');

			$footer_body = rtrim((string)file_get_contents($dir . '/mailing_list_footer.html'), "\n");
			$current = $dblink->query("SELECT emt_body FROM emt_email_templates WHERE emt_name = 'default_footer' AND emt_delete_time IS NULL LIMIT 1")->fetchColumn();
			if (is_string($current) && strpos($current, '{mailing_list_id}') !== false) {
				$footer_body = $current;
			}
			$templates = array(
				array('newsletter-1', 2, '*subject*', rtrim((string)file_get_contents($dir . '/newsletter-1.html'), "\n")),
				array('mailing_list_footer', 3, 'Email from Mailing List Footer', $footer_body),
			);
			foreach ($templates as $t) {
				$exists->execute(array($t[0]));
				if (!$exists->fetchColumn()) {
					$insert->execute($t);
				}
			}
			// newsletter-1 as sites were first installed with it named Integral
			// Zen; a row still byte-for-byte that text takes the neutral one
			// (spec email_template_defaults). An edited row, and Integral Zen's
			// own site, are left alone.
			$settings = Globalvars::get_instance();
			$own_site = preg_match('/integral\s*zen/i', (string)$settings->get_setting('site_name'))
				|| stripos((string)$settings->get_setting('webDir'), 'integralzen.org') !== false;
			if (!$own_site) {
				$row = $dblink->query("SELECT emt_email_template_id, emt_subject, emt_body FROM emt_email_templates
					WHERE emt_name = 'newsletter-1' AND emt_delete_time IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
				if ($row && hash('sha256', (string)$row['emt_subject']) === 'b17e168cdaf4ea3b742d3c4e72e27df6aec9199c0fefb71544068455e844669d'
						&& hash('sha256', (string)$row['emt_body']) === '3d5db01fcbbd993ab70f7c542483ba050f17b129b90bcbe9ed688e5444715304') {
					$dblink->prepare('UPDATE emt_email_templates SET emt_subject = ?, emt_body = ?, emt_update_time = now() WHERE emt_email_template_id = ?')
						->execute(array($templates[0][2], $templates[0][3], $row['emt_email_template_id']));
				}
			}
			return true;
		},
	],
];
