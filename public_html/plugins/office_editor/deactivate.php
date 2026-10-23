<?php
/**
 * Deactivation hook for office_editor (PluginManager::deactivate() runs it
 * while the plugin's classes still resolve).
 *
 * Switching the plugin off stops the editor and takes its pages off this
 * site's address (`office-editor-ctl off`), so nothing of it stays running or
 * reachable. The package stays: activating the plugin again, the host
 * converger runs the installer and puts the pages back. A failure is logged
 * and does not hold the plugin on.
 *
 * @version 0.1.0
 */

function office_editor_deactivate() {
	$run = OfficeEditor::hostCommand('off');
	if (!$run['ok']) {
		error_log('office_editor deactivate: the editor was not switched off: ' . $run['message']);
	}
}
?>
