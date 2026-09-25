<?php
/**
 * Plugin Settings fragment for dns_filtering — rendered by
 * adm/admin_settings_plugins.php under the plugin's settings form.
 *
 * "DNS server access": one row per configured DNS server, with the key it
 * reads this site's snapshot with (DnsResolverAccess). Issue mints a key and
 * shows its secret once, with the line to paste into the server's
 * SCD_JOINERY_SITES; issuing again replaces the key. Revoke ends it. Both are
 * API actions (resolver_key_issue, resolver_key_revoke) posted with the
 * browser-session credential.
 *
 * @version 1.0
 */

$dns_access_rows = DnsResolverAccess::panelState();
?>
<div class="jy-panel jy-mt-2" id="dns-server-access">
	<h4>DNS server access</h4>
	<p class="jy-muted">
		Each DNS server reads this site's devices, blocks and blocklist sources with its own key.
		A key can call that one read-only action and nothing else, and only from the server's address above.
		Issuing a key replaces the server's old one, so the server stops updating until its
		<code>SCD_JOINERY_SITES</code> entry is changed to the new one. Until then it keeps filtering from its cached copy.
	</p>
	<table class="table">
		<thead>
			<tr><th>Server</th><th>Address</th><th>Key</th><th></th></tr>
		</thead>
		<tbody>
		<?php foreach ($dns_access_rows as $row) {
			$key = $row['key']; ?>
			<tr>
				<td><?php echo htmlspecialchars($row['label']); ?></td>
				<td><?php echo $row['ip'] !== '' ? htmlspecialchars($row['ip']) : '<em class="jy-muted">not set</em>'; ?></td>
				<td>
					<?php if ($key) { ?>
						<code><?php echo htmlspecialchars($key->get('apk_public_key')); ?></code><br>
						<span class="jy-muted">Last used:
							<?php echo $key->get('apk_last_used_time') ? htmlspecialchars($key->get_local('apk_last_used_time')) : 'never'; ?></span>
						<?php if ($row['drift']) { ?>
							<br><strong>This key works only from <?php echo htmlspecialchars($row['restricted_to']); ?>,
							not the address set above. Issue a new key for this server.</strong>
						<?php } ?>
					<?php } else { ?>
						<em class="jy-muted">none</em>
					<?php } ?>
				</td>
				<td>
					<button type="button" class="btn btn-secondary dns-access-issue"
						data-slot="<?php echo htmlspecialchars($row['slot']); ?>"
						data-replacing="<?php echo $key ? '1' : '0'; ?>"><?php echo $key ? 'Issue new key' : 'Issue key'; ?></button>
					<?php if ($key) { ?>
						<button type="button" class="btn btn-secondary dns-access-revoke"
							data-slot="<?php echo htmlspecialchars($row['slot']); ?>">Revoke</button>
					<?php } ?>
				</td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	<div id="dns-access-secret" style="display: none;" class="jy-mt-2">
		<p><strong>Save this now. It is shown once and cannot be retrieved.</strong>
			Put this line in the server's <code>/etc/scrolldaddy/scrolldaddy.env</code> as its
			<code>SCD_JOINERY_SITES</code> entry for this site:</p>
		<pre id="dns-access-secret-line" style="white-space: pre-wrap; word-break: break-all;"></pre>
		<button type="button" class="btn btn-primary" id="dns-access-secret-done">I have saved it</button>
	</div>
	<p class="jy-muted" id="dns-access-status"></p>
</div>
<script>
(function () {
	var status = document.getElementById('dns-access-status');
	var secretBox = document.getElementById('dns-access-secret');
	var secretLine = document.getElementById('dns-access-secret-line');

	function run(action, slot, then) {
		status.textContent = 'Working…';
		joineryApi.post(action, { slot: slot }).then(function (data) {
			status.textContent = '';
			then(data);
		}).catch(function (e) {
			status.textContent = e.message || 'That did not work.';
		});
	}

	document.querySelectorAll('.dns-access-issue').forEach(function (button) {
		button.addEventListener('click', function () {
			var slot = button.getAttribute('data-slot');
			var issue = function () {
				run('dns_filtering/resolver_key_issue', slot, function (data) {
					secretLine.textContent = data.sites_entry;
					secretBox.style.display = '';
				});
			};
			if (button.getAttribute('data-replacing') === '1') {
				JoineryModal.confirm('Issue a new key for this server? Its current key stops working at once, and the server stops updating until its SCD_JOINERY_SITES entry holds the new one.', issue);
			} else {
				issue();
			}
		});
	});

	document.querySelectorAll('.dns-access-revoke').forEach(function (button) {
		button.addEventListener('click', function () {
			var slot = button.getAttribute('data-slot');
			JoineryModal.confirm('Revoke this server\'s key? It stops getting updates from this site and keeps filtering from its cached copy.', function () {
				run('dns_filtering/resolver_key_revoke', slot, function () {
					window.location.reload();
				});
			});
		});
	});

	document.getElementById('dns-access-secret-done').addEventListener('click', function () {
		secretLine.textContent = '';
		secretBox.style.display = 'none';
		window.location.reload();
	});
})();
</script>
