<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class RecipeSeederException extends Exception {}

/**
 * Recipes that ship with new installs.
 *
 * A curated recipe is useful on every install — triage the inbox, score mail
 * for phishing, put dated events on the calendar — but most of a recipe is
 * specific to the instance it was built on and cannot travel: the owner, the
 * mailbox it points at, the model. So what ships is the judgement and the
 * settings, declared in `plugins/joinery_ai/recipes.json` and seeded here on
 * plugin sync, the same road declared settings and declared tasks take.
 *
 * Three rules the rest of this class exists to enforce:
 *
 *  - Seed once, never overwrite. A declaration creates a recipe when one with
 *    its key does not exist, and otherwise does nothing. An upgrade must never
 *    replace a prompt the operator tuned.
 *  - Deletion is respected. The existence check counts soft-deleted rows, so a
 *    template the operator threw away stays thrown away.
 *  - Nothing arrives armed. A seeded recipe is disabled, unbound and has
 *    tainted writes off; enabling it is where the operator accepts the posture.
 *
 * See specs/implemented/joinery_ai_shipped_recipes.md.
 *
 * A declaration also serves a second consumer: the area AI panel instantiates
 * a member's own enabled copy of one via instantiateForUser() — same declared
 * fields, different identity and arming posture.
 *
 * @version 1.1
 */
class RecipeSeeder {

    /** Manifest path, relative to public_html. */
    const MANIFEST = 'plugins/joinery_ai/recipes.json';

    /**
     * Fields a shipped recipe never carries, and why.
     *
     * Named here rather than inline because both halves of the round trip need
     * the same list: the marking action strips them on the way out, and seeding
     * fixes them on the way in.
     */
    const NON_TRAVELLING_FIELDS = [
        'rcp_owner_user_id'        => 'points at a user on the publishing instance',
        'rcp_source_config'        => 'names a mailbox that exists nowhere else',
        'rcp_model'                => 'names a model the destination may not have',
        'rcp_enabled'              => 'must never arrive switched on',
        'rcp_allow_tainted_writes' => 'a security acknowledgment, not ours to give',
    ];

    /**
     * The requirement keys sit BETWEEN the two lists: an AGENT-mode declaration
     * may carry them (it has no job to declare a floor), so they are not
     * non-travelling — but fromDeclaration() never writes them into a row
     * either, because the seeder is create-only and a floor frozen at install
     * would miss every raise a later release ships. They are read live at
     * resolve time (AiModelRequirementBuilder::declarationFor()). A PIPELINE
     * declaration must not carry them at all: its job is the single source,
     * and a second answer here would be a place for the two to disagree.
     */
    const REQUIREMENT_DECLARATION_KEYS = [
        'min_tier', 'trust_floor', 'thinking_required', 'min_context',
    ];

    /**
     * Keys a declaration may carry. Anything else is a typo worth reporting.
     *
     * The four requirement keys are declarable but deliberately NOT seeded into
     * a row — fromDeclaration() never writes them. They are read live at
     * resolve time (AiModelRequirementBuilder), because this seeder is
     * create-only: a value written at install would be frozen there, and a
     * floor raised in a later release would silently miss every install that
     * already has the recipe. Pipeline recipes should omit them entirely and
     * let their job declare the floor; they exist for agent-mode declarations,
     * which have no job to ask.
     */
    const DECLARED_KEYS = [
        'key', 'name', 'pipeline_job', 'prompt', 'requires_plugin',
        'schedule_frequency', 'schedule_day_of_week', 'schedule_time',
        'max_iterations', 'max_tokens', 'monthly_token_cap', 'thinking_level',
        'min_tier', 'trust_floor', 'thinking_required', 'min_context',
    ];

    public static function manifestPath(): string {
        return PathHelper::getIncludePath(self::MANIFEST);
    }

