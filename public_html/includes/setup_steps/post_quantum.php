<?php
/**
 * Setup wizard step: Post-quantum sealing.
 *
 * Shown only while this server cannot reach ML-KEM (the step's `active`
 * gate): says what is missing in SealedBox::pqReadyReason()'s words and how
 * it is fixed. Included by views/setup.php with $page, $settings in scope.
 *
 * @version 1.1 - the fix offered follows the cause: the converger for a setting, a newer OS for OpenSSL
 * @version 1.0
 */
$pq_reason = SealedBox::pqReadyReason();
?>

	<div class="jy-fieldset">
<?php if ($pq_reason === null) { ?>
		<p><span class="badge badge-success">Ready</span> This server is ready for post-quantum key protection.</p>
<?php } else { ?>
		<p><?php echo htmlspecialchars($pq_reason); ?></p>
<?php if (in_array(SealedBox::pqReadyCause(), [MlKem768::CAUSE_OPENSSL, MlKem768::CAUSE_LIBCRYPTO], true)) { ?>
		<p>This cannot be fixed in place: the site has to move to a server running Ubuntu 26.04 or later.</p>
<?php } else { ?>
		<p>The server's maintenance task turns this on by itself within a few minutes.
			If it stays here, ask whoever runs the server to run:</p>
		<pre class="small"><?php echo htmlspecialchars(HostConvergerNotice::installCommand()); ?></pre>
<?php } ?>
<?php } ?>
	</div>
