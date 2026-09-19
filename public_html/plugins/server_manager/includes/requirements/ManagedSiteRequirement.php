<?php
/**
 * ManagedSiteRequirement - the one answer a Managed hosting line carries.
 *
 * The buyer configures their site on the Server Manager configure page, not
 * in the cart: the domain, the site name, the region, the admin email, and —
 * when we register the name for them — the registrant block and a frozen
 * quote. All of that lives on a draft provision row. What the cart needs is
 * ONE hidden answer: the id of that row. This requirement is that answer, and
 * the whole coupling between the store and Server Manager (the umbrella
 * spec's contract C1):
 *
 *  - On the product page it renders no fields. With a frozen draft it renders
 *    a one-line summary and the hidden id; without one, a button to the
 *    configure page.
 *  - validate() refuses add-to-cart unless the id names a pending_payment
 *    draft owned by the signed-in buyer.
 *  - process() stores the id in the question/answer shape an order item
 *    needs, and — when registering — the frozen domain quote under the same
 *    keys the registration guard reads, so the paid-line check works as it
 *    always has.
 *  - extra_cart_lines() adds the domain-year line from the frozen quote. It
 *    never re-quotes: the same draft always yields the same line, which is
 *    what the edit-cart path relies on.
 *
 * The store never learns what a Managed site is. It carries a product, a
 * price and an opaque answer; CustomerCloudFulfillment interprets the answer
 * at checkout (checkAvailability) and after payment (fulfill).
 *
 * Contributed by CustomerCloudFulfillment::extraRequirements(), never attached
 * as a pri_ row, so it does not appear in the product-edit picker.
 *
 * @version 1.1 - every stored key is question/answer-shaped with a label a person can read, and the
 *                site's domain travels as its own answer, so the cart and the order show the site
 *                and never a bare draft id or a raw timestamp
 * @version 1.0 - specs/managed_hosting_phase1_purchase.md §6.1
 */

require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedDomainIntake.php'));

class ManagedSiteRequirement extends AbstractProductRequirement {

	const LABEL = 'Managed site';

	/** Where the buyer configures the site. */
	const CONFIGURE_URL = '/profile/server_manager/configure';

	/** The form field and order-item key carrying the draft id. */
	const FIELD = 'managed_site';

	/**
	 * The line's form-data key naming WHICH freeze the line was built from:
	 * the draft's update time at process(). A draft that is edited and frozen
	 * again keeps its id but gets a new time, so a line built from the earlier
	 * freeze — with the earlier summary and the earlier quote — no longer
	 * matches, and the charge refuses it.
	 */
	const FROZEN_FIELD = 'managed_site_frozen';

	/** The line's readable summary — the domain and the deal — shown wherever the line is. */
	const SUMMARY_FIELD = 'managed_site_summary';

	/** The draft validate() accepted, for process() in the same request. */
	private $draft = null;

	/** The freeze the line was built from, whichever shape it was stored in. */
	public static function frozen_from(array $data): string {
		$frozen = $data[self::FROZEN_FIELD] ?? '';
		if (is_array($frozen)) {
			$frozen = $frozen['answer'] ?? '';
		}
		return (string)$frozen;
	}

	public function getFormGroup() { return 'info'; }

	// ------------------------------------------------------------------
	// Finding the buyer's draft
	// ------------------------------------------------------------------

	/**
	 * A pending_payment draft that this user owns, by id — or null. The row
	 * is looked up inside the owner's own rows, so somebody else's id finds
	 * nothing rather than being loaded and then refused.
	 */
	public static function draft_for_user(int $draft_id, int $user_id) {
		if ($draft_id <= 0 || $user_id <= 0) {
			return null;
		}
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
		$row = new CustomerCloudProvision($draft_id, TRUE);
		if (!$row->key || $row->get('cvp_delete_time')
				|| (int)$row->get('cvp_usr_user_id') !== $user_id
				|| (string)$row->get('cvp_origin') !== 'buyer'
				|| (string)$row->get('cvp_status') !== 'pending_payment') {
			return null;
		}
		return $row;
	}

	/**
	 * The frozen draft a cart line was built from, or null when the line is
	 * stale: no such draft, not this user's, not frozen, or frozen again since
	 * the line was made.
	 */
	public static function draft_for_line(array $data, int $user_id) {
		$answer = $data[self::FIELD] ?? 0;
		if (is_array($answer)) {
			$answer = $answer['answer'] ?? 0;
		}
		$draft = self::draft_for_user((int)$answer, $user_id);
		if ($draft === null) {
			return null;
		}
		$frozen = self::frozen_from($data);
		if ($frozen === '' || $frozen !== (string)$draft->get('cvp_update_time')) {
			return null;
		}
		return $draft;
	}

