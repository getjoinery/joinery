<?php
/**
 * Public releases page (/server_manager/releases): every release statement
 * logged under the Joinery release key, and how far the public log has been
 * read for any it does not account for. See logic/releases_logic.php.
 *
 * @version 1.1 - the keys each release installs, each log key checked against Sigstore's trusted root
 * @version 1.0
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/releases_logic.php'));

$page_vars = process_logic(releases_logic($_GET));
extract($page_vars);

$page = new PublicPage();
$page->public_header(array('is_valid_page' => true, 'title' => 'Joinery releases'), NULL);
echo PublicPage::BeginPage('Joinery releases', array());

$h = function ($s) { return htmlspecialchars((string)$s); };
$link = function ($url, $text) use ($h) {
	return $url !== '' ? '<a href="' . $h($url) . '" target="_blank" rel="noopener">' . $h($text) . '</a>' : $h($text);
};
?>
<div class="jy-ui" style="max-width: 60rem; margin: 2rem auto;">
<?php if (!$is_valid_page): ?>
	<h2>This site does not publish releases</h2>
	<p>The list of Joinery releases is kept by the site that publishes them.</p>
<?php else: ?>
	<h2>Joinery releases</h2>
	<p>Every Joinery release is built from a public commit and recorded in
		<a href="https://docs.sigstore.dev/logging/overview/" target="_blank" rel="noopener">Sigstore's public log</a>
		before any site installs it. A site installs only a release whose record is in that log, so a release
		cannot reach a site without becoming public here first.</p>

	<?php if ($unaccounted): ?>
	<div class="alert alert-danger" role="alert">
		<strong>The public log holds <?php echo count($unaccounted); ?> release record(s) signed with our key that we did not publish.</strong>
		<ul>
		<?php foreach ($unaccounted as $u): ?>
			<li><code><?php echo $h($u['origin']); ?></code> entry <code><?php echo (int)$u['index']; ?></code>: <?php echo $h($u['why']); ?>,
				found <?php echo $h(gmdate('Y-m-d H:i', (int)$u['found_at'])); ?> UTC</li>
		<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<?php if ($unlisted): ?>
	<div class="alert alert-danger" role="alert">
		<strong>A release installed <?php echo count($unlisted); ?> log key(s) that Sigstore does not list for that log.</strong>
		<ul>
		<?php foreach ($unlisted as $k): ?>
			<li><code><?php echo $h($k['origin']); ?></code> key <code><?php echo $h($k['fingerprint']); ?></code></li>
		<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<h3>Releases</h3>
	<div class="table-wrap">
	<table>
		<thead><tr><th>Version</th><th>Published</th><th>Core commit</th><th>Agent commit</th><th>Log entry</th><th>Seen in the log</th></tr></thead>
		<tbody>
		<?php foreach ($releases as $r): ?>
			<tr>
				<td><?php echo $h($r['version']); ?><?php if (!$r['shipped']): ?><br><small>logged, not shipped</small><?php endif; ?></td>
				<td><?php echo $h(substr($r['published_at'], 0, 10)); ?></td>
				<td><code><?php echo $link(ReleaseProvenance::commit_url(ReleaseProvenance::CORE_REPO, $r['core_commit']), substr($r['core_commit'], 0, 12)); ?></code></td>
				<td><code><?php echo $link(ReleaseProvenance::commit_url(ReleaseProvenance::AGENT_REPO, $r['agent_commit']), substr($r['agent_commit'], 0, 12)); ?></code></td>
				<td><code><?php echo $h($r['log_origin']); ?></code> #<?php echo (int)$r['log_index']; ?></td>
				<td><?php echo $r['seen_in_log'] !== '' ? $h(substr($r['seen_in_log'], 0, 16)) . ' UTC' : 'not yet read'; ?></td>
			</tr>
			<tr>
				<td></td>
				<td colspan="5"><small>Installs release key<?php echo count($r['keys']['statement']) === 1 ? '' : 's'; ?>
					<?php echo implode(', ', array_map(function ($fp) use ($h) { return '<code>' . $h($fp) . '</code>'; }, $r['keys']['statement'])); ?>;
					log key<?php echo count($r['keys']['log']) === 1 ? '' : 's'; ?>
					<?php echo implode(', ', array_map(function ($k) use ($h) {
						return '<code>' . $h($k['origin']) . '</code> <code>' . $h($k['fingerprint']) . '</code> (' . $h($k['sigstore'] === 'listed' ? 'Sigstore lists it' : ($k['sigstore'] === 'not listed' ? 'Sigstore does NOT list it' : 'not checked against Sigstore yet')) . ')';
					}, $r['keys']['log'])); ?></small></td>
			</tr>
		<?php endforeach; ?>
		<?php if (!$releases): ?>
			<tr><td colspan="6">No release has been logged yet.</td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	</div>
	<p><small>A release logged and not shipped is a publish that stopped after its record was written. The log keeps
		every record for good, so each one is listed here. Keys are shown by the first 16 hex digits of the SHA-256 of
		their public key. Each log key is checked once a day against
		<a href="https://github.com/sigstore/root-signing" target="_blank" rel="noopener">Sigstore's trusted root</a><?php
		echo $root_checked !== '' ? ', last at ' . $h($root_checked) : ''; ?>.</small></p>

	<h3>Watching the log</h3>
	<p>Every hour this site reads each new entry of the logs sites trust, looking for records signed with our release
		key. Each one must be a release listed above; any other would be a release that sites would install and that
		we did not publish, and would be shown at the top of this page.</p>
	<div class="table-wrap">
	<table>
		<thead><tr><th>Log</th><th>Read from entry</th><th>Read to entry</th><th>Last caught up</th></tr></thead>
		<tbody>
		<?php foreach ($logs as $l): ?>
			<tr>
				<td><code><?php echo $h($l['origin']); ?></code></td>
				<td><?php echo $l['from'] !== null ? (int)$l['from'] : 'not read yet'; ?></td>
				<td><?php echo $l['read_to'] !== null ? (int)$l['read_to'] : ''; ?></td>
				<td><?php echo $l['caught_up_at'] !== '' ? $h($l['caught_up_at']) : 'not yet'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<h3>Check a release yourself</h3>
	<p>This page is served by us, so it can point but not prove. To check a release from your own computer, clone
		<?php echo $link(ReleaseProvenance::CORE_REPO, 'the public repository'); ?> and run:</p>
	<pre><code>php utils/verify_release.php <?php echo $h($releases[0]['version'] ?? '<version>'); ?> --source=<?php echo $h($source); ?></code></pre>
	<p>It rebuilds the release from the public commits, compares it with what was published, and checks its entry
		in the public log.</p>
<?php endif; ?>
</div>
<?php
echo PublicPage::EndPage();
$page->public_footer(array('track' => TRUE));
