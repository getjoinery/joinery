<?php
/**
 * EmailJobCandidates — the one place that decides which message an AI email
 * job looks at next (specs/in_window_deferred_work.md § Which messages they
 * pick up).
 *
 * All three email jobs (triage, security scan, schedule) select from the same
 * pool by the same rules, and the spec requires they keep agreeing — a job that
 * drifted would judge mail its siblings skip, or stall behind mail they have
 * already handled. So the query lives here once rather than three times.
 *
 * The rules:
 *   - the recipe's own mailbox, not deleted, not spam, and not a draft — a
 *     half-written draft must never reach the model, and marking one handled
 *     would suppress the real judgement of the sent version, which reuses the
 *     same row;
 *   - not already in this recipe's processing log (idempotency). The log is
 *     scoped per RECIPE, so triage/scan/schedule recipes on one mailbox never
 *     suppress each other;
 *   - PARSED. An unparsed Fortress row has empty content columns; judging one
 *     would produce a verdict on nothing AND log it as handled, so it would
 *     never be judged again once the mail was parsed. Mail parsing runs ahead
 *     of AI work in the same drain, so waiting costs nothing;
 *   - UNREAD. A summary helps you decide whether to open something and a danger
 *     score is no use after you have read it, so read mail needs neither;
 *   - NEWEST first, so today's mail is handled before a backlog rather than
 *     behind one.
 *
 * Sealed mail is readable only when the owner's vault window is actually open
 * in this request. That condition is evaluated live rather than assumed from
 * config, so every path fails closed: a sealed-domain recipe reaching a
 * command-line worker (which can never hold a window) simply finds no
 * candidates instead of reading ciphertext as if it were text.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/aip_recipe_item_log_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxAliasConfig.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

class EmailJobCandidates {

	/**
	 * The vault scope a job needs for this mailbox, or null when it needs none.
	 * Sealed domains ('private', 'fortress') need the owner's window; a
	 * standard domain's mail is already readable, so those recipes keep running
	 * unattended on their schedule exactly as before.
	 */
	public static function requiredVaultScope(array $config): ?string {
		$address = (string)($config['mailbox_alias'] ?? '');
		if ($address === '') {
			return null;
		}
		return MailboxAliasConfig::isSealedAtRest($address)
			? UserEncryptionVault::SCOPE_USER
			: null;
	}

	/**
	 * Refuse a recipe bound to a sealed mailbox whose domain has not opted in
	 * to AI processing. Called from each job's validateConfig(), so the refusal
	 * lands when the recipe is SAVED — naming the domain and the setting —
	 * rather than silently producing nothing at run time.
	 */
	public static function assertAiProcessingAllowed(string $address): void {
		if (MailboxAliasConfig::aiProcessingAllowed($address)) {
			return;
		}
		$domain = substr(strrchr('@' . $address, '@'), 1);
		throw new InvalidArgumentException(
			"Mail for $domain is encrypted at rest, and this domain has not been set to let "
			. "Joinery AI read it. Turn on \"Let Joinery AI read this domain's mail while your "
			. "vault is unlocked\" for $domain first, or point this recipe at a mailbox on a "
			. 'domain that is not encrypted at rest.');
	}

	/**
	 * The next message this recipe should judge, or null when it is caught up.
	 *
	 * @param int $alias_id  the recipe's bound mailbox
	 * @param int $recipe_id scopes the processing-log exclusion to this recipe,
	 *                       so triage/scan/schedule recipes on one mailbox never
	 *                       suppress each other
	 * @param int $owner_id  whose window is consulted for sealed mail
	 */
	public static function nextId(int $alias_id, int $recipe_id, int $owner_id): ?int {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(self::sql($owner_id, 'SELECT iem_inbound_email_message_id', 'LIMIT 1'));
		$q->execute(['alias_id' => $alias_id, 'aip_recipe_id' => $recipe_id]);
		$id = (int)$q->fetchColumn();
		return $id > 0 ? $id : null;
	}

	/**
	 * Is there anything for this recipe to do? Same rules as nextId(), asked
	 * without loading a message — this runs on every vault heartbeat, so it
	 * stays a single indexed existence check.
	 */
	public static function hasCandidate(int $alias_id, int $recipe_id, int $owner_id): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(self::sql($owner_id, 'SELECT 1', 'LIMIT 1'));
		$q->execute(['alias_id' => $alias_id, 'aip_recipe_id' => $recipe_id]);
		return (bool)$q->fetchColumn();
	}

	/**
	 * How many messages this recipe still has to judge. Same rules as nextId();
	 * a COUNT rather than an EXISTS, so it is for surfaces that report a backlog,
	 * never for the heartbeat.
	 */
	public static function countCandidates(int $alias_id, int $recipe_id, int $owner_id): int {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(self::sql($owner_id, 'SELECT count(*)', ''));
		$q->execute(['alias_id' => $alias_id, 'aip_recipe_id' => $recipe_id]);
		return (int)$q->fetchColumn();
	}

	/**
	 * The shared selection. $owner_id decides only whether sealed rows are in
	 * scope — see the class note on failing closed.
	 */
	private static function sql(int $owner_id, string $select, string $limit): string {
		$sealed_readable = $owner_id > 0
			&& VaultUnlock::isOpen($owner_id, UserEncryptionVault::SCOPE_USER);

		return $select . "
			FROM iem_inbound_email_messages
			WHERE iem_iea_inbound_email_alias_id = :alias_id
			  AND iem_delete_time IS NULL
			  AND iem_spam_verdict IS DISTINCT FROM 'spam'
			  AND iem_direction IS DISTINCT FROM 'draft'
			  AND iem_pending_parse IS NOT TRUE
			  AND iem_is_read = false
			  " . ($sealed_readable ? '' : 'AND iem_content_sealed IS NOT TRUE') . "
			  AND " . MultiAipRecipeItemLog::notExistsClause('iem_inbound_email_message_id::text') . "
			" . ($limit === '' ? '' : 'ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC')
			. ' ' . $limit;
	}
}
?>
