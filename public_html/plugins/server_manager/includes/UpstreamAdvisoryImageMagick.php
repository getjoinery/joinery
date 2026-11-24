<?php
/**
 * UpstreamAdvisoryImageMagick - ImageMagick's own security advisories, from
 * its GitHub security advisories (specs/package_fixes_automatic.md WP2).
 *
 * The same feed as PHP's (UpstreamAdvisoryPhp): each advisory names its CVE
 * when one is assigned, ImageMagick's rating, and the releases that fix it,
 * spelled as its tags are ("7.1.2-36"), read here as Ubuntu spells the version
 * (7.1.2.36) so the release line and the tag (fixes/UPSTREAMS) follow. Its fix
 * commits name the advisory's GHSA, as PHP's do. An advisory with no CVE yet
 * is not read: Ubuntu's tracker lists CVEs. One unauthenticated call an hour.
 *
 * @version 1.0
 */
class UpstreamAdvisoryImageMagick extends UpstreamAdvisoryPhp {

	const URL = 'https://api.github.com/repos/ImageMagick/ImageMagick/security-advisories?state=published&per_page=100&sort=published&direction=desc';

	public function covers(string $source): bool {
		return $source === 'imagemagick';
	}

	public function label(): string {
		return 'ImageMagick';
	}

	protected function release(string $pv): ?string {
		return preg_match('/^(\d+\.\d+\.\d+)-(\d+)$/', $pv, $m) ? $m[1] . '.' . $m[2] : null;
	}
}
?>
