<?php
/**
 * EmailRequirement
 *
 * Tier 2 custom requirement: collects email address.
 * Used by cart_charge_logic for user lookup/creation.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));

class EmailRequirement extends AbstractProductRequirement {

    const LABEL = 'Email';

    public function render_fields($formwriter, $product, $existing_data = []) {
        $email = isset($existing_data['usr_email']) ? $existing_data['usr_email'] : '';

        $formwriter->textinput('email', 'Email', [
            'size' => 20,
            'value' => $email,
            'maxlength' => 255,
            'validation' => ['required' => true],
        ]);
    }

    public function validate($post_data, $product) {
        $errors = [];
        if (empty($post_data['email'])) {
            $errors[] = 'Email is Required';
        } elseif (!filter_var($post_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email address '" . $post_data['email'] . "' is not valid.";
        }
        return $errors;
    }

    public function process($post_data, $product, $order_detail, $user) {
        $data_array = [
            'email' => $post_data['email'],
        ];

        $display_array = [
            'Email' => $post_data['email'],
        ];

        return [$data_array, $display_array];
    }

    public function get_validation_info() {
        return [
            'email' => ['required' => ['true', 'Email is required']],
        ];
    }
}
