<?php
/**
 * Uninstall hook for office_editor (PluginManager::uninstall() runs it before
 * the plugin's tables are dropped).
 *
 * Takes the editor off this server (`office-editor-ctl remove`): the service
 * and its packages, the Apache drop-in, the idle-stop cron job, the sudoers
 * rule and the control command itself. A failure keeps the plugin installed,
 * so the uninstall can be run again rather than leave a root service behind
 * with nothing to manage it.
 *
 * The plugin is already inactive here, so its classes do not resolve by name:
 * OfficeEditor is loaded by path.
 *
 * @version 0.1.0
 */

function office_editor_uninstall() {
	require_once(PathHelper::getIncludePath('plugins/office_editor/includes/OfficeEditor.php'));
	$run = OfficeEditor::hostCommand('remove');
	if (!$run['ok']) {
		error_log('office_editor uninstall: the editor was not removed from this server: ' . $run['message']);
		return false;
	}
	return true;
}
?>
