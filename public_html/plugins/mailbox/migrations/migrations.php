<?php
/**
 * Inbound Email Plugin Migrations
 *
 * Tables and columns are created automatically from data class field
 * specifications. Admin menus are managed declaratively via plugin.json
 * adminMenu. Default settings are managed declaratively via plugin.json
 * settings.
 *
 * The only thing the auto-updater does NOT handle is non-unique indexes, so
 * the Mailbox Reader's thread-key index is created here (same pattern as the
 * server_manager plugin's index migration).
 *
 * @version 1.21.0
 */
return [
	[
		'id' => 'iem_001_thread_key_index',
		'version' => '1.9.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Threads are grouped by iem_thread_key within a mailbox scope; the
			// reader queries it on every list/thread fetch.
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS iem_thread_key_idx
				 ON iem_inbound_email_messages (iem_thread_key)"
			);
		},
	],

	[
		'id' => 'iem_002_state_columns_not_null',
		'version' => '1.9.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// The auto-updater adds bool columns but defers DEFAULT/NOT NULL to
			// its --upgrade pass; finalize them here so newly-stored mail and
			// existing rows are never NULL (NULL would break unread counting).
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_is_read = false WHERE iem_is_read IS NULL");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_is_starred = false WHERE iem_is_starred IS NULL");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_is_read SET DEFAULT false");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_is_starred SET DEFAULT false");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_is_read SET NOT NULL");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_is_starred SET NOT NULL");
		},
	],

	[
		// Every pre-existing iem_dkim_result was produced by the now-removed
		// hand-rolled verifier, which false-failed legitimate mail. Reset them
		// to 'unverified' so old rows are honest rather than confidently wrong.
		// The new iem_spf_result/iem_dmarc_result/iem_auth_source columns are
		// backfilled by their ADD COLUMN DEFAULTs (unverified/unverified/none),
		// so only iem_dkim_result needs this flip. Scoped to non-milter rows so
		// it never clobbers a genuine milter-stamped verdict.
		'id' => 'iem_003_reset_handrolled_dkim_verdicts',
		'version' => '1.10.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"UPDATE iem_inbound_email_messages
				 SET iem_dkim_result = 'unverified'
				 WHERE iem_auth_source IS DISTINCT FROM 'milter'"
			);
		},
	],

	[
		// Plugin-table sync adds new columns WITHOUT their field_specifications
		// DEFAULT (the same gap iem_002 finalizes for the bool columns) and does
		// not widen an existing column's type. So set the verdict-column defaults
		// here, backfill the existing NULL rows to honest 'unverified'/'none',
		// and widen iem_dkim_result to match the data class.
		'id' => 'iem_004_verdict_column_defaults',
		'version' => '1.10.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_spf_result   SET DEFAULT 'unverified'");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_dmarc_result SET DEFAULT 'unverified'");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_auth_source  SET DEFAULT 'none'");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_spf_result   = 'unverified' WHERE iem_spf_result   IS NULL");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_dmarc_result = 'unverified' WHERE iem_dmarc_result IS NULL");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_auth_source  = 'none'       WHERE iem_auth_source  IS NULL");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_dkim_result TYPE varchar(16)");
		},
	],

	[
		// Two-way IMAP sync hot paths (specs/two_way_imap_sync.md). The auto-updater
		// does not create non-unique indexes, so add the per-cycle lookup indexes
		// here: VANISHED correlation by (folder, uid), the folder-membership
		// subquery by folder, and the iem_ locator lookup by (account, folder, uid).
		'id' => 'iem_005_two_way_sync_indexes',
		'version' => '1.11.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS imf_folder_uid_idx
				 ON imf_inbound_message_folders (imf_iif_inbound_imap_folder_id, imf_imap_uid)"
			);
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS imf_message_idx
				 ON imf_inbound_message_folders (imf_iem_inbound_email_message_id)"
			);
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS iem_imap_locator_idx
				 ON iem_inbound_email_messages
				 (iem_iia_inbound_imap_account_id, iem_imap_folder, iem_imap_uid)"
			);
		},
	],

	[
		// Sync is gated on CONDSTORE (QRESYNC implies it, but Gmail has CONDSTORE
		// without QRESYNC). Backfill the new gate from the prior QRESYNC flag so a
		// feed already detected as QRESYNC-capable stays sync-capable without a
		// re-poll; CONDSTORE-only feeds (Gmail) pick it up on the next connect/Test.
		'id' => 'iia_001_backfill_condstore_from_qresync',
		'version' => '1.11.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"UPDATE iia_inbound_imap_accounts
				 SET iia_supports_condstore = true
				 WHERE iia_supports_qresync = true"
			);
		},
	],

	[
		// The plugin-table sync adds bool columns without finalizing DEFAULT/NOT NULL
		// (same gap iem_002 handles). Finalize iif_pending_remote_create so existing
		// rows (which already exist on the source) read false, not NULL.
		'id' => 'iif_001_pending_create_not_null',
		'version' => '1.11.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("UPDATE iif_inbound_imap_folders SET iif_pending_remote_create = false WHERE iif_pending_remote_create IS NULL");
			$dblink->exec("ALTER TABLE iif_inbound_imap_folders ALTER COLUMN iif_pending_remote_create SET DEFAULT false");
			$dblink->exec("ALTER TABLE iif_inbound_imap_folders ALTER COLUMN iif_pending_remote_create SET NOT NULL");
		},
	],

	[
		// Raw-message storage descriptor (specs/inbound_raw_message_storage.md).
		// Plugin-table sync adds the new iem_raw_* columns WITHOUT their
		// field_specifications DEFAULT (the gap iem_002/iif_001 finalize), so set
		// the defaults and backfill any NULLs here. Then correct the
		// reference-backed IMAP rows: they were written before the descriptor
		// existed and sit on the 'inline' default, but their raw is 'remote'
		// (re-fetched from the mailbox). MUST land before the driver-flag dispatch
		// goes live, or existing IMAP attachments/forwards route to the empty-raw
		// branch. Idempotent. Mirrors the iia_001/iem_003 pattern.
		'id' => 'iem_006_backfill_imap_remote_driver',
		'version' => '1.16.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_raw_storage_driver SET DEFAULT 'inline'");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_raw_sync_failed_count SET DEFAULT 0");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_raw_storage_driver = 'inline' WHERE iem_raw_storage_driver IS NULL");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_raw_sync_failed_count = 0 WHERE iem_raw_sync_failed_count IS NULL");
			$dblink->exec(
				"UPDATE iem_inbound_email_messages
				 SET iem_raw_storage_driver = 'remote'
				 WHERE iem_iia_inbound_imap_account_id IS NOT NULL
				   AND iem_raw_storage_driver = 'inline'"
			);
		},
	],

	[
		// Full-text search index for the Mailbox Reader (specs/inbound_email_fulltext_search.md).
		// The auto-updater does not create non-unique indexes, so the GIN index over
		// the canonical search expression is created here. The expression MUST stay
		// byte-identical to the one MailboxService::listThreads() filters on, or the
		// planner will not use the index.
		'id' => 'iem_007_fulltext_search_index',
		'version' => '1.19.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS iem_fulltext_idx
				 ON iem_inbound_email_messages
				 USING GIN (to_tsvector('english',
						coalesce(iem_sender, '')      || ' ' ||
						coalesce(iem_subject, '')     || ' ' ||
						coalesce(iem_body_plain, '')  || ' ' ||
						coalesce(iem_body_html, '')))"
			);
		},
	],

	[
		// Labels-on-Groups hot paths (specs/inbound_email_labels.md). The auto-updater
		// does not create non-unique indexes, so add: the projection's VANISHED
		// correlation by (binding, uid) and its by-message lookup (replacing the old
		// imf_ indexes), plus the "labels of a message" lookup on the core membership
		// join. The imf_ indexes are dropped with the imf_ table by the conversion below.
		'id' => 'iem_008_label_group_indexes',
		'version' => '1.20.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS ifm_folder_uid_idx
				 ON ifm_imap_folder_membership (ifm_iif_inbound_imap_folder_id, ifm_imap_uid)"
			);
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS ifm_message_idx
				 ON ifm_imap_folder_membership (ifm_iem_inbound_email_message_id)"
			);
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS grm_foreign_key_idx
				 ON grm_group_members (grm_foreign_key_id)"
			);
		},
	],

	[
		// One-time conversion of IMAP folders + imf_ membership into the labels-on-
		// Groups model (specs/inbound_email_labels.md). Runs once against the one
		// database (the plugin is not distributed). Idempotent — find-or-create and
		// upserts throughout — so a re-run is a no-op.
		//
		//   1. Bind every folder to its Group: a custom folder to a user-facing
		//      inbound_label group (shared by name = the merge), a special-use folder
		//      to a hidden inbound_imap_role group, the \All coverage folder to none.
		//   2. Convert each imf_ row into a grm_group_members row (membership = the
		//      truth, for present_local) and an ifm_ projection row (the shadow +
		//      UID, for present_base). Then drop the retired imf_ table + its indexes.
		//   3. Repoint each filter's label action from the old iif folder id to the
		//      folder's bound group id.
		'id' => 'iem_009_convert_folders_to_label_groups',
		'version' => '1.20.0',
		'up' => function($dbconnector) {
			require_once(PathHelper::getIncludePath('data/groups_class.php'));
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/imap_folder_membership_class.php'));
			$dblink = $dbconnector->get_db_link();
			$pgbool = function($v) { return ($v === true || $v === 't' || $v === '1' || $v === 1); };

			// 1. Bind folders -> groups.
			$folderGroup = array(); // iif id => group id (or null for coverage)
			$fids = $dblink->query("SELECT iif_inbound_imap_folder_id FROM iif_inbound_imap_folders")
				->fetchAll(PDO::FETCH_COLUMN);
			foreach ($fids as $fid) {
				$folder = new InboundImapFolder(intval($fid), TRUE);
				$folderGroup[intval($fid)] = $folder->key ? $folder->ensureGroup() : null;
			}

			// 2. imf_ membership -> grm_group_members (truth) + ifm_ projection (shadow).
			$hasImf = $dblink->query("SELECT to_regclass('public.imf_inbound_message_folders')")->fetchColumn();
			if ($hasImf) {
				$rows = $dblink->query(
					"SELECT imf_iem_inbound_email_message_id AS msg, imf_iif_inbound_imap_folder_id AS folder,
							imf_present_local AS local, imf_present_base AS base,
							imf_imap_uid AS uid, imf_imap_uidvalidity AS uidv
					 FROM imf_inbound_message_folders")->fetchAll(PDO::FETCH_ASSOC);
				foreach ($rows as $r) {
					$msg = intval($r['msg']);
					$fid = intval($r['folder']);
					$gid = $folderGroup[$fid] ?? null;
					if ($gid && $pgbool($r['local'])) {
						(new Group(intval($gid), TRUE))->add_member($msg);
					}
					if ($pgbool($r['base'])) {
						ImapFolderMembership::setBaseline($msg, $fid, true,
							$r['uid'] !== null ? intval($r['uid']) : null,
							$r['uidv'] !== null ? intval($r['uidv']) : null);
					}
				}
				$dblink->exec("DROP TABLE IF EXISTS imf_inbound_message_folders");
			}

			// 3. Repoint filter label actions (old fil_action_label_id = an iif id).
			$hasOld = $dblink->query(
				"SELECT 1 FROM information_schema.columns
				 WHERE table_name = 'fil_inbound_email_filters'
				   AND column_name = 'fil_action_label_id'")->fetchColumn();
			if ($hasOld) {
				$filters = $dblink->query(
					"SELECT fil_inbound_email_filter_id AS id, fil_action_label_id AS folder
					 FROM fil_inbound_email_filters
					 WHERE fil_action_label_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
				$upd = $dblink->prepare(
					"UPDATE fil_inbound_email_filters
					 SET fil_action_grp_group_id = ? WHERE fil_inbound_email_filter_id = ?");
				foreach ($filters as $f) {
					$gid = $folderGroup[intval($f['folder'])] ?? null;
					if ($gid) {
						$upd->execute(array(intval($gid), intval($f['id'])));
					}
				}
				$dblink->exec("ALTER TABLE fil_inbound_email_filters DROP COLUMN IF EXISTS fil_action_label_id");
			}
		},
	],

	[
		// One-time forward conversion from the Groups-based labels (the prior iteration)
		// to the dedicated ilb_/ilm_ model (specs/inbound_email_labels.md). Runs once
		// against the one database (the plugin is not distributed). Standard labels are
		// columns; only custom labels become rows, so role/standard memberships are
		// discarded — their state already lives in iem_ columns. Guarded on the legacy
		// iif_grp_group_id column so a re-run after the drop is a no-op.
		//
		//   1. Create an ilb_ label per genuine custom inbound_label Group (by name),
		//      skipping the Gmail system folders that leaked in as custom ([Gmail]/Starred
		//      is already iem_is_starred; [Gmail]/Important is dropped).
		//   2. Repoint each folder: a custom folder to its label, special-use/coverage to NULL.
		//   3. Convert each custom-label grm membership into one ilm_ row (present_local),
		//      folding the matching ifm_ shadow (present_base + UID + binding) inline.
		//   4. Repoint each filter's label action to the new label id, drop the old column.
		//   5/6. Drop the ifm_ table, the inbound_label/inbound_imap_role groups + their
		//      memberships, and the legacy iif_grp_group_id column.
		'id' => 'iem_010_convert_groups_to_dedicated_labels',
		'version' => '1.21.0',
		'up' => function($dbconnector) {
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
			$dblink = $dbconnector->get_db_link();
			$pgbool = function($v) { return ($v === true || $v === 't' || $v === '1' || $v === 1); };

			// Guard: only run while the legacy binding column still exists.
			$hasIifGroup = $dblink->query(
				"SELECT 1 FROM information_schema.columns
				 WHERE table_name = 'iif_inbound_imap_folders' AND column_name = 'iif_grp_group_id'")->fetchColumn();
			if (!$hasIifGroup) {
				return;
			}

			// 1. ilb_ label per custom inbound_label group, skipping the Gmail system leftovers.
			$skip = array('[gmail]/starred', '[gmail]/important');
			$groupLabel = array(); // grp id => ilb id
			$groups = $dblink->query(
				"SELECT grp_group_id, grp_name FROM grp_groups
				 WHERE grp_category = 'inbound_label' AND grp_delete_time IS NULL")->fetchAll(PDO::FETCH_ASSOC);
			foreach ($groups as $g) {
				if (in_array(strtolower(trim((string)$g['grp_name'])), $skip, true)) {
					continue;
				}
				$label = InboundEmailLabel::findOrCreate((string)$g['grp_name']);
				if ($label) {
					$groupLabel[intval($g['grp_group_id'])] = intval($label->key);
				}
			}

			// 2. Repoint folder bindings: custom folder -> its label; special-use/coverage -> NULL.
			$updFolder = $dblink->prepare(
				"UPDATE iif_inbound_imap_folders SET iif_ilb_inbound_email_label_id = ?
				 WHERE iif_inbound_imap_folder_id = ?");
			$folders = $dblink->query(
				"SELECT iif_inbound_imap_folder_id AS id, iif_grp_group_id AS gid
				 FROM iif_inbound_imap_folders")->fetchAll(PDO::FETCH_ASSOC);
			foreach ($folders as $f) {
				$gid = $f['gid'] !== null ? intval($f['gid']) : 0;
				$updFolder->execute(array($groupLabel[$gid] ?? null, intval($f['id'])));
			}

			// 3. Custom-label memberships -> ilm_ rows, folding the ifm_ shadow inline.
			$hasIfm = $dblink->query("SELECT to_regclass('public.ifm_imap_folder_membership')")->fetchColumn();
			$insIlm = $dblink->prepare(
				"INSERT INTO ilm_inbound_label_members
				   (ilm_iem_inbound_email_message_id, ilm_ilb_inbound_email_label_id, ilm_present_local,
				    ilm_present_base, ilm_iif_inbound_imap_folder_id, ilm_imap_uid, ilm_imap_uidvalidity,
				    ilm_create_time, ilm_update_time)
				 VALUES (?, ?, true, ?, ?, ?, ?, now(), now())");
			$bindFolder = $dblink->prepare(
				"SELECT iif_inbound_imap_folder_id FROM iif_inbound_imap_folders
				 WHERE iif_grp_group_id = ? AND iif_iia_inbound_imap_account_id = ? LIMIT 1");
			$shadow = $dblink->prepare(
				"SELECT ifm_present_base AS base, ifm_imap_uid AS uid, ifm_imap_uidvalidity AS uidv
				 FROM ifm_imap_folder_membership
				 WHERE ifm_iem_inbound_email_message_id = ? AND ifm_iif_inbound_imap_folder_id = ? LIMIT 1");
			foreach ($groupLabel as $gid => $lid) {
				$members = $dblink->prepare(
					"SELECT gm.grm_foreign_key_id AS msg, m.iem_iia_inbound_imap_account_id AS feed
					 FROM grm_group_members gm
					 JOIN iem_inbound_email_messages m ON m.iem_inbound_email_message_id = gm.grm_foreign_key_id
					 WHERE gm.grm_grp_group_id = ?");
				$members->execute(array($gid));
				foreach ($members->fetchAll(PDO::FETCH_ASSOC) as $row) {
					$msg = intval($row['msg']);
					$feed = $row['feed'] !== null ? intval($row['feed']) : 0;
					$folderId = null; $base = false; $uid = null; $uidv = null;
					if ($feed) {
						$bindFolder->execute(array($gid, $feed));
						$fid = $bindFolder->fetchColumn();
						if ($fid) {
							$folderId = intval($fid);
							if ($hasIfm) {
								$shadow->execute(array($msg, $folderId));
								$s = $shadow->fetch(PDO::FETCH_ASSOC);
								if ($s) {
									$base = $pgbool($s['base']);
									$uid  = $s['uid']  !== null ? intval($s['uid'])  : null;
									$uidv = $s['uidv'] !== null ? intval($s['uidv']) : null;
								}
							}
						}
					}
					// Unbound (local label, or no bound folder on the feed) stays clean-local.
					if ($folderId === null) {
						$base = true;
					}
					$insIlm->execute(array($msg, $lid, $base ? 'true' : 'false', $folderId, $uid, $uidv));
				}
			}

			// 4. Repoint filter label actions, then drop the legacy column.
			$hasFilGroup = $dblink->query(
				"SELECT 1 FROM information_schema.columns
				 WHERE table_name = 'fil_inbound_email_filters' AND column_name = 'fil_action_grp_group_id'")->fetchColumn();
			if ($hasFilGroup) {
				$updFil = $dblink->prepare(
					"UPDATE fil_inbound_email_filters SET fil_action_ilb_inbound_email_label_id = ?
					 WHERE fil_inbound_email_filter_id = ?");
				$filters = $dblink->query(
					"SELECT fil_inbound_email_filter_id AS id, fil_action_grp_group_id AS gid
					 FROM fil_inbound_email_filters WHERE fil_action_grp_group_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
				foreach ($filters as $f) {
					$lid = $groupLabel[intval($f['gid'])] ?? null;
					if ($lid) {
						$updFil->execute(array($lid, intval($f['id'])));
					}
				}
				$dblink->exec("ALTER TABLE fil_inbound_email_filters DROP COLUMN IF EXISTS fil_action_grp_group_id");
			}

			// 5/6. Drop the retired shadow table, the inbound_label/inbound_imap_role groups
			//      + their memberships (role/standard state lives in columns or is dropped),
			//      and the legacy folder binding column.
			$dblink->exec("DROP TABLE IF EXISTS ifm_imap_folder_membership");
			$dblink->exec(
				"DELETE FROM grm_group_members WHERE grm_grp_group_id IN
				   (SELECT grp_group_id FROM grp_groups WHERE grp_category IN ('inbound_label', 'inbound_imap_role'))");
			$dblink->exec("DELETE FROM grp_groups WHERE grp_category IN ('inbound_label', 'inbound_imap_role')");
			$dblink->exec("ALTER TABLE iif_inbound_imap_folders DROP COLUMN IF EXISTS iif_grp_group_id");
		},
	],

	[
		// Dedicated-label hot paths (specs/inbound_email_labels.md). The auto-updater does
		// not create non-unique/partial indexes, so add: the partial dirty index that makes
		// the sync push O(dirty), the VANISHED correlation by (binding, uid), and the
		// by-message and by-label lookups.
		'id' => 'iem_011_label_member_indexes',
		'version' => '1.21.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS ilm_dirty_idx
				 ON ilm_inbound_label_members (ilm_iif_inbound_imap_folder_id)
				 WHERE ilm_present_local <> ilm_present_base"
			);
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS ilm_folder_uid_idx
				 ON ilm_inbound_label_members (ilm_iif_inbound_imap_folder_id, ilm_imap_uid)"
			);
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS ilm_message_idx
				 ON ilm_inbound_label_members (ilm_iem_inbound_email_message_id)"
			);
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS ilm_label_idx
				 ON ilm_inbound_label_members (ilm_ilb_inbound_email_label_id)"
			);
		},
	],
];
