<?php
/**
 * Logic for the old admin address of the mailbox reader: sends the visitor to
 * the member reader, carrying a mailbox (?mailbox=N) or a message (?open=N)
 * the link named.
 *
 * @version 2.0 - redirect to /profile/mailbox/mailbox
 */

function admin_mailbox_reader_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$query = array();
	foreach (array('mailbox', 'open') as $key) {
		$id = intval($input[$key] ?? 0);
		if ($id > 0) {
			$query[$key] = $id;
		}
	}
	return LogicResult::redirect('/profile/mailbox/mailbox' . ($query ? '?' . http_build_query($query) : ''));
}
?>
