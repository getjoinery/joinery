<?php
/**
 * Copy past direct messages into their own history (spec email_lists_plugin
 * §3, §6).
 *
 * Before DirectMessage, a message to one member, a group or an event's people
 * was written as a campaign Email row with recipient groups. The group, event
 * and user pages now read DirectMessage, so each sent Email whose audience is
 * only user, group, event or event_waiting_list (no mailing list) is copied:
 * one DirectMessage, and one DirectMessageRecipient per EmailRecipient.
 *
 * The primary audience is the group or event; for a one-person message, the
 * person who is not the sender (the sender's own copy is a recipient too).
 *
 * Only sent Emails are copied. One still queued when the site upgrades is
 * delivered by the campaign send and is not in this history; the window is
 * the minute between its queueing and the next send pass, and accepted.
 *
 * Idempotent: a copied Email is skipped by dmg_eml_email_id. Skips cleanly
 * on a database without the campaign tables.
 */
function copy_direct_messages_to_history() {
	$db = DbConnector::get_instance()->get_db_link();

	foreach (array('eml_emails', 'erg_email_recipient_groups', 'erc_email_recipients', 'dmg_direct_messages', 'dmr_direct_message_recipients') as $table) {
		if (!$db->query("SELECT to_regclass('public.$table') IS NOT NULL")->fetchColumn()) {
			echo "$table is not here — nothing to copy\n";
			return true;
		}
	}

	$q = $db->query("SELECT e.eml_email_id, e.eml_usr_user_id, e.eml_subject, e.eml_message_html, e.eml_message_template_html,
			e.eml_scheduled_time, e.eml_sent_time
		FROM eml_emails e
		WHERE e.eml_status = 10 AND e.eml_delete_time IS NULL AND e.eml_mlt_mailing_list_id IS NULL
		  AND EXISTS (SELECT 1 FROM erg_email_recipient_groups g WHERE g.erg_eml_email_id = e.eml_email_id)
		  AND NOT EXISTS (SELECT 1 FROM erg_email_recipient_groups g WHERE g.erg_eml_email_id = e.eml_email_id
			AND (g.erg_provider IS NULL OR g.erg_provider NOT IN ('user', 'group', 'event', 'event_waiting_list')
				OR COALESCE(g.erg_operation, 'add') <> 'add'))
		  AND NOT EXISTS (SELECT 1 FROM dmg_direct_messages d WHERE d.dmg_eml_email_id = e.eml_email_id)
		ORDER BY e.eml_email_id");
	$emails = $q->fetchAll(PDO::FETCH_ASSOC);

	$groups_q = $db->prepare("SELECT erg_provider, erg_reference_id FROM erg_email_recipient_groups
		WHERE erg_eml_email_id = ? ORDER BY erg_email_recipient_group_id");
	$recipients_q = $db->prepare("SELECT erc_usr_user_id, erc_email, erc_name, erc_status, erc_sent_time
		FROM erc_email_recipients WHERE erc_eml_email_id = ? ORDER BY erc_email_recipient_id");
	$insert_message = $db->prepare("INSERT INTO dmg_direct_messages
		(dmg_usr_user_id_sender, dmg_subject, dmg_message_html, dmg_inner_template, dmg_provider, dmg_reference_id,
		 dmg_recipient_count, dmg_status, dmg_create_time, dmg_sent_time, dmg_eml_email_id)
		VALUES (?, ?, ?, ?, ?, ?, ?, 2, COALESCE(?::timestamp, ?::timestamp, now()), ?, ?)
		RETURNING dmg_direct_message_id");
	$insert_recipient = $db->prepare("INSERT INTO dmr_direct_message_recipients
		(dmr_dmg_direct_message_id, dmr_usr_user_id, dmr_email, dmr_name, dmr_status, dmr_sent_time)
		VALUES (?, ?, ?, ?, ?, ?)");

	$copied = 0;
	foreach ($emails as $email) {
		$groups_q->execute(array($email['eml_email_id']));
		$groups = $groups_q->fetchAll(PDO::FETCH_ASSOC);

		$primary = null;
		foreach ($groups as $g) {
			if ($g['erg_provider'] !== 'user') {
				$primary = $g;
				break;
			}
		}
		if ($primary === null) {
			foreach ($groups as $g) {
				if ((int)$g['erg_reference_id'] !== (int)$email['eml_usr_user_id']) {
					$primary = $g;
					break;
				}
			}
		}
		if ($primary === null) {
			$primary = $groups[0];
		}

		$recipients_q->execute(array($email['eml_email_id']));
		$recipients = $recipients_q->fetchAll(PDO::FETCH_ASSOC);

		try {
			DbConnector::transaction(function () use ($insert_message, $insert_recipient, $email, $primary, $recipients) {
				$insert_message->execute(array(
					$email['eml_usr_user_id'] ?: null,
					mb_substr((string)$email['eml_subject'], 0, 255) ?: '(no subject)',
					(string)$email['eml_message_html'],
					mb_substr((string)$email['eml_message_template_html'], 0, 255) ?: null,
					$primary['erg_provider'],
					(int)$primary['erg_reference_id'],
					count($recipients),
					$email['eml_scheduled_time'], $email['eml_sent_time'],
					$email['eml_sent_time'],
					$email['eml_email_id'],
				));
				$message_id = (int)$insert_message->fetchColumn();
				foreach ($recipients as $r) {
					// EmailRecipient: 1 sent, 2 unsubscribed, 3 failed, unset never tried.
					$status = ((int)$r['erc_status'] === 1) ? 3 : 4;
					$insert_recipient->execute(array(
						$message_id,
						$r['erc_usr_user_id'] ?: null,
						mb_substr(strtolower(trim((string)$r['erc_email'])), 0, 255),
						mb_substr((string)$r['erc_name'], 0, 255) ?: null,
						$status,
						$status === 3 ? $r['erc_sent_time'] : null,
					));
				}
			});
			$copied++;
		} catch (Throwable $e) {
			echo "Email " . $email['eml_email_id'] . " not copied: " . $e->getMessage() . "\n";
			return false;
		}
	}

	echo "Copied $copied past direct message(s) into the direct-message history\n";
	return true;
}