	/** The user's newest pending_payment draft, or null. */
	public static function newest_draft_for_user(int $user_id) {
		if ($user_id <= 0) {
			return null;
		}
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
		$rows = new MultiCustomerCloudProvision(array(
			'user_id' => $user_id, 'origin' => 'buyer', 'status' => 'pending_payment', 'deleted' => false,
		), array('cvp_customer_cloud_provision_id' => 'DESC'), 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** The site as one line, for the product page, the cart and the order. */
	public static function summary($draft): string {
		$line = (string)$draft->get('cvp_domain');
		if ((string)$draft->get('cvp_domain_source') === 'register') {
			$line .= ' (we register it, ' . self::money((string)$draft->get('cvp_domain_quote')) . ' for the first year)';
		} else {
			$line .= ' (your own domain)';
		}
		return $line;
	}

	private static function money(string $amount): string {
		$settings = Globalvars::get_instance();
		$symbol = CurrencyHelper::symbol(strtolower((string)$settings->get_setting('site_currency'))) ?: '$';
		return $symbol . number_format((float)$amount, 2, '.', '');
	}

	// ------------------------------------------------------------------
	// The product page
	// ------------------------------------------------------------------

	public function render_fields($formwriter, $product, $existing_data = array()) {
		$user_id = 0;
		if (!empty($existing_data['user']) && is_object($existing_data['user'])) {
			$user_id = (int)$existing_data['user']->key;
		}
		$draft = null;
		if (!empty($existing_data[self::FIELD])) {
			// Editing a cart line: the line names its draft.
			$wanted = is_array($existing_data[self::FIELD])
				? (int)($existing_data[self::FIELD]['answer'] ?? 0) : (int)$existing_data[self::FIELD];
			$draft = self::draft_for_user($wanted, $user_id);
		}
		if ($draft === null) {
			$draft = self::newest_draft_for_user($user_id);
		}

		if ($draft !== null) {
			$formwriter->hiddeninput(self::FIELD, '', array('value' => (int)$draft->key));
			echo '<p class="form-text">Your site: <strong>' . htmlspecialchars(self::summary($draft))
				. '</strong> &mdash; <a href="' . self::CONFIGURE_URL . '?cvp_customer_cloud_provision_id='
				. (int)$draft->key . '">change it</a></p>';
			return;
		}

		// No fields here on purpose: every question about the site is asked on
		// the configure page, so adding one later never touches the store.
		echo '<p class="form-text">Set up your site first &mdash; the domain, its name and where it lives &mdash; '
			. 'then come back here to pay.</p>';
		echo '<p><a class="btn btn-primary" href="' . self::CONFIGURE_URL . '">Set up your site</a></p>';
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	public function validate($post_data, $product) {
		$this->draft = null;
		$session = SessionControl::get_instance();
		$user_id = (int)$session->get_user_id();
		if ($user_id <= 0) {
			return array('Sign in and set up your site before adding Managed hosting to the cart.');
		}
		$draft = self::draft_for_user((int)($post_data[self::FIELD] ?? 0), $user_id);
		if ($draft === null) {
			return array('Set up your site first, then continue to payment from there.');
		}
		$this->draft = $draft;
		return array();
	}

	public function process($post_data, $product, $order_detail, $user) {
		if ($this->draft === null) {
			return array(array(), array());
		}
		$draft = $this->draft;
		// Every key is stored on the order item as a labelled answer and shown
		// on the order, so each carries a label a person can read.
		$data = array(
			self::SUMMARY_FIELD => array('question' => 'Site', 'answer' => self::summary($draft)),
			self::FIELD         => array('question' => 'Site setup', 'answer' => (string)$draft->key),
			self::FROZEN_FIELD  => array('question' => 'Site setup frozen at',
				'answer' => (string)$draft->get('cvp_update_time')),
		);
		if ((string)$draft->get('cvp_domain_source') === 'register') {
			// The same keys the registration guard and the orphan sweep read:
			// the domain-year line's price, and the name on the receipt.
			$data['managed_domain_price_line'] = number_format((float)$draft->get('cvp_domain_quote'), 2, '.', '');
			$data['managed_domain'] = array('question' => 'Registered domain',
				'answer' => (string)$draft->get('cvp_domain'));
		}
		$display = array('Site' => self::summary($draft));
		return array($data, $display);
	}

	public function get_display_data($order_detail, $user) {
		return array();
	}

	// ------------------------------------------------------------------
	// The companion cart line
	// ------------------------------------------------------------------

	/**
	 * The "Domain registration (1 year)" line, from the quote frozen on the
	 * draft. Deterministic for identical form data by construction — the
	 * price is read from the data, never re-quoted — which is what lets the
	 * edit-cart path find the line it previously contributed.
	 */
	public function extra_cart_lines($form_data, $product) {
		$price = trim((string)($form_data['managed_domain_price_line'] ?? ''));
		$domain = '';
		if (isset($form_data['managed_domain']['answer'])) {
			$domain = (string)$form_data['managed_domain']['answer'];
		}
		if ($price === '' || $domain === '') {
			return array();   // an owned domain: nothing to register
		}
		$product_id = ManagedDomainIntake::domainProductId();
		if ($product_id <= 0) {
			return array();
		}
		return array(array(
			'product_id' => $product_id,
			'form_data'  => array(
				'user_price_override' => $price,
				'managed_domain'      => array('question' => 'Registered domain', 'answer' => $domain),
			),
		));
	}
}
