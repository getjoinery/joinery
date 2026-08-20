<?php
/**
 * RecipeVaultScope — which recipes need an unlocked vault, and running them
 * (specs/in_window_deferred_work.md § Feature 2).
 *
 * A pipeline job declares the vault scope its items need. When it declares one,
 * that recipe's sealed sources can never be read from cron: the vault secret
 * lives in APCu keyed to the browser session, so a command-line worker holds no
 * window and would read nothing. The sealed subset executes here instead — in
 * slices, inside the owner's own request, while their window is open. A recipe
 * whose ENTIRE binding is sealed (cronRunnable() false) is skipped by the
 * dispatcher and refused by the spawner outright; a mixed binding still runs
 * from cron for its standard remainder.
 *
 * @version 1.2.0
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
		// The job resolves WITHOUT the swallow here, unlike job(): a registry
		// failure must throw so the consent path treats it as protected, not as
		// "needs nothing". A recipe with an EMPTY job id genuinely declares no
		// job, needs no scope, and can produce no items — that is a clean null.
		$job_id = trim((string)$recipe->get('rcp_pipeline_job'));
		if ($job_id === '') {
			return null;
		}
		$job = PipelineJobRegistry::get($job_id);
		if (!($job instanceof PipelineJobInterface)) {
			throw new RuntimeException("Pipeline job '$job_id' did not resolve to a job.");
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

	/** Does any part of this recipe's binding need an unlock window? */
	public static function requiresWindow(Recipe $recipe): bool {
		return self::forRecipe($recipe) !== null;
	}

	/**
	 * May the dispatcher schedule this recipe and the spawner give it a CLI
	 * worker? Yes unless NOTHING in its binding is readable without a window —
	 * a mixed binding (sealed and standard mailboxes on one list) runs from
	 * cron normally, where its sealed subset fails closed out of the candidate
	 * set and the standard remainder drains
	 * (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md § the scheduling split).
	 *
	 * Failure direction matches forRecipe(): an unanswerable question resolves
	 * to "runnable", because guessing "sealed-only" here would silently strand
	 * a schedule, while a wrongly spawned run just reports itself caught up.
	 */
	public static function cronRunnable(Recipe $recipe): bool {
		if (!self::requiresWindow($recipe)) {
			return true;
		}
		$job = self::job($recipe);
		if ($job === null) {
			return true;
		}
		try {
			return $job->hasUnsealedBinding(self::config($recipe));
		} catch (\Throwable $e) {
			error_log('RecipeVaultScope: unsealed-binding check failed for recipe '
				. (int)$recipe->key . ': ' . $e->getMessage());
			return true;
		}
	}

	/**
	 * How far this recipe's content may travel, as a trust floor — or null when
	 * it reads nothing sealed and so imposes none.
	 *
	 * Fails CLOSED: an unanswerable scope check, or a job that throws while
	 * answering, is treated as "reads protected content that must stay on the
	 * box". A job that cannot be resolved cannot say yes on a domain's behalf.
	 * The scheduling path makes the opposite choice for its own good reasons —
	 * see scopeOrThrow().
	 */
	public static function consentTrustFloor(Recipe $recipe): ?string {
		try {
			if (self::scopeOrThrow($recipe) === null) {
				return null;   // nothing sealed in play — any model may read it
			}
		} catch (\Throwable $e) {
			error_log('RecipeVaultScope: assuming protected content for recipe '
				. (int)$recipe->key . ' — scope check failed: ' . $e->getMessage());
		}

		$consent = InboundEmailDomain::CONSENT_LOCAL;
		try {
			$job = self::job($recipe);
			if ($job !== null) {
				$consent = (string)$job->processingConsent(self::config($recipe));
			}
		} catch (\Throwable $e) {
			error_log('RecipeVaultScope: consent check failed for recipe '
				. (int)$recipe->key . ': ' . $e->getMessage());
			$consent = InboundEmailDomain::CONSENT_LOCAL;
		}
		if (!in_array($consent, InboundEmailDomain::CONSENTS, true)) {
			$consent = InboundEmailDomain::CONSENT_LOCAL;
		}

		// Consent names the most permissive endpoint class the mail may reach;
		// a requirement names a floor. 'cloud' consent imposes no floor at all.
		return $consent === InboundEmailDomain::CONSENT_CLOUD
			? AiModelRequirement::TRUST_ANY : $consent;
	}

	/**
	 * The recipe's requirement with the sealed-content consent folded in.
	 *
	 * The consent is one more trust floor, and the STRICTEST of the recipe's own
	 * floor and the domains' consent wins. Folding it into the requirement
	 * rather than checking it afterwards is what makes sink zero structural: the
	 * resolver cannot select an endpoint the domain refused, because such an
	 * endpoint is filtered out before there is anything to choose from.
	 */
	public static function requirementFor(Recipe $recipe): AiModelRequirement {
		$req = AiModelRequirementBuilder::forRecipe($recipe);
		$floor = self::consentTrustFloor($recipe);
		return $floor === null ? $req : $req->tightenTrustFloor($floor);
	}

	/**
	 * Resolve the model this recipe runs on — the ONE resolution a run uses.
	 *
	 * This is sink zero: it precedes every storage sink, and no storage-side
	 * guard can see it, because the plaintext leaves over HTTPS rather than into
	 * a column. The recipe analogue of the Fortress chat pin — chat pins a
	 * Fortress conversation to local hardware outright; a recipe may use an
	 * off-box model, but only as far as the domain it reads has consented
	 * (specs/implemented/sealed_content_egress.md, resolved decision 5).
	 *
	 * Called at recipe save AND at run start. Both, deliberately: the save-time
	 * call is where the admin gets a comprehensible error, and the run-start
	 * call is what makes withdrawing a domain's consent actually stop the next
	 * run rather than leaving an already-saved recipe shipping mail to a vendor.
	 * Same one-way-tightening rule as the taint gate. WITHIN a run there is only
	 * ever one resolution — the object this returns.
	 *
	 * @throws LlmProviderException when nothing the domain permits can do the work
	 */
	public static function resolveForRecipe(Recipe $recipe): AiModelResolution {
		$floor = self::consentTrustFloor($recipe);
		$req = self::requirementFor($recipe);
		try {
			$resolution = AiModelResolver::resolve($req);
		} catch (LlmProviderException $e) {
			// When the consent is what narrowed the field, say THAT rather than
			// handing the admin a capability message they cannot act on: the
			// fix is a domain setting, not a bigger model.
			if ($floor !== null && $floor !== AiModelRequirement::TRUST_ANY) {
				throw new LlmProviderException(
					'This recipe reads mail that is encrypted at rest, and that mail\'s domain only '
					. 'permits it to be read by a model on '
					. ($floor === AiModelRequirement::TRUST_LOCAL
						? 'this server'
						: 'this server or a vendor you have accepted')
					. '. Nothing configured here fits. Either serve a suitable model locally, or '
					. 'change how far the domain\'s decrypted mail may travel on the mailbox '
					. 'domain page. (' . $e->getMessage() . ')');
			}
			throw $e;
		}
		self::assertResolutionAllowed($recipe, $resolution);
		return $resolution;
	}

	/**
	 * Belt and braces on sink zero: the model actually chosen must be one the
	 * domain permits.
	 *
	 * requirementFor() already filters an off-limits endpoint out of the
	 * candidate set, so this can only fire on a defect. It exists because the
	 * cost of the filter silently not applying is decrypted mail on a vendor's
	 * hardware, and a cheap assertion is worth having in front of that.
	 *
	 * @throws LlmProviderException
	 */
	public static function assertResolutionAllowed(Recipe $recipe, AiModelResolution $resolution): void {
		$floor = self::consentTrustFloor($recipe);
		if ($floor === null) return;
		if (AiModelRequirement::trustSatisfies($floor, $resolution->trust())) return;

		throw new LlmProviderException(
			'This recipe reads mail that is encrypted at rest, and “' . $resolution->label()
			. '” runs on a ' . $resolution->trust() . ' endpoint — so running it would send the '
			. 'decrypted mail further than that mail\'s domain has agreed to. Either pin a model '
			. 'this domain permits, or change how far its decrypted mail may travel on the '
			. 'mailbox domain page.');
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
				// The SEALED subset only: standard-mailbox work on a mixed
				// binding belongs to cron's schedule — answering for it here
				// would turn every open window into a drain-on-arrival bypass
				// of the recipe's schedule.
				if ($job->hasWork(self::config($recipe), $recipe, PipelineJobInterface::POSTURE_SEALED)) {
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
				$total += $job->countWork(self::config($recipe), $recipe, PipelineJobInterface::POSTURE_SEALED);
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
