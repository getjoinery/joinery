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
 * @version 1.4.0
 */

require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeSchedule.php'));

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
		// The job resolves WITHOUT the swallow here, unlike jobFor(): a registry
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
		return $job->requiresVaultScope(self::configFor($recipe));
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
	public static function jobFor(Recipe $recipe): ?PipelineJobInterface {
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
	public static function configFor(Recipe $recipe): array {
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
		$job = self::jobFor($recipe);
		if ($job === null) {
			return true;
		}
		try {
			return $job->hasUnsealedBinding(self::configFor($recipe));
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
			$job = self::jobFor($recipe);
			if ($job !== null) {
				$consent = (string)$job->processingConsent(self::configFor($recipe));
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
	 * Window-requiring recipes owned by this user that are DUE to run right
	 * now. Used by both the work predicate and the drain, so the heartbeat and
	 * the drain always agree about whether there is work.
	 *
	 * Two ways a recipe lands here:
	 *
	 *  - it is due by its own Runs setting, answered from the SEALED subset of
	 *    its binding (RecipeSchedule::isDue). An arrival recipe is due when
	 *    sealed mail is waiting; a clock recipe is due once its fire point has
	 *    passed unmet, whether or not it will find anything — the fire-point
	 *    comparison then suppresses it until the next period, so an empty run
	 *    costs at most one row per period;
	 *  - a person pressed Run Now and the resulting row is still pending on a
	 *    recipe no worker can ever claim (§ adoption in drain()). Dueness is
	 *    about AUTOMATIC runs, so a human's request outranks it entirely —
	 *    including the manual/automatic bit itself. A recipe set to Manually
	 *    only (rcp_enabled false) is scanned for exactly this and nothing
	 *    else: Run Now is the one trigger that setting keeps, and on a fully
	 *    sealed recipe this drain is the only executor Run Now has.
	 *
	 * Without the due gate an open window drains every sealed recipe with
	 * anything to do, which makes Manually only and every clock frequency mean
	 * the same thing on a sealed binding. Since this is also the heartbeat's
	 * work predicate, the gate additionally stops the heartbeat asking for
	 * drains nothing is waiting on.
	 *
	 * @return Recipe[]
	 */
	public static function pendingForOwner(int $user_id): array {
		if ($user_id <= 0) {
			return array();
		}
		$recipes = new MultiRecipe(array('deleted' => false, 'owner_user_id' => $user_id));
		$recipes->load();

		$now_utc = gmdate('Y-m-d H:i:s');
		$pending = array();
		foreach ($recipes as $recipe) {
			// The cheap question first for a Manually-only recipe: with no row
			// pending there is nothing it could possibly owe this drain, and
			// answering requiresWindow() below costs alias resolution per
			// recipe per heartbeat.
			$manual_only = !$recipe->get('rcp_enabled');
			if ($manual_only && !self::hasPendingRun((int)$recipe->key)) {
				continue;
			}
			if (!self::requiresWindow($recipe)) {
				continue;
			}
			if (self::jobFor($recipe) === null) {
				continue;
			}
			// A pending row on a recipe no worker will ever claim is this
			// drain's to run, and it exists because someone asked for it.
			if (self::adoptableRunId($recipe) !== null) {
				$pending[] = $recipe;
				continue;
			}
			// Manually only means no AUTOMATIC runs; with nothing to adopt,
			// dueness is not a question this recipe answers. (A pending row on
			// a MIXED manual-only recipe lands here too — that row is a
			// worker's to claim, exactly as when the recipe was automatic.)
			if ($manual_only) {
				continue;
			}
			// POSTURE_SEALED: standard-mailbox work on a mixed binding belongs
			// to cron's schedule — answering for it here would turn every open
			// window into a drain-on-arrival bypass of the recipe's schedule.
			if (RecipeSchedule::isDue($recipe, PipelineJobInterface::POSTURE_SEALED, $now_utc)) {
				$pending[] = $recipe;
			}
		}
		return $pending;
	}

	/**
	 * The pending run this drain should ADOPT for $recipe rather than queue
	 * behind, or null when there is none.
	 *
	 * Only for a recipe no CLI worker can ever claim (cronRunnable false).
	 * Run Now on such a recipe inserts a pending row, the spawner refuses it,
	 * and the pending reaper deliberately leaves in-window rows alone — so
	 * without adoption the row waits forever while the drain skips the recipe
	 * for "already has an active run". The row IS the work; the drain runs it.
	 *
	 * A row on a MIXED binding is left alone: a worker will claim that one, and
	 * two executors on one row is worse than a wait. A recipe already RUNNING
	 * somewhere is not adoptable either — that is a live run, not a stranded
	 * request.
	 */
	private static function adoptableRunId(Recipe $recipe): ?int {
		if (self::cronRunnable($recipe)) {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT rcr_run_id FROM rcr_recipe_runs
			  WHERE rcr_rcp_recipe_id = ?
			    AND rcr_status = ?
			    AND rcr_delete_time IS NULL
			  ORDER BY rcr_started_time ASC, rcr_run_id ASC
			  LIMIT 1");
		$q->execute(array((int)$recipe->key, RecipeRun::STATUS_PENDING));
		$id = $q->fetchColumn();
		return $id === false ? null : (int)$id;
	}

	/**
	 * Does any run of this recipe sit queued right now? The one question worth
	 * asking about a Manually-only recipe before resolving its binding.
	 */
	private static function hasPendingRun(int $recipe_id): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT 1 FROM rcr_recipe_runs
			  WHERE rcr_rcp_recipe_id = ?
			    AND rcr_status = ?
			    AND rcr_delete_time IS NULL
			  LIMIT 1");
		$q->execute(array($recipe_id, RecipeRun::STATUS_PENDING));
		return (bool)$q->fetchColumn();
	}

	/**
	 * Is a run of this recipe actually executing somewhere right now?
	 *
	 * Narrower than hasActiveRun(): a PENDING row means queued, and on a
	 * fully-sealed recipe queued means stranded, which is the one thing this
	 * drain exists to fix.
	 */
	private static function hasRunningRun(int $recipe_id): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT 1 FROM rcr_recipe_runs
			  WHERE rcr_rcp_recipe_id = ?
			    AND rcr_status = ?
			    AND rcr_delete_time IS NULL
			  LIMIT 1");
		$q->execute(array($recipe_id, RecipeRun::STATUS_RUNNING));
		return (bool)$q->fetchColumn();
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
	 *
	 * Counts only what is DUE, because pendingForOwner() is the source. Mail
	 * held back by a recipe's own clock schedule is not waiting on the person
	 * reading this number, and offering them a Catch up button for it would be
	 * offering to override a schedule they set.
	 */
	public static function outstandingItemCount(int $user_id): int {
		$total = 0;
		foreach (self::pendingForOwner($user_id) as $recipe) {
			$job = self::jobFor($recipe);
			if ($job === null) {
				continue;
			}
			try {
				$total += $job->countWork(self::configFor($recipe), $recipe, PipelineJobInterface::POSTURE_SEALED);
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
	 * schedule and manual runs — except where the drain ADOPTS a stranded
	 * pending row, which keeps the trigger it was queued with, because a run
	 * someone pressed Run Now for is a manual run wherever it ends up
	 * executing.
	 *
	 * A recipe already mid-run elsewhere is skipped rather than doubled up.
	 */
	public static function drain(int $user_id, string $secret_key, float $deadline): int {
		$ran = 0;
		foreach (self::pendingForOwner($user_id) as $recipe) {
			if (microtime(true) >= $deadline) {
				break;
			}
			if (self::hasRunningRun((int)$recipe->key)) {
				continue;
			}
			$adopt_id = self::adoptableRunId($recipe);
			if ($adopt_id !== null) {
				$run = new RecipeRun($adopt_id, TRUE);
				if (!$run->key || (string)$run->get('rcr_status') !== RecipeRun::STATUS_PENDING) {
					continue;   // claimed or cancelled since pendingForOwner looked
				}
			} else {
				// A pending row that is NOT adoptable belongs to a worker
				// (mixed binding) — queueing a second one behind it would run
				// the same recipe twice.
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
			}

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
