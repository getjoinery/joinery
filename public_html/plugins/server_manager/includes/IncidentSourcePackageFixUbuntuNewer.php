<?php
/**
 * IncidentSourcePackageFixUbuntuNewer - plane:package_fix_ubuntu_newer:
 * Ubuntu has shipped a newer build of a source package we carry a fix of
 * our own for (specs/package_fixes_ahead_of_ubuntu.md WP5). A warning, raised
 * on this management node's own node, saying which of two things to do:
 *
 *   - retire the recipe: Ubuntu's tracker shows every CVE it carries released
 *     on 26.04. Machines whose changelog check agrees have handed it back
 *     already; setting the recipe to state=none and publishing releases any
 *     still holding (a "New upstream version" entry names no CVE, so a
 *     machine's check and the tracker can disagree, and the machine's check
 *     never lifts its pin on the tracker's word);
 *   - rebuild the recipe on Ubuntu's newer build: some of its CVEs are not in
 *     it, and machines hold at our build, missing whatever else Ubuntu fixed.
 *
 * Either way it shows what the machines report (handed back, or holding
 * because Ubuntu's build lacks a CVE). Clears when the recipe is retired or
 * rebuilt on the newest Ubuntu build.
 *
 * @version 1.0
 */
class IncidentSourcePackageFixUbuntuNewer implements IncidentSource {

	const NAME = 'plane:package_fix_ubuntu_newer';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node, ?int $now = null): ?array {
		if (!ReleaseLogWatch::isSelf($node)) {
			return null;
		}
		return self::from_state(UpstreamFixWatch::state(), $now ?? time());
	}

	/** The incident the watch's stored state calls for, or null. Pure. */
	public static function from_state(array $state, int $now): ?array {
		if (!$state) {
			return null;
		}
		$c = UpstreamFixWatch::conclusions($state, $now);
		if (!$c['recipes']) {
			return null;
		}
		$lines = array();
		$retire = array();
		$rebuild = array();
		foreach ($c['recipes'] as $r) {
			$nodes = $r['handed_back'] . ' machine' . ($r['handed_back'] === 1 ? '' : 's') . ' handed back, '
				. $r['holding'] . ' holding at ours';
			if ($r['action'] === 'retire') {
				$retire[] = $r['source'];
				$lines[] = $r['source'] . ': Ubuntu has released every CVE ours carries (' . implode(', ', array_unique(array_values($r['released']))) . '). '
					. 'Set fixes/' . $r['source'] . '/RECIPE to state=none and publish; ' . $nodes
					. ($r['holding'] > 0 ? '. The machines still holding read no CVE in Ubuntu\'s changelog; retiring the recipe releases them.' : '.');
			} else {
				$rebuild[] = $r['source'];
				$lines[] = $r['source'] . ': Ubuntu\'s ' . $r['newest'] . ' lacks ' . implode(', ', $r['missing']) . '. Rebuild the recipe on '
					. $r['newest'] . ' (its ubuntu_source and dsc_sha256, revision 1) and publish; until then machines hold at our '
					. $r['version'] . ' and miss what else Ubuntu fixed. ' . $nodes . '.';
			}
		}
		$title = array();
		if ($retire) { $title[] = 'retire the package fix for ' . implode(', ', $retire); }
		if ($rebuild) { $title[] = 'rebuild the package fix for ' . implode(', ', $rebuild) . ' on Ubuntu\'s newer build'; }
		return array(
			'title'    => 'Ubuntu caught up: ' . implode('; ', $title),
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'Recipes' => implode("\n", $lines),
				'Why'     => 'A machine lifts our pin only when Ubuntu\'s own changelog names every CVE the fix carries.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'No package fix of ours is behind Ubuntu\'s own build.';
	}
}
?>
