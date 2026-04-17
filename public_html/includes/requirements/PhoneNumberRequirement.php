<?php
/**
 * PhoneNumberRequirement
 *
 * Tier 2 custom requirement: collects phone number using PhoneNumber model.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));
require_once(PathHelper::getIncludePath('data/phone_number_class.php'));

class PhoneNumberRequirement extends AbstractProductRequirement {

    const LABEL = 'Phone Number';

    public function render_fields($formwriter, $product, $existing_data = []) {
        PhoneNumber::renderFormFields($formwriter, [
            'required' => true,
            'include_user_id' => false,
            'model' => null,
        ]);
    }

    public function validate($post_data, $product) {
        $errors = [];
        if (empty($post_data['phn_phone_number'])) {
            $errors[] = 'Phone Number is not valid';
        }
        return $errors;
    }

    public function process($post_data, $product, $order_detail, $user) {
        $data_array = [
            'phn_phone_number' => $post_data['phn_phone_number'],
        ];

        $display_array = [
            'Phone Number' => $post_data['phn_phone_number'],
        ];

        return [$data_array, $display_array];
    }

    public function get_validation_info() {
        return [
            'phn_phone_number' => [
                'required' => ['true', 'Phone number is required'],
            ],
        ];
    }
}
