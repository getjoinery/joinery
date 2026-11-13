/*
 * /admin/admin_question_edit: the question type decides which validation
 * choices and length fields apply (adm/admin_question_edit.php).
 *
 * @version 1.0
 */
function set_validation_choices(){
	var value = document.getElementById("qst_type").value;

	// FormWriter V2 generates checkbox IDs as: fieldname_value
	var integerCheckbox = document.getElementById("validation_options_integer");
	var decimalCheckbox = document.getElementById("validation_options_decimal");

	if(value == 1){  //SHORT TEXT
		if(integerCheckbox) integerCheckbox.disabled = false;
		if(decimalCheckbox) decimalCheckbox.disabled = false;
		document.getElementById("max_length_container").style.display = "block";
		document.getElementById("min_length_container").style.display = "block";
		document.getElementById("max_value_container").style.display = "block";
		document.getElementById("min_value_container").style.display = "block";
		document.getElementById("answersbox").style.display = "none";
	}
	else if(value == 2){  //LONG TEXT
		if(integerCheckbox) {
			integerCheckbox.disabled = true;
			integerCheckbox.checked = false;
		}
		if(decimalCheckbox) {
			decimalCheckbox.disabled = true;
			decimalCheckbox.checked = false;
		}
		document.getElementById("max_length_container").style.display = "block";
		document.getElementById("min_length_container").style.display = "block";
		document.getElementById("max_value_container").style.display = "none";
		document.getElementById("min_value_container").style.display = "none";
		document.getElementById("answersbox").style.display = "none";
	}
	else {  //DROPDOWN, RADIO, CHECKBOX, CHECKBOX_LIST
		if(integerCheckbox) {
			integerCheckbox.disabled = true;
			integerCheckbox.checked = false;
		}
		if(decimalCheckbox) {
			decimalCheckbox.disabled = true;
			decimalCheckbox.checked = false;
		}
		document.getElementById("max_length_container").style.display = "none";
		document.getElementById("min_length_container").style.display = "none";
		document.getElementById("max_value_container").style.display = "none";
		document.getElementById("min_value_container").style.display = "none";
		document.getElementById("answersbox").style.display = "block";
	}

	// Additional checkbox-specific logic
	if(value == 5){  //CHECKBOX - limit to one option
		var existingOptions = document.querySelectorAll('.question-option-item');
		if(existingOptions.length >= 1){
			var addForm = document.getElementById("add-option-form");
			if(addForm) addForm.style.display = "none";
		}
	} else {
		var addForm = document.getElementById("add-option-form");
		if(addForm) addForm.style.display = "block";
	}
}

// Replace jQuery document ready and change handler
document.addEventListener('DOMContentLoaded', function() {
	set_validation_choices();
	document.getElementById("qst_type").addEventListener('change', set_validation_choices);
});
