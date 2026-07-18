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
 * attachments -> grants -> messages -> aliases -> domains. Every step is a
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
