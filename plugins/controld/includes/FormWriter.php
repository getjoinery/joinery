<?php
// PathHelper is already loaded by the time this file is included
// We just need the dependencies
if (!class_exists('FormWriterMasterBootstrap')) {
    PathHelper::requireOnce('includes/Globalvars.php');
    PathHelper::requireOnce('includes/DbConnector.php');
    PathHelper::requireOnce('includes/FormWriterMasterBootstrap.php');
}

// FormWriter class for ControlD plugin theme
// Extends the base Bootstrap FormWriter with ControlD-specific styling

class FormWriter extends FormWriterMasterBootstrap { 

	public $validate_style_info = '
							ignore: ":hidden:not(input[type=\'checkbox\'], input[type=\'radio\'])",
							errorElement: "p",
							errorClass: "text-danger",
							highlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name+"").addClass("is-invalid");
							  },
							  unhighlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								  $("#"+name+"").removeClass("is-invalid");
							  },
							errorPlacement: function(error, element) {
								error.appendTo(element.parents(".errorplacement").eq(0));
							}';
	
	//FORM STYLING - ControlD Theme styling
	protected $fileinput_container_class = ' form-group';
	protected $fileinput_input_class = '';
	
	protected $text_container_class = ' form-group';
	protected $text_label_class = '';
	
	protected $textintput_container_class_horizontal = 'row';
	protected $textintput_label_class_horizontal = ' form-group';
	protected $textintput_container_class = ' form-group';
	protected $textintput_label_class = '';
	protected $textintput_input_class = '';
	
	protected $textbox_container_class = ' form-group';
	protected $textbox_textarea_class = '';

	protected $checkboxinput_container_class = ' form-group';
	protected $checkboxinput_input_class = '';
	
	protected $checkboxList_container_class = ' form-group';
	protected $checkboxList_wrapper_class = '';
	protected $checkboxList_input_class_checkbox = '';	
	protected $checkboxList_input_class_radio = '';	
	
	protected $timeinput_container_class = ' form-group';
	protected $timeinput_input_class = 'timepicker';
	
	protected $dropinput_container_class = ' form-group';
	protected $dropinput_select_class = 'form-select';
	
	// ControlD button styling
	protected $button_primary_class = 'th-btn';
	protected $button_secondary_class = 'th-btn style2';
	
	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = NULL){
		$output = '<form class="'.$class.'" id="'. $this->formid.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'">';
		return $output;
	}

	function end_form(){
		return '</form>';
	}
	
	function toggleinput($label, $id, $class, $value, $truevalue, $hint){
		
		if($value == $truevalue){
			$checked = 'checked="checked"'; 
		}
		else{
			$checked = ''; 
		}
		
		$output = '
			<div class="form-check">
				<input '.$checked.' type="checkbox" class="form-check-input" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'">
				<label class="form-check-label" for="'.$id.'">'.$label.'</label>
			</div>
		';
		if($hint){
			$output .= '<small class="form-text text-muted">'.$hint.'</small>';
		}
		
		return $output;		
	}
	
	// Override button methods with ControlD styling
	function new_button($type="button", $class="", $id="", $onclick="", $text="", $btn_type="primary", $hint="", $override_styles=false){
		
		if($override_styles){
			//USE WHATEVER WAS PROVIDED
		}
		else{
			if($btn_type == 'primary'){
				$class = $this->button_primary_class.' '.$class;
			}
			else{
				$class = $this->button_secondary_class.' '.$class;
			}
		}
		
		$output = '';
		
		if($onclick){
			$onclick = 'onclick="'.$onclick.'"';
		}
		
		$output .= '<button type="'.$type.'" class="'.$class.'" id="'.$id.'" '.$onclick.'>'.$text.'</button>';
		
		if($hint){
			$output .= '<small class="form-text text-muted">'.$hint.'</small>';
		}
		
		return $output;
	}
	
	function new_form_button($class="", $id="", $onclick="", $text="", $btn_type="primary", $hint="", $override_styles=false){
		
		if($override_styles){
			//USE WHATEVER WAS PROVIDED
		}
		else{
			if($btn_type == 'primary'){
				$class = $this->button_primary_class.' '.$class;
			}
			else{
				$class = $this->button_secondary_class.' '.$class;
			}
		}
		
		$output = '';
		
		if($onclick){
			$onclick = 'onclick="'.$onclick.'"';
		}
		
		$output .= '<button type="submit" class="'.$class.'" id="'.$id.'" '.$onclick.'>'.$text.'</button>';
		
		if($hint){
			$output .= '<small class="form-text text-muted">'.$hint.'</small>';
		}
		
		return $output;
	}
	
	// All other methods are inherited from FormWriterMasterBootstrap
	// This provides a complete FormWriter implementation for ControlD theme
}
?>