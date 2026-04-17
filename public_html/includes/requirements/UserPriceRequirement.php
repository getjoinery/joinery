<?php
/**
 * UserPriceRequirement
 *
 * Tier 2 custom requirement: allows user to choose a price (donation amount).
 * affects_pricing() returns true.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));

class UserPriceRequirement extends AbstractProductRequirement {

    const LABEL = 'User Chooses Price';

    public function render_fields($formwriter, $product, $existing_data = []) {
        $formwriter->textinput('user_price', 'Optional donation amount ($)', [
            'size' => 20,
            'maxlength' => 255,
        ]);
    }

    public function validate($post_data, $product) {
        $errors = [];

        // Clean up the price value
        $price = isset($post_data['user_price']) ? $post_data['user_price'] : '';
        $price = str_replace(',', '.', preg_replace("/[^0-9\.,]/", "", $price));

        if ($price !== '' && $price < 0) {
            $errors[] = 'Donation amount must be zero or more.';
        }

        return $errors;
    }

    public function process($post_data, $product, $order_detail, $user) {
        $price = isset($post_data['user_price']) ? $post_data['user_price'] : '';
        $price = str_replace(',', '.', preg_replace("/[^0-9\.,]/", "", $price));

        $data_array = [
            'user_price' => $price,
        ];

        $display_array = [
            'Donation amount ($)' => $price . '.00',
        ];

        return [$data_array, $display_array];
    }

    public function affects_pricing(): bool {
        return true;
    }

    public function get_modified_price($post_data, $product, $base_price) {
        $price = isset($post_data['user_price']) ? $post_data['user_price'] : '';
        $price = str_replace(',', '.', preg_replace("/[^0-9\.,]/", "", $price));

        if ($price !== '' && is_numeric($price)) {
            return $base_price + floatval($price);
        }

        return $base_price;
    }

    public function get_validation_info() {
        // Optional field — no required validation
        return [];
    }
}
