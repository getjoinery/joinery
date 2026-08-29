<?php
/**
 * Setup wizard step: Stored secrets.
 *
 * Shown only while a stored secret is dead (the step's `active` gate). Lists the
 * affected secrets from the reconciler's cached verdict and links to the admin
 * secrets-health page, which carries the actions (re-enter, or acknowledge a
 * re-mint). Included by views/setup.php with $page, $settings in scope.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/sealed_secret_registry_class.php'));
require_once(PathHelper::getIncludePath('includes/SecretReconciler.php'));

$sealed_dead = new MultiSealedSecretRegistry(
	array('last_state' => SealedSecretRegistry::STATE_DEAD), array('ssr_feature' => 'ASC'));
$sealed_dead->load();
?>

	<div class="jy-fieldset">
<?php if (count($sealed_dead) === 0) { ?>
		<p><span class="badge badge-success">Clear</span> Every stored secret opens.</p>
<?php } else { ?>
		<p>These stored secrets cannot be read with this site's key:</p>
		<ul class="small">
<?php foreach ($sealed_dead as $sealed_row) {
			$sealed_orphan = $sealed_row->is_orphan();
			$sealed_kind = (string)$sealed_row->get('ssr_kind'); ?>
			<li>
				<strong><?php echo htmlspecialchars((string)$sealed_row->get('ssr_label')); ?></strong>
				&mdash; <?php echo htmlspecialchars((string)$sealed_row->get('ssr_feature')); ?>
<?php if ($sealed_orphan) { ?>
				<span class="badge badge-secondary">removed plugin</span>
<?php } elseif ($sealed_kind === 'regenerable-breaks-things') { ?>
				<span class="badge badge-warning">needs your OK to re-mint</span>
<?php } else { ?>
				<span class="badge badge-warning">needs re-entry</span>
<?php } ?>
			</li>
<?php } ?>
		</ul>
		<p><a class="btn btn-primary" href="/admin/admin_sealed_secrets">Open secrets health</a></p>
<?php } ?>
	</div>
