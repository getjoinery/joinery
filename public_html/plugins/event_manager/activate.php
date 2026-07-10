<?php
/**
 * Event Manager plugin activation hook.
 *
 * Runs once, after the plugin's tables are created and its declared settings
 * are seeded (PluginManager::onActivate). Two jobs the declarative seeders
 * can't do:
 *
 *   1. Generalize the session-analytics entity reference: fold the old
 *      sev_evt_event_id / sev_evs_event_session_id columns into the generic
 *      sev_entity_type / sev_entity_id pair, then drop the old columns. This
 *      lives here (not a core migration) because the backfill only matters once
 *      events exist, and it keeps the migration chain free of the drop.
 *   2. Merge the per-entity photo caps for `event` and `location` into the
 *      core max_entity_photos JSON setting (a declarative seed can't merge into
 *      an existing JSON value).
 *
 * All steps are idempotent and self-guarded so re-activation is safe.
 */
function event_manager_activate() {
	$dblink = DbConnector::get_instance()->get_db_link();

	// ---- 1. session_analytics entity generalization + old-column disposal ----
	// Guarded on the old session column still existing (post-drop this no-ops).
	// The generic pair keeps the SESSION (more specific — the event is
	// recoverable by joining evs_event_sessions on the session id).
	$col_exists = $dblink->query(
		"SELECT 1 FROM information_schema.columns
		 WHERE table_name = 'sev_session_analytics'
		   AND column_name = 'sev_evs_event_session_id' LIMIT 1"
	)->fetchColumn();

	if ($col_exists) {
		// The backfill reads the old columns and writes the generalized pair, so
		// the generic columns must already exist. Core update_database adds them
		// from the session_analytics class spec; if this plugin is activated from
		// the admin Plugins page before that ran, abort with a clear instruction
		// instead of letting the UPDATE fail with a raw SQL error.
		$new_col_exists = $dblink->query(
			"SELECT 1 FROM information_schema.columns
			 WHERE table_name = 'sev_session_analytics'
			   AND column_name = 'sev_entity_type' LIMIT 1"
		)->fetchColumn();
		if (!$new_col_exists) {
			throw new Exception(
				"event_manager activation aborted: sev_session_analytics still has the "
				. "pre-extraction columns but not the generalized sev_entity_type/sev_entity_id "
				. "pair. Run update_database (admin Utilities) first, then activate this plugin."
			);
		}

		// Session rows first (more specific), then bare-event rows.
		$dblink->exec(
			"UPDATE sev_session_analytics
			 SET sev_entity_type = 'event_session', sev_entity_id = sev_evs_event_session_id
			 WHERE sev_evs_event_session_id IS NOT NULL AND sev_entity_id IS NULL"
		);
		$dblink->exec(
			"UPDATE sev_session_analytics
			 SET sev_entity_type = 'event', sev_entity_id = sev_evt_event_id
			 WHERE sev_evt_event_id IS NOT NULL AND sev_entity_id IS NULL"
		);
		$dblink->exec("ALTER TABLE sev_session_analytics DROP COLUMN IF EXISTS sev_evs_event_session_id");
		$dblink->exec("ALTER TABLE sev_session_analytics DROP COLUMN IF EXISTS sev_evt_event_id");
	}

	// ---- 2. max_entity_photos: merge in event/location caps ------------------
	// The core setting declares only user/mailing_list; event_manager owns the
	// event/location caps. JSON-merge them in without clobbering admin edits.
	$q = $dblink->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = 'max_entity_photos'");
	$q->execute();
	$current = $q->fetchColumn();
	if ($current !== false) {
		$data = json_decode($current, true);
		if (!is_array($data)) {
			$data = array();
		}
		if (!isset($data['event'])) {
			$data['event'] = 10;
		}
		if (!isset($data['location'])) {
			$data['location'] = 10;
		}
		$u = $dblink->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = 'max_entity_photos'");
		$u->execute(array(json_encode($data)));
	}

	// ---- 3. Re-attribute pre-extraction scheduled-task rows ------------------
	// These task classes seeded their sct_scheduled_tasks rows while the task
	// files lived in core /tasks/ (sct_plugin_name NULL). Now that they belong to
	// event_manager, claim any unattributed rows so deactivate/uninstall suspends
	// them and the resume-on-activate step below picks them up. Idempotent.
	$reattr = $dblink->prepare(
		"UPDATE sct_scheduled_tasks SET sct_plugin_name = 'event_manager'
		 WHERE sct_task_class IN ('WeeklyEventsDigest', 'SendPostEventSurveys')
		   AND sct_plugin_name IS NULL"
	);
	$reattr->execute();
}
