<?php
/**
 * RecipeVaultScope — which recipes need an unlocked vault, and running them
 * (specs/in_window_deferred_work.md § Feature 2).
 *
 * A pipeline job declares the vault scope its items need. When it declares one,
 * that recipe can never run from cron: the vault secret lives in APCu keyed to
 * the browser session, so a command-line worker holds no window and would read
 * nothing. Those recipes are skipped by the dispatcher, refused by the spawner,
 * and executed here instead — in slices, inside the owner's own request, while
 * their window is open.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));

class RecipeVaultScope {

	/**
	 * The vault scope this recipe needs, or null when it needs none.
	 *
	 * Null for every agent-mode recipe and for any pipeline job whose binding
	 * doesn't touch sealed content — those keep running on their schedule.
	 * A job that cannot be resolved (removed plugin, renamed job) is treated as
	 * needing no scope: it will fail its own way at run time, and guessing
	 * "needs the vault" here would silently strand a schedule.
	 */
	public static function forRecipe(Recipe $recipe): ?string {
		try {
			return self::scopeOrThrow($recipe);
		} catch (\Throwable $e) {
			error_log('RecipeVaultScope: scope check failed for recipe '
				. (int)$recipe->key . ': ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * The same question, without the swallow.
	 *
	 * forRecipe() answers for SCHEDULING, where an unanswerable question has to
	 * resolve to "no window needed" or a transient fault strands a schedule
	 * indefinitely. A CONSENT gate needs the opposite: an unanswerable question
	 * must not read as permission. Same computation, opposite failure
	 * direction, so the caller picks rather than the callee guessing.
	 * Public because RunContentPurge needs the strict direction too: a recipe
	 * whose scope cannot be answered cannot be proven clean, so its old run
	 * content is cleared rather than kept.
	 */
	public static function scopeOrThrow(Recipe $recipe): ?string {
		// AGENT MODE IS OUT OF SCOPE HERE, AND THAT RESTS ON AN INVARIANT.
		//
		// An agent recipe reaches mail through the generic query_model tool, and
		// InboundEmailMessage is $ai_readable with sealed fields — so
		// ModelQueryExecutor WILL decrypt for it whenever an unlock window
		// happens to be open. Nothing in this file stops that; what stops it is
		// where agent runs execute. A CLI worker holds no window (APCu is
		// session-keyed), and the only in-window execution path — the deferred
		// work drain — runs pipeline recipes exclusively.
		//
		// The day any agent run executes inside a web request with the owner's
		// window open, a cloud-model agent recipe ships decrypted sealed mail
		// off-box with no consent check at all. Whoever changes that must gate
		// it here first. `sealed_egress` in the in_window_email suite pins the
		// invariant so the change cannot land quietly.
		if ((string)$recipe->get('rcp_mode') !== Recipe::MODE_PIPELINE) {
			return null;
		}
		$job = self::job($recipe);
		if ($job === null) {
			return null;
		}
		return $job->requiresVaultScope(self::config($recipe));
	}

	/**
	 * The recipe's job, or null when it cannot be resolved (empty job id,
	 * uninstalled plugin, renamed job).
	 *
	 * Deliberately silent: this runs on a hot path — every heartbeat, for every
	 * one of the user's recipes — and a recipe with an unresolvable job already
	 * reports itself properly when the runner tries to execute it. Logging here
	 * as well would repeat the same fact every 25 seconds.
	 */
	private static function job(Recipe $recipe): ?PipelineJobInterface {
		$job_id = trim((string)$recipe->get('rcp_pipeline_job'));
		if ($job_id === '') {
			return null;
		}
		try {
			$job = PipelineJobRegistry::get($job_id);
		} catch (\Throwable $e) {
			return null;
		}
		return $job instanceof PipelineJobInterface ? $job : null;
	}

	/**
	 * The recipe's stored binding, uncoerced.
	 *
	 * DescriptorValidator::coerce() is a SAVE-time check: it validates the value
	 * against the current option list, which costs a query per recipe and throws
	 * when a recipe points at a mailbox that has since been renamed or disabled.
	 * Asking which mailbox a recipe is bound to needs neither — the jobs read the
	 * value defensively, and a stale binding resolves to no alias and therefore
	 * no work.
	 */
	private static function config(Recipe $recipe): array {
		$config = Recipe::decodeSourceConfig($recipe);
		return is_array($config) ? $config : array();
	}

	/** Convenience for the dispatcher and spawner. */
	public static function requiresWindow(Recipe $recipe): bool {
		return self::forRecipe($recipe) !== null;
	}

	/**
	 * Refuse a recipe that would send sealed content to a model off the box.
	 *
	 * The recipe analogue of LlmProviderFactory::forConversation()'s Fortress
	 * pin. Chat pins Fortress conversations to local hardware outright; a recipe
	 * is allowed to use a cloud model, but only where the domain it reads has
	 * given the second, explicit consent (specs/implemented/sealed_content_egress.md,
	 * resolved decision 5).
	 *
	 * This is sink zero: it precedes every storage sink, and no storage-side
	 * guard can see it, because the plaintext leaves over HTTPS rather than into
	 * a column.
	 *
	 * Called at recipe save AND at run start. Both, deliberately: the save-time
	 * check is where the admin gets a comprehensible error, and the run-start
	 * re-check is what makes withdrawing a domain's consent actually stop the
	 * next run rather than leaving an already-saved recipe shipping mail to a
	 * vendor. Same one-way-tightening rule as the taint gate.
	 *
	 * @throws LlmProviderException when the pairing is refused
	 */
	public static function assertModelAllowed(Recipe $recipe): void {
		require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

		// Fail CLOSED: an unanswerable scope check is treated as "reads
		// protected content", so a job that throws cannot be the way a cloud
		// model gets handed sealed mail. The scheduling path makes the opposite
		// choice for its own good reasons — see scopeOrThrow().
		try {
			if (self::scopeOrThrow($recipe) === null) {
				return; // nothing sealed in play — any model may read it
			}
		} catch (\Throwable $e) {
			error_log('RecipeVaultScope: assuming protected content for recipe '
				. (int)$recipe->key . ' — scope check failed: ' . $e->getMessage());
		}
		$model = trim((string)$recipe->get('rcp_model'));
		if (!LlmProviderFactory::isCloudModel($model)) {
			return; // stays on the operator's hardware
		}

		// Past this point the recipe reads protected content on a cloud model, so
		// the ONLY thing that permits it is a domain that has said yes. A job
		// that cannot be resolved, or that throws while answering, cannot say
		// yes on that domain's behalf — so it does not.
		$consented = false;
		try {
			$job = self::job($recipe);
			$consented = ($job !== null) && $job->cloudProcessingAllowed(self::config($recipe));
		} catch (\Throwable $e) {
			error_log('RecipeVaultScope: cloud-consent check failed for recipe '
				. (int)$recipe->key . ': ' . $e->getMessage());
		}
		if ($consented) {
			return;
		}

		// An unpinned recipe follows the site's default provider, so name that
		// rather than an empty quotation mark pair the admin cannot act on.
		$what = $model !== ''
			? '“' . $model . '” runs on someone else\'s hardware'
			: 'this recipe has no model pinned, so it follows the site default provider, '
			  . 'which runs on someone else\'s hardware';

		throw new LlmProviderException(
			'This recipe reads mail that is encrypted at rest, and ' . $what . ' — so running '
			. 'it would send the decrypted mail off this server. Either pin a local model, or '
			. 'turn on “Send this domain\'s decrypted mail to cloud AI models” for the domain '
			. 'this mailbox belongs to.'
		);
	}

	/**
	 * Enabled, window-requiring recipes owned by this user that currently have
	 * something to do. Used by both the work predicate and the drain, so the
	 * heartbeat and the drain always agree about whether there is work.
	 *
	 * @return Recipe[]
	 */
	public static function pendingForOwner(int $user_id): array {
		if ($user_id <= 0) {
			return array();
		}
		$recipes = new MultiRecipe(array('enabled' => true, 'deleted' => false, 'owner_user_id' => $user_id));
		$recipes->load();

		$pending = array();
		foreach ($recipes as $recipe) {
			if (!self::requiresWindow($recipe)) {
				continue;
			}
			$job = self::job($recipe);
			if ($job === null) {
				continue;
			}
			try {
				if ($job->hasWork(self::config($recipe), $recipe)) {
					$pending[] = $recipe;
				}
			} catch (\Throwable $e) {
				error_log('RecipeVaultScope: work check failed for recipe '
					. (int)$recipe->key . ': ' . $e->getMessage());
			}
		}
		return $pending;
	}

	/** Does this user have any in-window recipe work waiting? */
	public static function hasWork(int $user_id): bool {
		return count(self::pendingForOwner($user_id)) > 0;
	}

	/**
	 * How many items are waiting across this user's in-window recipes.
	 *
	 * For surfaces that tell someone how far behind they are — never the
	 * heartbeat, which asks the cheaper hasWork(). Three recipes on one mailbox
	 * each count the same message once, which is correct: each has to judge it
	 * separately.
	 */
	public static function outstandingItemCount(int $user_id): int {
		$total = 0;
		foreach (self::pendingForOwner($user_id) as $recipe) {
			$job = self::job($recipe);
			if ($job === null) {
				continue;
			}
			try {
				$total += $job->countWork(self::config($recipe), $recipe);
			} catch (\Throwable $e) {
				error_log('RecipeVaultScope: count failed for recipe '
					. (int)$recipe->key . ': ' . $e->getMessage());
			}
		}
		return $total;
	}

	/**
	 * Run in-window recipes for this user until $deadline. Returns the number
	 * of recipe runs executed (not items — each run's own tally records those).
	 *
	 * Each recipe gets its own RecipeRun row, exactly as a scheduled run would,
	 * so run history, token accounting, the kill switch, and delivery all work
	 * unchanged. The trigger is recorded as 'window' to distinguish these from
	 * schedule and manual runs.
	 *
	 * A recipe already mid-run elsewhere is skipped rather than doubled up.
	 */
	public static function drain(int $user_id, string $secret_key, float $deadline): int {
		$ran = 0;
		foreach (self::pendingForOwner($user_id) as $recipe) {
			if (microtime(true) >= $deadline) {
				break;
			}
			if (self::hasActiveRun((int)$recipe->key)) {
				continue;
			}
			$run = new RecipeRun(NULL);
			$run->set('rcr_rcp_recipe_id', (int)$recipe->key);
			$run->set('rcr_status', RecipeRun::STATUS_PENDING);
			$run->set('rcr_trigger', RecipeRun::TRIGGER_WINDOW);
			$run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
			$run->prepare();
			$run->save();

			require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunner.php'));
			RecipeRunner::run($run, $deadline);
			$ran++;
		}
		return $ran;
	}

	private static function hasActiveRun(int $recipe_id): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT 1 FROM rcr_recipe_runs
			  WHERE rcr_rcp_recipe_id = ?
			    AND rcr_status IN (?, ?)
			    AND rcr_delete_time IS NULL
			  LIMIT 1");
		$q->execute(array($recipe_id, RecipeRun::STATUS_PENDING, RecipeRun::STATUS_RUNNING));
		return (bool)$q->fetchColumn();
	}
}
?>
