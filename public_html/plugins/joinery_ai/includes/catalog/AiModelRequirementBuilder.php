<?php

/**
 * Where a requirement comes from, when nobody filled one in.
 *
 * The requirement columns on a recipe are a power-user surface. The normal case
 * is an operator who never opens them, and this class is what makes that the
 * GOOD path rather than the neglected one: the job that does the work declares
 * the floor, because it is the only party that knows. EmailSecurityScanJob
 * knows it reads attacker-controlled mail and needs `capable`;
 * MarkAdvertisementsJob knows it is a yes/no on a short feed item and needs
 * `basic`. The operator who binds a mailbox to a recipe knows neither and
 * should not be asked.
 *
 * The chain is walked HERE, at resolve time, and an inherited value is never
 * written into a row. That is load-bearing: RecipeSeeder is create-only, so a
 * requirement materialised at seed time would be frozen at install and a floor
 * raised in a later release would never reach an existing install. Keeping the
 * columns NULL is what makes a job's floor cascade with the code that declares
 * it — and it means a non-NULL requirement column always says exactly one
 * thing: an operator overrode it.
 *
 * See specs/joinery_ai_model_capability_resolution.md §4a, §10.
 */
class AiModelRequirementBuilder {

    /** Fallback floor for a pipeline recipe whose job says nothing. */
    const FALLBACK_TIER = AiModelRequirement::TIER_STANDARD;

    /** Fallback floor for an agent-mode recipe with no declaration: the model
     *  is driving a tool loop, which is what `frontier` means. */
    const FALLBACK_TIER_AGENT = AiModelRequirement::TIER_FRONTIER;

    const FALLBACK_TRUST = AiModelRequirement::TRUST_ANY;

    /**
     * The effective requirement for one recipe.
     *
     * Most specific first: the recipe's own override columns, then the job's
     * declaration (pipeline) or the shipped recipes.json declaration (agent
     * mode, which has no job), then the platform fallback.
     */
    public static function forRecipe(Recipe $recipe): AiModelRequirement {
        $settings = Globalvars::get_instance();
        $is_pipeline = (string)$recipe->get('rcp_mode') === Recipe::MODE_PIPELINE;

        $job = self::jobFor($recipe);
        $declaration = self::declarationFor($recipe);

        // --- the floors, most specific wins ---
        // The fallback is deliberately applied through a different wither: it
        // still filters, but it never vetoes an explicit pin. Nobody stated it,
        // so it is not grounds to refuse the one thing the operator did state.
        $stated_tier = self::firstNonEmpty([
            $recipe->get('rcp_min_tier'),
            $job !== null ? self::jobMinTier($job) : null,
            $declaration['min_tier'] ?? null,
        ]);
        $fallback_tier = $is_pipeline ? self::FALLBACK_TIER : self::FALLBACK_TIER_AGENT;

        $trust = self::firstNonEmpty([
            $recipe->get('rcp_trust_floor'),
            $job !== null ? self::jobTrustFloor($job, $recipe) : null,
            $declaration['trust_floor'] ?? null,
            self::FALLBACK_TRUST,
        ]);

        $thinking_required = $recipe->get('rcp_thinking_required');
        if ($thinking_required === null || $thinking_required === '') {
            $thinking_required = (bool)($declaration['thinking_required'] ?? false);
        }

        $min_context = $recipe->get('rcp_min_context');
        if ($min_context === null || $min_context === '') {
            $min_context = $declaration['min_context'] ?? null;
        }

        $req = AiModelRequirement::make();
        $req = $stated_tier !== null
            ? $req->withMinTier((string)$stated_tier)
            : $req->withFallbackMinTier($fallback_tier);
        $req = $req
            ->withTrustFloor((string)$trust)
            ->withThinkingRequired((bool)$thinking_required)
            ->withMinContext($min_context === null ? null : (int)$min_context)
            ->withPin((string)$recipe->get('rcp_model'))
            ->withThinkingLevel(AgentLoop::resolveThinkingLevel(
                $recipe->get('rcp_thinking_level'),
                $settings->get_setting('joinery_ai_default_thinking_level')))
            ->withPolicy(self::sitePolicy())
            ->withPurpose('the recipe "' . (string)$recipe->get('rcp_name') . '"');

        // Agent mode hands the model a tool belt, so it must be able to hold one.
        if (!$is_pipeline) $req = $req->withTools(true);

        return $req;
    }

