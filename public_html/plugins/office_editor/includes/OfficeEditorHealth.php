<?php
/**
 * OfficeEditorHealth — the provisioning check behind the Plugins page badge.
 *
 * @version 0.2.0 - checkMembersMay(): unmet when the tiers leave no member able to use the editor
 * @version 0.1.2 - a container site's refusal offers no installer to run (ProvisioningCheckRefused)
 * @version 0.1.0
 */
class OfficeEditorHealth {

	/**
	 * Installed, and the web user may start it: the same two things opening a
	 * document needs. Starting is not tried (no side effects); `status` is the
	 * other command the sudoers rule allows, and answering it proves the rule.
	 */
	public static function checkInstalled() {
		if (!is_file(OfficeEditor::INSTALLED_MARK)) {
			// A container site is never given the editor: running the
			// installer again would only refuse again.
			if (Globalvars::get_instance()->get_setting('deployment_environment', true, true) === 'docker') {
				throw new ProvisioningCheckRefused('Not installed: This site runs in a container; the editor is offered on servers of their own only');
			}
			$why = @file_get_contents(OfficeEditor::REFUSED_MARK);
			throw new ProvisioningCheckFailed(is_string($why) && trim($why) !== ''
				? 'Not installed: ' . trim($why)
				: 'Not installed on this server. Run the plugin installers (Server Manager: Actions → Run Plugin Installers).');
		}
		if (!is_file(OfficeEditor::CTL)) {
			throw new ProvisioningCheckFailed('The editor control command is missing; run the installer again.');
		}
		$out = array();
		$rc = 1;
		@exec('sudo -n ' . escapeshellarg(OfficeEditor::CTL) . ' status 2>&1', $out, $rc);
		if ($rc !== 0) {
			throw new ProvisioningCheckFailed('The site cannot start the editor: ' . trim(implode(' ', $out)));
		}
	}

	/**
	 * Someone may use it: at least one live member has no tier, or is on a
	 * tier that leaves office_editing on (OfficeEditor::memberMay). Without
	 * this the page says Setup complete while Drive offers the editor to
	 * nobody. A member in several tier groups counts as allowed if any of
	 * them allows it; GetUserTier uses one of them, so in that rare case this
	 * can pass while that member is kept out.
	 */
	public static function checkMembersMay() {
		$dblink = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT 1 FROM usr_users u
			WHERE u.usr_delete_time IS NULL
			AND NOT EXISTS (
				SELECT 1 FROM grm_group_members gm
				JOIN grp_groups g ON g.grp_group_id = gm.grm_grp_group_id
					AND g.grp_category = 'subscription_tier' AND g.grp_delete_time IS NULL
				JOIN sbt_subscription_tiers t ON t.sbt_grp_group_id = g.grp_group_id
					AND t.sbt_delete_time IS NULL
				WHERE gm.grm_foreign_key_id = u.usr_user_id
				AND t.sbt_features->'office_editing' = 'false'::jsonb
				AND NOT EXISTS (
					SELECT 1 FROM grm_group_members gm2
					JOIN grp_groups g2 ON g2.grp_group_id = gm2.grm_grp_group_id
						AND g2.grp_category = 'subscription_tier' AND g2.grp_delete_time IS NULL
					JOIN sbt_subscription_tiers t2 ON t2.sbt_grp_group_id = g2.grp_group_id
						AND t2.sbt_delete_time IS NULL
					WHERE gm2.grm_foreign_key_id = u.usr_user_id
					AND (t2.sbt_features->'office_editing' IS NULL OR t2.sbt_features->'office_editing' <> 'false'::jsonb)
				)
			)
			LIMIT 1";
		try {
			$found = $dblink->query($sql)->fetchColumn();
		} catch (PDOException $e) {
			throw new ProvisioningCheckFailed('Could not read the membership tiers: ' . $e->getMessage());
		}
		if (!$found) {
			throw new ProvisioningCheckFailed('No member can use the editor: every member is on a tier with Edit Documents in Drive switched off. Switch it on for a tier to offer it.');
		}
	}
}
