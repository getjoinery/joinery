<?php
/**
 * The Joinery Business License a site is bought under.
 *
 * A business needs one licence per production site; a person, household,
 * school or nonprofit needs none. The site setup asks who the site is for
 * (cvp_license_choice) and, for a business, which licence:
 *
 *   free         personal, household, school or nonprofit use
 *   founder      buy the Founder licence with the site
 *   standard     buy the Standard business licence with the site
 *   held:<id>    a licence the buyer already owns and no live site uses
 *
 * A licence bought with a site rides in the same cart as a line of the licence
 * product that names the site (LINE_FIELD). The store records its ownership
 * after payment, as for any product whose every purchase is one more
 * (Ownership::tag_each_for_product), so it is found from that line whenever it
 * is needed (for_site) and nothing has to copy it. A held licence is named on
 * the site's row (cvp_own_ownership_id) at activation.
 *
 * A licence is in use while a live site names it either way; a site removed
 * leaves its licence free for another.
 *
 * The products are found by their links on this store, and are on sale here
 * only when set to "Each purchase is one more": a licence sold any other way
 * records no ownership per site, or refuses a business its second site.
 * Where neither is on sale, a business can only use a licence it already holds.
 *
 * @version 1.2 - site_line(): a held licence chosen for a site not yet paid for is named, not called no longer held
 * @version 1.1 - only a licence product's line or ownership counts (B40); one held licence per cart (B41);
 *                a revoked licence reads as no longer held (B42); an unpaid draft uses no licence (B44)
 * @version 1.0 - specs/hosting_after_purchase.md WP7
 */
class BusinessLicense {

	/** The two licences, by the choice that buys them. */
	const KINDS = array(
		'founder' => array(
			'link'     => 'founder-licence',
			'label'    => 'Founder licence',
			'features' => array(
				'Lifetime business licence for one production install',
				'Unlimited users, no feature limits',
				'Lifetime updates',
				'Every first-party paid plugin, free forever',
				'Development and staging copies free',
			),
			'note'     => 'Please consider investing in us with this limited-time founder licence.',
		),
		'standard' => array(
			'link'     => 'standard-business-licence',
			'label'    => 'Standard business licence',
			'features' => array(
				'Lifetime business licence for one production install',
				'Unlimited users, no feature limits',
				'Lifetime updates',
				'Some plugins sold separately',
				'Development and staging copies free',
			),
			'note'     => '',
		),
	);

	/** The licence line's form-data key naming the site it covers (the draft id). */
	const LINE_FIELD = 'business_licence_site';

	/** The licence line's readable key: the site's domain. */
	const LINE_SUMMARY = 'business_licence_for';

	/** The prefix of a choice naming a licence the buyer holds. */
	const HELD_PREFIX = 'held:';

	/** What the setup says to a business when it has nothing to choose. */
	const NONE_ON_SALE = 'Business licences are not on sale here right now. Contact us to buy one.';

	/** What the charge says when the licence line is no longer in the cart. */
	const LINE_MISSING = 'The business licence you chose for this site is no longer in your cart. '
		. 'Continue to payment from your site setup again.';

	private static $offers = null;

	/**
	 * The licences on sale, as kind => [product, version, price], in the
	 * order of KINDS. A product not found, inactive, without a one-time
	 * version, or not set to "Each purchase is one more" is not on sale.
	 */
	public static function offers(): array {
		if (self::$offers !== null) {
			return self::$offers;
		}
		$out = array();
		if (class_exists('Product')) {
			foreach (self::KINDS as $kind => $def) {
				$products = new MultiProduct(array('pro_link' => $def['link'], 'is_active' => true, 'deleted' => false));
				foreach ($products as $product) {
					$version = ManagedSiteDraft::managed_version($product);
					if ($version !== null && !$version->is_subscription() && $product->ownership_repeats()) {
						$out[$kind] = array('product' => $product, 'version' => $version,
							'price' => ManagedSiteDraft::money((string)$version->get('prv_version_price')));
					}
					break;
				}
			}
		}
		return self::$offers = $out;
	}

	/** Tests only: forget the products read, after making some. */
	public static function forget(): void {
		self::$offers = null;
	}

