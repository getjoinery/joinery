<?php
/**
 * SweepSiteDrafts - a draft that nobody came back for does not live forever.
 *
 * First phase of the provisioning umbrella task, ahead of Orders. It works
 * the buyer's pre-payment rows and nothing else:
 *
 *   draft            older than server_manager_draft_days, no order item
 *                    -> soft-deleted. A draft holds nothing, so there is
 *                       nothing else to undo.
 *   pending_payment  older than the same number of days
 *                    -> returned to draft. It was frozen so a cart line could
 *                       be built from it; after this long the line is gone
 *                       or its domain quote is stale, and the buyer starts
 *                       from Edit rather than paying a price the registrar
 *                       no longer charges.
 *
 * "Older" is measured from the row's last change (cvp_update_time): a buyer
 * who keeps editing keeps their draft, and a frozen row's last change is the
 * freeze itself. Rows past payment are never seen here — the query excludes
 * every status but the two above, and the origin rule refuses any other
 * origin in those states.
 *
 * @version 1.0 - specs/managed_hosting_phase1_purchase.md §5.2, §10 item 5
 */

class SweepSiteDrafts {

	/** The default when the setting is blank or nonsense. */
	const DEFAULT_DAYS = 14;

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));

		$days = $this->draft_days();
		$before = gmdate('Y-m-d H:i:s', time() - $days * 86400);

		$deleted = 0;
		$unfrozen = 0;
		$errors = array();

		$stale = new MultiCustomerCloudProvision(array(
			'origin' => 'buyer', 'status' => 'draft', 'updated_before' => $before, 'deleted' => false,
		));
		foreach ($stale as $row) {
			try {
				if ((int)$row->get('cvp_external_order_item_id')) {
					continue;   // never: a draft with an order item is a bug, and not this phase's to hide
				}
				$row->soft_delete();
				$deleted++;
			} catch (Throwable $e) {
				$errors[] = 'draft #' . $row->key . ': ' . $e->getMessage();
			}
		}

		$frozen = new MultiCustomerCloudProvision(array(
			'origin' => 'buyer', 'status' => 'pending_payment', 'updated_before' => $before, 'deleted' => false,
		));
		foreach ($frozen as $row) {
			try {
				$row->set('cvp_status', 'draft');
				$row->save();
				$unfrozen++;
			} catch (Throwable $e) {
				$errors[] = 'frozen draft #' . $row->key . ': ' . $e->getMessage();
			}
		}

		if ($deleted === 0 && $unfrozen === 0 && empty($errors)) {
			return array('status' => 'skipped', 'message' => 'No site drafts to sweep.');
		}
		$message = 'Site drafts: ' . $deleted . ' expired draft(s) removed, ' . $unfrozen
			. ' frozen draft(s) returned to draft (older than ' . $days . ' days).';
		if ($errors) {
			$message .= ' ' . count($errors) . ' error(s): ' . implode('; ', array_slice($errors, 0, 3));
			return array('status' => 'error', 'message' => $message);
		}
		return array('status' => 'success', 'message' => $message);
	}

	/** server_manager_draft_days, or the default when it is not a positive number. */
	public static function draft_days(): int {
		$settings = Globalvars::get_instance();
		$days = (int)$settings->get_setting('server_manager_draft_days', false, true);
		return $days > 0 ? $days : self::DEFAULT_DAYS;
	}
}
