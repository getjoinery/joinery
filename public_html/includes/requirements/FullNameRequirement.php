<?php
/**
 * FullNameRequirement
 *
 * Tier 2 custom requirement: collects first and last name.
 * Used by cart_charge_logic for user creation.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));

class FullNameRequirement extends AbstractProductRequirement {

    const LABEL = 'Name';

    public function render_fields($formwriter, $product, $existing_data = []) {
        $first_name = isset($existing_data['usr_first_name']) ? $existing_data['usr_first_name'] : '';
        $last_name = isset($existing_data['usr_last_name']) ? $existing_data['usr_last_name'] : '';

        $formwriter->textinput('full_name_first', 'First Name', [
            'size' => 20,
            'value' => $first_name,
            'maxlength' => 255,
            'validation' => ['required' => true],
        ]);
        $formwriter->textinput('full_name_last', 'Last Name', [
            'size' => 20,
            'value' => $last_name,
            'maxlength' => 255,
            'validation' => ['required' => true],
        ]);
    }

    public function validate($post_data, $product) {
        $errors = [];
        if (empty($post_data['full_name_first'])) {
            $errors[] = 'First Name is Required';
        }
        if (empty($post_data['full_name_last'])) {
            $errors[] = 'Last Name is Required';
        }
        return $errors;
    }

    public function process($post_data, $product, $order_detail, $user) {
        $data_array = [
            'full_name_first' => $post_data['full_name_first'],
            'full_name_last' => $post_data['full_name_last'],
        ];

        $display_array = [
            'First Name' => $post_data['full_name_first'],
            'Last Name' => $post_data['full_name_last'],
        ];

        return [$data_array, $display_array];
    }

    public function get_validation_info() {
        return [
            'full_name_first' => ['required' => ['true', 'First Name is required']],
            'full_name_last' => ['required' => ['true', 'Last name is required']],
        ];
    }
}
