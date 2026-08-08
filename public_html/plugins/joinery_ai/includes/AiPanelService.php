<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AreaScopedJobInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeSeeder.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/TaintGate.php'));

class AiPanelServiceException extends Exception {}

/** The refusal that means "show a confirm dialog, not an error": turning ON a
 *  tainted-capable recipe before its owner has accepted tainted writes. */
class AiPanelConfirmRequired extends Exception {}

/**
 * The area AI panel's server side (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md
 * § Phase 2): what the signed-in user's recipe cards look like for one area
 * context, and the one toggle that binds or unbinds that context.
 *
 * Owner scoping IS the authorization — every path here reads and writes only
 * recipes belonging to $user_id, so the two API actions are member-callable
 * with no permission gate. The global kill switch (rcp_enabled) is
 * dashboard-only: a toggle against a globally disabled recipe is refused here,
 * server-side, so the panel's grayed control is a rendering of server truth.
 *
 * @version 1.0
 */
class AiPanelService {

    /**
     * Every registered pipeline job that belongs to $area, keyed by job id.
     * @return array<string, AreaScopedJobInterface>
     */
    public static function areaJobs(string $area): array {
        $jobs = [];
        foreach (PipelineJobRegistry::all() as $job_id => $class) {
            $job = PipelineJobRegistry::get($job_id);
            if ($job instanceof AreaScopedJobInterface && $job->area() === $area) {
                $jobs[$job_id] = $job;
            }
        }
        return $jobs;
    }

    /**
     * The panel's cards for one user + area + context: the user's own recipes
     * whose job belongs to the area, plus one template card per shipped
     * declaration in the area the user has no instance of yet — so on a stock
     * install the panel is never empty.
     */
    public static function state(int $user_id, int $permission, string $area, array $context): array {
        $area_jobs = self::areaJobs($area);
        $cards = [];
        $instance_keys = [];

        $recipes = new MultiRecipe(['owner_user_id' => $user_id, 'deleted' => false]);
        $recipes->load();
        foreach ($recipes as $recipe) {
            $job_id = (string)$recipe->get('rcp_pipeline_job');
            if (!isset($area_jobs[$job_id])) continue;
            // Either identity counts as "an instance of" a declaration: the
            // seeder's own row (declared_key) for the resolved superadmin, a
            // panel-made instance (template_key) for everyone — so neither is
            // ever doubled by a template card.
            foreach (['rcp_declared_key', 'rcp_template_key'] as $key_col) {
                $k = (string)$recipe->get($key_col);
                if ($k !== '') $instance_keys[$k] = true;
            }
            $cards[] = self::cardForRecipe($recipe, $area_jobs[$job_id], $context, $user_id, $permission);
        }

        foreach (self::areaDeclarations($area_jobs) as $declaration) {
            if (isset($instance_keys[(string)$declaration['key']])) continue;
            $cards[] = self::cardForTemplate($declaration, $area_jobs, $context, $user_id);
        }

        return $cards;
    }

    /**
     * Apply one panel toggle: bind or unbind $context on the user's recipe
     * (or on their fresh instance of a shipped template — created here, on
     * first toggle-ON, never by mutating the seeded row).
     *
     * @throws AiPanelConfirmRequired  message = TaintGate::explain() text; the
     *         caller renders it as a confirm dialog and retries with
     *         $accept_tainted_writes once the person agrees.
     * @throws AiPanelServiceException on every plain refusal
     * @return array the refreshed card
     */
    public static function toggle(int $user_id, int $permission, string $area, array $context,
            ?int $recipe_id, string $template_key, bool $enabled, bool $accept_tainted_writes): array {
        $area_jobs = self::areaJobs($area);

        $recipe = null;
        $declaration = null;
        if ($recipe_id !== null && $recipe_id > 0) {
            $recipe = self::ownRecipe($recipe_id, $user_id);
        } elseif ($template_key !== '') {
            // A template card may already have grown an instance in another
            // tab — the toggle then edits that instance, never a second copy.
            $recipe = self::userInstanceOf($template_key, $user_id);
            if ($recipe === null) {
                $declaration = RecipeSeeder::declarationByKey($template_key);
                if ($declaration === null) {
                    throw new AiPanelServiceException('Unknown template.');
                }
            }
        } else {
            throw new AiPanelServiceException('Nothing to toggle: no recipe or template named.');
        }

        // Resolve the job and hold it to the panel's area.
        $job_id = $recipe !== null
            ? (string)$recipe->get('rcp_pipeline_job')
            : trim((string)($declaration['pipeline_job'] ?? ''));
        if (!isset($area_jobs[$job_id])) {
            throw new AiPanelServiceException('That recipe does not belong to this page.');
        }
        $job = $area_jobs[$job_id];

        if ($recipe !== null && !$recipe->get('rcp_enabled')) {
            // The kill switch is dashboard-only; the panel never writes it —
            // and the seeded superadmin rows ship disabled on purpose, so
            // their first enablement stays a dashboard act.
            throw new AiPanelServiceException('Paused from the recipes dashboard.');
        }

        if ($enabled) {
            // The taint handshake precedes everything — including template
            // instantiation, so declining the dialog leaves nothing behind.
            $accepted = $recipe !== null && (bool)$recipe->get('rcp_allow_tainted_writes');
            $untrusted = self::jobUntrusted($job);
            if ($untrusted && !$accepted && !$accept_tainted_writes) {
                throw new AiPanelConfirmRequired(
                    TaintGate::explain(TaintGate::evaluate([], [], '', true)));
            }
            if ($recipe === null) {
                $recipe = RecipeSeeder::instantiateForUser($declaration, $user_id,
                    $untrusted && $accept_tainted_writes);
            }
            self::applyBinding($recipe, $job, $context, true, $accept_tainted_writes);
        } else {
            if ($recipe === null) {
                throw new AiPanelServiceException('This template is not on any mailbox yet.');
            }
            self::applyBinding($recipe, $job, $context, false, false);
        }

        $recipe = new Recipe((int)$recipe->key, TRUE);
        return self::cardForRecipe($recipe, $job, $context, $user_id, $permission);
    }

