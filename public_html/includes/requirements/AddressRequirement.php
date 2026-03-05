<?php
/**
 * AddressRequirement
 *
 * Tier 2 custom requirement: collects address using Address model.
 * Shows existing address picker for logged-in users.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));
require_once(PathHelper::getIncludePath('data/address_class.php'));

class AddressRequirement extends AbstractProductRequirement {

    const LABEL = 'Address';

    public function render_fields($formwriter, $product, $existing_data = []) {
        $user = isset($existing_data['user']) ? $existing_data['user'] : null;
        $new_address_display = true;

        if ($user) {
            $default_address = $user->get_default_address();
            $address_book = new MultiAddress(['user_id' => $user->key, 'deleted' => false]);
            $address_book->load();
            $address_dropdown_builder = $address_book->get_address_dropdown_options($user->get_default_address());
            $new_address_display = true;

            if (count($address_dropdown_builder) > 1) {
                echo '<div id="address_container" class="sm:col-span-6 errorplacement">
                    <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                    <select name="address" id="address" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">'
                    . implode('', $address_dropdown_builder) .
                    '</select></div>';
                $new_address_display = false;
                echo '<div id="new_address_block" class="sm:col-span-6" style="display:none;">';
                Address::renderFormFields($formwriter, [
                    'required' => true,
                    'include_country' => true,
                    'include_user_id' => false,
                    'model' => null,
                ]);
                echo '</div>';
            } else {
                $formwriter->hiddeninput('address', '', ['value' => 'new']);
                Address::renderFormFields($formwriter, [
                    'required' => true,
                    'include_country' => true,
                    'include_user_id' => false,
                    'model' => null,
                ]);
            }
        } else {
            $formwriter->hiddeninput('address', '', ['value' => 'new']);
            Address::renderFormFields($formwriter, [
                'required' => true,
                'include_country' => true,
                'include_user_id' => false,
                'model' => null,
            ]);
        }
    }

    public function get_javascript(): string {
        return '
        document.addEventListener("DOMContentLoaded", function() {
            const addressSelect = document.getElementById("address");
            const newAddressBlock = document.getElementById("new_address_block");

            if (addressSelect && newAddressBlock) {
                addressSelect.addEventListener("change", function() {
                    if (this.value === "new") {
                        newAddressBlock.style.display = "block";
                    } else {
                        newAddressBlock.style.display = "none";
                    }
                });
            }
        });

        function is_new_address(element) {
            const addressSelect = document.getElementById("address");
            return addressSelect && addressSelect.value === "new";
        }';
    }

    public function validate($post_data, $product) {
        $errors = [];
        if (empty($post_data['address'])) {
            $errors[] = 'The address section must be filled out.';
        }
        return $errors;
    }

    public function process($post_data, $product, $order_detail, $user) {
        $session = SessionControl::get_instance();

        if ($post_data['address'] === 'new') {
            try {
                $user_id = null;
                if ($session->get_user_id()) {
                    $user_id = $session->get_user_id();
                }
                $address = Address::CreateAddressFromForm($post_data, $user_id);
                return [
                    ['address' => $address],
                    ['Address' => $address->get_address_string(', ')],
                ];
            } catch (AddressException $e) {
                // Return error as display data — validation should have caught this
                return [
                    ['address' => null],
                    ['Address' => 'Invalid: ' . $e->getMessage()],
                ];
            }
        } else {
            $address_key = LibraryFunctions::decode($post_data['address']);
            if ($address_key === false) {
                return [
                    ['address' => null],
                    ['Address' => 'Invalid address selection'],
                ];
            }
            $address = new Address($address_key, true);
            $address->authenticate_write([
                'current_user_id' => $session->get_user_id(),
                'current_user_permission' => $session->get_permission(),
            ]);
            return [
                ['address' => $address],
                ['Address' => $address->get_address_string(', ')],
            ];
        }
    }

    public function get_validation_info() {
        return [
            'usa_address1' => [
                'required' => ['is_new_address', 'Street Address must be set.'],
            ],
            'usa_city' => [
                'required' => ['is_new_address', 'City must be set.'],
            ],
            'usa_zip_code_id' => [
                'required' => ['is_new_address', 'Zip/Postcode must be set.'],
            ],
            'usa_state' => [
                'required' => ['is_new_address', 'State must be set.'],
            ],
        ];
    }
}
