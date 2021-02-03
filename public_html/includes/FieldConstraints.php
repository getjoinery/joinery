<?php

require_once('SystemClass.php');


class FieldConstraintError extends SystemClassException implements DisplayableErrorMessage {}

function NoSymbols($field, $value) {
	$allowed_check = '/^[A-Za-z0-9\.\*&,\- @\':\(\)+?!#%]+$/';
	if (!preg_match($allowed_check, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field can only contain letters, numbers and the ' .
			'following characters: . * & , - @ \' ( ) + ? ! # %');
	}

	$double_check = '/[\.\*&,\-@\':]{3}/';
	if (preg_match($double_check, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field cannot contain repeated symbols.');
	}
}

function NoCaps($field, $value) {
	$value = preg_replace('/\s+/', '', $value);
	$lower = strtolower($value);
	$count = 0;
	$value_length = strlen($value);
	for($i=0;$i<$value_length;$i++) {
		if ($value[$i] != $lower[$i]) {
			$count++;
		}
	}

	if (($count * 2) > strlen($value)) {
		throw new FieldConstraintError(
			'Please use fewer capital letters in the ' . $field . ' field.');
	}
}

function NoWebsite($field, $value) {
	$website = '/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i';
	if (preg_match($website, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field contains a website URL.  Please move your ' .
			'website address to the "Business Website" section.');
	}
}

function NoEmailAddress($field, $value) {
	$email = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}/i';
	if (preg_match($email, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field contains an email address.  For your privacy ' .
			'please do not include this information in this section.  We will automatically send all ' .
			'requests from prospective clients to your email address.');
	}
}

function NoPhoneNumber($field, $value) {
	$phone = '/(1[-\. ]?)?(\([2-9]\d{2}\)|[2-9]\d{2})[-\. ]?[2-9]\d{2}[-\. ]?\d{4}/';

	if (preg_match($phone, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field contains a phone number.  Please move your ' .
			'phone number to the "Phone" section.');
	}
}

function WordLength($field, $value, $min, $max) {
	$len = strlen($value);
	if ($len < $min) {
		throw new FieldConstraintError(
			'Field "' . $field . '" needs to be at least ' . $min . ' characters.');
	}
	if ($len > $max) {
		throw new FieldConstraintError(
			'Field "' . $field . '" needs to be at most ' . $max . ' characters.');
	}
}

function CheckRequiredFields($object, $required_fields, $fields) {
	foreach ($required_fields as $required_field) {
		if (gettype($required_field) == 'array') {
			$one_true = FALSE;
			foreach($required_field as $element) {
				if ($object->get($element)) {
					// If they pass an array, we check to see if one of them is true
					// If so, we are good.
					$one_true = TRUE;
					break;
				}
			}
			if (!$one_true) {
				$display_names = array();
				foreach($required_field as $field) {
					$display_names[] = "'" . $fields[$field] . "'";
				}
				throw new FieldConstraintError(
					'One of ' . implode(', ', $display_names) . ' must be set.');
				}
			} else if (is_null($object->get($required_field)) || $object->get($required_field) === '') {
			throw new FieldConstraintError(
				'Required field "' . $fields[$required_field] . '" must be set.');
		}
	}
}

?>
