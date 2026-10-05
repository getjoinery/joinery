<?php
/**
 * Shared fixtures for the mailbox test suites. Not a test file (no
 * @joinery-test header) — required by a mailbox test after harness_boot().
 */

/**
 * Insert a throwaway user directly, bypassing the User model's email-
 * deliverability validation (test domains have no MX, so User::save() would
 * reject them). A model loaded from this id still exercises the real
 * permanent_delete() cascade — this only sidesteps ingress validation.
 *
 * The caller owns teardown (delete by id, or a preClean by email prefix), as
 * the mailbox suites already do; this helper does not auto-register a row.
 *
 * @param string $email
 * @param int    $permission  usr_permission (default 5)
 * @param string $first_name  usr_first_name label (default 'MbTest')
 * @return int   the new usr_user_id
 */
function mailbox_make_user(string $email, int $permission = 5, string $first_name = 'MbTest'): int {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare("INSERT INTO usr_users
		(usr_first_name, usr_email, usr_timezone, usr_permission)
		VALUES (?, ?, 'UTC', ?) RETURNING usr_user_id");
	$stmt->execute(array($first_name, $email, $permission));
	return (int)$stmt->fetchColumn();
}

/**
 * Best-effort removal of leftover inbound-mail fixtures for a set of test
 * domains, matched by a `ied_domain LIKE` pattern. Cascades in FK-safe order:
 * attachments -> grants -> send attempts -> messages -> aliases -> domains.
 * A forward records a send attempt against its alias (MailboxSendAttempt),
 * so a suite that forwards leaves one per alias. Every step is a
 * no-op when there is nothing to remove, so one call serves suites that create
 * only domains+aliases as well as suites that also store messages/attachments.
 *
 * This is a preClean helper — it sweeps orphans a previous crashed run may have
 * left, so it swallows its own errors and never throws into a test.
 *
 * @param string      $domain_like       e.g. 'att-test-%'
 * @param string|null $user_email_like   when set, also DELETE usr_users LIKE it
 *                                        (e.g. 'att\_%@example.test'); null skips
 * @param bool        $purge_orphan_grants  when true, first remove grants whose
 *                                        alias no longer exists (a global sweep)
 */
function mailbox_purge_domains(string $domain_like, ?string $user_email_like = null, bool $purge_orphan_grants = false): void {
	$db = DbConnector::get_instance()->get_db_link();
	try {
		if ($purge_orphan_grants) {
			$db->exec("DELETE FROM ieg_inbound_email_mailbox_grants
				WHERE ieg_iea_inbound_email_alias_id NOT IN
				(SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases)");
		}

		$q = $db->prepare("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains WHERE ied_domain LIKE ?");
		$q->execute(array($domain_like));
		$dids = $q->fetchAll(PDO::FETCH_COLUMN);
		if ($dids) {
			$in = implode(',', array_map('intval', $dids));

			$mids = $db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				WHERE iem_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
			if ($mids) {
				$min = implode(',', array_map('intval', $mids));
				$db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN ($min)");
			}

			$aids = $db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
				WHERE iea_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
			if ($aids) {
				$ain = implode(',', array_map('intval', $aids));
				$db->exec("DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id IN ($ain)");
				$db->exec("DELETE FROM mst_mailbox_send_attempts WHERE mst_iea_inbound_email_alias_id IN ($ain)");
			}

			try {
				$db->exec("DELETE FROM ifd_inbound_forward_destinations WHERE ifd_ied_inbound_email_domain_id IN ($in)");
			} catch (\Throwable $e) {
				// a test database copied before the table existed
			}
			$db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id IN ($in)");
			$db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id IN ($in)");
			$db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id IN ($in)");
		}

		if ($user_email_like !== null) {
			$uq = $db->prepare("DELETE FROM usr_users WHERE usr_email LIKE ?");
			$uq->execute(array($user_email_like));
		}
	} catch (\Throwable $e) {
		// preClean is best-effort — never let orphan-sweeping fail a test.
	}
}

/**
 * Record a forwarding destination as confirmed, as the person at it would by
 * following the confirmation link (specs/relay_receive_only_forwarding.md,
 * rule 4). Forwarding tests call it for the destinations they forward to;
 * an unconfirmed destination receives nothing.
 *
 * @param int|null $alias_id null = the domain's catch-all
 */
function mailbox_confirm_forward_destination(int $domain_id, ?int $alias_id, string $destination): void {
	$row = InboundForwardDestination::find($domain_id, $alias_id, $destination);
	if (!$row) {
		$row = new InboundForwardDestination(NULL);
		$row->set('ifd_ied_inbound_email_domain_id', $domain_id);
		$row->set('ifd_iea_inbound_email_alias_id', $alias_id);
		$row->set('ifd_destination', $destination);
	}
	$row->set('ifd_status', InboundForwardDestination::STATUS_CONFIRMED);
	$row->set('ifd_confirmed_time', gmdate('Y-m-d H:i:s'));
	$row->save();
}

/** Confirm every destination of a forwarding mailbox. */
function mailbox_confirm_alias_destinations(InboundEmailAlias $alias): void {
	foreach ($alias->get_destinations_array() as $dest) {
		mailbox_confirm_forward_destination(intval($alias->get('iea_ied_inbound_email_domain_id')), intval($alias->key), $dest);
	}
}