	/** The licence kind an ownership's tag is, 'founder' or 'standard'; '' for any other tag. */
	public static function kind_of_tag(string $tag): string {
		if ($tag === '' || !class_exists('Product')) {
			return '';
		}
		foreach (self::KINDS as $kind => $def) {
			$products = new MultiProduct(array('pro_link' => $def['link'], 'deleted' => false));
			foreach ($products as $product) {
				if (trim((string)$product->get('pro_ownership_tag')) === $tag) {
					return $kind;
				}
			}
		}
		return '';
	}

	/** "Founder licence", for a kind. */
	public static function label(string $kind): string {
		return self::KINDS[$kind]['label'] ?? 'Business licence';
	}

	/** The id a choice names when it is a held licence, or 0. */
	public static function held_id(string $choice): int {
		return strpos($choice, self::HELD_PREFIX) === 0 ? (int)substr($choice, strlen(self::HELD_PREFIX)) : 0;
	}

	/** Does this choice buy a licence with the site? */
	public static function buys(string $choice): bool {
		return isset(self::KINDS[$choice]);
	}

	// ------------------------------------------------------------------
	// Which licence covers which site
	// ------------------------------------------------------------------

	/**
	 * The site a licence ownership is in use by, or null when it is free. A
	 * licence is in use while a live site names it on its row, or while the
	 * site it was bought with (its line names it) is live and paid for.
	 */
	public static function site_using(Ownership $own, int $except_draft_id = 0): ?CustomerCloudProvision {
		if (self::kind_of_tag((string)$own->get('own_tag')) === '') {
			return null;   // not a licence: it covers no site (B40)
		}
		$named = new MultiCustomerCloudProvision(array('cvp_own_ownership_id' => (int)$own->key, 'deleted' => false));
		foreach ($named as $site) {
			if ((int)$site->key !== $except_draft_id) {
				return $site;
			}
		}
		$item_id = (int)$own->get('own_odi_order_item_id');
		if ($item_id > 0 && class_exists('OrderItem')) {
			$item = new OrderItem($item_id, TRUE);
			$draft_id = $item->key ? self::draft_id_from_line((array)$item->get_raw_data()) : 0;
			if ($draft_id > 0 && $draft_id !== $except_draft_id) {
				// Only a site that was paid for uses the licence bought with it:
				// a licence paid alone, its site left unpaid, is free (B44).
				$site = new CustomerCloudProvision($draft_id, TRUE);
				if ($site->key && !$site->get('cvp_delete_time') && !$site->is_pre_payment()) {
					return $site;
				}
			}
		}
		return null;
	}

	/** The live, unrevoked licence ownerships a user holds, as Ownership rows. */
	public static function held_by(int $user_id): array {
		if ($user_id <= 0 || !class_exists('Ownership')) {
			return array();
		}
		$out = array();
		foreach (new MultiOwnership(array('user_id' => $user_id, 'revoked' => false)) as $own) {
			if (self::kind_of_tag((string)$own->get('own_tag')) !== '') {
				$out[] = $own;
			}
		}
		return $out;
	}

	/** The licences a user holds that no live site uses (other than this draft), as Ownership rows. */
	public static function unused_by(int $user_id, int $except_draft_id = 0): array {
		return array_values(array_filter(self::held_by($user_id), function ($own) use ($except_draft_id) {
			return self::site_using($own, $except_draft_id) === null;
		}));
	}

	/**
	 * The licence covering a site, or null: the one named on its row, else
	 * the one bought with it (a licence line in its order naming it).
	 */
	public static function for_site(CustomerCloudProvision $site): ?Ownership {
		$own = self::recorded_for_site($site);
		return ($own !== null && self::is_live($own)) ? $own : null;
	}

	/** Is this ownership a licence still held: not revoked, not deleted, of a licence product? */
	public static function is_live(Ownership $own): bool {
		return $own->key && !$own->get('own_revoked_time') && !$own->get('own_delete_time')
			&& self::kind_of_tag((string)$own->get('own_tag')) !== '';
	}

