<?php
/**
 * UpstreamAdvisoryDriver - how one upstream project rates its own security
 * fixes (specs/package_fixes_ahead_of_ubuntu.md WP5).
 *
 * Ubuntu's tracker says whether a CVE is fixed on 26.04, but its priority on
 * the day a fix ships is the triage default, not a rating: on 2026-11-20 it
 * was "medium" for both CVEs PHP itself rated critical. A driver reads the
 * project's own advisories, which rate each fix the day it ships and say
 * whether a fixed release exists. A source no driver claims is rated from NVD
 * (as Ubuntu's tracker mirrors it) and Ubuntu's priority once it is past
 * triage.
 *
 * @version 1.0
 */
interface UpstreamAdvisoryDriver {

	/** Whether this driver rates the CVEs of Ubuntu source package $source. */
	public function covers(string $source): bool;

	/** The project's name, for the incident's words: "PHP". */
	public function label(): string;

	/**
	 * The project's advisories, by CVE id: its rating (critical, high,
	 * medium, low) and whether it has released a fix, with the releases
	 * that carry it. Null when the advisories cannot be read.
	 *
	 * @return array<string, array{rating:string, fixed:bool, fixed_in:string, url:string}>|null
	 */
	public function advisories(): ?array;
}
?>
