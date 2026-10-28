<?php
/**
 * Every site was installed with getjoinery's Terms of Use and Privacy Policy
 * as its own terms_url and privacy_url, so each site's members were asked to
 * accept getjoinery's terms at sign-in. A site whose setting still holds
 * exactly that default is cleared: it has no terms of its own until its owner
 * names them, and its members are asked nothing. A site that named its own
 * pages keeps them, and getjoinery's own site is left alone, where those pages
 * are its own (specs/easy_signup_completion.md Q9).
 *
 * Idempotent: once the value is no longer the old default there is nothing to do.
 */
function terms_urls_not_getjoinery() {
	$settings = Globalvars::get_instance();
	$host = strtolower(trim((string)$settings->get_setting('webDir')));
	if ($host === 'getjoinery.com' || $host === 'www.getjoinery.com') {
		echo "  getjoinery's own site: its terms are its own, left as they are.\n";
		return true;
	}

	$db = DbConnector::get_instance()->get_db_link();
	$clear = $db->prepare('UPDATE stg_settings SET stg_value = ? WHERE stg_name = ? AND TRIM(stg_value) = ?');
	foreach (array(
		'terms_url'   => 'https://getjoinery.com/page/terms',
		'privacy_url' => 'https://getjoinery.com/page/privacy',
	) as $name => $old_default) {
		$clear->execute(array('', $name, $old_default));
		if ($clear->rowCount() > 0) {
			echo "  Cleared {$name}: it named getjoinery's page, not this site's.\n";
		}
	}
	return true;
}