    /**
     * The requirement for one chat turn.
     *
     * Chat inherits nothing and carries no floor — the user picks a model, or
     * the site's chat default applies. The only constraint chat carries is the
     * Fortress trust floor, which is a property of the security level rather
     * than anything stored per conversation.
     */
    public static function forConversation(AiConversation $conversation): AiModelRequirement {
        $settings = Globalvars::get_instance();
        $pin = trim((string)$conversation->get('aic_model'));
        if ($pin === '') $pin = trim((string)$settings->get_setting('joinery_ai_default_model'));

        $req = AiModelRequirement::make()
            ->withMinTier(AiModelRequirement::TIER_BASIC)
            ->withTrustFloor(AiModelRequirement::TRUST_ANY)
            ->withTools(true)
            ->withPin($pin)
            ->withThinkingLevel(AgentLoop::resolveThinkingLevel(
                $conversation->get('aic_thinking_level'),
                $settings->get_setting('joinery_ai_default_thinking_level')))
            ->withPolicy(self::sitePolicy())
            ->withPurpose('this chat');

        if ((string)$conversation->get('aic_security_level') === AiConversation::LEVEL_FORTRESS) {
            // Fortress content never leaves the box. Enforced by the resolver
            // from the level, so there is no per-conversation column to keep in
            // step and no second definition of "local" to drift.
            $req = $req->tightenTrustFloor(AiModelRequirement::TRUST_LOCAL);
        }
        return $req;
    }

    /** A bare requirement for a one-off surface (a connection test, a tool that
     *  just needs SOMETHING to run on). */
    public static function forPurpose(string $purpose): AiModelRequirement {
        return AiModelRequirement::make()
            ->withMinTier(AiModelRequirement::TIER_BASIC)
            ->withPolicy(self::sitePolicy())
            ->withPurpose($purpose);
    }

    /** The site's selection policy, validated. Ships prefer_local everywhere —
     *  local is the platform's standing posture, not a carried preference. */
    public static function sitePolicy(): string {
        $p = strtolower(trim((string)Globalvars::get_instance()->get_setting('joinery_ai_selection_policy')));
        return in_array($p, AiModelResolver::POLICIES, true) ? $p : AiModelResolver::POLICY_PREFER_LOCAL;
    }

    // ================= sources =================

    /** The pipeline job behind a recipe, or null. Never throws: an unresolvable
     *  job means "no declared floor", which the fallback covers. */
    private static function jobFor(Recipe $recipe): ?PipelineJobInterface {
        $id = trim((string)$recipe->get('rcp_pipeline_job'));
        if ($id === '') return null;
        try {
            return PipelineJobRegistry::get($id);
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function jobMinTier(PipelineJobInterface $job): ?string {
        try {
            $t = trim((string)$job->minTier());
            return $t !== '' ? $t : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function jobTrustFloor(PipelineJobInterface $job, Recipe $recipe): ?string {
        try {
            $t = trim((string)$job->defaultTrustFloor());
            return $t !== '' ? $t : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * The shipped declaration behind a seeded recipe, as a requirement fragment.
     *
     * Agent-mode declared recipes carry their floors in recipes.json and read
     * them live — DECLARED_KEYS deliberately does not seed them into a row,
     * because a row would freeze them at install.
     */
    private static function declarationFor(Recipe $recipe): array {
        $key = trim((string)$recipe->get('rcp_declared_key'));
        if ($key === '') $key = trim((string)$recipe->get('rcp_template_key'));
        if ($key === '') return [];
        try {
            $d = RecipeSeeder::declarationByKey($key);
        } catch (Throwable $e) {
            return [];
        }
        return is_array($d) ? $d : [];
    }

    /** The first value that is neither null nor ''. */
    private static function firstNonEmpty(array $values) {
        foreach ($values as $v) {
            if ($v !== null && $v !== '') return $v;
        }
        return null;
    }
}
