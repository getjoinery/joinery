<?php
/**
 * DOBRequirement
 *
 * Tier 2 custom requirement: collects date of birth using 3-dropdown UI.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));

class DOBRequirement extends AbstractProductRequirement {

    const LABEL = 'Date of Birth';

    public function render_fields($formwriter, $product, $existing_data = []) {
?>
        <div id="dob_container" class="errorplacement sm:col-span-6">
            <label for="dob_date" class="block text-sm font-medium text-gray-700">Date of Birth</label>

            <select style="width: 175px" name="dob_month" id="dob_month" class="mt-1 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
            <option value="" selected></option><option value="01">01 - January</option><option value="02">02 - February</option><option value="03">03 - March</option><option value="04">04 - April</option><option value="05">05 - May</option><option value="06">06 - June</option><option value="07">07 - July</option><option value="08">08 - August</option><option value="09">09 - September</option><option value="10">10 - October</option><option value="11">11 - November</option><option value="12">12 - December</option></select>

            <select style="width: 75px;" class="mt-1  text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md" name="dob_day" id="dob_day">
            <option value="" selected></option>
            <?php
            foreach(range(1, 31) as $day) {
                echo "<option value=\"$day\">$day</option>";
            }
            ?>
            </select>

            <select style="width: 100px;" name="dob_year" id="dob_year" class="mt-1 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
            <option value="" selected></option>
            <?php
            foreach(range(intval(date('Y') - 0), 1900, -1) as $year) {
                echo "<option value=\"$year\">$year</option>";
            }
            ?>
            </select>
        </div>
<?php
    }

    public function validate($post_data, $product) {
        $errors = [];

        if (empty($post_data['dob_month']) || empty($post_data['dob_day']) || empty($post_data['dob_year'])) {
            $errors[] = 'Date of Birth must be fully filled out.';
            return $errors;
        }

        if (!is_numeric($post_data['dob_month']) || !is_numeric($post_data['dob_day']) || !is_numeric($post_data['dob_year'])) {
            $errors[] = 'Date of Birth is invalid.';
            return $errors;
        }

        $day = intval($post_data['dob_day']);
        $month = intval($post_data['dob_month']);
        $year = intval($post_data['dob_year']);

        if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 1900 || $year > 2030) {
            $errors[] = 'Date of Birth is invalid.';
        }

        return $errors;
    }

    public function process($post_data, $product, $order_detail, $user) {
        $day = intval($post_data['dob_day']);
        $month = intval($post_data['dob_month']);
        $year = intval($post_data['dob_year']);

        $data_array = [
            'dob_day' => $day,
            'dob_month' => $month,
            'dob_year' => $year,
        ];

        $display_array = [
            'Date Of Birth' => $month . '/' . $day . '/' . $year,
        ];

        return [$data_array, $display_array];
    }

    public function get_validation_info() {
        return [
            'dob_month' => [
                'required' => ['true', 'Please enter the month you were born', 'dob_container'],
            ],
            'dob_day' => [
                'required' => ['true', 'Please enter the day of the month you were born', 'dob_container'],
            ],
            'dob_year' => [
                'required' => ['true', 'Please enter the year you were born', 'dob_container'],
            ],
        ];
    }
}
