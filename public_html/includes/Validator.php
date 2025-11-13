<?php
class Validator {

	var $errors; // A variable to store a list of error messages

	// Validate something has been entered
	// NOTE: Only this method does nothing to prevent SQL injection
	// use with addslashes() command
	function validateGeneral($theinput, $blankname, $description = NULL){
		if (trim($theinput) != "") {
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You must enter something in this blank.';
			}

			$this->errors[] = $description;
			return false;
		}
	}

	// Validate text only
	function validateTextOnly($theinput, $blankname, $description = ''){
		$result = ereg ("^[A-Za-z0-9\ ]+$", $theinput );
		if ($result){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'Only uppercase and lowercase letters and spaces allowed in this blank.';
			}

			$this->errors[] = $description;
			return false;
		}
	}

	// Validate text only, no spaces allowed
	function validateTextOnlyNoSpaces($theinput, $blankname, $description = ''){
		$result = ereg ("^[A-Za-z0-9]+$", $theinput );
		if ($result){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'Only uppercase and lowercase letters (with no spaces) allowed in this blank.';
			}

			$this->errors[] = $description;
			return false;
		}
	}

	// Validate any entry, no spaces allowed
	function validateGeneralNoSpaces($theinput, $blankname, $description = ''){
		if (!strstr($theinput," ")){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'No spaces are allowed in this blank.';
			}
			$this->errors[] = $description;
			return false;
		}
	}


	// VALIDATE BOOLEAN
	function validateBoolean($theinput, $blankname, $description = ''){
		if ($theinput == TRUE || $theinput == FALSE || $theinput == 1 ||$theinput == 0){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'This value must be true or false (1 or 0).';
			}
			$this->errors[] = $description;
			return false;
		}
	}

	// Validate email address
	function validateEmail($themail, $blankname, $description = ''){
		$result = ereg ("^[^@ ]+@[^@ ]+\.[^@ \.]+$", $themail );
		if ($result){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid email address.';
			}
			$this->errors[] = $description;
			return false;
		}

	}

	// VALIDATE ZIP CODE
	function validateZip($theinput, $blankname, $description = ''){

		$result = preg_match('/^[0-9]{5}([- ]?[0-9]{4})?$/', $theinput);
		if ($result){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid zip code..';
			}
			$this->errors[] = $description;
			return false;
		}
	}

	// VALIDATE PREFIX
	function validatePrefix($theinput, $blankname, $description = ''){

		if ($theinput == "Mr." || $theinput == "Ms."){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not choose a valid prefix.';
			}
			$this->errors[] = $description;
			return false;
		}
	}

	// VALIDATE DROPDOWN
	function validateDropDown($theinput, $blankname, $description = ''){

		if ($theinput != ""){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You must choose something.';
			}
			$this->errors[] = $description;
			return false;
		}
	}


	// Validate numbers only
	function validateNumber($theinput, $blankname, $description = ''){
		if (is_numeric($theinput)) {
			return true; // The value is numeric, return true
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'Only numbers are allowed in this field.';
			}
			$this->errors[] = $description; // Value not numeric! Add error description to list of errors
			return false; // Return false
		}
	}

	// Validate numbers only
	function validateYear($theinput, $blankname, $description = ''){
		if (is_numeric($theinput) && $theinput >1000) {
			return true; // The value is numeric, return true
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid date.';
			}
			$this->errors[] = $description; // Value not numeric! Add error description to list of errors
			return false; // Return false
		}
	}

	// Validate date
	function validateDate($thedate, $blankname, $description = ''){

		if (strtotime($thedate) === -1 || $thedate == '') {
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid date.';
			}
			$this->errors[] = $description;
			return false;
		}else{
			return true;
		}
	}

	// Validate user date
	function validateUserDate($startdate, $enddate,  $blankname, $description = ''){

		if (strtotime($startdate) > strtotime($enddate)) {
			if($description == NULL){
				$description = $blankname . ': ' . 'Your start date is after your end date.';
			}
			$this->errors[] = $description;
			return false;
		}else{
			return true;
		}
	}


	// Check whether any errors have been found (i.e. validation has returned false)
	// since the object was created
	function foundErrors() {
		if (count($this->errors) > 0){
			return true;
		}else{
			return false;
		}
	}

	// Return a string containing a list of errors found,
	// Seperated by a given deliminator
	function listErrors($delim = ' '){
		return implode($delim,$this->errors);
	}

	// Manually add something to the list of errors
	function addError($description){
		$this->errors[] = $description;
	}

	// Validate phone number
	function validatePhone($theinput, $blankname, $description = ''){
		// Accept various phone formats: (123) 456-7890, 123-456-7890, 123.456.7890, 1234567890
		$result = preg_match('/^\(?([0-9]{3})\)?[-. ]?([0-9]{3})[-. ]?([0-9]{4})$/', $theinput);
		if ($result){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid phone number.';
			}
			$this->errors[] = $description;
			return false;
		}
	}

	// Validate URL
	function validateURL($theinput, $blankname, $description = ''){
		$result = preg_match('/^https?:\/\/.+/', $theinput);
		if ($result){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid URL.';
			}
			$this->errors[] = $description;
			return false;
		}
	}

	// Validate SSN (Social Security Number)
	function validateSSN($theinput, $blankname, $description = ''){
		// Accept formats: 123-45-6789 or 123456789
		$result = preg_match('/^([0-9]{3})[-]?([0-9]{2})[-]?([0-9]{4})$/', $theinput);
		if ($result){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid SSN.';
			}
			$this->errors[] = $description;
			return false;
		}
	}

	// Validate EIN (Employer Identification Number)
	function validateEIN($theinput, $blankname, $description = ''){
		// Accept formats: 12-3456789 or 123456789
		$result = preg_match('/^([0-9]{2})[-]?([0-9]{7})$/', $theinput);
		if ($result){
			return true;
		}else{
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid EIN.';
			}
			$this->errors[] = $description;
			return false;
		}
	}

	// Validate credit card number (basic Luhn algorithm check)
	function validateCard($theinput, $blankname, $description = ''){
		// Remove spaces and dashes
		$cardNumber = preg_replace('/[\s\-]/', '', $theinput);

		// Check if it's numeric and has a reasonable length
		if (!preg_match('/^[0-9]{13,19}$/', $cardNumber)) {
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid credit card number.';
			}
			$this->errors[] = $description;
			return false;
		}

		// Luhn algorithm validation
		$sum = 0;
		$length = strlen($cardNumber);
		$parity = $length % 2;

		for ($i = 0; $i < $length; $i++) {
			$digit = (int)$cardNumber[$i];
			if ($i % 2 == $parity) {
				$digit *= 2;
				if ($digit > 9) {
					$digit -= 9;
				}
			}
			$sum += $digit;
		}

		if ($sum % 10 == 0) {
			return true;
		} else {
			if($description == NULL){
				$description = $blankname . ': ' . 'You did not enter a valid credit card number.';
			}
			$this->errors[] = $description;
			return false;
		}
	}

}
?>