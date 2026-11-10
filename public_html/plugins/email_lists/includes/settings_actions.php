<?php
/**
 * Plugin Settings fragment for email_lists — rendered under the plugin's
 * settings form. Shows whether the chosen mailing list provider answers with
 * the saved credentials. The check reads the provider and writes nothing; it
 * runs when asked (?check_provider=1), not on every page view, since it is a
 * call to an outside service.
 *
 * @version 1.0
 */

$el_provider_key = (string)Globalvars::get_instance()->get_setting('mailing_list_provider', true, true);
$el_provider = $el_provider_key !== '' ? MailingListService::getProvider($el_provider_key) : null;

echo '<h5>Provider connection</h5>';
if (!$el_provider) {
	echo '<p class="text-muted">No mailing list provider selected.</p>';
} elseif (!$el_provider::validateConfiguration()['valid']) {
	echo '<p class="text-muted">Credentials not configured.</p>';
} elseif (empty($_GET['check_provider'])) {
	echo '<p><a href="?plugin=email_lists&amp;check_provider=1" class="btn btn-outline-primary btn-sm">Check the connection</a></p>';
} else {
	$el_result = $el_provider->validateApiConnection();
	if (!empty($el_result['success'])) {
		echo '<p style="color:#28a745;"><strong>&#10003; ' . htmlspecialchars((string)$el_result['label']) . '</strong></p>';
		foreach ((array)($el_result['details'] ?? array()) as $el_key => $el_value) {
			echo '<div class="text-muted" style="font-size:0.85em;">' . htmlspecialchars((string)$el_key) . ': ' . htmlspecialchars((string)$el_value) . '</div>';
		}
	} else {
		echo '<p style="color:#dc3545;"><strong>&#10007; ' . htmlspecialchars((string)($el_result['label'] ?? 'Connection failed')) . '</strong></p>';
		if (!empty($el_result['error'])) {
			echo '<p class="text-muted" style="font-size:0.85em;">' . htmlspecialchars((string)$el_result['error']) . '</p>';
		}
	}
}
