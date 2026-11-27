<?php
/**
 * /https-status - why this site has no secure connection yet, for anyone.
 *
 * Open without signing in, because it is read before HTTPS works, when
 * signing in would send a password unprotected. Everything it shows is public
 * already (what the name's DNS answers, whether the site has a certificate,
 * what the certificate authority said). The diagnosis is reused for a minute
 * (setup_https_diagnose_cached), so the page cannot be used to make the server
 * repeat its outside lookups. The same findings appear in the setup wizard's
 * Secure connection step and in `sudo joinery-https-check` on the server.
 *
 * Wording rule: the reader may not know what HTTPS or DNS is. Plain words; the
 * one term of art is "A record", the label they must find where their DNS is
 * managed.
 *
 * @version 1.0 - specs: none (Ian's StackScript install, 2026-11-27)
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('logic/setup_https_check_logic.php'));

// Opened over a verified secure connection, the answer is already known.
$d = LibraryFunctions::isSecure()
	? array('applicable' => true, 'domain' => setup_https_site_domain(), 'https_ready' => true)
	: setup_https_diagnose_cached();
$findings = setup_https_findings($d);
$domain = (string)($d['domain'] ?? '');
$secure_now = !empty($d['https_ready']);

$page = new PublicPage();
$page->public_header([
	'is_valid_page' => true,
	'title'         => 'Secure connection',
	'header_only'   => true,
]);
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$marks = array('ok' => '✓', 'problem' => '✗', 'waiting' => '…');
?>
<div class="jy-ui">
<div class="auth-page">
	<div class="auth-card">
		<h3><?php echo $secure_now ? 'This site is secure' : 'This site is not on a secure connection yet'; ?></h3>
		<?php if ($secure_now): ?>
			<p><a href="https://<?php echo $h($domain); ?>/">Go to https://<?php echo $h($domain); ?></a></p>
		<?php else: ?>
			<p>Until it is, don't sign in here: your password would cross the internet unprotected. The server gets its certificate by itself once everything below is in order.</p>
		<?php endif; ?>
		<ul>
			<?php foreach ($findings as $item): ?>
				<li><span aria-hidden="true"><?php echo $marks[$item['kind']] ?? ''; ?></span> <?php echo $h($item['text']); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if (!$secure_now): ?>
			<p class="jy-muted">The server's owner can see more with <code>sudo joinery-https-check</code> in the server's console. This page updates about once a minute. <a href="/https-status">Check again</a></p>
		<?php endif; ?>
	</div>
</div>
</div>
<?php
$page->public_footer(['header_only' => true]);