    /**
     * Read and validate the declarations.
     *
     * @param string|null $path    manifest to read; defaults to the shipped one
     * @param array|null  $errors  out: one message per skipped entry
     * @return array[] usable declarations, in file order
     * @throws RecipeSeederException only when the FILE itself is unusable
     */
    public static function declarations(?string $path = null, ?array &$errors = null): array {
        $errors = [];
        $path = $path ?? self::manifestPath();
        if (!file_exists($path)) return [];

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RecipeSeederException('recipes.json could not be read.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RecipeSeederException('recipes.json is not valid JSON.');
        }

        $entries = $data['recipes'] ?? [];
        if (!is_array($entries)) {
            throw new RecipeSeederException('recipes.json: "recipes" must be a list.');
        }

        // A problem with the FILE throws — nothing can be salvaged from JSON
        // that will not parse. A problem with one ENTRY skips that entry and
        // reports it: one typo must not stop every other template seeding, on
        // every install, for ever. Same treatment the per-recipe create loop
        // gives a failure in seedDeclared().
        $seen = [];
        $out = [];
        foreach ($entries as $i => $entry) {
            $problem = null;
            $key = is_array($entry) ? (string)($entry['key'] ?? '') : '';

            if (!is_array($entry)) {
                $problem = "entry #$i is not an object";
            } elseif (!preg_match('/^[a-z0-9_]+$/', $key)) {
                $problem = "entry #$i has no usable 'key' — lowercase letters, digits and underscores only";
            } elseif (isset($seen[$key])) {
                $problem = "'$key' is declared twice; the later one is ignored";
            } elseif (trim((string)($entry['name'] ?? '')) === '') {
                $problem = "entry '$key' has no 'name'";
            } else {
                foreach (array_keys($entry) as $field) {
                    if (strpos((string)$field, '_') === 0) continue; // _comment and friends
                    if (!in_array($field, self::DECLARED_KEYS, true)) {
                        $problem = "entry '$key' declares unknown field '$field'";
                        break;
                    }
                }
            }

            if ($problem !== null) {
                $errors[] = 'recipes.json: ' . $problem . ' — skipped.';
                continue;
            }

            $seen[$key] = true;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Seed every declaration that this install does not already have.
     *
     * Never throws for an ordinary "not ready yet" condition — an install still
     * mid-setup has no human admin to own a recipe, and the right answer there
     * is to say so and try again at the next sync, not to fail the sync or
     * create an ownerless recipe.
     *
     * @param array[]|null  $declarations   defaults to the shipped manifest
     * @param callable|null $owner_resolver defaults to resolveOwnerUserId()
     * @return string[] human-readable messages for the sync report
     */
    public static function seedDeclared(?array $declarations = null, ?callable $owner_resolver = null): array {
        $messages = [];

        if ($declarations === null) {
            try {
                $declarations = self::declarations(null, $manifest_errors);
                // A malformed entry is reported and skipped, so the rest still
                // seed; only an unusable FILE stops everything.
                foreach ($manifest_errors as $manifest_error) {
                    $messages[] = $manifest_error;
                }
            } catch (RecipeSeederException $e) {
                return [$e->getMessage()];
            }
        }
        if (empty($declarations)) return $messages;

        // Nothing to do if every declaration is already accounted for — checked
        // before resolving an owner so a mid-setup install stays quiet once it
        // has been seeded.
        $pending = [];
        foreach ($declarations as $declaration) {
            if (self::declarationRequirementMet($declaration) && !self::exists($declaration['key'])) {
                $pending[] = $declaration;
            }
        }
        if (empty($pending)) return $messages;

        $owner_id = $owner_resolver ? $owner_resolver() : self::resolveOwnerUserId();
        if ($owner_id === null) {
            $messages[] = 'shipped recipes not seeded yet — no administrator to own them.';
            return $messages;
        }

        foreach ($pending as $declaration) {
            try {
                $recipe = self::create($declaration, $owner_id);
                $messages[] = "seeded recipe '" . $recipe->get('rcp_name') . "' (disabled, awaiting setup)";
            } catch (Throwable $e) {
                $messages[] = "could not seed '" . $declaration['key'] . "' — " . $e->getMessage();
                error_log('[joinery_ai] recipe seed failed for ' . $declaration['key'] . ': ' . $e->getMessage());
            }
        }

        return $messages;
    }

    /**
     * Does this install already have the recipe a key declares?
     *
     * Soft-deleted rows count. Checking only live rows would resurrect a
     * template the operator deleted on purpose at every upgrade, for ever.
     */
    public static function exists(string $key): bool {
        $existing = new MultiRecipe(['declared_key' => $key, 'include_deleted' => true]);
        return $existing->count_all() > 0;
    }

    /**
     * A declaration can name a plugin it depends on (the email templates need
     * the mailbox plugin). Without it the template would be inert clutter, so
     * it simply does not arrive — and does arrive later, at the sync that
     * follows activating that plugin.
     */
    public static function declarationRequirementMet(array $declaration): bool {
        $required = trim((string)($declaration['requires_plugin'] ?? ''));
        if ($required === '') return true;
        return PluginHelper::isPluginActive($required);
    }

    /**
     * The user a seeded recipe belongs to: the lowest-numbered active
     * administrator, excluding the system and deleted-placeholder accounts.
     *
     * The exclusion is the whole point of doing this by hand. User::USER_SYSTEM
     * is id 2 and carries permission 10, so the naive "lowest permission-10 row"
     * picks it on any install where the system row seeds before the human one —
     * and every shipped recipe would then execute its writes as a service
     * account rather than as a person who can be held responsible for them.
     */
    public static function resolveOwnerUserId(): ?int {
        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare(
            "SELECT usr_user_id FROM usr_users
             WHERE usr_permission >= 10
               AND usr_delete_time IS NULL
               AND usr_user_id NOT IN (?, ?)
             ORDER BY usr_user_id ASC
             LIMIT 1"
        );
        $q->execute([User::USER_SYSTEM, User::USER_DELETED]);
        $id = $q->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Create one recipe from a declaration. Everything the declaration does not
     * carry is fixed here rather than defaulted, so the inert-on-arrival posture
     * cannot be lost by editing recipes.json.
     */
    private static function create(array $declaration, int $owner_id): Recipe {
        $recipe = self::fromDeclaration($declaration, $owner_id);
        $recipe->set('rcp_declared_key', $declaration['key']);
        $recipe->set('rcp_enabled', false);
        $recipe->set('rcp_allow_tainted_writes', false);

        // No prepare() — a pipeline recipe with no mailbox bound cannot satisfy
        // its job's config descriptor, and that unbound state is exactly what a
        // template is. The edit form validates when the operator sets it up.
        $recipe->save();
        $recipe->load();
        return $recipe;
    }

    /**
     * A member's own runnable instance of a shipped declaration — the area AI
     * panel's toggle-on of a template card (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md
     * § Templates and per-user instances). Unlike a seeded row it arrives
     * ENABLED: the toggle is itself the enablement choice, and the tainted-
     * writes acceptance rode the same dialog ($accepted_tainted_writes). It
     * carries rcp_template_key (non-unique) rather than rcp_declared_key (the
     * seeder's unique identity), so any number of members can instantiate the
     * same declaration.
     */
    public static function instantiateForUser(array $declaration, int $owner_id,
            bool $accepted_tainted_writes): Recipe {
        $recipe = self::fromDeclaration($declaration, $owner_id);
        $recipe->set('rcp_template_key', $declaration['key']);
        $recipe->set('rcp_enabled', true);
        $recipe->set('rcp_allow_tainted_writes', $accepted_tainted_writes);
        $recipe->save();
        $recipe->load();
        return $recipe;
    }

    /** The shipped declaration with this key, or null. */
    public static function declarationByKey(string $key): ?array {
        try {
            foreach (self::declarations() as $declaration) {
                if ((string)$declaration['key'] === $key) {
                    return $declaration;
                }
            }
        } catch (RecipeSeederException $e) {
            // Unreadable manifest — no declarations to offer.
        }
        return null;
    }

    /** The declared, travelling fields applied to a fresh unsaved Recipe —
     *  shared by the seeder's inert template rows and the panel's per-user
     *  instances, which differ only in identity and arming posture. */
    private static function fromDeclaration(array $declaration, int $owner_id): Recipe {
        $job = trim((string)($declaration['pipeline_job'] ?? ''));

        $recipe = new Recipe(NULL);
        $recipe->set('rcp_name', $declaration['name']);
        $recipe->set('rcp_prompt', (string)($declaration['prompt'] ?? ''));
        $recipe->set('rcp_mode', $job !== '' ? Recipe::MODE_PIPELINE : Recipe::MODE_AGENT);
        $recipe->set('rcp_pipeline_job', $job !== '' ? $job : null);
        $recipe->set('rcp_schedule_frequency', (string)($declaration['schedule_frequency'] ?? 'weekly'));
        $recipe->set('rcp_schedule_day_of_week', $declaration['schedule_day_of_week'] ?? null);
        $recipe->set('rcp_schedule_time', $declaration['schedule_time'] ?? null);
        $recipe->set('rcp_max_iterations', (int)($declaration['max_iterations'] ?? 5));
        $recipe->set('rcp_max_tokens', (int)($declaration['max_tokens'] ?? 5000));
        $recipe->set('rcp_monthly_token_cap', (int)($declaration['monthly_token_cap'] ?? 200000));
        $recipe->set('rcp_thinking_level', (string)($declaration['thinking_level'] ?? 'off'));
        $recipe->set('rcp_delivery_dashboard', true);

        // The binding never travels; temperature and top_p are left null so
        // they fall back to whatever this install tuned globally.
        $recipe->set('rcp_owner_user_id', $owner_id);
        $recipe->set('rcp_source_config', []);
        // No pin, and no requirement columns either. A model name cannot travel
        // (it names something the destination may not serve) and a requirement
        // must not be frozen into a row — both are answered live, from the job
        // or from this declaration, so raising a floor in a release changes
        // every existing install with no reseed and no migration.
        $recipe->set('rcp_model', '');
        return $recipe;
    }

    // ========== Marking a recipe to ship ==========

    /**
     * Write a recipe into recipes.json as a declaration, creating or replacing
     * the entry for its key.
     *
     * Only meaningful on an instance that publishes upgrades — see
     * DeploymentHelper::isUpgradeServer(). Elsewhere the file is replaced
     * wholesale by the next upgrade and the edit would be silently discarded,
     * so callers gate the control rather than letting it lie.
     *
     * @return string the declared key now stored on the recipe
     * @throws RecipeSeederException
     */
    public static function ship(Recipe $recipe, ?string $path = null): string {
        if (!$recipe->key) {
            throw new RecipeSeederException('Save the recipe before marking it to ship.');
        }

        $path = $path ?? self::manifestPath();
        $data = ['recipes' => []];
        if (file_exists($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (!is_array($decoded)) {
                throw new RecipeSeederException('recipes.json is not valid JSON — fix it before marking a recipe.');
            }
            $data = $decoded;
            if (!isset($data['recipes']) || !is_array($data['recipes'])) $data['recipes'] = [];
        }

        $existing_keys = [];
        foreach ($data['recipes'] as $entry) {
            if (is_array($entry) && !empty($entry['key'])) $existing_keys[] = (string)$entry['key'];
        }

        $key = (string)$recipe->get('rcp_declared_key');
        if ($key === '') {
            // Keys already claimed by a row on this instance count as taken too:
            // the column is unique, so a name that slugs onto one would fail the
            // stamp-back save below rather than the file write.
            $db = DbConnector::get_instance()->get_db_link();
            $claimed = $db->query("SELECT rcp_declared_key FROM rcp_recipes WHERE rcp_declared_key IS NOT NULL")
                          ->fetchAll(PDO::FETCH_COLUMN);
            $key = self::makeKey((string)$recipe->get('rcp_name'),
                array_merge($existing_keys, array_map('strval', $claimed)));
        }

        $declaration = self::declarationFrom($recipe, $key, $path);

        $replaced = false;
        foreach ($data['recipes'] as $i => $entry) {
            if (is_array($entry) && (string)($entry['key'] ?? '') === $key) {
                $data['recipes'][$i] = $declaration;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) $data['recipes'][] = $declaration;
        $data['recipes'] = array_values($data['recipes']);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RecipeSeederException('Could not encode recipes.json.');
        }
        if (@file_put_contents($path, $json . "\n") === false) {
            throw new RecipeSeederException('Could not write ' . self::MANIFEST . ' — check file permissions.');
        }
        @chmod($path, 0666);

        // Stamping the key back means marking the same recipe again updates its
        // entry instead of adding a second one, and means this instance won't
        // re-seed a duplicate of a recipe it already has.
        if ((string)$recipe->get('rcp_declared_key') !== $key) {
            $recipe->set('rcp_declared_key', $key);
            $recipe->set('rcp_update_time', gmdate('Y-m-d H:i:s'));
            $recipe->save();
        }

        return $key;
    }

    /**
     * The travelling half of a recipe: what it does and how it is bounded,
     * with identity and binding left off.
     */
    public static function declarationFrom(Recipe $recipe, string $key, ?string $path = null): array {
        $declaration = [
            'key'  => $key,
            'name' => (string)$recipe->get('rcp_name'),
        ];

        $job = trim((string)$recipe->get('rcp_pipeline_job'));
        if ($job !== '') $declaration['pipeline_job'] = $job;

        // The prompt travels only when the operator wrote one. An empty prompt
        // means the job class's own defaultPrompt() applies, and that is the
        // wording we want on the destination — it improves with every upgrade,
        // where a prompt frozen into this file never would.
        $declaration['prompt'] = (string)$recipe->get('rcp_prompt');

        $declaration['schedule_frequency'] = (string)$recipe->get('rcp_schedule_frequency');
        $dow = $recipe->get('rcp_schedule_day_of_week');
        if ($dow !== null && $dow !== '') $declaration['schedule_day_of_week'] = (int)$dow;
        $time = $recipe->get('rcp_schedule_time');
        if (is_object($time) && method_exists($time, 'format')) $time = $time->format('H:i:s');
        if ($time) $declaration['schedule_time'] = (string)$time;

        $declaration['max_iterations']    = (int)$recipe->get('rcp_max_iterations');
        $declaration['max_tokens']        = (int)$recipe->get('rcp_max_tokens');
        $declaration['monthly_token_cap'] = (int)$recipe->get('rcp_monthly_token_cap');
        $declaration['thinking_level']    = (string)$recipe->get('rcp_thinking_level') ?: 'off';

        // An AGENT-mode recipe carries its own requirement, because it has no
        // job to declare one. A pipeline recipe carries none: its job is the
        // single source, and repeating the floor here would be a second answer
        // to keep in step. Only an operator's explicit override travels — a
        // NULL column means "inherit", and shipping an inherited value would
        // freeze whatever this publishing instance happened to resolve.
        if ($job === '') {
            foreach ([
                'min_tier'          => 'rcp_min_tier',
                'trust_floor'       => 'rcp_trust_floor',
                'min_context'       => 'rcp_min_context',
                'thinking_required' => 'rcp_thinking_required',
            ] as $declared => $column) {
                $v = $recipe->get($column);
                if ($v === null || $v === '') continue;
                $declaration[$declared] = ($declared === 'min_context') ? (int)$v
                    : (($declared === 'thinking_required') ? (bool)$v : (string)$v);
            }
        }

        // Carry forward a requires_plugin already declared for this key rather
        // than dropping it — the marking action re-writes the whole entry.
        try {
            foreach (self::declarations($path) as $existing) {
                if ((string)$existing['key'] === $key && !empty($existing['requires_plugin'])) {
                    $declaration['requires_plugin'] = (string)$existing['requires_plugin'];
                }
            }
        } catch (RecipeSeederException $e) {
            // Unreadable manifest is reported by ship(); nothing to carry forward.
        }

        return $declaration;
    }

    /** A stable, readable key from the recipe name, unique within the file. */
    public static function makeKey(string $name, array $existing_keys): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim((string)$slug, '_');
        if ($slug === '') $slug = 'recipe';
        $slug = substr($slug, 0, 80);

        $key = $slug;
        $n = 2;
        while (in_array($key, $existing_keys, true)) {
            $key = $slug . '_' . $n;
            $n++;
        }
        return $key;
    }

}
