<?php
/**
 * Joinery AI plugin migrations.
 *
 * Tables and columns come from the data classes; settings come from
 * plugin.json. These are data changes only.
 *
 * @version 0.24.10
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

	[
		/**
		 * Chat protection is two levels, Standard and Private, plus the Local
		 * models only add-on under Private. A conversation stored at the
		 * retired third level ('fortress': sealed + pinned to a local model)
		 * becomes Private with aic_local_models_only on — the same seal and the
		 * same pin, under the new shape. The default-level setting follows:
		 * 'fortress' becomes 'private' with joinery_ai_default_chat_local_only on.
		 *
		 * Guards the table AND both columns: an installed-but-inactive plugin
		 * can keep a stale aic_conversations that never received the new
		 * column. Missing pieces DEFER (nothing recorded) so the migration runs
		 * for real on the pass that has them. Idempotent — a second run finds
		 * no 'fortress' rows and no 'fortress' setting.
		 */
		'id' => 'aic_001_fold_fortress_into_local_models_only',
		'version' => '0.24.10',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			$table = $dblink->query("SELECT to_regclass('public.aic_conversations')")->fetchColumn();
			if (!$table) {
				echo "aic_conversations not present - deferred to the next update_database pass.\n";
				return 'defer';
			}
			$cols = $dblink->query(
				"SELECT column_name FROM information_schema.columns
				 WHERE table_schema = 'public' AND table_name = 'aic_conversations'
				   AND column_name IN ('aic_security_level', 'aic_local_models_only')")
				->fetchAll(PDO::FETCH_COLUMN);
			if (count($cols) < 2) {
				echo "aic_local_models_only not present yet - deferred to the next update_database pass.\n";
				return 'defer';
			}

			$q = $dblink->query(
				"UPDATE aic_conversations
				    SET aic_security_level = 'private', aic_local_models_only = true
				  WHERE aic_security_level = 'fortress'");
			echo "aic_conversations: " . $q->rowCount() . " conversation(s) moved to Private + Local models only.\n";

			$q = $dblink->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
			$q->execute(['joinery_ai_default_chat_level']);
			if ((string)$q->fetchColumn() === 'fortress') {
				Setting::put('joinery_ai_default_chat_level', 'private');
				Setting::put('joinery_ai_default_chat_local_only', '1');
				echo "joinery_ai_default_chat_level: fortress -> private, joinery_ai_default_chat_local_only on.\n";
			} else {
				echo "joinery_ai_default_chat_level: no change.\n";
			}
		},
	],
];