    // ---- internals ----

    /** @param AreaScopedJobInterface&PipelineJobInterface $job */
    private static function jobUntrusted($job): bool {
        return ($job instanceof PipelineJobInterface) && $job->untrustedDigest();
    }

    /** The user's own live recipe, or a refusal — never someone else's. */
    private static function ownRecipe(int $recipe_id, int $user_id): Recipe {
        $recipe = new Recipe($recipe_id, TRUE);
        if (!$recipe->key || $recipe->get('rcp_delete_time')
                || (int)$recipe->get('rcp_owner_user_id') !== $user_id) {
            throw new AiPanelServiceException('No such recipe of yours.');
        }
        return $recipe;
    }

    /** The user's existing instance of a declaration (their own panel-made
     *  copy, or the seeded row when the user IS the resolved superadmin). */
    private static function userInstanceOf(string $template_key, int $user_id): ?Recipe {
        foreach (['template_key', 'declared_key'] as $option) {
            $matches = new MultiRecipe([$option => $template_key, 'owner_user_id' => $user_id,
                'deleted' => false]);
            $matches->load();
            foreach ($matches as $recipe) {
                return new Recipe((int)$recipe->key, TRUE);
            }
        }
        return null;
    }

    /**
     * The one write door for both toggle directions: re-read the stored list
     * under a row lock, bind/unbind through the job, validate, and update only
     * the columns this toggle owns — so a dashboard save racing a panel toggle
     * can never clobber an address the other surface just wrote.
     *
     * Turning OFF skips validateConfig() deliberately: removal only shrinks
     * the list, and re-validating the REMAINING addresses would let one stale
     * entry (a grant revoked elsewhere) trap the person out of unbinding this
     * mailbox. A stale entry already contributes nothing at resolve time and
     * is named on the run tally.
     */
    private static function applyBinding(Recipe $recipe, AreaScopedJobInterface $job,
            array $context, bool $on, bool $accept_tainted_writes): void {
        $db = DbConnector::get_instance()->get_db_link();
        $db->beginTransaction();
        try {
            $q = $db->prepare(
                'SELECT rcp_source_config FROM rcp_recipes WHERE rcp_recipe_id = ? FOR UPDATE');
            $q->execute([(int)$recipe->key]);
            $stored = json_decode((string)$q->fetchColumn(), true);
            $config = is_array($stored) ? $stored : [];

            $config = $job->bindContext($config, $context, $on);
            if ($on) {
                $job->validateConfig($config, $recipe);
            }

            $sets = 'rcp_source_config = ?, rcp_update_time = ?';
            $params = [json_encode($config), gmdate('Y-m-d H:i:s')];
            if ($accept_tainted_writes && !$recipe->get('rcp_allow_tainted_writes')) {
                $sets .= ', rcp_allow_tainted_writes = TRUE';
            }
            $params[] = (int)$recipe->key;
            $u = $db->prepare("UPDATE rcp_recipes SET $sets WHERE rcp_recipe_id = ?");
            $u->execute($params);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            if ($e instanceof InvalidArgumentException) {
                throw new AiPanelServiceException($e->getMessage());
            }
            throw $e;
        }
    }

