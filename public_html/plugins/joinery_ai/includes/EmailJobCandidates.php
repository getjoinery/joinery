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
 *   - one of the recipe's bound mailboxes (the union across its
 *     `mailbox_aliases` list), not deleted, not spam, and not a draft — a
 *     half-written draft must never reach the model, and marking one handled
 *     would suppress the real judgement of the sent version, which reuses the
 *     same row;
 *   - not already in this recipe's processing log (idempotency). The log is
 *     scoped per RECIPE, so triage/scan/schedule recipes on one mailbox never
 *     suppress each other. Item keys are message ids, unique across mailboxes,
 *     so the log needs no per-mailbox scoping;
 *   - PARSED. An unparsed sealed row has empty content columns; judging one
 *     would produce a verdict on nothing AND log it as handled, so it would
 *     never be judged again once the mail was parsed. Mail parsing runs ahead
 *     of AI work in the same drain, so waiting costs nothing;
 *   - UNREAD. A summary helps you decide whether to open something and a danger
 *     score is no use after you have read it, so read mail needs neither;
 *   - RECENT: received within the recipe's `lookback_days` (default
 *     DEFAULT_LOOKBACK_DAYS). Every candidate costs a model call, and a
 *     mailbox with years of unread mail would otherwise be walked end to end
 *     the moment a recipe was bound to it — for verdicts nobody wants on mail
 *     that old. The floor is rolling, so mail that arrives while a recipe is
 *     paused for longer than the window is never judged either, which is the
 *     same judgement an owner would make. 0 lifts the floor for a recipe
 *     that genuinely means to read everything;
 *   - NEWEST first across the union, so today's mail is handled before the
 *     rest of the window rather than behind it.
 *
 * Sealed mail is readable only when the owner's vault window is actually open
 * in this request. That condition is evaluated live rather than assumed from
 * config, so every path fails closed: a sealed address reaching a command-line
 * worker (which can never hold a window) simply contributes no candidates —
 * the standard addresses on the same list are unaffected — instead of anything
 * reading ciphertext as if it were text.
 *
 * @version 1.3 - never selects a Fortress message
 * @version 1.2
 * @changelog 1.2 - lookback_days floor on iem_received_time (default 7 days)
 */

require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_item_log_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxAliasConfig.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

class EmailJobCandidates {

	/** How far back a recipe reads when its config does not say. */
	const DEFAULT_LOOKBACK_DAYS = 7;

	/**
	 * The recipe's mail-age floor in days, from its `lookback_days` config;
	 * the default when the key is absent (every recipe saved before the field
	 * existed) or not a whole number. 0 means no floor.
	 */
	public static function lookbackDays(array $config): int {
		$raw = $config['lookback_days'] ?? null;
		if ($raw === null || $raw === '' || !is_numeric($raw)) {
			return self::DEFAULT_LOOKBACK_DAYS;
		}
		return max(0, (int)$raw);
	}

	/**
	 * The vault scope a job needs for this binding, or null when it needs none.
	 * Any listed address on a sealed domain (level 'private') puts the
	 * recipe's sealed subset behind the owner's window; a list of standard
	 * addresses needs no window and keeps running unattended on its schedule.
	 * Answered from the LISTED addresses (not the resolved set) so a revoked
	 * grant can't quietly flip a recipe's scheduling posture.
	 */
	public static function requiredVaultScope(array $config): ?string {
		foreach (MailboxAliasConfig::listedAddresses($config) as $address) {
			if (MailboxAliasConfig::isSealedAtRest($address)) {
				return UserEncryptionVault::SCOPE_USER;
			}
		}
		return null;
	}

	/**
	 * Refuse a recipe bound to a sealed mailbox whose domain has not opted in
	 * to AI processing. Called from each job's validateConfig() per listed
	 * address, so the refusal lands when the recipe is SAVED — naming the
	 * domain and the setting — rather than silently producing nothing at run
	 * time.
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
	 * The alias ids this recipe may READ in this request: the resolved bound
	 * set (live grant + liveness checks, see resolveBoundAliases()), with each
	 * sealed address contributing only when its domain has the AI opt-in AND
	 * the owner's vault window is open right now. A sealed address failing
	 * either check silently contributes nothing; the standard addresses on the
	 * same list are unaffected.
	 *
	 * $posture (PipelineJobInterface::POSTURE_*) narrows to one subset:
	 * 'sealed' for the in-window drain and heartbeat, 'standard' for anything
	 * asking what cron can reach, null for everything readable now.
	 */
	public static function readableAliasIds(array $config, int $owner_id, ?string $posture = null): array {
		$resolved = MailboxAliasConfig::resolveBoundAliases($config, $owner_id);
		$ids = [];
		foreach ($resolved as $alias_id => $address) {
			$sealed = MailboxAliasConfig::isSealedAtRest($address);
			if ($posture === PipelineJobInterface::POSTURE_SEALED && !$sealed) continue;
			if ($posture === PipelineJobInterface::POSTURE_STANDARD && $sealed) continue;
			if ($sealed) {
				if (!MailboxAliasConfig::aiProcessingAllowed($address)) continue;
				if ($owner_id <= 0
						|| !VaultUnlock::isOpen($owner_id, UserEncryptionVault::SCOPE_USER)) {
					continue;
				}
			}
			$ids[] = (int)$alias_id;
		}
		return $ids;
	}

