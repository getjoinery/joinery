<?php
/**
 * The Custom CSS setting is retired (specs/style_themes.md). What it was for,
 * a small per-site style fix, is a style theme: a package of stylesheets
 * applied from the Themes page as the look, with a name and a file.
 *
 * A site whose row is absent or blank loses nothing, and nothing is written.
 * A site whose row holds CSS is told: every superadmin is emailed the saved
 * text in full, with where it belongs now. Then the row is deleted, so no
 * undeclared setting is left behind and nothing keeps rendering a value from
 * nowhere visible. The email is the record.
 *
 * Idempotent: once the row is gone there is nothing to send or delete.
 */
function custom_css_retired() {
	$db = DbConnector::get_instance()->get_db_link();

	$read = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$read->execute(array('custom_css'));
	$css = $read->fetchColumn();
	if ($css === false) {
		return true;
	}
	$css = (string)$css;

	if (trim($css) !== '') {
		$site = (string)Globalvars::get_instance()->get_setting('webDir', true, true);
		$subject = 'The Custom CSS setting on ' . $site . ' is retired; here is what it held';
		$body = "The Custom CSS setting is gone from " . $site . ", and the CSS below no longer loads on any page.\n\n"
			. "This CSS belongs in a style theme: a folder holding a theme.json that lists it under \"styles\", "
			. "zipped and uploaded on the admin Themes page, then applied as the look. "
			. "It loads after the active theme's stylesheet on every page, which is where Custom CSS was meant to sit.\n\n"
			. "Saved text, in full:\n\n"
			. "----------------------------------------\n"
			. $css . "\n"
			. "----------------------------------------\n";
		$sent = 0;
		foreach (new MultiUser(array('permission_range' => array(10, 10), 'deleted' => FALSE)) as $admin) {
			$to = trim((string)$admin->get('usr_email'));
			if ($to === '' || $admin->get('usr_is_disabled') || $admin->get('usr_is_admin_disabled')) {
				continue;
			}
			try {
				EmailSender::quickSend($to, $subject, $body);
				$sent++;
			} catch (Throwable $e) {
				echo "  Could not email $to the retired Custom CSS: " . $e->getMessage() . "\n";
			}
		}
		echo "  Custom CSS held " . strlen($css) . " bytes; emailed the text to $sent superadmin(s).\n";
		if ($sent === 0) {
			// Nobody was told, so the only record would be the row. Keep it
			// until someone can be: the next run tries again.
			echo "  No superadmin could be emailed; keeping the row for the next run.\n";
			return true;
		}
	}

	$del = $db->prepare('DELETE FROM stg_settings WHERE stg_name = ?');
	$del->execute(array('custom_css'));
	if ($del->rowCount() > 0) {
		echo "  Removed retired setting custom_css.\n";
	}
	return true;
}
?>