    /** Shipped declarations whose job belongs to this area. */
    private static function areaDeclarations(array $area_jobs): array {
        $out = [];
        try {
            foreach (RecipeSeeder::declarations() as $declaration) {
                if (!RecipeSeeder::declarationRequirementMet($declaration)) continue;
                $job_id = trim((string)($declaration['pipeline_job'] ?? ''));
                if ($job_id !== '' && isset($area_jobs[$job_id])) {
                    $out[] = $declaration;
                }
            }
        } catch (RecipeSeederException $e) {
            // Unreadable manifest — the panel still shows the user's own rows.
        }
        return $out;
    }

    /** @param AreaScopedJobInterface&PipelineJobInterface $job */
    private static function cardForRecipe(Recipe $recipe, $job, array $context,
            int $user_id, int $permission): array {
        $config = Recipe::decodeSourceConfig($recipe);
        $covered = $job->coversContext($config, $context, $recipe);
        $paused = !$recipe->get('rcp_enabled');
        $bound_total = $job->contextCount($config);

        $blocked_reason = null;
        $blocked_text = null;
        if ($paused) {
            $blocked_reason = 'paused';
            $blocked_text = 'Paused from the recipes dashboard.';
        } elseif (!$covered) {
            // Would turning this ON here be refused? Dry-run the same bind +
            // validate the toggle would run, so the disabled control carries
            // the server's own wording (sealed domain without the AI opt-in,
            // a grant the owner does not hold) instead of failing on click.
            try {
                $job->validateConfig($job->bindContext($config, $context, true), $recipe);
            } catch (InvalidArgumentException $e) {
                $blocked_reason = 'binding_refused';
                $blocked_text = $e->getMessage();
            }
        }

        return [
            'recipe_id'      => (int)$recipe->key,
            'template_key'   => null,
            'name'           => (string)$recipe->get('rcp_name'),
            'job_label'      => ($job instanceof PipelineJobInterface) ? $job->label() : '',
            'covered'        => $covered,
            'paused'         => $paused,
            'blocked_reason' => $blocked_reason,
            'blocked_text'   => $blocked_text,
            'other_count'    => max(0, $bound_total - ($covered ? 1 : 0)),
            'last_run'       => self::lastRunLine((int)$recipe->key),
            'dashboard_url'  => $permission >= 10
                ? '/admin/joinery_ai/edit?rcp_recipe_id=' . (int)$recipe->key : null,
        ];
    }

    private static function cardForTemplate(array $declaration, array $area_jobs,
            array $context, int $user_id): array {
        $job_id = trim((string)($declaration['pipeline_job'] ?? ''));
        $job = $area_jobs[$job_id];

        // Same dry-run as a real card: would first toggle-ON here be refused?
        $blocked_reason = null;
        $blocked_text = null;
        $probe = new Recipe(NULL);
        $probe->set('rcp_owner_user_id', $user_id);
        try {
            $job->validateConfig($job->bindContext([], $context, true), $probe);
        } catch (InvalidArgumentException $e) {
            $blocked_reason = 'binding_refused';
            $blocked_text = $e->getMessage();
        }

        return [
            'recipe_id'      => null,
            'template_key'   => (string)$declaration['key'],
            'name'           => (string)$declaration['name'],
            'job_label'      => ($job instanceof PipelineJobInterface) ? $job->label() : '',
            'covered'        => false,
            'paused'         => false,
            'blocked_reason' => $blocked_reason,
            'blocked_text'   => $blocked_text,
            'other_count'    => 0,
            'last_run'       => 'Not set up yet',
            'dashboard_url'  => null,
        ];
    }

    /** "Last ran 20 minutes ago" / "Running now" / "Has not run yet". */
    private static function lastRunLine(int $recipe_id): string {
        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare(
            "SELECT rcr_status, rcr_started_time FROM rcr_recipe_runs
              WHERE rcr_rcp_recipe_id = ? AND rcr_delete_time IS NULL
              ORDER BY rcr_started_time DESC LIMIT 1");
        $q->execute([$recipe_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'Has not run yet';
        if (in_array((string)$row['rcr_status'], ['pending', 'running'], true)) {
            return 'Running now';
        }
        return 'Last ran ' . self::ago((string)$row['rcr_started_time']);
    }

    private static function ago(string $utc_time): string {
        $seconds = max(0, time() - (int)strtotime($utc_time . ' UTC'));
        if ($seconds < 90) return 'just now';
        if ($seconds < 5400) return floor($seconds / 60) . ' minutes ago';
        if ($seconds < 129600) return floor($seconds / 3600) . ' hours ago';
        return floor($seconds / 86400) . ' days ago';
    }

}