	/**
	 * The next message this recipe should judge, or null when it is caught up.
	 *
	 * @param int[] $alias_ids the recipe's readable bound mailboxes (readableAliasIds())
	 * @param int   $recipe_id scopes the processing-log exclusion to this recipe,
	 *                         so triage/scan/schedule recipes on one mailbox never
	 *                         suppress each other
	 * @param int   $owner_id  whose window is consulted for sealed rows
	 * @param int   $lookback_days mail-age floor (lookbackDays()); 0 = none
	 */
	public static function nextId(array $alias_ids, int $recipe_id, int $owner_id,
			int $lookback_days = self::DEFAULT_LOOKBACK_DAYS): ?int {
		if (empty($alias_ids)) return null;
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(self::sql($alias_ids, $owner_id, $lookback_days, 'SELECT iem_inbound_email_message_id', 'LIMIT 1'));
		$q->execute(self::params($alias_ids, $recipe_id, $lookback_days));
		$id = (int)$q->fetchColumn();
		return $id > 0 ? $id : null;
	}

	/**
	 * Is there anything for this recipe to do? Same rules as nextId(), asked
	 * without loading a message — this runs on every vault heartbeat, so it
	 * stays a single indexed existence check.
	 */
	public static function hasCandidate(array $alias_ids, int $recipe_id, int $owner_id,
			int $lookback_days = self::DEFAULT_LOOKBACK_DAYS): bool {
		if (empty($alias_ids)) return false;
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(self::sql($alias_ids, $owner_id, $lookback_days, 'SELECT 1', 'LIMIT 1'));
		$q->execute(self::params($alias_ids, $recipe_id, $lookback_days));
		return (bool)$q->fetchColumn();
	}

	/**
	 * How many messages this recipe still has to judge. Same rules as nextId();
	 * a COUNT rather than an EXISTS, so it is for surfaces that report a backlog,
	 * never for the heartbeat.
	 */
	public static function countCandidates(array $alias_ids, int $recipe_id, int $owner_id,
			int $lookback_days = self::DEFAULT_LOOKBACK_DAYS): int {
		if (empty($alias_ids)) return 0;
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(self::sql($alias_ids, $owner_id, $lookback_days, 'SELECT count(*)', ''));
		$q->execute(self::params($alias_ids, $recipe_id, $lookback_days));
		return (int)$q->fetchColumn();
	}

	/** Named placeholders for the alias set, the log-exclusion recipe id, and the age floor. */
	private static function params(array $alias_ids, int $recipe_id, int $lookback_days): array {
		$params = ['aip_recipe_id' => $recipe_id];
		foreach (array_values($alias_ids) as $i => $alias_id) {
			$params["alias_$i"] = (int)$alias_id;
		}
		if ($lookback_days > 0) {
			// iem_received_time is UTC, like every stored time.
			$params['received_since'] = gmdate('Y-m-d H:i:s', time() - $lookback_days * 86400);
		}
		return $params;
	}

	/**
	 * The shared selection, across the union of the given aliases. $owner_id
	 * decides only whether sealed ROWS are in scope — alias-level sealed
	 * filtering already happened in readableAliasIds(); this row-level guard
	 * stays as defense in depth so a sealed row on a nominally standard alias
	 * (a domain mid-flip) can never be judged without the window.
	 */
	private static function sql(array $alias_ids, int $owner_id, int $lookback_days,
			string $select, string $limit): string {
		$sealed_readable = $owner_id > 0
			&& VaultUnlock::isOpen($owner_id, UserEncryptionVault::SCOPE_USER);

		$placeholders = [];
		foreach (array_values($alias_ids) as $i => $unused) {
			$placeholders[] = ":alias_$i";
		}

		return $select . "
			FROM iem_inbound_email_messages
			WHERE iem_iea_inbound_email_alias_id IN (" . implode(', ', $placeholders) . ")
			  AND iem_delete_time IS NULL
			  AND iem_spam_verdict IS DISTINCT FROM 'spam'
			  AND iem_direction IS DISTINCT FROM 'draft'
			  AND iem_pending_parse IS NOT TRUE
			  -- Fortress mail: sealed to a key only the owner's devices hold, and
			  -- never read by server-side AI (specs/client_custody_mail.md § R7).
			  AND (iem_sealed_key IS NULL OR iem_sealed_key NOT LIKE 'v1.edgeseal.%')
			  AND iem_is_read = false
			  " . ($lookback_days > 0 ? 'AND iem_received_time >= :received_since' : '') . "
			  " . ($sealed_readable ? '' : 'AND iem_content_sealed IS NOT TRUE') . "
			  AND " . MultiAipRecipeItemLog::notExistsClause('iem_inbound_email_message_id::text') . "
			" . ($limit === '' ? '' : 'ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC')
			. ' ' . $limit;
	}
}
?>
