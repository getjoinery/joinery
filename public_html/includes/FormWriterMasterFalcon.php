<?php
require_once('FormWriterBase.php');
require_once('DbConnector.php');
require_once('Globalvars.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriterMaster extends FormWriterBase {
	public $validate_style_info = '
							/* ignore: ":hidden:not(input[type=\'checkbox\'], input[type=\'radio\'])", */
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
	
	//FORM STYLING
	
	protected $text_container_class = 'mb-3';
	protected $text_label_class = 'col-sm-2 col-form-label';
	protected $text_input_class = 'form-control-plaintext outline-none';
	
	protected $textinput_container_class = 'mb-3';
	protected $textinput_label_class = 'form-label';
	protected $textinput_input_class = 'form-control';
	
	protected $textbox_container_class = 'form-label';
	protected $textbox_textarea_class = 'form-control';

	protected $checkboxinput_container_class = 'form-check';
	protected $checkboxinput_input_class = 'form-check-input';
	protected $checkboxinput_label_class = 'form-check-label';
	
	protected $checkboxList_container_class = 'form-check';
	protected $checkboxList_input_class = 'form-check-input';
	protected $checkboxList_label_class = 'form-check-label';		
	
	protected $dropinput_container_class = 'mb-3';
	protected $dropinput_label_class = 'form-label';
	protected $dropinput_select_class = 'form-select';
	
	protected $button_primary_class = 'btn-primary';
	protected $button_secondary_class = 'btn-secondary';




	
	
	

	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = NULL){
		$output = '<form class="'.$class.'" id="'. $this->formid.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'"><fieldset>';
		return $output;
	}

	function end_form(){
		return '</fieldset></form>';
	}
	
	//DEPRECATED
	function start_buttons($class = '') {
		return '';
	}


	//STYLE IS 'primary' or 'secondary'
	//WIDTH IS 'standard' or 'full'
	function new_button($label='Submit', $link, $style='primary', $width='standard', $class='', $id=NULL) {
		$output = '';
		if($style == 'primary'){
			$class = $this->button_primary_class . ' ' . $class;
		}
		else{
			$class = $this->button_secondary_class . ' ' . $class;
		}
		
		if($width == 'full'){
			$output .= '<div class="d-grid gap-2">';
		}
		
		
		$output .= '<a href="'.$link.'"><button type="button" class="btn me-1 mb-1 '.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= '>';
		$output .= $label.'</button></a>';
		if($width == 'full'){
			$output .= '</div>';
		}
		return $output;
	}

	//STYLE IS 'primary' or 'secondary'
	//WIDTH IS 'standard' or 'full'
	function new_form_button($label='Submit', $style='primary', $width='standard', $class='', $id=NULL) {
		$output = '';
		if($style == 'primary'){
			$class = $this->button_primary_class . ' ' . $class;
		}
		else{
			$class = $this->button_secondary_class . ' ' . $class;
		}

		if($width == 'full'){
			$output .= '<div class="d-grid gap-2">';
		}	
		
		$output = '<button type="submit" class="btn me-1 mb-1 '.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= '>';
		$output .= $label.'</button>';
		if($width == 'full'){
			$output .= '</div>';
		}
		return $output;
	}

	
	function end_buttons() {
		return '';
	}
	
	function set_validate($validation_rules, $custom_js = NULL, $debug=false){
		$debugtext = '';
		if($debug){
			$debugtext = ',
			
        invalidHandler: function(event, validator) {
            if (validator.numberOfInvalids()) {
                let errorList = "Please fix the following errors:\n\n";
                $.each(validator.errorList, function(index, error) {
                    errorList += "- " + error.element.name + ": " + error.message + "\n";
                });
                alert(errorList);
            }
        }';
		}
		
		$output = '
		<script type="text/javascript">
			$(document).ready(function() {
				jQuery.validator.addMethod("phoneUS", function(phone_number, element) {
				    phone_number = phone_number.replace(/\s+/g, "");
					return this.optional(element) || phone_number.length > 9 &&
						phone_number.match(/^(1-?)?(\([2-9]\d{2}\)|[2-9]\d{2})-?[2-9]\d{2}-?\d{4}$/);
				}, "Please specify a valid phone number");
				
				function parseTime(timeStr) {
					var parts = timeStr.split(":");
					return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
				}

				// Custom validator to check that end_time is greater than start_time.
				$.validator.addMethod("timeGreaterThan", function(value, element, param) {
					// Get the start time field value
					var startVal = $(param).val();
					// Only validate if both values are present.
					if (!startVal || !value) {
						return true; // Let the required rule handle empty fields.
					}
					return parseTime(value) > parseTime(startVal);
				}, "End time must be after start time.");

					$("#'.$this->formid.'").validate({
							'.$custom_js.'
							rules: {';
							$output .= "\r\n";
							foreach($validation_rules as $name=>$rules){
								$output .= $name .': {';
								$output .= "\r\n";
								foreach($rules as $type=>$value){
									$output .=  $type . ': '. $value['value'] . ',';
									$output .= "\r\n";
								}
								$output .=  '},';
								$output .= "\r\n";
							}
							$output .=  '},';
							$output .= "\r\n";
							
							$output .=  'messages: {';
							$output .= "\r\n";
							foreach($validation_rules as $name=>$rules){

								foreach($rules as $rule_name=>$rule_values){
									if($rule_values['message']){
										$output .=  $name .': {';
										$output .= "\r\n";
										$output .=  $rule_name . ': '. $rule_values['message'] . ',';
										$output .= "\r\n";
										$output .=  '},';
										$output .= "\r\n";
									}
								}
							}
							$output .=  '},';	
							$output .= "\r\n";							
							$output .=  $this->validate_style_info . $debugtext .'
							});';

			$output .= '});
		  </script>';
	
		return $output;
	
	}
	



	/*******************************
	GENERATES A LABEL WITH TEXT, NOT EDITABLE

	INPUT: 	id (str)
			label (str)
			value (value)
			class (str)		
			layout ('default', 'horizontal', '')
	********************************/
	function text($id, $label, $value, $class, $layout='default') {
		if($layout == 'default'){
			$output = '
			<div id="'.$id.'_container" class="'.$this->text_container_class.' errorplacement">
			<label for="'.$id.'" class="'.$this->text_label_class.'">'.$label.'</label>
			<input class="'.$this->text_input_class.'" id="'.$id.'" type="text" readonly="" value="'.$value.'" />
			</div>';
		}
		else{
			$output = '
			<div class="'.$this->text_container_class.' row">
			  <label for="'.$id.'" class="'.$this->text_label_class.'">'.$label.'</label>
			  <div class="col-sm-10">
				<input class="'.$this->text_input_class.'" id="'.$id.'" type="text" readonly="" value="'.$value.'" />
				<div class="mb-3 row"></div>
			  </div>
			</div>';						
		}
		return $output;
	}



	/*******************************
	GENERATES A TEXT INPUT FIELD

	INPUT: 	label (str)
			id (str)
			class (str)
			size(integer)
			value (value)
			hint (string appears inside)
			maxlength (1-255)
			readonly (true or false)
			autocomplete (true or false)
			formhint (string that appears at the beginning of the field)
			type (text, date, password, file)
			layout ('default', 'horizontal', '')
	********************************/
	function textinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $type='text', $layout='default') {

		//FORMS ARE EITHER HORIZONTAL OR REGULAR
		if($layout == 'default'){
			$containerclass = $this->textinput_container_class;
			$labelclass = $this->textinput_label_class;
		}
		else if($layout == 'horizontal'){
			$containerclass = $this->textinput_container_class;
			$labelclass = $this->textinput_label_class;
		}
		else{
			$labelclass = '';
			$containerclass = '';
		}
		
		if($value){
			$value = str_replace('"', '&quot;', $value );
		}
		
		
		if($hint){ 
			$hint_text = 'placeholder="'.$hint.'" onfocus="this.placeholder = \'\'" onblur="this.placeholder = \''.$hint.'\'"';
		}	
		
		
		$output = '<div id="'.$id . '_container" class="errorplacement '.$containerclass.'">';
		

		
		if(!$autocomplete){
			$autocomplete = 'autocomplete="off"';
		}
		else{
			$autocomplete = '';
		}




		
		if($layout == 'horizontal'){
			$output .= '<div class="row mb-3">';
			if($label){
				$output .= '<label for="'.$id.'" class="col-sm-2 col-form-label">'.$label.'</label>';
			} 
			$output .= '<div class="col-sm-10">';
	
	
			if($formhint){
				$output .= '<div class="input-group">
				<div class="input-group-text">'.$formhint.'</div>';
			}

			$output .= '<input name="'.$id.'" id="'.$id.'"'.$autocomplete.' value="'.$value.'" size="'.$size.'" type="'.$type.'" class="'.$this->textinput_input_class.' '.$class.'" '.$hint_text.' maxlength="'.$maxlength.'" '.$readonly.$this->_get_next_tab_index().'/>';
			
			if($formhint){
				$output .= '</div>';
			}

			$output .= '</div> </div>';
		}
		else{
			if($label){
				$output .= '<label for="'.$id.'" class="'.$labelclass.'">'.$label.'</label>';
			} 

			if($formhint){
				$output .= '<div class="input-group">
				<div class="input-group-text">'.$formhint.'</div>';
			}
			
			$output .= '<input name="'.$id.'" id="'.$id.'"'.$autocomplete.' value="'.$value.'" size="'.$size.'" type="'.$type.'" class="'.$this->textinput_input_class.' '.$class.'" '.$hint_text.' maxlength="'.$maxlength.'" '.$readonly.$this->_get_next_tab_index().'/>';
			
			if($formhint){
				$output .= '</div>';
			}
			
		}

		$output .= '</div>';
		
		return $output;

	}
	

	/*******************************
	GENERATES A FILE INPUT

	SEE TEXTINPUT
	********************************/		
	function fileinput($label, $id, $class, $size, $hint, $layout='default') {
		return $this->textinput($label, $id, $class, $size, NULL, $hint, 255, '', FALSE, FALSE, 'file', $layout);
	}

	/*******************************
	GENERATES A PASSWORD INPUT

	SEE TEXTINPUT
	********************************/	
	function passwordinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly="", $layout='default') {
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, TRUE, FALSE, 'password', $layout);
	}
	

	/*******************************
	GENERATES A TEXT BOX

	INPUT: 	label (str)
			id (str)
			class (str)
			rows (integer)
			cols (integer)
			value (value)
			hint (string)
			htmlmode ('yes', 'no')
	********************************/
	function textbox($label, $id, $class, $rows, $cols, $value, $hint, $htmlmode="no") {
		$output = '';
		if($htmlmode == 'yes'){
			$output .= '
			
			<script src="/adm/includes/Trumbowyg-2-26/dist/trumbowyg.min.js"></script>
			<link rel="stylesheet" href="/adm/includes/Trumbowyg-2-26/dist/ui/trumbowyg.min.css">
			<script src="/adm/includes/Trumbowyg-2-26/dist/plugins/cleanpaste/trumbowyg.cleanpaste.min.js"></script>
			<script src="/adm/includes/Trumbowyg-2-26/dist/plugins/preformatted/trumbowyg.preformatted.min.js"></script>
			<script src="/adm/includes/Trumbowyg-2-26/dist/plugins/allowtagsfrompaste/trumbowyg.allowtagsfrompaste.min.js"></script>
			<script type="text/javascript">';
			$output .= "
				$(document).ready(function() {
						$('.html_editable').trumbowyg({
							autogrow: false,
							autogrowOnEnter: false,
							btns: [
								['viewHTML'],
								['undo', 'redo'], // Only supported in Blink browsers
								['formatting'],
								['strong', 'em', 'del'],
								['superscript', 'subscript'],
								['link'],
								['insertImage'],
								['preformatted'],
								['justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull'],
								['unorderedList', 'orderedList'],
								['horizontalRule'],
								['removeformat'], 
								['fullscreen']
							],
							 semantic:{
							  'div': 'div'
							},
							plugins: {
								allowTagsFromPaste: {
									allowedTags: ['p', 'br','blockquote', 'b', 'i', 'strong', 'em', 'ul', 'li', 'ol', 'a','code','pre','h1','h2','h3','h4','h5','embed','table','tr','td','th','img','video']
								}
							}
						});
						$('.trumbowyg-editor').attr('name', 'trumbobox');

				});
			</script>
			
			<style>
			.trumbowyg-box,
			.trumbowyg-editor,
			.trumbowyg-textarea {
				height: 500px;
			}

			.trumbowyg-box.trumbowyg-fullscreen,
			.trumbowyg-box.trumbowyg-fullscreen .trumbowyg-editor,
			.trumbowyg-box.trumbowyg-fullscreen .trumbowyg-textarea {
				height: 100%;
			}
			/*
			.trumbowyg-box {
				max-height: 500px;
			}
			*/
			</style>
			";
	
	
			$output .= '
				<div id="'.$id.'_container" class="mb-3 errorplacement">
					<label class="'.$this->textbox_container_class.'" for="'.$id.'">'.$label.'</label>
					<textarea name="'.$id.'" id="'.$id.'" class="'.$this->textbox_textarea_class.' html_editable" rows="'.$rows.'" cols="'.$cols.'" placeholder="'.$hint.'">'.$value.'</textarea>
				</div>';
		}
		else{
			$output .= '
				<div id="'.$id.'_container" class="mb-3 errorplacement">
					<label class="'.$this->textbox_container_class.'" for="'.$id.'">'.$label.'</label>
					<textarea name="'.$id.'" id="'.$id.'" class="'.$this->textbox_textarea_class.'" rows="'.$rows.'" cols="'.$cols.'" placeholder="'.$hint.'">'.$value.'</textarea>
				</div>';
		}
				
		if($formhint){
			$output .= '<div id="'.$id.'_hint"><small>'.$formhint.'</small></div>';
		}

		
		return $output;
	}

	

	/*******************************
	GENERATES A CHECKBOX

	INPUT: 	label (str)
			id (str)
			class (str)
			align(unused)
			value (value)
			truevalue (value if checked)
			hint (string)
			layout ('default', 'horizontal', '')
			TODO:  Remove align or use it for other things
	********************************/
	function checkboxinput($label, $id, $class, $align, $value, $truevalue, $hint, $layout='default'){

		//FORMS ARE EITHER HORIZONTAL OR DEFAULT
		if($layout == 'default'){
			$containerclass = $this->checkboxinput_container_class;
			$labelclass = $this->checkboxinput_label_class;
		}
		else if($layout == 'horizontal'){
			$containerclass = $this->checkboxinput_container_class ;
			$labelclass = $this->checkboxinput_label_class;
		}
		else{
			$labelclass = '';
			$containerclass = '';
		}

		
		if($value == $truevalue){
			$checked = 'checked="checked"'; 
		}
		else{
			$checked = '';
		}




		if($layout == 'horizontal'){
			return '<div class="row mb-3">
						<div class="col-form-label col-sm-2 pt-0">'.$label.'</div>
						<div class="col-sm-10">
						  <div class="'.$containerclass.' errorplacement">
							<div id="'.$id.'_container" class="'.$containerclass.'">
								<input class="'.$this->checkboxinput_input_class.'" type="checkbox" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'" '.$checked.' '.$this->_get_next_tab_index().' />
								<label for="'.$id.'" class="'.$labelclass.'"></label>                  
							</div>
						   </div>
						</div>
					</div>';
		}
		else{
			return '<div class=" errorplacement">
					<div id="'.$id.'_container" class="'.$containerclass.'">
						<input class="'.$this->checkboxinput_input_class.'" type="checkbox" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'" '.$checked.' '.$this->_get_next_tab_index().' />
						<label for="'.$id.'" class="'.$labelclass.'">'.$label.'</label>                  
					</div>
				   </div>';			
		}



	}


	/*******************************
	GENERATES A CHECKBOX GROUP GIVEN AN ARRAY OF VALUES

	INPUT: 	label (str)
			id (str)
			optionvals(associative array, 'label'=>'value')
			checkedvals(single dimensional array)
			readonlyvals(single dimensional array)
	********************************/


	
	//IF TYPE IS 'RADIO' THIS BECOMES A RADIO INPUT
	function checkboxList($label, $id, $class, $optionvals, $checkedvals=array(), $disabledvals=array(), $readonlyvals=array(), $hint='', $type='checkbox') {
		$output = '';

		if(empty($optionvals)){
			return false;
		}

		if(!is_array($checkedvals)){
			$checkedvals = array();
		}

		if($type=='checkbox'){
			$class= $this->checkboxList_input_class;
		}
		else if($type=='radio'){
			$type='radio';
			$class= $this->checkboxList_input_class;
			
			if(is_array($checkedvals) && count($checkedvals) > 1){
				throw new SystemDisplayableError('A radio field cannot have more than one checked value.');
			}
			
			if($readonlyvals){
				throw new SystemDisplayableError('A radio field cannot have read only values.');
			}			
		}
		else{
			throw new SystemDisplayableError('Invalid checkbox type.');
		}

		$output .= '<div id="'.$id.'_container" class="errorplacement">';
		$output .= '<label for="'.$id.'">'.$label.'</label>';
		//$output .=  '<fieldset style="padding:30px; margin:0px;">';
		foreach ($optionvals as $key => $value) {
			$uniqid = $id . $value;
			if(in_array($value, $checkedvals)){
				$checked = 'checked="checked"';
			}
			else{
				$checked = '';
			}
			
			//DISABLED MEANS THE VALUE IS NOT PASSED THROUGH POST
			if(in_array($value, $disabledvals)){
				$disabled = 'disabled="disabled"';
			}
			else{
				$disabled = '';
			}			

			//READONLY MEANS IT CANNOT BE CHANGED AND IS SUBMITTED THROUGH POST
			if(in_array($value, $readonlyvals)){
				if($checked){
					$output .= $this->hiddeninput($id.'[]', $value);	
					//$output .= '<label for="'.$uniqid.'">'.$key.' (checked, read only)</label><br>';
				}
				else{
					$output .= $this->hiddeninput($id.'[]', '');	
					//$output .= '<label for="'.$uniqid.'">'.$key.' (unchecked, read only)</label><br>';
				}
				
				$output .= '
						<div class="'.$this->checkboxList_container_class.'">
							<input class="'.$class.'" type="'.$type.'" id="'.$uniqid.'" name="'.$id.'[]" value="'.$value.'" '.$checked.' disabled="disabled" />
							<label class="'.$this->checkboxList_label_class.'" for="'.$uniqid.'">'.$key.'</label>                  
						</div>
					   ';				
				
				
			}
			else{

				$output .= '
						<div class="'.$this->checkboxList_container_class.'">
							<input class="'.$class.'" type="'.$type.'" id="'.$uniqid.'" name="'.$id.'[]" value="'.$value.'" '.$checked.' '.$disabled.' />
							<label class="'.$this->checkboxList_label_class.'" for="'.$uniqid.'">'.$key.'</label>                  
						</div>
					   ';
			}
		}
		//$output .=  '</fieldset>';
		$output .=  '</div>';
		
		return $output;

	}



	/*******************************
	GENERATES A RADIO GROUP GIVEN AN ARRAY OF VALUES

	INPUT: 	label (str)
			id (str)
			optionvals(associative array, 'label'=>'value')
			checkedval
			readonlyvals(single dimensional array)
	********************************/
	function radioinput($label, $id, $class, &$optionvals, $checkedval, $disabledvals, $readonlyvals, $hint) {
		$checkedvals = array($checkedval);
		return $this->checkboxList($label, $id, $class, $optionvals, $checkedvals, $disabledvals, $readonlyvals, $hint, 'radio');
		
	}


	/*******************************
	GENERATES A COMBO DATE AND A TIME INPUT FIELD

	INPUT: 	label (str)
			id (str)
			class (str)
			value (HH:MM PM)
			hint (string)
			layout ('default', 'horizontal')
	********************************/	
	function datetimeinput2($label, $id, $class, $value, $hint, $readonly=false, $formhint=FALSE, $layout='default'){
	
		$value = trim($value);
		$value = str_replace(' ', 'T', $value);
			
		//$formhint = 'MM/DD/YYYY HH:MM AM/PM';

		return $this->textinput($label, $id, $class, NULL, $value, $hint, 255, $readonly, false, $formhint, 'datetime-local', $layout);
		
	}	

	/*******************************
	GENERATES A DATEPICKER

	SEE TEXTINPUT
	********************************/	
	function dateinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $layout='default' ){
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, $autocomplete, $formhint, 'date', $layout);
	}
	
	/*******************************
	GENERATES A DATE AND A TIME INPUT FIELD

	INPUT: 	label (str)
			id (str)
			class (str)
			value (HH:MM PM)
			hint (string)
			layout ('default', 'horizontal')
	********************************/	
	function timeinput($label, $id, $class, $value, $hint, $layout='default') {
		$class = $class . ' timepicker';
		$output = '';
		$output .= '
		<link rel="stylesheet" href="/adm/includes/jquery-timepicker-1.3.5/jquery.timepicker.min.css"/>
		<script type="text/javascript" src="/adm/includes/jquery-timepicker-1.3.5/jquery.timepicker.min.js"></script>
		<script type="text/javascript">
		$(document).ready(function(){
			$("input.timepicker").timepicker({
				timeFormat: "h:mm p",
				interval: 15,
				//minTime: "10",
				//maxTime: "6:00pm",
				//defaultTime: "11",
				//startTime: "00:00",
				//dynamic: false,
				//dropdown: true,
				//scrollbar: true
			});
		});
		</script>';
		
		$output .= $this->textinput($label, $id, $class, $size, $value, $hint, 10, $readonly, TRUE, FALSE, 'text', $layout);
		return $output;

	
	}



	/*******************************
	GENERATES A DATE AND A TIME INPUT FIELD

	INPUT: 	label (str)
			id (str)
			class (str)
			inputdatetime (datetime)
			hint (string)
			timehint (unused)
			datehint (unused)
			layout ('default', 'row', 'horizontal')
			
			*DOES NOT CONVERT FOR TIMEZONES
	********************************/
	function datetimeinput($label, $id, $class, $inputdatetime, $hint, $timehint, $datehint, $layout='default') {
		
			//REMOVE TIME OR DATE FROM LABEL
			$label = preg_replace('/\s*(time|date)$/i', '', trim($label));
			if(!is_null($inputdatetime) && $inputdatetime != ''){
				$session = SessionControl::get_instance();
				$inputdate = LibraryFunctions::convert_time($inputdatetime, 'UTC', 'UTC', 'Y-m-d');
				$inputtime = LibraryFunctions::convert_time($inputdatetime, 'UTC', 'UTC', 'g:i a');
			}
			else{
				$inputdate = '';
				$inputtime = '';
			}
			
			$output = '';
			if($layout == 'default'){
				$output .= $this->dateinput($label.' date', $id.'_date', NULL, NULL, $inputdate, $hint, NULL, NULL, NULL, NULL, 'default');
				$output .= $this->timeinput($label.' time', $id.'_time', NULL, $inputtime, NULL, 'default'); 
			}
			else if($layout == 'horizontal'){
				$output .= '<div class="row gy-2 gx-3">
					<div class="col-auto">';
				
				$output .= $this->dateinput($label.' date', $id.'_date', NULL, NULL, $inputdate, $hint, NULL, NULL, NULL, NULL, 'default');
			
		
				$output .= '  </div>
					<div class="col-auto">';
			
				$output .= $this->timeinput($label.' time', $id.'_time', NULL, $inputtime, NULL, 'default'); 
			
				$output .= '</div></div>';
			}
			else if($layout = 'row'){
				$output .= $this->dateinput($label.' date', $id.'_date', NULL, NULL, $inputdate, $hint, NULL, NULL, NULL, NULL, 'horizontal');

				$output .= $this->timeinput($label.' time', $id.'_time', NULL, $inputtime, NULL, 'horizontal'); 				
			}
			return $output;

	}



	/*******************************
	GENERATES A DROPDOWN GIVEN AN ARRAY OF VALUES

	INPUT: 	label (str)
			id (str)
			class (str)
			optionvals(associative array, 'label'=>'value')
			input (value selected)
			hint (string)
			showdefault (if true, it shows "choose one")
			forcestrict (selects value only if exactly equal ===)
			imagedropdown (special case for images)
			layout ('default', 'horizontal', '')
	********************************/
	function dropinput($label, $id, $class, &$optionvals, $input, $hint, $showdefault=TRUE, $forcestrict=FALSE, $ajaxendpoint=FALSE, $imagedropdown=FALSE, $layout='default') {
		
		$output = '';
		
		//FORMS ARE EITHER HORIZONTAL OR REGULAR
		if($layout == 'default'){
			$containerclass = $this->dropinput_container_class;
			$labelclass = $this->dropinput_label_class;
		}
		else if($layout == 'horizontal'){
			$containerclass = $this->dropinput_container_class;
			$labelclass = $this->dropinput_label_class;
		}
		else{
			$labelclass = '';
			$containerclass = '';
		}
		
		if($ajaxendpoint){
			$output .= '<link href="/includes/select2.min.css" rel="stylesheet" />
			<script src="/includes/select2.full.min.js"></script>';
		
			$output .= '<script type="text/javascript">
			$(document).ready(function() {
			  $("#'.$id.'").select2({
				placeholder: "None",
				ajax: {
				  url: "'.$ajaxendpoint.'",
				  dataType: "json",
				  delay: 250,
				  processResults: function (data) {
					return {
					  results: data
					};
				  },
				  minimumInputLength: 3,
				  cache: true
				}
			  });
			});
				</script>';
		
		}
		


				
			
						
			if($layout == 'horizontal'){
				$output .= '<div id="'.$id.'_container" class="'.$containerclass.' errorplacement">
								<div class="row mb-3">
									<label for="'.$id.'" class="col-sm-2 col-form-label">'.$label.'</label>
									<div class="col-sm-10">';

			}
			else{
				$output .= '<div id="'.$id.'_container" class="'.$containerclass.' errorplacement">
								<label for="'.$id.'" class="'.$labelclass.'">'.$label.'</label>';				
			}
						
			$output .= '<select name="'.$id.'" id="'.$id.'" class="'.$this->dropinput_select_class.'">';
								


			if($showdefault){
				if($showdefault === true){
					if(is_null($input)){
						$output .=  '<option value="" selected="selected">Choose One</option>';
					}
					else{
						$output .= '<option value="">Choose One</option>';
					}
				}
				else{
					if(is_null($input)){
						$output .=  '<option value="" selected="selected">'.$showdefault.'</option>';
					}
					else{
						$output .= '<option value="">'.$showdefault.'</option>';
					}						
				}
			}


			foreach ($optionvals as $key => $value) {
				if($forcestrict){
					if ($input === $value) { 
						$output .= '<option value="'. $value .'" selected="selected">' . $key . '</option>';
					} 
					else {
						$output .= '<option value="'. $value .'">' . $key . '</option>';
					}					
				}
				else{
					
					if ($input == $value) { 
						$output .= '<option value="'. $value .'" selected="selected">' . $key . '</option>';
					} 
					else {

						$output .= '<option value="'. $value .'">' . $key . '</option>';
					}
				}
			}
			$output .= '</select>';

			if($layout == 'horizontal'){
				$output .= '</div></div>';
			}			
						
			$output .= '</div>';	
		

			return $output;
				 
	}

	function imageinput($label, $id, $class, &$optionvals, $input, $hint,$showdefault=TRUE, $forcestrict=TRUE, $ajaxendpoint=FALSE) {
		
		$output = '';
		
			$output .= '
			<style>
			.image-dropdown {
				/*style the "box" in its minimzed state*/
				border:1px solid black; width:600px; height:80px; overflow:hidden;
				/*animate the dropdown collapsing*/
				transition: height 0.1s;
			}
			.image-dropdown:hover {
				/*when expanded, the dropdown will get native means of scrolling*/
				height:400px; overflow-y:scroll;
				/*animate the dropdown expanding*/
				transition: height 0.5s;
			}
			.image-dropdown input {
				/*hide the nasty default radio buttons!*/
				position:absolute;top:0;left:0;opacity:0;
			}
			.image-dropdown label {
				/*style the labels to look like dropdown options*/
				display:none; margin:2px; height:80px; opacity:0.8;  overflow:hidden;
				/*background:url("http://www.google.com/images/srpr/logo3w.png") 50% 50%;*/
				}
			.image-dropdown:hover label{
				/*this is how labels render in the "expanded" state.
				 we want to see only the selected radio button in the collapsed menu,
				 and all of them when expanded*/
				display:block;
			}
			.image-dropdown input:checked + label {
				/*tricky! labels immediately following a checked radio button
				  (with our markup they are semantically related) should be fully opaque
				  and visible even in the collapsed menu*/
				opacity:1 !important; font-weight: bold; display:block;
			}
			.dropimagewidth {
				display: inline-block;
				width: 80px;
				padding-right: 5px;
			}
			</style>
			';
			
			$output .= '<h5>'.$label.'</h5><div id="'.$id.'_container" class="errorplacement image-dropdown">';
								

			if($showdefault){
				if(is_null($input)){
					$output .= '<input type="radio" id="default_id" name="'.$id.'" value="" checked="checked" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/adm/includes/images/image_placeholder_thumbnail.png"></span> No Image</label>';
				}
				else{
					$output .= '<input type="radio" id="default_id" name="'.$id.'" value="" checked="checked" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/adm/includes/images/image_placeholder_thumbnail.png"></span> No Image</label>';
				}
			}


			foreach ($optionvals as $key => $value) {			

				if($forcestrict && $input === $value){
					
					$output .= '<input type="radio" id="' . $value . '_id" name="'.$id.'" value="'. $value .'" checked="checked" /><label for="' . $value . '_id"> ' . $key . '</label>';
				} elseif ($input == $value) { 
					$output .= '<input type="radio" id="' . $value . '_id" name="'.$id.'" value="'. $value .'" checked="checked" /><label for="' . $value . '_id"> ' . $key . '</label>';
				} else {

					$output .= '<input type="radio" id="' . $value . '_id" name="'.$id.'" value="'. $value .'" /><label for="' . $value . '_id"> ' . $key . '</label>';
				}
			}
			$output .= '
					</div>';

		

			return $output;
				 
	}




	static function file_upload_full($getvars=NULL, $delete=FALSE, $checkall=FALSE){
		$getargs = '';
		if($getvars){ 
			foreach($getvars as $getvar=>$getval){
				$getargs.= '<input type="hidden" name="'.$getvar.'" value="'.$getval.'"/>';      
			}
		}
		
		$settings = Globalvars::get_instance();
		$allowed_extensions = $settings->get_setting('allowed_upload_extensions');
		$accept_attr = '.' . str_replace(',', ',.', $allowed_extensions);
		
		// Get actual PHP upload limits
		$upload_max = ini_get('upload_max_filesize');
		$post_max = ini_get('post_max_size');
		// Convert to bytes to compare
		function parseSize($size) {
			$unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
			$size = preg_replace('/[^0-9\.]/', '', $size);
			if ($unit) {
				return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
			} else {
				return round($size);
			}
		}
		$upload_max_bytes = parseSize($upload_max);
		$post_max_bytes = parseSize($post_max);
		$max_size = min($upload_max_bytes, $post_max_bytes);
		$max_size_display = round($max_size / (1024 * 1024)) . 'MB';
	?>
		<!-- File Drop Zone -->
		<div id="file-drop-zone" class="border border-2 border-dashed rounded p-4 text-center mb-3" style="border-color: #dee2e6; background-color: #f8f9fa; transition: all 0.3s ease; cursor: pointer;">
			<i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
			<h5 class="text-muted">Drop files here or click to browse</h5>
			<p class="text-muted mb-3">Maximum file size: <?php echo $max_size_display; ?> | Allowed types: <?php echo strtoupper(str_replace(',', ', ', $allowed_extensions)); ?></p>
			<input type="file" id="file-input" multiple accept="<?php echo $accept_attr; ?>" style="display: none;">
			<button type="button" id="browse-btn" class="btn btn-outline-primary">
				<i class="fas fa-folder-open me-1"></i> Browse Files
			</button>
		</div>

		<!-- Upload Controls -->
		<div class="d-flex justify-content-between align-items-center mb-3">
			<div>
				<button type="button" id="upload-all-btn" class="btn btn-primary" disabled>
					<i class="fas fa-upload me-1"></i> Upload All
				</button>
				<button type="button" id="clear-all-btn" class="btn btn-outline-secondary ms-2" disabled>
					<i class="fas fa-trash-alt me-1"></i> Clear All
				</button>
			</div>
			<div id="overall-progress" class="flex-grow-1 ms-3" style="display: none;">
				<div class="progress" style="height: 8px;">
					<div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%"></div>
				</div>
			</div>
		</div>

		<!-- Files Table -->
		<div class="table-responsive">
			<table class="table table-hover">
				<thead class="table-light">
					<tr>
						<th><i class="fas fa-file me-1"></i>File Name</th>
						<th><i class="fas fa-weight-hanging me-1"></i>Size</th>
						<th><i class="fas fa-info-circle me-1"></i>Status</th>
						<th><i class="fas fa-cogs me-1"></i>Actions</th>
					</tr>
				</thead>
				<tbody id="files-list">
					<tr id="no-files-message">
						<td colspan="4" class="text-center text-muted py-4">
							<i class="fas fa-file-upload fa-2x mb-2"></i>
							<div>No files selected</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php if($getargs): ?>
		<form id="hidden-form-data" style="display: none;">
			<?php echo $getargs; ?>
		</form>
		<?php endif; ?>
		<script>
		$(function() {
			'use strict';
			
			let selectedFiles = [];
			
			// Get allowed file extensions from server setting
			const allowedExtensions = '<?php echo $allowed_extensions; ?>';
			const allowedTypes = new RegExp('\\.(' + allowedExtensions.replace(/,/g, '|') + ')$', 'i');
			const maxFileSize = <?php echo $max_size; ?>; // Maximum file size in bytes
			
			// DOM elements
			const $dropZone = $('#file-drop-zone');
			const $fileInput = $('#file-input');
			const $browseBtn = $('#browse-btn');
			const $uploadAllBtn = $('#upload-all-btn');
			const $clearAllBtn = $('#clear-all-btn');
			const $filesList = $('#files-list');
			const $noFilesMessage = $('#no-files-message');
			const $overallProgress = $('#overall-progress');
			const $progressBar = $overallProgress.find('.progress-bar');

			// File size formatter
			function formatFileSize(bytes) {
				if (bytes === 0) return '0 Bytes';
				const k = 1024;
				const sizes = ['Bytes', 'KB', 'MB', 'GB'];
				const i = Math.floor(Math.log(bytes) / Math.log(k));
				return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
			}

			// Generate unique ID for each file
			function generateFileId() {
				return 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
			}

			// Get file icon based on extension
			function getFileIcon(filename) {
				const ext = filename.split('.').pop().toLowerCase();
				const iconMap = {
					'pdf': 'fas fa-file-pdf text-danger',
					'doc': 'fas fa-file-word text-primary',
					'docx': 'fas fa-file-word text-primary',
					'xls': 'fas fa-file-excel text-success',
					'xlsx': 'fas fa-file-excel text-success',
					'jpg': 'fas fa-file-image text-info',
					'jpeg': 'fas fa-file-image text-info',
					'png': 'fas fa-file-image text-info',
					'gif': 'fas fa-file-image text-info',
					'mp3': 'fas fa-file-audio text-warning',
					'mp4': 'fas fa-file-video text-danger',
					'm4a': 'fas fa-file-audio text-warning'
				};
				return iconMap[ext] || 'fas fa-file text-muted';
			}

			// Add files to the list
			function addFiles(files) {
				Array.from(files).forEach(file => {
					// Validate file type using server setting
					if (!allowedTypes.test(file.name)) {
						showToast('Invalid file type: ' + file.name + '. Allowed: ' + allowedExtensions, 'error');
						return;
					}

					// Validate file size using server limit
					if (file.size > maxFileSize) {
						showToast('File too large: ' + file.name + '. Maximum size: <?php echo $max_size_display; ?>', 'error');
						return;
					}

					const fileId = generateFileId();
					const fileObj = {
						id: fileId,
						file: file,
						status: 'pending'
					};

					selectedFiles.push(fileObj);
					renderFileRow(fileObj);
				});

				updateUI();
			}

			// Render a file row in the table
			function renderFileRow(fileObj) {
				$noFilesMessage.hide();
				
				const fileIcon = getFileIcon(fileObj.file.name);
				const $row = $(`
					<tr data-file-id="${fileObj.id}" class="file-row">
						<td>
							<div class="d-flex align-items-center">
								<i class="${fileIcon} me-2"></i>
								<span class="file-name">${fileObj.file.name}</span>
							</div>
						</td>
						<td class="file-size">${formatFileSize(fileObj.file.size)}</td>
						<td class="file-status">
							<span class="badge bg-secondary">Ready to upload</span>
						</td>
						<td class="file-actions">
							<button type="button" class="btn btn-sm btn-outline-primary upload-single-btn" title="Upload this file">
								<i class="fas fa-upload"></i>
							</button>
							<button type="button" class="btn btn-sm btn-outline-danger remove-file-btn ms-1" title="Remove this file">
								<i class="fas fa-times"></i>
							</button>
						</td>
					</tr>
				`);

				$filesList.append($row);
			}

			// Update UI state
			function updateUI() {
				const hasFiles = selectedFiles.length > 0;
				const pendingFiles = selectedFiles.filter(f => f.status === 'pending').length;
				
				$uploadAllBtn.prop('disabled', pendingFiles === 0);
				$clearAllBtn.prop('disabled', !hasFiles);
				
				if (!hasFiles) {
					$noFilesMessage.show();
				}
				
				// Update button text with count
				if (pendingFiles > 0) {
					$uploadAllBtn.html(`<i class="fas fa-upload me-1"></i> Upload All (${pendingFiles})`);
				} else {
					$uploadAllBtn.html('<i class="fas fa-upload me-1"></i> Upload All');
				}
			}

			// Show toast notification
			function showToast(message, type = 'info') {
				console.log(type + ': ' + message);
				// Simple alert for now - can be enhanced with proper toast notifications
				if (type === 'error') {
					alert('Error: ' + message);
				}
			}

			// Upload a single file
			function uploadFile(fileObj) {
				return new Promise((resolve, reject) => {
					const formData = new FormData();
					formData.append('files[]', fileObj.file);
					
					// Add any additional form data
					$('#hidden-form-data input').each(function() {
						formData.append($(this).attr('name'), $(this).val());
					});

					const $row = $(`.file-row[data-file-id="${fileObj.id}"]`);
					const $status = $row.find('.file-status');
					const $actions = $row.find('.file-actions');

					// Update UI to uploading state
					$status.html('<span class="badge bg-primary">Uploading...</span>');
					$actions.html(`
						<div class="d-flex align-items-center">
							<div class="progress me-2" style="width: 60px; height: 20px;">
								<div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
							</div>
							<span class="text-muted small">0%</span>
						</div>
					`);

					// Create XMLHttpRequest for progress tracking
					const xhr = new XMLHttpRequest();

					xhr.upload.addEventListener('progress', function(e) {
						if (e.lengthComputable) {
							const progress = Math.round((e.loaded / e.total) * 100);
							$actions.find('.progress-bar').css('width', progress + '%');
							$actions.find('.text-muted').text(progress + '%');
							$status.html(`<span class="badge bg-primary">Uploading ${progress}%</span>`);
						}
					});

					xhr.addEventListener('load', function() {
						if (xhr.status === 200) {
							try {
								const response = JSON.parse(xhr.responseText);
								if (response.files && response.files[0]) {
									const file = response.files[0];
									console.log('Upload response file object:', file); // Debug log
									if (file.url) {
										// Success
										$status.html('<span class="badge bg-success"><i class="fas fa-check me-1"></i>Upload successful</span>');
										$actions.html(`
											<a href="${file.url}" target="_blank" class="btn btn-sm btn-outline-success" title="Download file">
												<i class="fas fa-download"></i>
											</a>
											<button type="button" class="btn btn-sm btn-outline-danger remove-file-btn ms-1" title="Remove from list">
												<i class="fas fa-times"></i>
											</button>
										`);
										
										// Make filename clickable if we have a file ID
										console.log('Checking for file_id:', file.file_id); // Debug log
										if (file.file_id) {
											const $nameElement = $row.find('.file-name');
											const fileName = $nameElement.text();
											const $iconElement = $nameElement.siblings('i');
											const fileIcon = $iconElement.attr('class');
											
											console.log('Making filename clickable:', fileName, 'with ID:', file.file_id); // Debug log
											
											$nameElement.parent().html(`
												<div class="d-flex align-items-center">
													<i class="${fileIcon} me-2"></i>
													<a href="/admin/admin_file?fil_file_id=${file.file_id}" class="file-name-link text-decoration-none">${fileName}</a>
												</div>
											`);
										} else {
											console.log('No file_id found in response'); // Debug log
										}
										
										fileObj.status = 'completed';
										fileObj.url = file.url;
										fileObj.file_id = file.file_id;
										resolve(fileObj);
									} else if (file.error) {
										throw new Error(file.error);
									}
								} else {
									throw new Error('Invalid response format');
								}
							} catch (e) {
								reject(e);
							}
						} else {
							reject(new Error('Upload failed with status: ' + xhr.status));
						}
					});

					xhr.addEventListener('error', function() {
						reject(new Error('Network error during upload'));
					});

					xhr.open('POST', '/admin/admin_file_upload_process');
					xhr.send(formData);
				}).catch(error => {
					// Handle error
					const $row = $(`.file-row[data-file-id="${fileObj.id}"]`);
					const $status = $row.find('.file-status');
					const $actions = $row.find('.file-actions');
					
					$status.html(`<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>Error</span>`);
					$actions.html(`
						<button type="button" class="btn btn-sm btn-outline-primary upload-single-btn" title="Retry upload">
							<i class="fas fa-redo"></i>
						</button>
						<button type="button" class="btn btn-sm btn-outline-danger remove-file-btn ms-1" title="Remove this file">
							<i class="fas fa-times"></i>
						</button>
					`);
					fileObj.status = 'error';
					showToast(`Upload failed: ${fileObj.file.name} - ${error.message}`, 'error');
					throw error;
				});
			}

			// Event Handlers
			$browseBtn.on('click', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$fileInput[0].click(); // Use native click instead of jQuery
			});
			
			$fileInput.on('change', function() {
				if (this.files.length > 0) {
					addFiles(this.files);
					this.value = ''; // Reset input
				}
			});

			// Drag and drop styling
			$dropZone.on('dragover dragenter', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).addClass('border-primary').css('background-color', '#e3f2fd');
			});

			$dropZone.on('dragleave', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).removeClass('border-primary').css('background-color', '#f8f9fa');
			});

			$dropZone.on('drop', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).removeClass('border-primary').css('background-color', '#f8f9fa');
				
				const files = e.originalEvent.dataTransfer.files;
				if (files.length > 0) {
					addFiles(files);
				}
			});

			// Hover effect
			$dropZone.on('mouseenter', function() {
				$(this).css('background-color', '#e3f2fd');
			}).on('mouseleave', function() {
				$(this).css('background-color', '#f8f9fa');
			});

			// Upload all files
			$uploadAllBtn.on('click', async function() {
				const pendingFiles = selectedFiles.filter(f => f.status === 'pending');
				if (pendingFiles.length === 0) return;

				$overallProgress.show();
				$uploadAllBtn.prop('disabled', true);
				
				let completed = 0;
				const total = pendingFiles.length;

				for (const fileObj of pendingFiles) {
					try {
						await uploadFile(fileObj);
						completed++;
						const progress = Math.round((completed / total) * 100);
						$progressBar.css('width', progress + '%');
					} catch (error) {
						console.error('Upload failed:', error);
						completed++; // Count errors as completed for progress
						const progress = Math.round((completed / total) * 100);
						$progressBar.css('width', progress + '%');
					}
				}

				setTimeout(() => {
					$overallProgress.hide();
					$progressBar.css('width', '0%');
					updateUI();
				}, 1000);
			});

			// Clear all files
			$clearAllBtn.on('click', function() {
				if (confirm('Are you sure you want to clear all files?')) {
					selectedFiles = [];
					$filesList.find('.file-row').remove();
					updateUI();
				}
			});

			// Event delegation for dynamic buttons
			$filesList.on('click', '.upload-single-btn', function() {
				const fileId = $(this).closest('.file-row').data('file-id');
				const fileObj = selectedFiles.find(f => f.id === fileId);
				if (fileObj && (fileObj.status === 'pending' || fileObj.status === 'error')) {
					uploadFile(fileObj).then(() => {
						updateUI();
					}).catch(() => {
						updateUI();
					});
				}
			});

			$filesList.on('click', '.remove-file-btn', function() {
				const $row = $(this).closest('.file-row');
				const fileId = $row.data('file-id');
				
				// Remove from array
				selectedFiles = selectedFiles.filter(f => f.id !== fileId);
				
				// Remove from DOM with animation
				$row.fadeOut(300, function() {
					$(this).remove();
					updateUI();
				});
			});

			// Click to browse anywhere in drop zone (except on buttons)
			$dropZone.on('click', function(e) {
				// Only trigger if clicking directly on the drop zone, not on child elements
				if (e.target === this) {
					e.preventDefault();
					e.stopPropagation();
					$fileInput[0].click(); // Use native click
				}
			});
		});
		</script>

		<style>
		#file-drop-zone:hover {
			background-color: #e3f2fd !important;
			border-color: #2196f3 !important;
		}
		
		.file-row {
			transition: background-color 0.2s ease;
		}
		
		.file-row:hover {
			background-color: #f8f9fa;
		}
		
		.progress {
			border-radius: 0.25rem;
		}
		
		.badge {
			font-size: 0.75em;
		}
		
		.btn-sm {
			font-size: 0.8rem;
		}
		
		.table th {
			font-weight: 600;
			font-size: 0.9rem;
		}
		
		.file-name-link {
			color: #0d6efd !important;
		}
		
		.file-name-link:hover {
			color: #0a58ca !important;
			text-decoration: underline !important;
		}
		</style>
	
	
    
    <!-- The XDomainRequest Transport is included for cross-domain file deletion for IE 8 and IE 9 -->
    <!--[if (gte IE 8)&(lt IE 10)]>
      <script src="/includes/jquery-file-upload/js/cors/jquery.xdr-transport.js"></script>
    <![endif]-->	
	  <?php
		
	}


}
?>
