<?php
/**
 * Store plugin migrations.
 *
 * @version 1.0 - store_001 adds the renewal receipt and renewal reminder email templates
 */
return [
	[
		// The two templates SubscriptionEmails sends that no site has yet, in
		// plugins/store/email_templates/: 'subscription_renewal_receipt' and
		// 'subscription_renewal_reminder'. Each is added only where no row of
		// its name exists, live or deleted, so an edited or removed one stays
		// as its site left it.
		'id' => 'store_001_subscription_email_templates',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dir = PathHelper::getIncludePath('plugins/store/email_templates');
			$exists = $dblink->prepare('SELECT 1 FROM emt_email_templates WHERE emt_name = ? LIMIT 1');
			$insert = $dblink->prepare('INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
				VALUES (?, ?, ?, ?, now(), now())');
			$templates = array(
				array('subscription_renewal_receipt', 2, 'Your receipt from *site_name*', 'subscription_renewal_receipt.html'),
				array('subscription_renewal_reminder', 2, 'Your subscription renews soon', 'subscription_renewal_reminder.html'),
			);
			foreach ($templates as $t) {
				$exists->execute(array($t[0]));
				if (!$exists->fetchColumn()) {
					$insert->execute(array($t[0], $t[1], $t[2], rtrim((string)file_get_contents($dir . '/' . $t[3]), "\n")));
				}
			}
			return true;
		},
	],
];