	/**
	 * The licence ownership recorded for a site, held or not: the one named on
	 * its row, else the licence bought with it. Only a licence product's
	 * ownership counts: any other line naming the site is not its licence (B40).
	 */
	private static function recorded_for_site(CustomerCloudProvision $site): ?Ownership {
		$held = (int)$site->get('cvp_own_ownership_id');
		if ($held > 0) {
			$own = new Ownership($held, TRUE);
			return $own->key ? $own : null;
		}
		if (!self::buys((string)$site->get('cvp_license_choice'))) {
			return null;
		}
		$paying = $site->local_order_item();
		if ($paying === null || !class_exists('MultiOrderItem')) {
			return null;
		}
		foreach (new MultiOrderItem(array('order_id' => (int)$paying->get('odi_ord_order_id'))) as $item) {
			if (self::draft_id_from_line((array)$item->get_raw_data()) !== (int)$site->key) {
				continue;
			}
			foreach (new MultiOwnership(array('order_item_id' => (int)$item->key, 'include_deleted' => true)) as $own) {
				if (self::kind_of_tag((string)$own->get('own_tag')) !== '') {
					return $own;
				}
			}
		}
		return null;
	}

	/**
	 * What Your Sites says about a site's licence: "Free licence (personal
	 * use)", "Founder licence", "Founder licence (being recorded)" while
	 * the store has not yet recorded a licence bought with it, '' when the
	 * site was set up before the question was asked.
	 */
	public static function site_line(CustomerCloudProvision $site): string {
		$choice = (string)$site->get('cvp_license_choice');
		if ($choice === '') {
			return '';
		}
		if ($choice === 'free') {
			return 'Free licence (personal use)';
		}
		$own = self::recorded_for_site($site);
		if ($own !== null && self::is_live($own)) {
			$line = self::label(self::kind_of_tag((string)$own->get('own_tag')));
			$key = trim((string)$own->get('own_license_key'));
			return $key !== '' ? $line . ', key ' . $key : $line;
		}
		// A licence the buyer already holds, chosen for a site not paid for
		// yet: it is tied to the site at payment, so nothing is recorded now.
		// While it is still theirs and free, the summary names it.
		$held_id = self::held_id($choice);
		if ($own === null && $held_id > 0 && $site->is_pre_payment()) {
			foreach (self::unused_by((int)$site->get('cvp_usr_user_id'), (int)$site->key) as $free) {
				if ((int)$free->key === $held_id) {
					return self::label(self::kind_of_tag((string)$free->get('own_tag'))) . ', one you already own';
				}
			}
		}
		if ($own !== null || !self::buys($choice)) {
			// Revoked (a refund), deleted, or a held licence gone (B42).
			return 'Business licence no longer held';
		}
		// Bought with the site: on its way until the store records it. A site
		// sold by another store has its licence there, and is named only (B42).
		if ($site->is_pre_payment() || $site->local_order_item() === null) {
			return self::label($choice);
		}
		return self::label($choice) . ' (being recorded)';
	}

	// ------------------------------------------------------------------
	// The setup and the cart
	// ------------------------------------------------------------------

	/**
	 * The licence choice from the setup form, checked: '' and an error when
	 * it cannot be used. use is 'free' or 'business'; licence is a KINDS key
	 * or held:<id>.
	 */
	public static function choice_from(array $post, int $user_id, int $draft_id): array {
		$use = (string)($post['licence_use'] ?? '');
		if ($use === 'free') {
			return array('choice' => 'free', 'error' => '');
		}
		if ($use !== 'business') {
			return array('choice' => '', 'error' => 'Say who the site is for: personal use, or a business.');
		}
		$choice = (string)($post['licence_choice'] ?? '');
		if (self::buys($choice)) {
			if (!isset(self::offers()[$choice])) {
				return array('choice' => '', 'error' => 'The ' . self::label($choice) . ' is not on sale right now. Choose another.');
			}
			return array('choice' => $choice, 'error' => '');
		}
		$held = self::held_id($choice);
		if ($held > 0) {
			foreach (self::unused_by($user_id, $draft_id) as $own) {
				if ((int)$own->key === $held) {
					return array('choice' => $choice, 'error' => '');
				}
			}
			return array('choice' => '', 'error' => 'That licence is already in use by another site. Choose another.');
		}
		return array('choice' => '',
			'error' => (empty(self::offers()) && empty(self::unused_by($user_id, $draft_id)))
				? self::NONE_ON_SALE : 'Choose a business licence for the site.');
	}

