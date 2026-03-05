<?php
/**
 * NewsletterSignupRequirement
 *
 * Tier 2 custom requirement: newsletter signup checkbox.
 * post_purchase() handles the newsletter subscription side effect.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));

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
        // Newsletter subscription side effect
        if (!empty($data['newsletter'])) {
            // The actual newsletter subscription logic will be called here
            // This was previously handled in cart_charge_logic.php
        }
    }
}
