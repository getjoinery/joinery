<?php
/**
 * IncidentTitles — the plain-language line for an incident, composed on this
 * side from its source (incident_triage.md, Data: inc_title).
 *
 * A title is the plane's own words, never text a node sent: a node's case
 * carries its reason, which is shown under the title as the node's account,
 * escaped. So a forged reason can never pose as the plane's verdict.
 *
 * @version 1.0
 */
class IncidentTitles {

	/** What each agent recipe's case means, as the problem a person reads. */
	const RECIPES = array(
		'agent_supervision'  => 'The agent keeps stopping',
		'certificate_expiry' => 'A certificate is not renewing',
		'container_health'   => 'A site container is down or not answering',
		'disk_headroom'      => 'The disk is nearly full',
		'fail2ban'           => 'fail2ban is not running',
		'service_health'     => 'The database, PHP or the web server is not answering',
	);

	/** The title for a source, from its kind and name. */
	public static function for_source(string $source): string {
		if (strpos($source, 'recipe:') === 0) {
			$recipe = substr($source, strlen('recipe:'));
			return self::RECIPES[$recipe] ?? ('The agent\'s ' . $recipe . ' check keeps failing');
		}
		if (strpos($source, 'classifier:') === 0) {
			return 'The agent found a change on the machine it cannot explain';
		}
		return 'Needs a look: ' . $source;
	}

	/** Where the source came from, in a word or two, for the list's Type column. */
	public static function kind_label(string $source): string {
		if (strpos($source, 'recipe:') === 0 || strpos($source, 'classifier:') === 0) {
			return 'Agent';
		}
		if (strpos($source, 'plane:') === 0) {
			return 'Monitoring';
		}
		return 'Other';
	}
}
?>
