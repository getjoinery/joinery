<?php
/**
 * JoineryServiceRequirement - the one answer a getjoinery services line carries.
 *
 * A self-hosted site rents our email and backup storage per site, so the
 * purchase is per site too. The site is already known to this plane: its own
 * Email or Backups step connected it and asked once, which left its service
 * rows at unpaid (specs/services_phase2_platform.md §4). What the cart needs
 * is ONE hidden answer naming that site: the id of one of its rows. This is
 * that answer, the same shape ManagedSiteRequirement is for a Managed site
 * (specs/services_phase3_selling_nudge.md §1):
 *
 *  - The buyer comes from the connected sites page, whose Buy button posts
 *    the row id at the product's URL. On the product page alone there is no
 *    site to name, so it says where to buy from.
 *  - validate() refuses add-to-cart unless the id names a live row of the
 *    signed-in buyer.
 *  - process() stores the id, marked internal, and the site's host as the
 *    line's readable answer.
 *
 * JoineryServicesFulfillment reads the answer before the charge
 * (checkAvailability) and after it (fulfill). Contributed by that provider,
 * never attached as a pri_ row.
 *
 * @version 1.0 - specs/services_phase3_selling_nudge.md §1
 */

require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));

class JoineryServiceRequirement extends AbstractProductRequirement {

	const LABEL = 'Site';

	/** The form field and order-item key carrying the row id. */
	const FIELD = 'joinery_services_site';

	/** The line's readable answer: the site's host. */
	const SUMMARY_FIELD = 'joinery_services_host';

	/** Where the buyer picks the site and buys. */
	const SITES_URL = '/profile/server_manager/services';

	/** The row validate() accepted, for process() in the same request. */
	private $row = null;

	public function getFormGroup() { return 'info'; }

	/**
	 * A live row this user owns, by id, or null. Somebody else's id finds
	 * nothing, and a row of a site the account disconnected is refused: its
	 * key no longer works, so nothing bought could reach it.
	 */
	public static function row_for_user(int $row_id, int $user_id): ?ServiceTenant {
		if ($row_id <= 0 || $user_id <= 0) {
			return null;
		}
		$row = new ServiceTenant($row_id, TRUE);
		if (!$row->key || $row->get('svt_delete_time') || (int)$row->get('svt_usr_user_id') !== $user_id) {
			return null;
		}
		if (!$row->is_node_linked() && !self::key_active((int)$row->get('svt_apk_api_key_id'))) {
			return null;
		}
		return $row;
	}

	private static function key_active(int $key_id): bool {
		if ($key_id <= 0) {
			return false;
		}
		try {
			$key = new ApiKey($key_id, TRUE);
			return $key->key && (bool)$key->get('apk_is_active') && !$key->get('apk_delete_time');
		} catch (\Throwable $e) {
			return false;
		}
	}

	/** The row id a cart line or an order item's stored answers carry. */
	public static function row_id_from(array $data): int {
		$answer = $data[self::FIELD] ?? 0;
		if (is_array($answer)) {
			$answer = $answer['answer'] ?? 0;
		}
		return (int)$answer;
	}

	/** The row a cart line names, still the buyer's, or null. */
	public static function row_for_line(array $data, int $user_id): ?ServiceTenant {
		return self::row_for_user(self::row_id_from($data), $user_id);
	}

	public static function site_label(ServiceTenant $row): string {
		$host = trim((string)$row->get('svt_host'));
		return $host !== '' ? $host : 'site #' . (int)$row->key;
	}

	public function render_fields($formwriter, $product, $existing_data = array()) {
		$user_id = 0;
		if (!empty($existing_data['user']) && is_object($existing_data['user'])) {
			$user_id = (int)$existing_data['user']->key;
		}
		$row = !empty($existing_data[self::FIELD])
			? self::row_for_user(self::row_id_from($existing_data), $user_id) : null;
		if ($row !== null) {
			$formwriter->hiddeninput(self::FIELD, '', array('value' => (int)$row->key));
			echo '<p class="form-text">For <strong>' . htmlspecialchars(self::site_label($row)) . '</strong></p>';
			return;
		}
		echo '<p class="form-text">These services are bought for one of your sites. Choose the site on '
			. '<a href="' . self::SITES_URL . '">your connected sites</a> and buy from there.</p>';
	}

	public function validate($post_data, $product) {
		$this->row = null;
		$user_id = (int)SessionControl::get_instance()->get_user_id();
		if ($user_id <= 0) {
			return array('Sign in to buy services for your site.');
		}
		$row = self::row_for_user((int)($post_data[self::FIELD] ?? 0), $user_id);
		if ($row === null) {
			return array('Choose the site on your connected sites page, then buy from there.');
		}
		$this->row = $row;
		return array();
	}

	public function process($post_data, $product, $order_detail, $user) {
		if ($this->row === null) {
			return array(array(), array());
		}
		$label = self::site_label($this->row);
		$data = array(
			self::SUMMARY_FIELD => array('question' => 'Site', 'answer' => $label),
			self::FIELD         => array('question' => 'Site record', 'answer' => (string)$this->row->key,
				'internal' => true),
		);
		return array($data, array('Site' => $label));
	}

	public function get_display_data($order_detail, $user) {
		return array();
	}
}
