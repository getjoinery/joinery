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
 * @version 1.27.0
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
			// imf_ is a retired table (converted away by iem_009); on a fresh
			// install it never exists, so its indexes are skipped — they died
			// with the table anyway.
			$hasImf = $dblink->query("SELECT to_regclass('public.imf_inbound_message_folders')")->fetchColumn();
			if ($hasImf) {
				$dblink->exec(
					"CREATE INDEX IF NOT EXISTS imf_folder_uid_idx
					 ON imf_inbound_message_folders (imf_iif_inbound_imap_folder_id, imf_imap_uid)"
				);
				$dblink->exec(
					"CREATE INDEX IF NOT EXISTS imf_message_idx
					 ON imf_inbound_message_folders (imf_iem_inbound_email_message_id)"
				);
			}
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
			// ifm_ is a retired table (dropped by iem_010); on a fresh install
			// it never exists, so its indexes are skipped — they died with the
			// table anyway.
			$hasIfm = $dblink->query("SELECT to_regclass('public.ifm_imap_folder_membership')")->fetchColumn();
			if ($hasIfm) {
				$dblink->exec(
					"CREATE INDEX IF NOT EXISTS ifm_folder_uid_idx
					 ON ifm_imap_folder_membership (ifm_iif_inbound_imap_folder_id, ifm_imap_uid)"
				);
				$dblink->exec(
					"CREATE INDEX IF NOT EXISTS ifm_message_idx
					 ON ifm_imap_folder_membership (ifm_iem_inbound_email_message_id)"
				);
			}
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
			// Historical no-op. The conversion this migration performed ran
			// against the imf_-era schema, and its machinery (the
			// ImapFolderMembership class, InboundImapFolder::ensureGroup) no
			// longer exists in the codebase — the Groups-based model it
			// converted INTO was itself retired by iem_010. Every database
			// that held imf_-era state has already applied it (tracked in
			// plm_plugin_migrations); a fresh install has nothing to convert.
			// Recorded as applied so the history stays linear.
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

	[
		// Encryption at rest (specs/implemented/inbound_email_encryption_at_rest.md
		// § 6.1): iem_sender/iem_subject/iem_body_plain/iem_body_html hold
		// ciphertext once a mailbox owner holds a Sealed Vault, so the GIN
		// tsvector index scanning them is both useless (never matches a search
		// term) and a wasted-write cost on every ingest. Search moves to
		// MailboxIndex (a per-owner sealed FTS5 working copy). iem_007 itself is
		// left in place as history — a plain drop, not a rollback, so
		// update_database never recreates it.
		'id' => 'iem_012_drop_fulltext_gin_index',
		'version' => '1.30.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("DROP INDEX IF EXISTS iem_fulltext_idx");
		},
	],

	[
		// Sealing fix pack (specs/mailbox_sealing_fix_pack.md, Fix 2): sealed
		// state became a per-attachment fact (ima_is_sealed) instead of an
		// inference from the message's flags. Every file-backed attachment of an
		// already-sealed message was sealed by ingest under the old inference,
		// so stamp them; plaintext Files left by backfill on rows sealed AFTER
		// this fix land with the flag correctly false at write time.
		'id' => 'ima_001_stamp_sealed_attachment_flags',
		'version' => '1.31.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"UPDATE ima_inbound_message_attachments SET ima_is_sealed = true
				 WHERE ima_fil_file_id IS NOT NULL
				 AND ima_iem_inbound_email_message_id IN (
					SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE iem_content_sealed = true)");
		},
	],

	[
		// Sealing fix pack (specs/mailbox_sealing_fix_pack.md, Fix 7): decryption
		// resolves the vault owner from the row itself (iem_sealed_owner_user_id,
		// written at seal time) so grant/alias changes can never strand sealed
		// mail. Populate existing sealed rows from the alias's current single
		// grantee — the same resolution their sealer used at the time.
		'id' => 'iem_013_populate_sealed_owner',
		'version' => '1.31.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"UPDATE iem_inbound_email_messages m
				 SET iem_sealed_owner_user_id = g.owner_id
				 FROM (
					SELECT ieg_iea_inbound_email_alias_id AS alias_id,
						   MIN(ieg_usr_user_id) AS owner_id
					FROM ieg_inbound_email_mailbox_grants
					GROUP BY ieg_iea_inbound_email_alias_id
					HAVING COUNT(*) = 1
				 ) g
				 WHERE m.iem_iea_inbound_email_alias_id = g.alias_id
				 AND m.iem_content_sealed = true
				 AND m.iem_sealed_owner_user_id IS NULL");
		},
	],

	[
		// Send-protection fix pack (specs/mailbox_send_protection_fix_pack.md,
		// Fix 8): the forwarding subdomain is strictly per-domain
		// (ied_forwarding_subdomain) — a server-wide value would rewrite one
		// tenant's SRS envelope onto another tenant's subdomain. The setting is
		// no longer declared in plugin.json; drop its seeded row.
		'id' => 'stg_001_drop_forwarding_subdomain_setting',
		'version' => '1.34.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$stmt = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name = ?");
			$stmt->execute(array('mailbox_forwarding_subdomain'));
		},
	],

	[
		// Data-loss hardening (specs/mailbox_data_loss_fixes.md, Fix 1): the
		// retention purge is removed outright — it hard-deleted every stored
		// message older than mailbox_retention_days with no exemption, a
		// test-capture-era housekeeping behavior that is wrong for a real
		// archive. Drop its seeded setting and tombstone any scheduled-task
		// registration so the deleted task class can never be re-armed.
		'id' => 'stg_002_remove_retention_purge',
		'version' => '1.35.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$stmt = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name = ?");
			$stmt->execute(array('mailbox_retention_days'));
			$stmt = $dblink->prepare(
				"UPDATE sct_scheduled_tasks SET sct_delete_time = now()
				 WHERE sct_task_class = ? AND sct_delete_time IS NULL");
			$stmt->execute(array('PurgeOldMailboxMessages'));
		},
	],

	[
		// Data-loss hardening (specs/mailbox_data_loss_fixes.md, Fix 3): the
		// per-window store cap now defaults to 0 (disabled) and defers rather
		// than drops when set. Adopt the new default only on deployments still
		// on the old 500 default; a deliberately-customized cap is left alone.
		'id' => 'stg_003_disable_default_store_cap',
		'version' => '1.35.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$stmt = $dblink->prepare(
				"UPDATE stg_settings SET stg_value = '0'
				 WHERE stg_name = ? AND stg_value = '500'");
			$stmt->execute(array('mailbox_max_per_window'));
		},
	],

	[
		// The sealed search index covers every stored message, trashed ones
		// included (specs/mailbox_trash_folder.md § Change 2a). An index built
		// before that skipped trashed rows while its high-water mark advanced past
		// them, so those rows would never be revisited and restoring one could
		// never make it searchable again. Drop each persisted index and reset its
		// mark, so the next unlock rebuilds with full coverage — the index is a
		// disposable cache, entirely reconstructible from the message rows.
		'id' => 'imi_001_reset_index_for_full_coverage',
		'version' => '1.67.0',
		'up' => function($dbconnector) {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));
			$index = new MailboxIndex();
			$rows = $dbconnector->get_db_link()
				->query('SELECT imi_usr_user_id FROM imi_inbound_mailbox_search_index')
				->fetchAll(PDO::FETCH_COLUMN);
			foreach ($rows as $uid) {
				$index->purgePersisted(intval($uid));
			}
		},
	],

	[
		// Restore the full-text GIN index. iem_012 dropped it when the sealed
		// MailboxIndex arrived, but only a SEALED mailbox searches through that
		// index — every unsealed scope still runs the tsvector expression in
		// MailboxService, which was left doing a sequential scan over every body.
		//
		// Built from MailboxService::FULLTEXT_SQL rather than retyped, because the
		// index is only usable while the two expressions match byte for byte —
		// which is exactly how this one came to be silently unused before.
		'id' => 'iem_013_restore_fulltext_gin_index',
		'version' => '1.60.0',
		'up' => function($dbconnector) {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
			$dbconnector->get_db_link()->exec(
				"CREATE INDEX IF NOT EXISTS iem_fulltext_idx
				 ON iem_inbound_email_messages
				 USING GIN (" . MailboxService::FULLTEXT_SQL . ")");
		},
	],

	[
		// Carry the raised batch size to deployments that already hold a row.
		// Declared settings seed only where the name is MISSING, so a factory
		// default only ever reaches a fresh install — every site installed before
		// this kept importing 200 messages a pass, which is what made a large
		// import crawl.
		//
		// Only rows still sitting at the old factory value are touched. A number an
		// operator actually chose is left exactly as they left it.
		'id' => 'stg_004_raise_import_batch_size',
		'version' => '1.76.0',
		'up' => function($dbconnector) {
			$q = $dbconnector->get_db_link()->prepare(
				"UPDATE stg_settings SET stg_value = '1000'
				 WHERE stg_name = 'mailbox_import_batch_size' AND stg_value = '200'");
			$q->execute();
			echo $q->rowCount() > 0
				? "mailbox_import_batch_size raised from the old factory 200 to 1000.\n"
				: "mailbox_import_batch_size left alone (not at the old factory 200).\n";
		},
	],

	[
		// An IMAP feed's import history boolean becomes a three-way scope, so a
		// feed can reach back a fixed number of days instead of only
		// all-or-nothing. Every feed that was importing history is a 'full' feed.
		//
		// Lives HERE rather than in core migrations because iia_import_scope is a
		// plugin-table column: plugin sync creates it, and plugin migrations run
		// right after that sync — a core migration would run a step earlier and
		// fail on the missing column, halting the core migration loop.
		//
		// Runs before the deferred column-drop step of update_database, so the
		// boolean is still there to read. Idempotent, and a no-op once it is gone.
		'id' => 'iia_001_backfill_import_scope',
		'version' => '1.93.8',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Plugin-table sync adds new columns WITHOUT their
			// field_specifications DEFAULT (the same gap iem_002/iem_004 close),
			// so set the defaults, backfill the NULL rows, and finalize NOT NULL
			// on the scope column the data class declares.
			$dblink->exec("ALTER TABLE iia_inbound_imap_accounts ALTER COLUMN iia_import_scope SET DEFAULT 'future'");
			$dblink->exec("ALTER TABLE iia_inbound_imap_accounts ALTER COLUMN iia_import_days SET DEFAULT 30");
			$dblink->exec("UPDATE iia_inbound_imap_accounts SET iia_import_scope = 'future' WHERE iia_import_scope IS NULL");
			$dblink->exec("UPDATE iia_inbound_imap_accounts SET iia_import_days = 30 WHERE iia_import_days IS NULL");

			$has_bool = (int)$dblink->query(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = 'public' AND table_name = 'iia_inbound_imap_accounts'
				   AND column_name = 'iia_import_history'")->fetchColumn();
			if ($has_bool) {
				$q = $dblink->query(
					"UPDATE iia_inbound_imap_accounts SET iia_import_scope = 'full'
					 WHERE iia_import_history = true AND iia_import_scope IS DISTINCT FROM 'full'");
				echo "iia_inbound_imap_accounts: " . $q->rowCount() . " feed(s) recorded as full-history.\n";
			} else {
				echo "iia_import_history already dropped, nothing to carry over.\n";
			}

			$dblink->exec("ALTER TABLE iia_inbound_imap_accounts ALTER COLUMN iia_import_scope SET NOT NULL");
		},
	],

	[
		// Carry the old yes/no cloud consent onto the three-valued one.
		//
		// A boolean could not say "a vendor I have accepted, but not a general
		// cloud", which is a distinction an operator can reasonably want. The
		// new column holds the most permissive endpoint trust class this
		// domain's decrypted mail may reach, using the same three names an
		// endpoint uses for its own trust — so the sealed-egress gate is a
		// comparison rather than a translation.
		//
		// Mapping: false -> 'local' (it never left the box), true -> 'cloud'
		// (it could reach anywhere). Nothing lands on 'trusted', because nobody
		// has been asked that question yet and inventing an answer would be
		// widening a consent on their behalf.
		//
		// Plugin tables sync AFTER the core migration list runs, so on the first
		// pass the new column may not exist yet; a missing column here would
		// throw and halt the loop. The guard DEFERS (stays pending, nothing
		// recorded) rather than returning — a plain return would be recorded as
		// applied and the carry-over would never happen. The next
		// update_database pass runs it for real.
		'id' => 'ied_001_ai_processing_consent',
		'version' => '1.26.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			$has_new = (int)$dblink->query(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = 'public' AND table_name = 'ied_inbound_email_domains'
				   AND column_name = 'ied_ai_processing_consent'")->fetchColumn();
			if (!$has_new) {
				echo "ied_ai_processing_consent not present yet - deferred to the next update_database pass.\n";
				return 'defer';
			}

			// Plugin-table sync adds a new column without its
			// field_specifications DEFAULT, so set it before anything writes.
			$dblink->exec("ALTER TABLE ied_inbound_email_domains
			               ALTER COLUMN ied_ai_processing_consent SET DEFAULT 'local'");
			$dblink->exec("UPDATE ied_inbound_email_domains SET ied_ai_processing_consent = 'local'
			               WHERE ied_ai_processing_consent IS NULL OR ied_ai_processing_consent = ''");

			$has_old = (int)$dblink->query(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = 'public' AND table_name = 'ied_inbound_email_domains'
				   AND column_name = 'ied_ai_cloud_enabled'")->fetchColumn();
			if ($has_old) {
				$q = $dblink->query(
					"UPDATE ied_inbound_email_domains SET ied_ai_processing_consent = 'cloud'
					 WHERE ied_ai_cloud_enabled = true
					   AND ied_ai_processing_consent IS DISTINCT FROM 'cloud'");
				echo "ied_inbound_email_domains: " . $q->rowCount() . " domain(s) carried over as 'cloud'.\n";
			} else {
				echo "ied_ai_cloud_enabled already dropped, nothing to carry over.\n";
			}

			$dblink->exec("ALTER TABLE ied_inbound_email_domains
			               ALTER COLUMN ied_ai_processing_consent SET NOT NULL");
		},
	],

	[
		// Drop the retired yes/no cloud consent column once ied_001 has carried
		// its value onto the three-valued one. It is already out of the data
		// class, so nothing reads or writes it — but while the physical column
		// lingers, a future re-run of the carry-over could re-widen a consent an
		// operator has since tightened. Deferred until the new column is
		// finalized NOT NULL, which is ied_001's last statement — so this can
		// never destroy the old value before it has been carried over.
		'id' => 'ied_002_drop_retired_cloud_consent',
		'version' => '1.26.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			$has_old = (int)$dblink->query(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = 'public' AND table_name = 'ied_inbound_email_domains'
				   AND column_name = 'ied_ai_cloud_enabled'")->fetchColumn();
			if (!$has_old) {
				echo "ied_ai_cloud_enabled already gone, nothing to drop.\n";
				return;
			}

			$carried = (int)$dblink->query(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = 'public' AND table_name = 'ied_inbound_email_domains'
				   AND column_name = 'ied_ai_processing_consent' AND is_nullable = 'NO'")->fetchColumn();
			if (!$carried) {
				echo "ied_001 has not completed yet - deferred to the next update_database pass.\n";
				return 'defer';
			}

			$dblink->exec("ALTER TABLE ied_inbound_email_domains DROP COLUMN ied_ai_cloud_enabled");
			echo "ied_ai_cloud_enabled dropped.\n";
		},
	],

	[
		// A Direct signing identity minted for an IMAP-source domain (the Setup
		// tab's advanced run used to do this for gmail.com) claims an authority
		// the deployment does not have, flips the messenger's siteReady, and
		// dead-ends the operator on DNS records nobody can publish
		// (specs/imap_source_domain_boundaries.md § 4). The mint now refuses;
		// this removes any row minted before it did. Hard delete: the row is a
		// keypair nothing may ever verify against, not history worth keeping.
		'id' => 'jdi_001_drop_imap_source_identities',
		'version' => '1.96.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$count = $dblink->exec(
				"DELETE FROM jdi_direct_identities
				 WHERE jdi_domain IN (
					SELECT ied_domain FROM ied_inbound_email_domains
					WHERE ied_is_imap_source = true AND ied_delete_time IS NULL)"
			);
			echo ($count > 0)
				? "Removed $count Direct signing identity row(s) on IMAP-source domains.\n"
				: "No Direct signing identities on IMAP-source domains, nothing to remove.\n";
		},
	],
];
