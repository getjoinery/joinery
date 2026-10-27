<?php
/**
 * Replaces the Integral Zen account emails every site was installed with by the
 * neutral defaults in email_templates/ (spec email_template_defaults, B167).
 *
 * A row is replaced only while its subject and body are still byte-for-byte the
 * shipped Integral Zen text, compared by the SHA-256 of each original below. A
 * row an admin has changed in any way, and a soft-deleted row, is left alone.
 * Integral Zen's own site is left alone entirely: there the text is its own.
 *
 * Runs once (its database version).
 */
function email_templates_neutral_defaults() {
	$settings = Globalvars::get_instance();
	$site_name = (string)$settings->get_setting('site_name');
	$web_dir = (string)$settings->get_setting('webDir');
	if (preg_match('/integral\s*zen/i', $site_name) || stripos($web_dir, 'integralzen.org') !== false) {
		echo "  Integral Zen's own site: its email templates are its own, left as they are.\n";
		return true;
	}

	// name => [sha256 of the shipped subject, sha256 of the shipped body]
	$shipped = array(
		'default_outer_template' => array('9c817e2486372a3c5f1cf0854e34383b0002dae58c24f6926ad26f545f13530a', 'e6b5912c88222a29308c6461369f0158015588fd2fd01e137ba26daf046c8af6'),
		'event_bulk_footer'      => array('fb6061adbfa853cd2a2a8681998c2c541920602399c951ea12dff51ad8a1aa06', '76f7bb7078f48442fd44f8c1660aa57d6967b27ef4c7ed80722d74a6edee1721'),
		'activation_content'     => array('094721d720c7741c668c924020244d7a7daa7a03c259a74c3e46e540f005e9ce', '9fe0368ed124d7a75d8fe73c8df2011ef0500c8086efbb8aabda125b3512a73b'),
		'new_account_content'    => array('32d4d61c6059f2023b2a5a54138d948ce1b5f35938f77fe46fee0eab8af04044', '81726bf4ac4f8dec5bbf51e26dd76feab16480ef3992d3c2f43d69b16328a35d'),
		'forgotpw_content'       => array('a3addb4fd7e8600b0dfa96b52b5b69cb2e4f6ff15e4c7f722395b269ef593f3d', 'b437622b1d2e24b7164f4124e38b8eaf11bd5a52da00de8b87aaeca9cb0bdbdf'),
		'email_change_content'   => array('561625d836c6af3d6e1eaf8b7c863dd1a56668bca60168339627a9e1efdc3963', 'c6bdac2079406d337f2cd7ee83dcaac0813a79a3f37397bf01b7e03fe2f6b260'),
		'newsletter-1'           => array('b17e168cdaf4ea3b742d3c4e72e27df6aec9199c0fefb71544068455e844669d', '3d5db01fcbbd993ab70f7c542483ba050f17b129b90bcbe9ed688e5444715304'),
	);

	$db = DbConnector::get_instance()->get_db_link();
	$select = $db->prepare('SELECT emt_email_template_id, emt_subject, emt_body FROM emt_email_templates
		WHERE emt_name = ? AND emt_delete_time IS NULL');
	$update = $db->prepare('UPDATE emt_email_templates SET emt_subject = ?, emt_body = ?, emt_update_time = NOW()
		WHERE emt_email_template_id = ?');

	$replaced = array();
	foreach ($shipped as $name => $hashes) {
		$default = EmailTemplateDefaults::get($name);
		if (!$default) {
			echo "  No default for $name in email_templates/; skipped.\n";
			continue;
		}
		$select->execute(array($name));
		foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
			if (hash('sha256', (string)$row['emt_subject']) !== $hashes[0]
				|| hash('sha256', (string)$row['emt_body']) !== $hashes[1]) {
				continue;
			}
			$update->execute(array($default['subject'], $default['body'], (int)$row['emt_email_template_id']));
			$replaced[] = $name;
		}
	}

	echo $replaced
		? '  Replaced the shipped Integral Zen text in: ' . implode(', ', $replaced) . "\n"
		: "  No template still holds the shipped Integral Zen text.\n";
	return true;
}
?>
