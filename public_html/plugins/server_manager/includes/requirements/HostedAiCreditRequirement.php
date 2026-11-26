<?php
/**
 * HostedAiCreditRequirement — which of the buyer's sites an AI credit
 * purchase is for (specs/hosted_ai_credit.md §3.7).
 *
 * AI credit is per site, so the line names one of the buyer's sites that has
 * an AI credit account: a Managed site or a self-hosted site on the bundle.
 * The "Buy more AI credit" link on a site's hosting banner opens the product
 * with ?ai_site=<account id>, which preselects that site; the buyer can pick
 * another of theirs. validate() refuses any account that is not the signed-in
 * buyer's, so a hand-edited id buys nothing for anyone else.
 *
 * Contributed by HostedAiCreditFulfillment, never attached as a pri_ row.
 *
 * @version 1.1 - an account being removed cannot be bought for (B14)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
// Named: classes under includes/provisioning/ are not on the name-resolution path.
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionHostedAi.php'));

class HostedAiCreditRequirement extends AbstractProductRequirement {

	const LABEL = 'Site';

	/** The form field and order-item key carrying the account id. */
	const FIELD = 'hosted_ai_account';

	/** The line's readable answer: the site's name. */
	const SUMMARY_FIELD = 'hosted_ai_site';

	/** The query parameter the banner link carries. */
	const QUERY = 'ai_site';

	/** @var HostedAiAccount|null The account validate() accepted, for process(). */
	private $account = null;

	public function getFormGroup() { return 'info'; }

	/** The buyer's live AI credit accounts, id => site name. */
	public static function accounts_for_user(int $user_id): array {
		$out = array();
		if ($user_id <= 0) { return $out; }
		foreach (new MultiHostedAiAccount(array('deleted' => false), array('hai_hosted_ai_account_id' => 'ASC')) as $account) {
			if (self::owned_by($account, $user_id)) {
				$out[(int)$account->key] = ProvisionHostedAi::label($account);
			}
		}
		return $out;
	}

	/** A live account this user owns, or null. */
	public static function account_for_user(int $account_id, int $user_id): ?HostedAiAccount {
		if ($account_id <= 0 || $user_id <= 0) { return null; }
		$account = new HostedAiAccount($account_id, TRUE);
		if (!$account->key || $account->get('hai_delete_time')) { return null; }
		return self::owned_by($account, $user_id) ? $account : null;
	}

	private static function owned_by(HostedAiAccount $account, int $user_id): bool {
		// Removing too: credit bought for a site on its way out would only expire (B14).
		if (in_array((string)$account->get('hai_state'), array(HostedAiAccount::STATE_REMOVED, HostedAiAccount::STATE_REMOVING), true)) {
			return false;
		}
		$tenant_id = (int)$account->get('hai_svt_service_tenant_id');
		if ($tenant_id) {
			$tenant = new ServiceTenant($tenant_id, TRUE);
			return $tenant->key && !$tenant->get('svt_delete_time') && (int)$tenant->get('svt_usr_user_id') === $user_id;
		}
		$provision_id = (int)$account->get('hai_cvp_customer_cloud_provision_id');
		if (!$provision_id) { return false; }
		$provision = new CustomerCloudProvision($provision_id, TRUE);
		return $provision->key && !$provision->get('cvp_delete_time') && (int)$provision->get('cvp_usr_user_id') === $user_id;
	}

	/** The account id a cart line or an order item's stored answers carry. */
	public static function account_id_from(array $data): int {
		$answer = $data[self::FIELD] ?? '';
		if (is_array($answer)) {
			$answer = $answer['answer'] ?? '';
		}
		return (int)trim((string)$answer);
	}

	public function render_fields($formwriter, $product, $existing_data = array()) {
		$user_id = (int)SessionControl::get_instance()->get_user_id();
		$sites = self::accounts_for_user($user_id);
		if (!$sites) {
			echo '<p class="form-text">AI credit is bought for one of your sites with us. Sign in with the account '
				. 'your site is under to choose it.</p>';
			return;
		}
		$chosen = self::account_id_from($existing_data);
		if (!$chosen) {
			$chosen = (int)($_GET[self::QUERY] ?? 0);
		}
		if (!isset($sites[$chosen])) {
			$chosen = count($sites) === 1 ? (int)array_key_first($sites) : 0;
		}
		$formwriter->dropinput(self::FIELD, 'Site', array(
			'options'      => $sites,
			'value'        => $chosen ?: null,
			'empty_option' => count($sites) > 1 ? 'Choose the site' : false,
			'helptext'     => 'The credit is added to this site\'s AI allowance at once. Unspent credit is refundable '
				. 'on request, and ends when the site is removed.',
		));
	}

	public function validate($post_data, $product) {
		$this->account = null;
		$user_id = (int)SessionControl::get_instance()->get_user_id();
		if ($user_id <= 0) {
			return array('Sign in to buy AI credit for your site.');
		}
		$account = self::account_for_user((int)($post_data[self::FIELD] ?? 0), $user_id);
		if ($account === null) {
			return array('Choose which of your sites the AI credit is for.');
		}
		$this->account = $account;
		return array();
	}

	public function process($post_data, $product, $order_detail, $user) {
		if ($this->account === null) {
			return array(array(), array());
		}
		$label = ProvisionHostedAi::label($this->account);
		$data = array(
			self::SUMMARY_FIELD => array('question' => 'Site', 'answer' => $label),
			self::FIELD         => array('question' => 'AI credit account', 'answer' => (string)$this->account->key,
				'internal' => true),
		);
		return array($data, array('Site' => $label));
	}

	public function get_display_data($order_detail, $user) {
		return array();
	}
}
