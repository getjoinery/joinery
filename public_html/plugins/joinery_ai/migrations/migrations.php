<?php
/**
 * Joinery AI plugin migrations.
 *
 * Tables and columns come from the data classes; settings come from
 * plugin.json. These are data changes only.
 *
 * @version 0.20.0
 */
return [
	[
		/**
		 * Clear the model pins that were never decisions.
		 *
		 * rcp_model used to carry a column DEFAULT of 'claude-haiku-4-5', so
		 * every recipe created without an opinion was minted holding that name.
		 * Under the new design a non-empty rcp_model means one thing — an
		 * operator deliberately pinned this recipe to that model — and leaving
		 * column-default residue in place would make two dozen accidents read
		 * as deliberate pins to a paid vendor the moment an Anthropic key is
		 * set, permanently defeating "prefer local".
		 *
		 * Only the exact old default is cleared, and only where the recipe
		 * carries no other override. A recipe someone genuinely pinned to Haiku
		 * AND gave a floor to is left alone; and the seeder always wrote '' for
		 * shipped recipes, so nothing seeded is touched either way.
		 *
		 * aic_model needs no equivalent sweep: it never had a column default,
		 * so every value there is a real pick by a real person.
		 *
		 * Plugin tables sync AFTER the core migration list, so the requirement
		 * columns may not exist on the first pass. The guard DEFERS (stays
		 * pending, nothing recorded) rather than returning — a plain return
		 * would be recorded as applied and the migration would never complete.
		 * The next update_database pass runs it for real.
		 */
		'id' => 'rcp_001_clear_default_model_residue',
		'version' => '0.20.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			$has_tier = (int)$dblink->query(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = 'public' AND table_name = 'rcp_recipes'
				   AND column_name = 'rcp_min_tier'")->fetchColumn();
			if (!$has_tier) {
				echo "rcp_min_tier not present yet - deferred to the next update_database pass.\n";
				return 'defer';
			}

			// The old column default would keep minting residue on every new
			// recipe, so it goes before anything is cleared.
			$dblink->exec("ALTER TABLE rcp_recipes ALTER COLUMN rcp_model DROP DEFAULT");

			$q = $dblink->query(
				"UPDATE rcp_recipes SET rcp_model = NULL
				 WHERE rcp_model = 'claude-haiku-4-5'
				   AND rcp_min_tier IS NULL
				   AND rcp_trust_floor IS NULL
				   AND rcp_min_context IS NULL
				   AND rcp_thinking_required IS NULL");
			echo "rcp_recipes: " . $q->rowCount() . " column-default model pin(s) cleared.\n";
		},
	],

	[
		/**
		 * Retire joinery_ai_llm_provider.
		 *
		 * It answered "which backend drives recipes?", and nothing asks that
		 * any more: every configured endpoint is available at once, and what a
		 * piece of work runs on is a consequence of what that work needs. Left
		 * in the table it would be an undeclared row nothing reads — the exact
		 * shape a misspelled setting takes, which is what makes a stale one
		 * hard to tell from a live one later.
		 */
		'id' => 'rcp_002_retire_llm_provider_setting',
		'version' => '0.20.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$q = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name = ?");
			$q->execute(['joinery_ai_llm_provider']);
			echo "joinery_ai_llm_provider: " . $q->rowCount() . " row(s) removed.\n";
		},
	],

	[
		/**
		 * Retire joinery_ai_local_vision.
		 *
		 * It asked the operator to declare whether their local model accepts
		 * images. The host already knows: an endpoint declaring `probe: "ollama"`
		 * is asked via /api/show, which reports a capabilities array — so the
		 * answer is right the moment a vision model is swapped in, with nothing
		 * to remember and no publish. An operator-maintained fact table was the
		 * wrong shape for something the machine can be asked.
		 */
		'id' => 'rcp_003_retire_local_vision_setting',
		'version' => '0.20.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$q = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name = ?");
			$q->execute(['joinery_ai_local_vision']);
			echo "joinery_ai_local_vision: " . $q->rowCount() . " row(s) removed.\n";
		},
	],
];
