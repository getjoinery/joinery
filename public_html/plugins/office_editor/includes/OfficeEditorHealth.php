<?php
/**
 * OfficeEditorHealth — the provisioning check behind the Plugins page badge.
 *
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
			$why = @file_get_contents('/etc/joinery/office_editor/refused');
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
}
