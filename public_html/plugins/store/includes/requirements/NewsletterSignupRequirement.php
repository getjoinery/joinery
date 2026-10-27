<?php
/**
 * NewsletterSignupRequirement
 *
 * Tier 2 custom requirement: newsletter signup checkbox.
 * post_purchase() handles the newsletter subscription side effect.
 *
 * @version 1.1 - post_purchase() subscribes the buyer to the default mailing list
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));

class NewsletterSignupRequirement extends AbstractProductRequirement {

    const LABEL = 'Newsletter Signup';

    public function render_fields($formwriter, $product, $existing_data = []) {
        $formwriter->checkboxinput('newsletter', 'Keep me updated by email', [
            'value' => '1',
        ]);
    }

    public function validate($post_data, $product) {
        // Optional — no validation needed
        return [];
    }

    public function process($post_data, $product, $order_detail, $user) {
        $newsletter = !empty($post_data['newsletter']);

        $data_array = [
            'newsletter' => $newsletter ? '1' : '0',
        ];

        $display_array = [
            'Newsletter Signup' => $newsletter ? 'Yes' : 'No',
        ];

        return [$data_array, $display_array];
    }

    public function post_purchase($data, $order_item, $user, $order) {
        if (empty($data['newsletter']) || !$user || !$user->key) {
            return;
        }
        $list = Globalvars::get_instance()->get_setting('default_mailing_list');
        if (!$list) {
            return;
        }
        // Scoped to the default list: ticking this box subscribes the buyer
        // and leaves every other list they are on as it is.
        $scope = ($list === 'all') ? null : array((int)$list);
        $user->add_user_to_mailing_lists($list, $scope);
    }
}
