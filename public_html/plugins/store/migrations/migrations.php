<?php
/**
 * Store plugin migrations.
 *
 * @version 1.1 - store_002 carries the receipt and reminder marks to the outbox and drops their columns
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
	[
		// The receipt and the reminder move to the outbox (specs/email_outbox.md
		// §9.2): a receipt mark (on the subscription's oldest line) becomes a
		// Sent row about that line, key receipt:<invoice id>; a reminder mark
		// is copied from every line that has one, key renewal:<period end> in
		// SubscriptionEmails::period_key()'s form, so whichever line is later
		// picked as the anchor has its row. Then both columns go. One
		// transaction: a failure leaves nothing half done.
		'id' => 'store_002_subscription_email_marks_to_outbox',
		'version' => '1.6.53',
		'up' => function($dbconnector) {
			$db = $dbconnector->get_db_link();
			$column = function ($name) use ($db) {
				$q = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = 'odi_order_items' AND column_name = ?");
				$q->execute(array($name));
				return (bool)$q->fetchColumn();
			};
			$receipts = $column('odi_receipted_invoice_id');
			$reminders = $column('odi_renewal_reminded_for');
			if (!$receipts && !$reminders) {
				return;
			}
			$settings = Globalvars::get_instance();
			$from = (string)$settings->get_setting('defaultemail');
			$from_name = mb_substr((string)($settings->get_setting('defaultemailname') ?: $from), 0, 255);
			DbConnector::transaction(function ($db) use ($from, $from_name, $receipts, $reminders) {
				$insert = "INSERT INTO equ_queued_emails (equ_from, equ_from_name, equ_to, equ_to_name, equ_subject,
						equ_status, equ_retry_count, equ_timestamp, equ_sent_time, equ_service, equ_entity_type, equ_entity_id,
						equ_once_key, equ_template, equ_body)
					SELECT ?, ?, trim(u.usr_email), trim(u.usr_email),
						coalesce((SELECT nullif(t.emt_subject, '') FROM emt_email_templates t WHERE t.emt_name = ? ORDER BY t.emt_email_template_id LIMIT 1),
							'(sent before the outbox)'),
						4, 0, now(), now(), 'carried', 'odi_order_items', o.odi_order_item_id, %s, ?, NULL
					FROM odi_order_items o JOIN usr_users u ON u.usr_user_id = o.odi_usr_user_id
					WHERE %s AND coalesce(trim(u.usr_email), '') <> ''
					ON CONFLICT (equ_entity_type, equ_entity_id, equ_once_key) DO NOTHING";
				$carried = 0;
				if ($receipts) {
					$q = $db->prepare(sprintf($insert, "'receipt:' || o.odi_receipted_invoice_id",
						"coalesce(o.odi_receipted_invoice_id, '') <> ''"));
					$q->execute(array($from, $from_name, 'subscription_renewal_receipt', 'subscription_renewal_receipt'));
					$carried += $q->rowCount();
				}
				if ($reminders) {
					$q = $db->prepare(sprintf($insert, "'renewal:' || to_char(o.odi_renewal_reminded_for, 'YYYY-MM-DD HH24:MI:SS')",
						'o.odi_renewal_reminded_for IS NOT NULL'));
					$q->execute(array($from, $from_name, 'subscription_renewal_reminder', 'subscription_renewal_reminder'));
					$carried += $q->rowCount();
				}
				$db->exec('ALTER TABLE odi_order_items DROP COLUMN IF EXISTS odi_receipted_invoice_id');
				$db->exec('ALTER TABLE odi_order_items DROP COLUMN IF EXISTS odi_renewal_reminded_for');
				error_log('store_002: ' . $carried . ' subscription email mark(s) carried to the outbox; their columns dropped.');
			});
		},
	],
];