	/** The licence cart line a draft brings, or none. Deterministic for the same draft. */
	public static function cart_line(int $draft_id, string $choice, string $domain): array {
		$offer = self::offers()[$choice] ?? null;
		if ($offer === null || $draft_id <= 0) {
			return array();
		}
		return array(array(
			'product_id' => (int)$offer['product']->key,
			'form_data'  => array(
				self::LINE_SUMMARY => array('question' => 'For site', 'answer' => $domain),
				self::LINE_FIELD   => array('question' => 'Site record', 'answer' => (string)$draft_id, 'internal' => true),
			),
		));
	}

	/** The draft a licence line names, or 0. */
	public static function draft_id_from_line(array $data): int {
		$answer = $data[self::LINE_FIELD] ?? 0;
		if (is_array($answer)) {
			$answer = $answer['answer'] ?? 0;
		}
		return (int)$answer;
	}

	/**
	 * Does the session's cart carry a line of this licence's product naming
	 * this draft? A line of any other product carrying the same key is not a
	 * licence, whatever its form data says (B40).
	 */
	public static function in_cart(int $draft_id, string $choice): bool {
		$offer = self::offers()[$choice] ?? null;
		if ($offer === null || !class_exists('ShoppingCart') || !isset($_SESSION)) {
			return false;
		}
		try {
			foreach (ShoppingCart::current()->items as $item) {
				$product_id = isset($item[1]) && is_object($item[1]) ? (int)$item[1]->key : 0;
				if ($product_id === (int)$offer['product']->key
						&& self::draft_id_from_line((array)($item[2] ?? array())) === $draft_id) {
					return true;
				}
			}
		} catch (\Throwable $e) {
			return false;
		}
		return false;
	}

	/**
	 * Before the charge: what refuses this draft's licence, or null. A licence
	 * to buy must still be in the cart; a held one must still be free.
	 */
	public static function refusal(CustomerCloudProvision $draft): ?string {
		$choice = (string)$draft->get('cvp_license_choice');
		if (self::buys($choice) && !self::in_cart((int)$draft->key, $choice)) {
			return self::LINE_MISSING;
		}
		$held = self::held_id($choice);
		if ($held > 0) {
			$own = new Ownership($held, TRUE);
			if (!self::is_live($own)
					|| (int)$own->get('own_usr_user_id') !== (int)$draft->get('cvp_usr_user_id')
					|| self::site_using($own, (int)$draft->key) !== null
					|| self::chosen_elsewhere_in_cart($choice, (int)$draft->key)) {
				return 'The business licence you chose for this site is already in use by another site, or no longer '
					. 'yours. Edit the site setup to choose another.';
			}
		}
		return null;
	}

	/**
	 * Does another site in the session's cart name the same held licence? Two
	 * sites paid together would both pass the in-use check, since neither is
	 * named on the licence until its activation (B41).
	 */
	private static function chosen_elsewhere_in_cart(string $choice, int $draft_id): bool {
		if (!class_exists('ShoppingCart') || !isset($_SESSION)) {
			return false;
		}
		try {
			foreach (ShoppingCart::current()->items as $item) {
				$other_id = CustomerCloudFulfillment::draft_id_from((array)($item[2] ?? array()));
				if ($other_id <= 0 || $other_id === $draft_id) {
					continue;
				}
				$other = new CustomerCloudProvision($other_id, TRUE);
				if ($other->key && !$other->get('cvp_delete_time')
						&& (string)$other->get('cvp_license_choice') === $choice) {
					return true;
				}
			}
		} catch (\Throwable $e) {
			return false;
		}
		return false;
	}

	/**
	 * At activation: a held licence is named on the site's row. Returns what
	 * an operator must hear about, or '' (a licence bought with the site is
	 * found from its line and needs nothing here).
	 */
	public static function attach_held(CustomerCloudProvision $site): string {
		$held = self::held_id((string)$site->get('cvp_license_choice'));
		if ($held <= 0 || (int)$site->get('cvp_own_ownership_id') === $held) {
			return '';
		}
		$own = new Ownership($held, TRUE);
		if (!self::is_live($own) || (int)$own->get('own_usr_user_id') !== (int)$site->get('cvp_usr_user_id')) {
			return 'the business licence #' . $held . ' the buyer chose is not theirs or no longer held';
		}
		$other = self::site_using($own, (int)$site->key);
		if ($other !== null) {
			return 'the business licence #' . $held . ' the buyer chose is already used by ' . $other->get('cvp_domain');
		}
		$site->set('cvp_own_ownership_id', $held);
		return '';
	}
}
