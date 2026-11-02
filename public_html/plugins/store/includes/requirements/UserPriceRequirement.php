<?php
/**
 * UserPriceRequirement
 *
 * Tier 2 custom requirement: allows user to choose a price (donation amount).
 * affects_pricing() returns true.
 *
 * @version 1.1 - the donation is read with Product::buyer_amount(): a malformed amount is refused, not guessed at
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));

class UserPriceRequirement extends AbstractProductRequirement {

    const LABEL = 'User Chooses Price';

    public function render_fields($formwriter, $product, $existing_data = []) {
        $formwriter->textinput('user_price', 'Optional donation amount ($)', [
            'maxlength' => 255,
        ]);
    }

    public function validate($post_data, $product) {
        $errors = [];

        // Clean up the price value
        $raw = trim((string)($post_data['user_price'] ?? ''));
        if ($raw !== '' && Product::buyer_amount($raw) === null) {
            $errors[] = 'Enter the donation as a number, like 25 or 25.50.';
        }

        return $errors;
    }

    public function process($post_data, $product, $order_detail, $user) {
        $price = (string)Product::buyer_amount($post_data['user_price'] ?? '');

        $data_array = [
            'user_price' => $price,
        ];

        $display_array = [
            'Donation amount ($)' => $price,
        ];

        return [$data_array, $display_array];
    }

    public function affects_pricing(): bool {
        return true;
    }

    public function get_modified_price($post_data, $product, $base_price) {
        $price = Product::buyer_amount($post_data['user_price'] ?? '');
        if ($price !== null) {
            return $base_price + (float)$price;
        }

        return $base_price;
    }

    public function get_validation_info() {
        // Optional field — no required validation
        return [];
    }
}
