<?php
/**
 * Setup wizard step: Secure connection. Included by views/setup.php with
 * $page, $page_vars, $viewer, $settings, $next_key in scope.
 *
 * Entirely server-rendered from $page_vars['https_diagnosis'] (setup_logic
 * runs setup_https_diagnose() whenever this panel is about to render). The
 * "Check again" button just reloads this page — every render re-runs the
 * checks fresh. No page JavaScript and no API call on purpose: this page is
 * only ever seen over plain HTTP, which the API face correctly refuses.
 *
 * Wording rule for this page: the reader may not know what HTTPS, DNS, or an
 * API is. Plain words only; the one term of art allowed is "A record", named
 * because that is the label they must find at their registrar.
 *
 * @version 1.2
 * @changelog 1.2 - Every render stamps "Checked just now, at {time}" so
 *   pressing "Check again" visibly registers even when nothing changed; the
 *   address-connected-but-not-armed state says what is actually missing (the
 *   server's own security setup) instead of a vague "finish this".
 * @changelog 1.1 - Server-rendered from the shared diagnosis instead of
 *   calling the API from the page (426 over plain HTTP); manual-command
 *   section removed; wording rewritten to be jargon-free.
 */

$d = $page_vars['https_diagnosis'] ?? null;
?>

	<div class="jy-fieldset">
		<h4>Where things stand</h4>
<?php if (!is_array($d)) { ?>
		<p class="jy-muted">The check couldn't run — reload the page to try again.</p>
<?php } elseif (empty($d['applicable'])) { ?>
		<p>This site doesn't have a real web address yet (it answers on
			<strong><?php echo htmlspecialchars($d['domain'] !== '' ? $d['domain'] : 'localhost'); ?></strong>),
			so a secure connection isn't possible. Point a web address at this server first.</p>
<?php } else {
		$points_at = implode(', ', array_merge((array)$d['dns_a'], (array)$d['dns_aaaa']));
		$server_ip = $d['server_ip4'] !== '' ? $d['server_ip4'] : $d['server_ip6'];
?>
<?php if (!empty($d['https_ready'])) { ?>
		<p><strong>Good news — the secure version of your site is ready.</strong>
			You're just not on it yet. Use the button below to switch over; you'll see a
			padlock in your browser's address bar, and everything you type from then on is
			protected. The site takes care of renewing its own security from here — you
			won't need to think about this again.</p>
		<div class="jy-mt-2">
			<a class="btn btn-primary" href="https://<?php echo htmlspecialchars($d['domain']); ?>/setup?step=https">Switch to the secure site &rarr;</a>
		</div>
<?php } else { ?>
		<ul>
			<li>Your web address: <strong><?php echo htmlspecialchars($d['domain']); ?></strong></li>
			<li>This server: <strong><?php echo htmlspecialchars($server_ip !== '' ? $server_ip : 'could not be determined'); ?></strong></li>
			<li>Your web address currently points to: <strong><?php echo htmlspecialchars($points_at !== '' ? $points_at : 'nothing yet'); ?></strong></li>
		</ul>
<?php if (empty($d['dns_match'])) { ?>
<?php if ($server_ip !== '') { ?>
		<p><strong>Your web address isn't connected to this server yet.</strong>
			Sign in wherever your web address is registered (GoDaddy, Namecheap, Cloudflare,
			and so on), find its settings, and point <strong><?php echo htmlspecialchars($d['domain']); ?></strong>
			at <strong><?php echo htmlspecialchars($server_ip); ?></strong> — the setting to change is
			called the <strong>A record</strong>.</p>
		<p>That change can take anywhere from a few minutes to a few hours to take effect.
<?php if (!empty($d['retry_armed'])) { ?>
			Once it does, this server switches the secure connection on all by itself —
			press "Check again" whenever you like to see where things are.</p>
<?php } else { ?>
			Once it does, whoever manages this server needs to switch the secure
			connection on — then press "Check again" here.</p>
<?php } ?>
<?php } else { ?>
		<p><strong>This server couldn't work out its own public address</strong> —
			it may not be able to reach the internet right now. Try "Check again" in a
			few minutes; if this keeps happening, whoever manages the server should
			take a look.</p>
<?php } ?>
<?php } elseif (!empty($d['retry_armed'])) { ?>
		<p><strong>Almost there — your web address points at this server.</strong>
			The server is switching the secure connection on by itself; that usually takes
			about five minutes. Give it a little while, then press "Check again".</p>
<?php } else { ?>
		<p><strong>Your web address is connected — that part is done.</strong>
			What's missing is the server's own security setup, which it would normally
			run by itself; on this server the automatic setup isn't turned on. Whoever
			set up the server can run it in one step — the <code>setup_ssl</code> script
			that came with the server — and this page will show the change.</p>
<?php } ?>
<?php } ?>
		<p class="jy-muted">Checked just now, at
			<?php echo htmlspecialchars(LibraryFunctions::convert_time(gmdate('Y-m-d H:i:s'), 'UTC', SessionControl::get_instance()->get_timezone(), 'g:i:s a')); ?>.
			Pressing "Check again" runs a fresh check.</p>
<?php } ?>
		<form method="GET" action="/setup" class="jy-mt-2">
			<input type="hidden" name="step" value="https">
			<button type="submit" class="btn btn-secondary">Check again</button>
		</form>
	</div>

	<form method="POST" action="/setup" class="jy-mt-3">
		<input type="hidden" name="action" value="decline_step">
		<input type="hidden" name="step_key" value="https">
		<button type="submit" class="btn btn-link jy-muted">Keep going without a secure connection</button>
	</form>
